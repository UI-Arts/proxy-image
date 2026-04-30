<?php

namespace UIArts\ProxyImage\Facades;

use Illuminate\Support\Facades\Facade;
use UIArts\ProxyImage\Services\ProxyImageService;

/**
 * @method static string imageUrl(string $path, ?int $width = null, int|string|null $height = null, string $format = 'webp', string $mode = 'fill', ?int $quality = null, bool $autoOrient = true, ?string $disk = null)
 * @method static string singleUrl(string $path, ?int $width = null, ?int $height = null, string $format = 'webp', string $mode = 'fill', ?int $quality = null, bool $autoOrient = true, ?string $disk = null)
 * @method static string|false picture(string|array|null $pictures, array $sizes = [], array $attributes = [], ?string $disk = null, ?string $class = null)
 * @method static array|false pictureData(string|array|null $pictures, array $sizes = [], array $attributes = [], ?string $disk = null, ?string $class = null)
 *
 * @see ProxyImageService
 */
class ProxyImage extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ProxyImageService::class;
    }
}
