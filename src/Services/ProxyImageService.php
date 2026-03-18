<?php

namespace UIArts\ProxyImage\Services;

use UIArts\ProxyImage\Security\UrlSigner;
use UIArts\ProxyImage\Support\Base64Url;
use Symfony\Component\HttpFoundation\Response;
use UIArts\ProxyImage\Services\Source\LocalSource;
use UIArts\ProxyImage\Services\Source\S3Source;
use Illuminate\Support\Str;
use UIArts\ProxyImage\Services\ImageRenderer\ImageRendererInterface;
use Illuminate\Support\Facades\Storage;

class ProxyImageService
{
    public function __construct(
        protected OpsParser $parser,
        protected ImageRendererInterface $renderer
    ) {
    }

    public function handle(
        string $signature,
        string $ops,
        string $encoded,
        string $ext
    ): Response {
        $ext = strtolower($ext);

        // 1) ext allowlist
        if (!in_array($ext, config('proxy-image.allowed_ext', []), true)) {
            abort(400, 'Bad ext');
        }

        // 2) decode path (може містити "s3:"/"local:")
        $decoded = Base64Url::decode($encoded);

        // 3) security path
        if (
            str_contains($decoded, "\0") ||
            str_contains($decoded, '\\') ||
            str_contains($decoded, '..') ||
            preg_match('~^[a-zA-Z][a-zA-Z0-9+\-.]*://~', $decoded)
        ) {
            abort(403);
        }

        // 4) verify signature over "{ops}/{encoded}.{ext}"
        $signedPart = $ops . '/' . $encoded . '.' . $ext;

        if (!UrlSigner::verify($signature, $signedPart)) {
            abort(403);
        }

        // 5) parse ops (allowlist/limits)
        $parsedOps = $this->parser->parse($ops);

        // 6) resolve source + driver, AND strip source prefix from path
        [$source, $driver, $relativePath] = $this->resolveSourceDriverAndRelativePath($decoded);

        // 7) apply origin prefix (allowlist root) by building storage key
        $originPrefix = (string) (config("proxy-image.origins.$driver.prefix") ?? '');
        $key = $this->buildKey($originPrefix, $relativePath);

        // 8) negative cache on 404
        if (!$source->exists($key)) {
            return response('Not found', 404, [
                'Cache-Control' => 'public, max-age=60',
            ]);
        }

        // 9) read stream + render
        $stream = $source->readStream($key);
        $result = $this->renderer->render($stream, $parsedOps, $ext);

        $ttl = (int) config('proxy-image.cache_ttl', 31536000);
        $signedPart = $ops . '/' . $encoded . '.' . $ext;

        return response()->stream(function () use ($result) {
            try {
                if (!is_file($result['tmp_out'])) {
                    error_log('tmp_out missing: ' . $result['tmp_out']);
                    return;
                }

                $out = fopen($result['tmp_out'], 'rb');
                if (is_resource($out)) {
                    fpassthru($out);
                    fclose($out);
                }
            } finally {
                ($result['cleanup'])();
            }
        }, 200, [
            'Content-Type' => $result['content_type'],
            'Cache-Control' => 'public, max-age=' . $ttl . ', immutable',
            'ETag' => '"' . sha1($signedPart) . '"',
        ]);
    }

    /**
     * @return array{0:object,1:string,2:string} [$source, $driver, $relativePath]
     */
    protected function resolveSourceDriverAndRelativePath(string $decoded): array
    {
        foreach (config('proxy-image.source_prefixes') as $prefix => $driver) {
            if (Str::startsWith($decoded, $prefix)) {
                $relative = substr($decoded, strlen($prefix));
                $relative = ltrim($relative, '/');

                $source = match ($driver) {
                    's3' => app(S3Source::class),
                    'local' => app(LocalSource::class),
                    default => abort(400),
                };

                return [$source, $driver, $relative];
            }
        }

        $defaultDriver = $this->resolveDisk();
        $defaultSource = match ($defaultDriver) {
            's3' => app(S3Source::class),
            'local' => app(LocalSource::class),
            default => app(LocalSource::class),
        };

        return [$defaultSource, $defaultDriver, ltrim($decoded, '/')];
    }

