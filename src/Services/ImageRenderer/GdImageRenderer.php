<?php

namespace UIArts\ProxyImage\Services\ImageRenderer;

class GdImageRenderer implements ImageRendererInterface
{
    public function render($originStream, array $ops, string $ext): array
    {
        if (!is_resource($originStream)) {
            abort(502, 'Bad origin stream');
        }

        $ext = strtolower($ext);

        if ($ext === 'avif' && !function_exists('imageavif')) {
            abort(415, 'AVIF is not supported by GD build');
        }

        // 1) spool origin -> tmp input file
        $tmpIn = tempnam(sys_get_temp_dir(), 'imgin_');
        $tmpOut = tempnam(sys_get_temp_dir(), 'imgout_');

        if ($tmpIn === false || $tmpOut === false) {
            abort(500, 'Tmp failed');
        }

        $in = fopen($tmpIn, 'wb');
        if (!is_resource($in)) {
            @unlink($tmpIn);
            @unlink($tmpOut);
            abort(500, 'Tmp open failed');
        }

        try {
            stream_copy_to_stream($originStream, $in);
        } finally {
            fclose($in);
            fclose($originStream);
        }

        // 2) decode image (GD will allocate pixel memory)
        $img = @imagecreatefromstring(file_get_contents($tmpIn));
        if ($img === false) {
            $this->cleanupFiles($tmpIn, $tmpOut);
            abort(415, 'Unsupported image');
        }

        try {
            // auto-orient: GD не вміє “autoOrient” сам по собі
            if (!empty($ops['auto_orient'])) {
                $img = $this->autoOrientIfPossible($img, $tmpIn);
            }

            // resize
            if (!empty($ops['resize'])) {
                $mode = $ops['resize']['mode'];
                $w = (int) $ops['resize']['w'];
                $h = (int) $ops['resize']['h'];

                $img = $this->resize($img, $w, $h, $mode);
            }

            // strip metadata: в GD метадані не переносяться при енкоді (по суті “strip” вже буде)

            // 3) encode to output
            $q = (int) ($ops['quality'] ?? 85);
            $contentType = $this->contentType($ext);

            $ok = $this->save($img, $tmpOut, $ext, $q);
            if (!$ok) {
                $this->cleanupFiles($tmpIn, $tmpOut);
                abort(500, 'Encode failed');
            }

            return [
                'tmp_out' => $tmpOut,
                'content_type' => $contentType,
                'cleanup' => function () use ($tmpIn, $tmpOut) {
                    $this->cleanupFiles($tmpIn, $tmpOut);
                },
            ];
        } finally {
            if (is_resource($img)) {
                imagedestroy($img);
            }
            // tmpIn/tmpOut очищає cleanup()
            @unlink($tmpIn); // input можна прибрати вже тут
        }
    }

    private function resize($img, int $w, int $h, string $mode)
    {
        $srcW = imagesx($img);
        $srcH = imagesy($img);

        if ($mode === 'fit') {
            // fit inside W/H, keep aspect
            $scale = min($w / $srcW, $h / $srcH);
            $dstW = max(1, (int) floor($srcW * $scale));
            $dstH = max(1, (int) floor($srcH * $scale));

            $dst = imagecreatetruecolor($dstW, $dstH);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);

            imagecopyresampled($dst, $img, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
            imagedestroy($img);

            return $dst;
        }

        if ($mode === 'fill') {
            // cover + center crop to W/H
            $scale = max($w / $srcW, $h / $srcH);
            $tmpW = max(1, (int) ceil($srcW * $scale));
            $tmpH = max(1, (int) ceil($srcH * $scale));

            $tmp = imagecreatetruecolor($tmpW, $tmpH);
            imagealphablending($tmp, false);
            imagesavealpha($tmp, true);

            imagecopyresampled($tmp, $img, 0, 0, 0, 0, $tmpW, $tmpH, $srcW, $srcH);

            $dst = imagecreatetruecolor($w, $h);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);

            $srcX = (int) max(0, floor(($tmpW - $w) / 2));
            $srcY = (int) max(0, floor(($tmpH - $h) / 2));

            imagecopy($dst, $tmp, 0, 0, $srcX, $srcY, $w, $h);

            imagedestroy($tmp);
            imagedestroy($img);

            return $dst;
        }

        imagedestroy($img);
        abort(400, 'Bad resize mode');
    }

    private function save($img, string $path, string $ext, int $q): bool
    {
        $ext = strtolower($ext);

        if ($ext === 'jpg' || $ext === 'jpeg') {
            $q = max(0, min(100, $q));
            return imagejpeg($img, $path, $q);
        }

        if ($ext === 'png') {
            $level = 6;
            return imagepng($img, $path, $level);
        }

        if ($ext === 'webp') {
            $q = max(0, min(100, $q));
            return function_exists('imagewebp') ? imagewebp($img, $path, $q) : false;
        }

        if ($ext === 'avif') {
            $q = max(0, min(100, $q));
            return function_exists('imageavif') ? imageavif($img, $path, $q) : false;
        }

        return false;
    }

    private function autoOrientIfPossible($img, string $tmpIn)
    {
        if (!function_exists('exif_read_data')) {
            return $img;
        }

        $exif = @exif_read_data($tmpIn);
        if (!is_array($exif)) {
            return $img;
        }

        $orientation = (int) ($exif['Orientation'] ?? 1);

        // minimal set (common cases)
        return match ($orientation) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => $img,
        };
    }

    private function contentType(string $ext): string
    {
        return (string) (config("proxy-image.content_types.$ext") ?? 'application/octet-stream');
    }

    private function cleanupFiles(string $tmpIn, string $tmpOut): void
    {
        @unlink($tmpIn);
        @unlink($tmpOut);
    }
}
