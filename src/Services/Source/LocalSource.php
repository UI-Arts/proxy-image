<?php

namespace UIArts\ProxyImage\Services\Source;

use Illuminate\Support\Facades\Storage;
use UIArts\ProxyImage\Support\AbortLogger;

class LocalSource implements ImageSourceInterface
{
    public function readStream(string $path)
    {
        $stream = Storage::disk(
            config('proxy-image.origins.local.disk')
        )->readStream($path);

        if ($stream === false) {
            AbortLogger::abort(404, 'Origin not found', ['driver' => 'local', 'path' => $path], 'info');
        }

        return $stream;
    }

    public function exists(string $path): bool
    {
        return Storage::disk(config('proxy-image.origins.local.disk'))->exists($path);
    }
}
