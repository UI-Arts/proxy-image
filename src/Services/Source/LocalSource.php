<?php

namespace UIArts\ProxyImage\Services\Source;

use Illuminate\Support\Facades\Storage;

class LocalSource implements ImageSourceInterface
{
    public function readStream(string $path)
    {
        $stream = Storage::disk(
            config('proxy-image.origins.local.disk')
        )->readStream($path);

        if ($stream === false) {
            abort(404);
        }

        return $stream;
    }

    public function exists(string $path): bool
    {
        return Storage::disk(config('proxy-image.origins.local.disk'))->exists($path);
    }
}
