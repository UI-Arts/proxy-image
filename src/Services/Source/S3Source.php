<?php

namespace UIArts\ProxyImage\Services\Source;

use Illuminate\Support\Facades\Storage;

class S3Source implements ImageSourceInterface
{
    public function readStream(string $path)
    {
        $stream = Storage::disk(
            config('proxy-image.origins.s3.disk')
        )->readStream($path);

        if ($stream === false) {
            abort(404);
        }

        return $stream;
    }

    public function exists(string $path): bool
    {
        return Storage::disk(config('proxy-image.origins.s3.disk'))->exists($path);
    }
}