    protected function buildKey(string $prefix, string $relative): string
    {
        $prefix = trim($prefix);
        $relative = ltrim($relative, '/');
        $key = $prefix === '' ? $relative : rtrim($prefix, '/') . '/' . $relative;

        return ltrim($key, '/');
    }

    public function imageUrl(string $path, ?int $width = null, ?int $height = null, string $format = 'webp', string $mode = 'fill', ?int $quality = null, bool $autoOrient = true, ?string $disk = null): string
    {
        $disk = $this->resolveDisk($disk);
        $format = strtolower($format);
        $extension = $this->detectExtension($path);

        if ($this->isBypassExtension($extension)) {
            return $this->originalUrl($path, $disk);
        }

        if (!in_array($format, config('proxy-image.allowed_ext', []), true)) {
            return $this->originalUrl($path, $disk);
        }

        if (!$this->isSupportedResize($width, $height)) {
            return $this->originalUrl($path, $disk);
        }

        $path = $this->normalizePath($path, $disk);
        $ops = $this->buildOps($width, $height, $mode, $quality, $autoOrient);

        $encoded = rtrim(strtr(base64_encode($path), '+/', '-_'), '=');
        $signedPart = $ops . '/' . $encoded . '.' . $format;
        $signature = UrlSigner::sign($signedPart);

        return "/i/{$signature}/{$ops}/{$encoded}.{$format}";
    }

    public function singleUrl(string $path, ?int $width = null, ?int $height = null, string $format = 'webp', string $mode = 'fill', ?int $quality = null, bool $autoOrient = true, ?string $disk = null): string
    {
        return $this->imageUrl($path, $width, $height, $format, $mode, $quality, $autoOrient, $disk);
    }

