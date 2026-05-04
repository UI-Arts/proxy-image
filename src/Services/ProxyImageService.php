<?php

namespace UIArts\ProxyImage\Services;

use UIArts\ProxyImage\Security\UrlSigner;
use Symfony\Component\HttpFoundation\Response;
use UIArts\ProxyImage\Services\Origin\OriginResolver;
use UIArts\ProxyImage\Services\Requests\ImageRequestHandler;

class ProxyImageService
{
    public function __construct(
        protected ImageRequestHandler $requestHandler,
        protected OriginResolver $originResolver
    ) {
    }

    public function handle(
        string $signature,
        string $ops,
        string $encoded,
        string $ext
    ): Response {
        return $this->requestHandler->handle($signature, $ops, $encoded, $ext);
    }

    public function imageUrl(string $path, ?int $width = null, $height = null, string $format = 'webp', string $mode = 'fill', ?int $quality = null, bool $autoOrient = true, ?string $disk = null): string
    {
        $disk = $this->originResolver->resolveDisk($disk);
        $format = strtolower($format);

        if ($this->isPicturePlaceholderMode()) {
            [$placeholderWidth, $placeholderHeight] = $this->resolveLocalDevelopmentDimensionsForUrl($width, $height);

            return $this->buildLocalDevelopmentUrl($placeholderWidth, $placeholderHeight);
        }

        $extension = $this->detectExtension($path);

        if ($this->isBypassExtension($extension)) {
            return $this->originalUrl($path, $disk);
        }

        if (!in_array($format, config('proxy-image.allowed_ext', []), true)) {
            return $this->originalUrl($path, $disk);
        }

        if (is_int($height) && !$this->isSupportedResize($width, $height)) {
            return $this->originalUrl($path, $disk);
        }

        $path = $this->originResolver->normalizePath($path, $disk);
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

    public function picture(string|array|null $pictures, array $sizes = [], array $attributes = [], ?string $disk = null, ?string $class = null): string|false
    {
        $data = $this->buildPictureRenderData($pictures, $sizes, $attributes, $disk, $class);

        if ($data === false) {
            return false;
        }

        return $this->renderPictureHtml($data);
    }

    public function pictureData(string|array|null $pictures, array $sizes = [], array $attributes = [], ?string $disk = null, ?string $class = null): array|false
    {
        $renderData = $this->buildPictureRenderData($pictures, $sizes, $attributes, $disk, $class);

        if ($renderData === false) {
            return false;
        }

        $imgData = (array) ($renderData['img'] ?? []);
        $img = [
            'src' => (string) ($imgData['src'] ?? ''),
            'srcset' => (string) ($imgData['srcset'] ?? ''),
            'alt' => (string) ($imgData['alt'] ?? ''),
            'title' => (string) ($imgData['title'] ?? ''),
            'width' => $imgData['width'] ?? null,
            'height' => $imgData['height'] ?? null,
        ];

        return [
            'mode' => (string) ($renderData['mode'] ?? 'proxy'),
            'img' => $img,
            'sources' => array_values((array) ($renderData['sources'] ?? [])),
        ];
    }

    protected function buildPictureRenderData(string|array|null $pictures, array $sizes = [], array $attributes = [], ?string $disk = null, ?string $class = null): array|false
    {
        if (empty($pictures) || empty($sizes)) {
            return false;
        }

        $disk = $this->originResolver->resolveDisk($disk);

        $formats = $this->normalizeFormats(
            $attributes['formats'] ?? config('proxy-image.picture.formats', ['jpg'])
        );
        $fallbackFormat = $this->normalizeFallbackFormat(
            $attributes['fallback_format'] ?? config('proxy-image.picture.fallback_format', 'jpg')
        );
        $placeholder = config('proxy-image.picture.placeholder');
        $breakpoints = config('proxy-image.picture.breakpoints', []);
        $devicesOrder = config('proxy-image.picture.devices_order', ['mobile', 'tablet', 'desktop']);

        $quality = (int) ($attributes['quality'] ?? 85);
        $mode = (string) ($attributes['mode'] ?? 'fill');
        $densities = $this->normalizeDensities(
            $attributes['densities'] ?? config('proxy-image.picture.densities', [1])
        );
        $alt = (string) ($attributes['alt'] ?? '');
        $title = (string) ($attributes['title'] ?? '');
        $zoom = (string) ($attributes['data-zoom'] ?? '');
        $error = (string) ($attributes['data-error-src'] ?? '');
        $lazy = (string) ($attributes['loading'] ?? 'lazy');
        $imgClass = (string) ($attributes['class'] ?? $attributes['img_class'] ?? '');
        $pictureClass = (string) ($class ?? $attributes['picture_class'] ?? '');
        $fetchPriority = $this->normalizeFetchPriority($attributes['fetchPriority'] ?? $attributes['fetchpriority'] ?? null);

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

        if ($this->isPicturePlaceholderMode()) {
            $localSourceItems = $this->buildLocalDevelopmentSourceItems(
                $sizes,
                $breakpoints,
                $devicesOrder
            );
            [$localWidth, $localHeight] = $this->resolveLocalDevelopmentDimensions(
                (int) $fallbackWidth,
                $fallbackHeight
            );
            $fallbackSrc = $this->buildLocalDevelopmentUrl($localWidth, $localHeight);

            return [
                'mode' => 'placeholder',
                'sources' => $localSourceItems,
                'img' => [
                    'src' => $fallbackSrc,
                    'srcset' => '',
                    'placeholder_src' => $fallbackSrc,
                    'alt' => $alt,
                    'title' => $title,
                    'width' => $localWidth,
                    'height' => $localHeight,
                    'data_zoom' => $zoom,
                    'data_error_src' => $error,
                    'loading' => $lazy,
                    'fetchpriority' => $fetchPriority,
                ],
                'img_class' => $imgClass,
                'picture_class' => $pictureClass,
            ];
        }

        $deviceVariants = $this->buildPictureDeviceVariants(
            $normalizedPictures,
            $sizes,
            $breakpoints,
            $devicesOrder
        );
        $sources = $this->buildBypassSourceItemsFromVariants($deviceVariants, $disk);
        $normalizedPathCache = [];
        $originalUrlCache = [];
        $signedUrlCache = [];
        $densitySrcsetCache = [];
        $densitiesKey = implode(',', $densities);

        foreach ($formats as $format) {
            $format = strtolower((string) $format);

            $type = $this->contentTypeForExtension($format);
            $formatSources = [];

            foreach ($deviceVariants as $variant) {
                if ($variant['is_bypass'] || $variant['media'] === null) {
                    continue;
                }

                $path = $variant['path'];
                $width = $variant['width'];
                $height = $variant['height'];
                $cacheKey = $path . "\0" . $width . "\0" . (string) $height . "\0" . $format . "\0" . $mode . "\0" . (string) $quality . "\0" . $densitiesKey;

                if (!array_key_exists($cacheKey, $densitySrcsetCache)) {
                    $normalizedPath = $normalizedPathCache[$path] ??= $this->originResolver->normalizePath($path, $disk);
                    $densitySrcsetCache[$cacheKey] = $this->buildDensitySrcsetFromPreparedPath(
                        $path,
                        $normalizedPath,
                        $width,
                        $height,
                        $format,
                        $mode,
                        $quality,
                        $disk,
                        $densities,
                        $originalUrlCache,
                        $signedUrlCache
                    );
                }

                $srcset = $densitySrcsetCache[$cacheKey];

                if ($srcset === '') {
                    continue;
                }

                $formatSources[] = [
                    'media' => $variant['media'],
                    'srcset' => $srcset,
                ];
            }

            if (empty($formatSources)) {
                continue;
            }

            $uniqueSrcsets = array_values(array_unique(array_column($formatSources, 'srcset')));

            // If all device variants are identical, emit a single source without media.
            if (count($uniqueSrcsets) === 1) {
                $sources[] = [
                    'type' => $type,
                    'srcset' => $uniqueSrcsets[0],
                    'media' => null,
                ];
                continue;
            }

            $seen = [];

            foreach ($formatSources as $formatSource) {
                $key = $formatSource['media'] . '|' . $formatSource['srcset'];

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;

                $sources[] = [
                    'type' => $type,
                    'srcset' => $formatSource['srcset'],
                    'media' => $formatSource['media'],
                ];
            }
        }

        if ($this->isBypassExtension($fallbackExtension)) {
            $fallbackSrc = $this->originalUrl($fallbackPath, $disk);

            if ($fallbackHeight === 'a') {
                $fallbackHeight = 'auto';
            }

            return [
                'mode' => 'bypass',
                'sources' => $sources,
                'img' => [
                    'src' => $fallbackSrc,
                    'srcset' => '',
                    'placeholder_src' => $fallbackSrc,
                    'alt' => $alt,
                    'title' => $title,
                    'width' => (int) $fallbackWidth,
                    'height' => $fallbackHeight,
                    'data_zoom' => $zoom,
                    'data_error_src' => $error,
                    'loading' => $lazy,
                    'fetchpriority' => $fetchPriority,
                ],
                'img_class' => $imgClass,
                'picture_class' => $pictureClass,
            ];
        }

        $fallbackSrc = $this->imageUrl($fallbackPath, (int) $fallbackWidth, $fallbackHeight, $fallbackFormat, $mode, $quality, true, $disk);
        $normalizedFallbackPath = $normalizedPathCache[$fallbackPath] ??= $this->originResolver->normalizePath($fallbackPath, $disk);
        $fallbackSrcsetCacheKey = $fallbackPath . "\0" . (int) $fallbackWidth . "\0" . (string) $fallbackHeight . "\0" . $fallbackFormat . "\0" . $mode . "\0" . (string) $quality . "\0" . $densitiesKey;

        if (!array_key_exists($fallbackSrcsetCacheKey, $densitySrcsetCache)) {
            $densitySrcsetCache[$fallbackSrcsetCacheKey] = $this->buildDensitySrcsetFromPreparedPath(
                $fallbackPath,
                $normalizedFallbackPath,
                (int) $fallbackWidth,
                $fallbackHeight,
                $fallbackFormat,
                $mode,
                $quality,
                $disk,
                $densities,
                $originalUrlCache,
                $signedUrlCache
            );
        }

        $fallbackSrcset = $densitySrcsetCache[$fallbackSrcsetCacheKey];

        if ($fallbackHeight == 'a') {
            $fallbackHeight = 'auto';
        }

        return [
            'mode' => 'proxy',
            'sources' => $sources,
            'img' => [
                'src' => $fallbackSrc,
                'srcset' => $fallbackSrcset,
                'placeholder_src' => (string) $placeholder,
                'alt' => $alt,
                'title' => $title,
                'width' => (int) $fallbackWidth,
                'height' => $fallbackHeight,
                'data_zoom' => $zoom,
                'data_error_src' => $error,
                'loading' => $lazy,
                'fetchpriority' => $fetchPriority,
            ],
            'img_class' => $imgClass,
            'picture_class' => $pictureClass,
        ];
    }

    protected function renderPictureHtml(array $data): string
    {
        $pictureClass = trim((string) ($data['picture_class'] ?? ''));
        $imgClass = trim((string) ($data['img_class'] ?? ''));
        $pictureClassAttr = $pictureClass === '' ? '' : ' class="' . e($pictureClass) . '"';
        $imgClassAttr = $imgClass === '' ? '' : ' class="' . e($imgClass) . '"';

        $sourcesHtml = '';
        foreach ((array) ($data['sources'] ?? []) as $source) {
            $srcset = (string) ($source['srcset'] ?? '');
            if ($srcset === '') {
                continue;
            }

            $type = $source['type'] ?? null;
            $media = $source['media'] ?? null;
            $typeAttr = $type === null || $type === '' ? '' : ' type="' . e((string) $type) . '"';
            $mediaAttr = $media === null || $media === '' ? '' : ' media="' . e((string) $media) . '"';
            $sourcesHtml .= '<source' . $typeAttr . ' srcset="' . e($srcset) . '"' . $mediaAttr . '>' . PHP_EOL;
        }

        $img = (array) ($data['img'] ?? []);
        $mode = (string) ($data['mode'] ?? 'proxy');
        $src = (string) ($img['src'] ?? '');
        $srcset = (string) ($img['srcset'] ?? '');
        $placeholderSrc = (string) ($img['placeholder_src'] ?? $src);
        $alt = e((string) ($img['alt'] ?? ''));
        $title = e((string) ($img['title'] ?? ''));
        $width = e((string) ($img['width'] ?? ''));
        $height = e((string) ($img['height'] ?? ''));
        $zoom = e((string) ($img['data_zoom'] ?? ''));
        $error = e((string) ($img['data_error_src'] ?? ''));
        $loading = e((string) ($img['loading'] ?? 'lazy'));
        $fetchPriority = $img['fetchpriority'] ?? null;
        $fetchPriorityAttr = $fetchPriority === null || $fetchPriority === ''
            ? ''
            : ' fetchpriority="' . e((string) $fetchPriority) . '"';

        if ($mode === 'proxy') {
            return <<<HTML
            <picture{$pictureClassAttr}>
                {$sourcesHtml}<img{$imgClassAttr}
                    data-src="{$src}"
                    data-srcset="{$srcset}"
                    src="{$placeholderSrc}"
                    alt="{$alt}"
                    title="{$title}"
                    width="{$width}"
                    height="{$height}"
                    data-zoom="{$zoom}"
                    data-error-src="{$error}"
                    onerror="imgError(this)"
                    loading="{$loading}"
                    {$fetchPriorityAttr}
                />
            </picture>
            HTML;
        }

        return <<<HTML
        <picture{$pictureClassAttr}>
            {$sourcesHtml}<img{$imgClassAttr}
                src="{$src}"
                alt="{$alt}"
                title="{$title}"
                width="{$width}"
                height="{$height}"
                data-zoom="{$zoom}"
                data-error-src="{$error}"
                onerror="imgError(this)"
                loading="{$loading}"
                {$fetchPriorityAttr}
            />
        </picture>
        HTML;
    }

    protected function buildPictureDeviceVariants(
        array $normalizedPictures,
        array $sizes,
        array $breakpoints,
        array $devicesOrder
    ): array {
        $variants = [];

        foreach ($devicesOrder as $device) {
            if (!isset($normalizedPictures[$device], $sizes[$device])) {
                continue;
            }

            [$width, $height] = $sizes[$device];
            $path = (string) $normalizedPictures[$device];
            $extension = $this->detectExtension($path);

            $variants[] = [
                'device' => (string) $device,
                'path' => $path,
                'width' => (int) $width,
                'height' => $height,
                'media' => isset($breakpoints[$device]) ? (string) $breakpoints[$device] : null,
                'extension' => $extension,
                'is_bypass' => $this->isBypassExtension($extension),
            ];
        }

        return $variants;
    }

    protected function buildBypassSourceItemsFromVariants(array $deviceVariants, string $disk): array
    {
        $deviceSources = [];
        $urlCache = [];

        foreach ($deviceVariants as $variant) {
            if (!$variant['is_bypass'] || $variant['media'] === null) {
                continue;
            }

            $path = $variant['path'];
            $srcset = $urlCache[$path] ??= $this->originalUrl($path, $disk);

            $deviceSources[] = [
                'media' => $variant['media'],
                'srcset' => $srcset,
                'type' => $this->bypassTypeForExtension($variant['extension']),
            ];
        }

        if (empty($deviceSources)) {
            return [];
        }

        $uniqueVariants = [];

        foreach ($deviceSources as $source) {
            $uniqueVariants[$source['type'] . '|' . $source['srcset']] = true;
        }

        if (count($uniqueVariants) === 1) {
            $single = $deviceSources[0];

            return [[
                'type' => $single['type'],
                'srcset' => $single['srcset'],
                'media' => null,
            ]];
        }

        $out = [];
        $seen = [];

        foreach ($deviceSources as $source) {
            $key = $source['media'] . '|' . $source['srcset'] . '|' . $source['type'];

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = [
                'type' => $source['type'],
                'srcset' => $source['srcset'],
                'media' => $source['media'],
            ];
        }

        return $out;
    }

    protected function buildDensitySrcsetFromPreparedPath(
        string $path,
        string $normalizedPath,
        int $width,
        int|string $height,
        string $format,
        string $mode,
        ?int $quality,
        string $disk,
        array $densities,
        array &$originalUrlCache,
        array &$signedUrlCache
    ): string {
        if ($width <= 0) {
            return '';
        }

        if ($height !== 'a') {
            $height = (int) $height;

            if ($height <= 0) {
                return '';
            }
        }

        $parts = [];

        foreach ($densities as $density) {
            $density = (int) $density;

            if ($density <= 0) {
                continue;
            }

            $scaledWidth = max(1, $width * $density);
            $scaledHeight = $height === 'a' ? 'a' : max(1, $height * $density);

            if (is_int($scaledHeight) && !$this->isSupportedResize($scaledWidth, $scaledHeight)) {
                $url = $originalUrlCache[$path] ??= $this->originalUrl($path, $disk);
            } else {
                $url = $this->buildSignedImageUrlFromPreparedPath(
                    $normalizedPath,
                    $scaledWidth,
                    $scaledHeight,
                    $format,
                    $mode,
                    $quality,
                    true,
                    $signedUrlCache
                );
            }

            $parts[] = "{$url} {$density}x";
        }

        return implode(', ', $parts);
    }

    protected function buildSignedImageUrlFromPreparedPath(
        string $normalizedPath,
        int $width,
        int|string $height,
        string $format,
        string $mode,
        ?int $quality,
        bool $autoOrient,
        array &$cache
    ): string {
        $key = $normalizedPath . "\0" . $width . "\0" . (string) $height . "\0" . $format . "\0" . $mode . "\0" . (string) $quality . "\0" . (int) $autoOrient;

        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $ops = $this->buildOps($width, $height, $mode, $quality, $autoOrient);
        $encoded = rtrim(strtr(base64_encode($normalizedPath), '+/', '-_'), '=');
        $signedPart = $ops . '/' . $encoded . '.' . $format;
        $signature = UrlSigner::sign($signedPart);
        $cache[$key] = "/i/{$signature}/{$ops}/{$encoded}.{$format}";

        return $cache[$key];
    }

    protected function bypassTypeForExtension(string $extension): ?string
    {
        $extension = strtolower($extension);

        return match ($extension) {
            'svg', 'svg+xml' => 'image/svg+xml',
            'gif' => 'image/gif',
            default => null,
        };
    }

    protected function normalizeFetchPriority(mixed $fetchPriority): ?string
    {
        if (!is_string($fetchPriority)) {
            return null;
        }

        $normalized = strtolower(trim($fetchPriority));

        if (in_array($normalized, ['high', 'low', 'auto'], true)) {
            return $normalized;
        }

        return null;
    }

    protected function contentTypeForExtension(string $extension): string
    {
        return (string) (config("proxy-image.content_types.{$extension}") ?? 'application/octet-stream');
    }

    protected function buildOps(?int $width, $height = null, string $mode, ?int $quality, bool $autoOrient): string
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

    protected function isPicturePlaceholderMode(): bool
    {
        return (bool) config('proxy-image.picture.placeholder_mode', false);
    }

    protected function resolveLocalDevelopmentDimensionsForUrl(?int $width, int|string|null $height): array
    {
        $width = $width === null || $width <= 0 ? 1200 : $width;

        return $this->resolveLocalDevelopmentDimensions($width, $height);
    }

    protected function resolveLocalDevelopmentDimensions(int $width, int|string|null $height): array
    {
        $width = max(1, $width);

        if ($height === null || $height === 'a' || (int) $height <= 0) {
            $height = (int) max(1, round($width / 2));
        }

        $height = max(1, (int) $height);

        return [$width, $height];
    }

    protected function buildLocalDevelopmentUrl(int $width, int $height): string
    {
        $baseUrl = 'https://picsum.photos';

        return "{$baseUrl}/{$width}/{$height}";
    }

    protected function buildLocalDevelopmentSourceItems(
        array $sizes,
        array $breakpoints,
        array $devicesOrder
    ): array {
        $items = [];

        foreach ($devicesOrder as $device) {
            if (!isset($sizes[$device], $breakpoints[$device])) {
                continue;
            }

            [$width, $height] = $sizes[$device];
            [$width, $height] = $this->resolveLocalDevelopmentDimensions(
                (int) $width,
                $height
            );
            $src = $this->buildLocalDevelopmentUrl($width, $height);

            $items[] = [
                'type' => null,
                'srcset' => $src,
                'media' => (string) $breakpoints[$device],
            ];
        }

        return $items;
    }

    protected function buildDensitySrcset(
        string $path,
        int $width,
        int|string $height,
        string $format,
        string $mode,
        ?int $quality,
        string $disk,
        array $densities = [1, 2]
    ): string {
        if ($width <= 0 || ($height <= 0 && $height !== "a")) {
            return '';
        }

        $parts = [];

        foreach ($densities as $density) {
            $density = (int) $density;

            if ($density <= 0) {
                continue;
            }

            $scaledWidth = max(1, $width * $density);
            $scaledHeight = $height === 'a' ? 'a' : max(1, $height * $density);

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

    protected function normalizeFallbackFormat(mixed $format): string
    {
        $normalized = $this->normalizeFormats([$format]);

        if (!empty($normalized)) {
            return $normalized[0];
        }

        $configuredFallback = $this->normalizeFormats([
            config('proxy-image.picture.fallback_format', 'jpg'),
        ]);

        if (!empty($configuredFallback)) {
            return $configuredFallback[0];
        }

        $allowed = $this->normalizeFormats((array) config('proxy-image.allowed_ext', []));

        if (!empty($allowed)) {
            return $allowed[0];
        }

        return 'jpg';
    }

    public function originalUrl(string $path, ?string $disk = null): string
    {
        return $this->originResolver->originalUrl($path, $disk);
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
