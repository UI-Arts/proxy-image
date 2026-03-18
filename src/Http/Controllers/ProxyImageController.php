<?php

namespace UIArts\ProxyImage\Http\Controllers;

use UIArts\ProxyImage\Services\ProxyImageService;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Routing\Controller;

class ProxyImageController extends Controller
{
    public function __invoke(
        string $signature,
        string $ops,
        string $encoded,
        string $ext
    ): Response {
        return app(ProxyImageService::class)
            ->handle($signature, $ops, $encoded, $ext);
    }
}
