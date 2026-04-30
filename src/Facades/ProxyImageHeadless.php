<?php

namespace UIArts\ProxyImage\Facades;

use Illuminate\Support\Facades\Facade;
use UIArts\ProxyImage\Services\ProxyImageService;

/**
 * @method static array|false pictureData(string|array|null $pictures, array $sizes = [], array $attributes = [], ?string $disk = null, ?string $class = null)
 *
 * @see ProxyImageService
 */
class ProxyImageHeadless extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ProxyImageService::class;
    }
}
