<?php

namespace UIArts\ProxyImage\Services\ImageRenderer;

interface ImageRendererInterface
{
    /**
     * @param resource $originStream
     * @return array{tmp_out: string, content_type: string, cleanup: callable}
     */
    public function render($originStream, array $ops, string $ext): array;
}
