<?php

namespace UIArts\ProxyImage\Providers;

use Illuminate\Support\ServiceProvider;
use UIArts\ProxyImage\Services\ProxyImageService;
use UIArts\ProxyImage\Services\ImageRenderer\ImageRendererInterface;
use UIArts\ProxyImage\Services\ImageRenderer\GdImageRenderer;
use UIArts\ProxyImage\Services\ImageRenderer\LibvipsImageRenderer;

class ImagesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // config
        $this->mergeConfigFrom(
            __DIR__ . '/../config/proxy-image.php',
            'proxy-image'
        );

        // core service
        $this->app->singleton(ProxyImageService::class);
        $this->app->singleton(ImageRendererInterface::class, function () {
            $renderer = strtolower((string) config('proxy-image.renderer', 'gd'));

            return match ($renderer) {
                'libvips' => app(LibvipsImageRenderer::class),
                'gd' => app(GdImageRenderer::class),
                default => app(GdImageRenderer::class),
            };
        });
    }

    public function boot(): void
    {
        // routes
        $this->loadRoutesFrom(__DIR__ . '/../../routes/images.php');

        // publish config
        $this->publishes([
            __DIR__ . '/../config/proxy-image.php' => config_path('proxy-image.php'),
        ], 'proxy-image-config');

        // public
        $this->publishes([
            __DIR__ . '/../stubs/images.php' => public_path('images.php'),
        ], 'proxy-image-public');

    }
}
