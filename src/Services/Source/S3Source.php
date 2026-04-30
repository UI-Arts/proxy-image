<?php

namespace UIArts\ProxyImage\Services\Source;

use Illuminate\Support\Facades\Storage;
use UIArts\ProxyImage\Support\AbortLogger;

class S3Source implements ImageSourceInterface
{
    public function readStream(string $path)
    {
        $stream = Storage::disk(
            config('proxy-image.origins.s3.disk')
        )->readStream($path);

        if ($stream === false) {
            AbortLogger::abort(404, 'Origin not found', ['driver' => 's3', 'path' => $path], 'info');
        }

        return $stream;
    }

    public function exists(string $path): bool
    {
        return Storage::disk(config('proxy-image.origins.s3.disk'))->exists($path);
    }
}
