<?php

use Illuminate\Support\Facades\Route;

Route::get(
    '/i/{signature}/{ops}/{encoded}.{ext}',
    'UIArts\ProxyImage\Http\Controllers\ProxyImageController'
)->where([
            'signature' => '[A-Za-z0-9\-_]+',
            'ops' => '[A-Za-z0-9:\/\-,\.]+',
            'encoded' => '[A-Za-z0-9\-_]+',
            'ext' => '(jpg|jpeg|png|webp|avif)',
        ]);