    public function picture(string|array|null $pictures, array $sizes = [], array $attributes = [], ?string $disk = null): string|false
    {
        if (empty($pictures) || empty($sizes)) {
            return false;
        }
        $disk = $this->resolveDisk($disk);

        $formats = $this->normalizeFormats(
            $attributes['formats'] ?? config('proxy-image.picture.formats', ['jpg'])
        );
        $fallbackFormat = $attributes['fallback_format'] ?? config('proxy-image.picture.fallback_format', 'jpg');
        $placeholder = config('proxy-image.picture.placeholder');
        $breakpoints = config('proxy-image.picture.breakpoints', []);
        $devicesOrder = config('proxy-image.picture.devices_order', ['mobile', 'tablet', 'desktop']);

        $quality = (int) ($attributes['quality'] ?? 85);
        $mode = (string) ($attributes['mode'] ?? 'fill');
        $densities = $this->normalizeDensities(
            $attributes['densities'] ?? config('proxy-image.picture.densities', [1])
        );
        $alt = e($attributes['alt'] ?? '');
        $title = e($attributes['title'] ?? '');
        $zoom = e($attributes['data-zoom'] ?? '');
        $error = e($attributes['data-error-src'] ?? '');
        $lazy = e($attributes['loading'] ?? 'lazy');
        $imgClass = e($attributes['class'] ?? '');

        $normalizedPictures = $this->normalizePicturesByDevice($pictures, $sizes, $devicesOrder);

        if (empty($normalizedPictures)) {
            return false;
        }

        $fallbackDevice = $this->resolveFallbackDevice($normalizedPictures, $sizes, $devicesOrder);

        if ($fallbackDevice === null) {
            return false;
        }

        [$fallbackWidth, $fallbackHeight] = $sizes[$fallbackDevice];
        $fallbackPath = $normalizedPictures[$fallbackDevice];
        $fallbackExtension = $this->detectExtension($fallbackPath);

        if ($this->isBypassExtension($fallbackExtension)) {
            $originalUrl = $this->originalUrl($fallbackPath, $disk);

            return <<<HTML
            <picture>
                <img
                    class="{$imgClass}"
                    src="{$originalUrl}"
                    alt="{$alt}"
                    title="{$title}"
                    width="{$fallbackWidth}"
                    height="{$fallbackHeight}"
                    data-zoom="{$zoom}"
                    data-error-src="{$error}"
                    onerror="imgError(this)"
                    loading="{$lazy}"
                />
            </picture>
            HTML;
        }

        $sources = '';

        foreach ($formats as $format) {
            $format = strtolower((string) $format);

            $type = $this->contentTypeForExtension($format);
            $formatSources = [];

            foreach ($devicesOrder as $device) {
                if (
                    !isset($normalizedPictures[$device]) ||
                    !isset($sizes[$device]) ||
                    !isset($breakpoints[$device])
                ) {
                    continue;
                }

                [$width, $height] = $sizes[$device];
                $path = $normalizedPictures[$device];

                if ($this->isBypassExtension($this->detectExtension($path))) {
                    continue;
                }

                $srcset = $this->buildDensitySrcset(
                    $path,
                    (int) $width,
                    (int) $height,
                    $format,
                    $mode,
                    $quality,
                    $disk,
                    $densities
                );

                if ($srcset === '') {
                    continue;
                }

                $formatSources[] = [
                    'media' => (string) $breakpoints[$device],
                    'srcset' => $srcset,
                ];
            }

            if (empty($formatSources)) {
                continue;
            }

            $uniqueSrcsets = array_values(array_unique(array_column($formatSources, 'srcset')));

            // If all device variants are identical, emit a single source without media.
            if (count($uniqueSrcsets) === 1) {
                $sources .= '<source type="' . e($type) . '" srcset="' . e($uniqueSrcsets[0]) . '">' . PHP_EOL;
                continue;
            }

            $seen = [];

            foreach ($formatSources as $formatSource) {
                $key = $formatSource['media'] . '|' . $formatSource['srcset'];

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;

                $sources .= '<source type="' . e($type) . '" srcset="' . e($formatSource['srcset']) . '" media="' . e($formatSource['media']) . '">' . PHP_EOL;
            }
        }

        $fallbackSrc = $this->imageUrl($fallbackPath, (int) $fallbackWidth, (int) $fallbackHeight, $fallbackFormat, $mode, $quality, true, $disk);
        $fallbackSrcset = $this->buildDensitySrcset(
            $fallbackPath,
            (int) $fallbackWidth,
            (int) $fallbackHeight,
            (string) $fallbackFormat,
            $mode,
            $quality,
            $disk,
            $densities
        );

        return <<<HTML
        <picture>
            {$sources}<img
                class="{$imgClass}"
                data-src="{$fallbackSrc}"
                data-srcset="{$fallbackSrcset}"
                src="{$placeholder}"
                alt="{$alt}"
                title="{$title}"
                width="{$fallbackWidth}"
                height="{$fallbackHeight}"
                data-zoom="{$zoom}"
                data-error-src="{$error}"
                onerror="imgError(this)"
                loading="{$lazy}"
            />
        </picture>
        HTML;
    }

    protected function contentTypeForExtension(string $extension): string
    {
        return (string) (config("proxy-image.content_types.{$extension}") ?? 'application/octet-stream');
    }

    protected function normalizePath(string $path, string $disk): string
    {
        $path = ltrim($path, '/');

        if ($this->hasSourcePrefix($path)) {
            return $path;
        }

        return "{$disk}:{$path}";
    }

    protected function resolveDisk(?string $disk = null): string
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

