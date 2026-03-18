<?php

namespace UIArts\ProxyImage\Facades;

use Illuminate\Support\Facades\Facade;
use UIArts\ProxyImage\Services\ProxyImageService;

class ProxyImage extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ProxyImageService::class;
    }
}
