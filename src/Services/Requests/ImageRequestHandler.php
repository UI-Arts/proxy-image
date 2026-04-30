<?php

namespace UIArts\ProxyImage\Services\Requests;

use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UIArts\ProxyImage\Security\UrlSigner;
use UIArts\ProxyImage\Services\ImageRenderer\ImageRendererInterface;
use UIArts\ProxyImage\Services\OpsParser;
use UIArts\ProxyImage\Services\Origin\OriginResolver;
use UIArts\ProxyImage\Support\AbortLogger;
use UIArts\ProxyImage\Support\Base64Url;

class ImageRequestHandler
{
    public function __construct(
        protected OpsParser $parser,
        protected ImageRendererInterface $renderer,
        protected OriginResolver $originResolver
    ) {
    }

    public function handle(
        string $signature,
        string $ops,
        string $encoded,
        string $ext
    ): Response {
        $ext = strtolower($ext);

        if (!in_array($ext, config('proxy-image.allowed_ext', []), true)) {
            AbortLogger::abort(400, 'Bad ext', ['ext' => $ext]);
        }

        $decoded = Base64Url::decode($encoded);

        if (
            str_contains($decoded, "\0") ||
            str_contains($decoded, '\\') ||
            str_contains($decoded, '..') ||
            preg_match('~^[a-zA-Z][a-zA-Z0-9+\-.]*://~', $decoded)
        ) {
            AbortLogger::abort(403, 'Forbidden path', ['path' => $decoded]);
        }

        $signedPart = $ops . '/' . $encoded . '.' . $ext;
        if (!UrlSigner::verify($signature, $signedPart)) {
            AbortLogger::abort(403, 'Bad signature', ['signed_part' => $signedPart]);
        }

        $parsedOps = $this->parser->parse($ops);
        [$source, $driver, $relativePath] = $this->originResolver->resolveSourceDriverAndRelativePath($decoded);
        $originPrefix = (string) (config("proxy-image.origins.$driver.prefix") ?? '');
        $key = $this->originResolver->buildKey($originPrefix, $relativePath);

        try {
            $stream = $source->readStream($key);
        } catch (HttpException $e) {
            if ($e->getStatusCode() === 404) {
                Log::info('proxy-image: origin not found', [
                    'driver' => $driver,
                    'key' => $key,
                ]);

                return response('Not found', 404, [
                    'Cache-Control' => 'public, max-age=60',
                ]);
            }

            throw $e;
        }

        $result = $this->renderer->render($stream, $parsedOps, $ext);
        $ttl = (int) config('proxy-image.cache_ttl', 31536000);

        return response()->stream(function () use ($result) {
            try {
                if (!is_file($result['tmp_out'])) {
                    Log::warning('proxy-image: tmp output missing', [
                        'tmp_out' => $result['tmp_out'],
                    ]);
                    return;
                }

                $out = fopen($result['tmp_out'], 'rb');
                if (!is_resource($out)) {
                    Log::warning('proxy-image: failed to open tmp output', [
                        'tmp_out' => $result['tmp_out'],
                    ]);
                    return;
                }

                fpassthru($out);
                fclose($out);
            } finally {
                ($result['cleanup'])();
            }
        }, 200, [
            'Content-Type' => $result['content_type'],
            'Cache-Control' => 'public, max-age=' . $ttl . ', immutable',
            'ETag' => '"' . sha1($signedPart) . '"',
        ]);
    }
}
