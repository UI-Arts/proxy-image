<?php

namespace UIArts\ProxyImage\Services\Origin;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use UIArts\ProxyImage\Services\Source\ImageSourceInterface;
use UIArts\ProxyImage\Services\Source\LocalSource;
use UIArts\ProxyImage\Services\Source\S3Source;
use UIArts\ProxyImage\Support\AbortLogger;

class OriginResolver
{
    public function __construct(
        protected LocalSource $localSource,
        protected S3Source $s3Source
    ) {
    }

    /**
     * @return array{0:ImageSourceInterface,1:string,2:string} [$source, $driver, $relativePath]
     */
    public function resolveSourceDriverAndRelativePath(string $decoded): array
    {
        foreach ((array) config('proxy-image.source_prefixes', []) as $prefix => $driver) {
            if (Str::startsWith($decoded, $prefix)) {
                $relative = ltrim(substr($decoded, strlen($prefix)), '/');
                $source = $this->sourceByDriver($driver);

                return [$source, $driver, $relative];
            }
        }

        $defaultDriver = $this->resolveDisk();
        $defaultSource = $this->sourceByDriver($defaultDriver, $this->localSource);

        return [$defaultSource, $defaultDriver, ltrim($decoded, '/')];
    }

    public function buildKey(string $prefix, string $relative): string
    {
        $prefix = trim($prefix);
        $relative = ltrim($relative, '/');
        $key = $prefix === '' ? $relative : rtrim($prefix, '/') . '/' . $relative;

        return ltrim($key, '/');
    }

    public function resolveDisk(?string $disk = null): string
    {
        $disk = trim((string) $disk);

        if ($disk === '') {
            $disk = (string) config('proxy-image.default_disk', 'local');
        }

        if (!is_array(config("proxy-image.origins.{$disk}"))) {
            return 'local';
        }

        return $disk;
    }

    public function hasSourcePrefix(string $path): bool
    {
        foreach ((array) config('proxy-image.source_prefixes', []) as $prefix => $driver) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function normalizePath(string $path, string $disk): string
    {
        $path = ltrim($path, '/');

        if ($this->hasSourcePrefix($path)) {
            return $path;
        }

        return "{$disk}:{$path}";
    }

    public function originalUrl(string $path, ?string $disk = null): string
    {
        $disk = $this->resolveDisk($disk);
        $path = trim($path);

        if ($this->isAbsoluteUrl($path)) {
            return $path;
        }

        $path = ltrim($path, '/');

        if ($this->hasSourcePrefix($path)) {
            [, $resolvedDisk, $relativePath] = $this->resolveSourceDriverAndRelativePath($path);
            $originPrefix = (string) (config("proxy-image.origins.$resolvedDisk.prefix") ?? '');
            $key = $this->buildKey($originPrefix, $relativePath);

            return Storage::disk(config("proxy-image.origins.$resolvedDisk.disk"))->url($key);
        }

        $originPrefix = (string) (config("proxy-image.origins.$disk.prefix") ?? '');
        $key = $this->buildKey($originPrefix, $path);

        return Storage::disk(config("proxy-image.origins.$disk.disk"))->url($key);
    }

    private function sourceByDriver(string $driver, ?ImageSourceInterface $fallback = null): ImageSourceInterface
    {
        return match ($driver) {
            's3' => $this->s3Source,
            'local' => $this->localSource,
            default => $fallback ?? AbortLogger::abort(
                400,
                'Bad source driver',
                ['driver' => $driver]
            ),
        };
    }

    private function isAbsoluteUrl(string $path): bool
    {
        return (bool) preg_match('~^(?:https?:)?//~i', $path);
    }
}
