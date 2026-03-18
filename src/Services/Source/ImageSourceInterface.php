<?php

namespace UIArts\ProxyImage\Services\Source;

interface ImageSourceInterface
{
    public function readStream(string $path);
    public function exists(string $path): bool;
}
