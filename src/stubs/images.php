<?php

declare(strict_types=1);

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

ini_set('display_errors', '0');
error_reporting(E_ALL);

error_log("IMAGES ENTRYPOINT HIT: " . ($_SERVER['REQUEST_URI'] ?? ''));

require __DIR__ . '/../vendor/autoload.php';

$logFile = __DIR__ . '/../storage/logs/images_entrypoint.log';
@file_put_contents($logFile, "HIT " . ($_SERVER['REQUEST_URI'] ?? '') . "\n", FILE_APPEND);

$app = require_once __DIR__ . '/../bootstrap/app.php';

try {
    /** @var \UIArts\ProxyImage\Http\Kernels\ImagesKernel $kernel */
    $kernel = $app->make(\UIArts\ProxyImage\Http\Kernels\ImagesKernel::class);

    $request = Request::capture();
    $response = $kernel->handle($request);

    $response->send();
    $kernel->terminate($request, $response);
} catch (\Throwable $e) {
    @file_put_contents($logFile, "ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n", FILE_APPEND);
    error_log("IMAGES ENTRYPOINT ERROR: " . $e->getMessage());
    error_log($e->getTraceAsString());

    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Images error\n";
}
