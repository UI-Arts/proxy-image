<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use UIArts\ProxyImage\Http\Kernels\ImagesKernel;

define('LARAVEL_START', microtime(true));

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';

/** @var ImagesKernel $kernel */
$kernel = $app->make(ImagesKernel::class);

$request = Request::capture();
$response = $kernel->handle($request);

$response->send();
$kernel->terminate($request, $response);