    protected function hasSourcePrefix(string $path): bool
    {
        foreach ((array) config('proxy-image.source_prefixes', []) as $prefix => $driver) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    protected function buildOps(?int $width, ?int $height, string $mode, ?int $quality, bool $autoOrient): string
    {
        $parts = [];

        if ($width !== null && $height !== null) {
            $parts[] = "rs:{$mode}:{$width}:{$height}";
        }

        if ($quality !== null) {
            $parts[] = "q:{$quality}";
        }

        if ($autoOrient) {
            $parts[] = 'a:1';
        }

        return implode('/', $parts);
    }

    protected function normalizePicturesByDevice(string|array $pictures, array $sizes, array $devicesOrder): array
    {
        $result = [];

        if (is_string($pictures)) {
            foreach ($devicesOrder as $device) {
                if (isset($sizes[$device])) {
                    $result[$device] = $pictures;
                }
            }

            return $result;
        }

        foreach ($devicesOrder as $device) {
            if (
                isset($sizes[$device]) &&
                !empty($pictures[$device]) &&
                is_string($pictures[$device])
            ) {
                $result[$device] = $pictures[$device];
            }
        }

        return $result;
    }

    protected function resolveFallbackDevice(array $normalizedPictures, array $sizes, array $devicesOrder): ?string
    {
        foreach ($devicesOrder as $device) {
            if (isset($normalizedPictures[$device], $sizes[$device])) {
                return $device;
            }
        }

        return null;
    }

    protected function buildDensitySrcset(
        string $path,
        int $width,
        int $height,
        string $format,
        string $mode,
        ?int $quality,
        string $disk,
        array $densities = [1, 2]
    ): string {
        if ($width <= 0 || $height <= 0) {
            return '';
        }

        $parts = [];

        foreach ($densities as $density) {
            $density = (int) $density;

            if ($density <= 0) {
                continue;
            }

            $scaledWidth = max(1, $width * $density);
            $scaledHeight = max(1, $height * $density);

            $url = $this->imageUrl(
                $path,
                $scaledWidth,
                $scaledHeight,
                $format,
                $mode,
                $quality,
                true,
                $disk
            );

            $parts[] = "{$url} {$density}x";
        }

        return implode(', ', $parts);
    }

    protected function normalizeDensities(mixed $densities): array
    {
        if (is_string($densities)) {
            $densities = array_map('trim', explode(',', $densities));
        } elseif (is_int($densities)) {
            $densities = [$densities];
        } elseif (!is_array($densities)) {
            $densities = [1];
        }

        $normalized = [];

        foreach ($densities as $density) {
            $density = (int) $density;

            if ($density <= 0 || in_array($density, $normalized, true)) {
                continue;
            }

            $normalized[] = $density;
        }

        if (empty($normalized)) {
            return [1];
        }

        sort($normalized);

        return $normalized;
    }

    protected function normalizeFormats(mixed $formats): array
    {
        if (is_string($formats)) {
            $formats = array_map('trim', explode(',', $formats));
        } elseif (!is_array($formats)) {
            $formats = [];
        }

        $allowed = (array) config('proxy-image.allowed_ext', []);
        $normalized = [];

        foreach ($formats as $format) {
            $format = strtolower((string) $format);
            $format = $format === 'jpeg' ? 'jpg' : $format;

            if ($format === '' || !in_array($format, $allowed, true) || in_array($format, $normalized, true)) {
                continue;
            }

            $normalized[] = $format;
        }

        return $normalized;
    }

    public function originalUrl(string $path, ?string $disk = null): string
    {
        $disk = $this->resolveDisk($disk);
        $path = ltrim($path, '/');

        if ($this->hasSourcePrefix($path)) {
            [$source, $resolvedDisk, $relativePath] = $this->resolveSourceDriverAndRelativePath($path);
            $originPrefix = (string) (config("proxy-image.origins.$resolvedDisk.prefix") ?? '');
            $key = $this->buildKey($originPrefix, $relativePath);

            return Storage::disk(config("proxy-image.origins.$resolvedDisk.disk"))->url($key);
        }

        $originPrefix = (string) (config("proxy-image.origins.$disk.prefix") ?? '');
        $key = $this->buildKey($originPrefix, $path);

        return Storage::disk(config("proxy-image.origins.$disk.disk"))->url($key);
    }

    protected function detectExtension(string $path): string
    {
        $cleanPath = parse_url($path, PHP_URL_PATH) ?: $path;
        $extension = strtolower(pathinfo($cleanPath, PATHINFO_EXTENSION));

        return $extension;
    }

    protected function isBypassExtension(string $extension): bool
    {
        return in_array(
            strtolower($extension),
            (array) config('proxy-image.picture.exclude_types', []),
            true
        );
    }

    protected function isSupportedResize(?int $width, ?int $height): bool
    {
        if ($width === null || $height === null) {
            return false;
        }

        if ($width <= 0 || $height <= 0) {
            return false;
        }

        $rsCfg = (array) config('proxy-image.allowed_ops.rs', []);
        $maxW = (int) ($rsCfg['max_width'] ?? 4000);
        $maxH = (int) ($rsCfg['max_height'] ?? 4000);
        $maxPixels = (int) ($rsCfg['max_pixels'] ?? 16_000_000);

        if ($width > $maxW || $height > $maxH) {
            return false;
        }

        if (($width * $height) > $maxPixels) {
            return false;
        }

        return true;
    }
}
