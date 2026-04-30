<?php

namespace UIArts\ProxyImage\Services\ImageRenderer;

use UIArts\ProxyImage\Support\AbortLogger;

class GdImageRenderer implements ImageRendererInterface
{
    public function render($originStream, array $ops, string $ext): array
    {
        if (!is_resource($originStream)) {
            AbortLogger::abort(502, 'Bad origin stream');
        }

        $ext = strtolower($ext);

        if ($ext === 'avif' && !function_exists('imageavif')) {
            AbortLogger::abort(415, 'AVIF is not supported by GD build');
        }

        // 1) spool origin -> tmp input file
        $tmpIn = tempnam(sys_get_temp_dir(), 'imgin_');
        $tmpOut = tempnam(sys_get_temp_dir(), 'imgout_');

        if ($tmpIn === false || $tmpOut === false) {
            if (is_string($tmpIn)) {
                @unlink($tmpIn);
            }
            if (is_string($tmpOut)) {
                @unlink($tmpOut);
            }
            AbortLogger::abort(500, 'Tmp failed');
        }

        $in = fopen($tmpIn, 'wb');
        if (!is_resource($in)) {
            @unlink($tmpIn);
            @unlink($tmpOut);
            AbortLogger::abort(500, 'Tmp open failed', ['tmp_in' => $tmpIn, 'tmp_out' => $tmpOut]);
        }

        try {
            $copied = stream_copy_to_stream($originStream, $in);
            if ($copied === false || $copied === 0) {
                $this->cleanupFiles($tmpIn, $tmpOut);
                AbortLogger::abort(502, 'Bad origin stream', ['tmp_in' => $tmpIn]);
            }
        } finally {
            fclose($in);
            fclose($originStream);
        }

        $this->validateInputImageBounds($tmpIn);

        // 2) decode image (GD will allocate pixel memory)
        $img = $this->createImageFromFile($tmpIn);
        if ($img === false) {
            $this->cleanupFiles($tmpIn, $tmpOut);
            AbortLogger::abort(415, 'Unsupported image');
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
                $h = $ops['resize']['h'];

                $img = $this->resize($img, $w, $h, $mode);
            }

            // strip metadata: в GD метадані не переносяться при енкоді (по суті “strip” вже буде)

            // 3) encode to output
            $q = (int) ($ops['quality'] ?? 85);
            $contentType = $this->contentType($ext);

            $ok = $this->save($img, $tmpOut, $ext, $q);
            if (!$ok) {
                $this->cleanupFiles($tmpIn, $tmpOut);
                AbortLogger::abort(500, 'Encode failed', ['ext' => $ext, 'quality' => $q]);
            }

            return [
                'tmp_out' => $tmpOut,
                'content_type' => $contentType,
                'cleanup' => function () use ($tmpIn, $tmpOut) {
                    $this->cleanupFiles($tmpIn, $tmpOut);
                },
            ];
        } finally {
            if ($this->isGdImage($img)) {
                imagedestroy($img);
            }
            // tmpIn/tmpOut очищає cleanup()
            @unlink($tmpIn); // input можна прибрати вже тут
        }
    }

    private function resize($img, int $w, $h, string $mode)
    {
        $srcW = imagesx($img);
        $srcH = imagesy($img);

        if ($mode === 'fit') {
            $autoHeight = ($h === 'a');

            if ($autoHeight) {
                // fit по ширині, висоту рахуємо пропорційно
                $dstW = max(1, (int) floor($w));
                $scale = $dstW / $srcW;
                $dstH = max(1, (int) floor($srcH * $scale));
            } else {
                $h = (int) $h;

                // fit inside W/H, keep aspect
                $scale = min($w / $srcW, $h / $srcH);
                $dstW = max(1, (int) floor($srcW * $scale));
                $dstH = max(1, (int) floor($srcH * $scale));
            }

            $dst = imagecreatetruecolor($dstW, $dstH);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);

            imagecopyresampled($dst, $img, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
            imagedestroy($img);

            return $dst;
        }

        if ($mode === 'fill') {
            if ($h === 'a') {
                imagedestroy($img);
                AbortLogger::abort(400, 'Bad fill height', ['height' => $h]);
            }

            $h = (int) $h;
            if ($h <= 0) {
                imagedestroy($img);
                AbortLogger::abort(400, 'Bad fill height', ['height' => $h]);
            }

            // cover + center crop in a single resample pass
            $dstRatio = $w / $h;
            $srcRatio = $srcW / $srcH;

            if ($srcRatio > $dstRatio) {
                $cropH = $srcH;
                $cropW = (int) max(1, floor($srcH * $dstRatio));
                $cropX = (int) max(0, floor(($srcW - $cropW) / 2));
                $cropY = 0;
            } else {
                $cropW = $srcW;
                $cropH = (int) max(1, floor($srcW / $dstRatio));
                $cropX = 0;
                $cropY = (int) max(0, floor(($srcH - $cropH) / 2));
            }

            $dst = imagecreatetruecolor($w, $h);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);

            imagecopyresampled($dst, $img, 0, 0, $cropX, $cropY, $w, $h, $cropW, $cropH);
            imagedestroy($img);

            return $dst;
        }

        imagedestroy($img);
        AbortLogger::abort(400, 'Bad resize mode', ['mode' => $mode]);
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

        $type = @exif_imagetype($tmpIn);
        if ($type !== IMAGETYPE_JPEG) {
            return $img;
        }

        $exif = @exif_read_data($tmpIn);
        if (!is_array($exif)) {
            return $img;
        }

        $orientation = (int) ($exif['Orientation'] ?? 1);

        // minimal set (common cases)
        $rotated = match ($orientation) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => $img,
        };

        if ($rotated !== $img && $this->isGdImage($img)) {
            imagedestroy($img);
        }

        return $rotated;
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

    private function isGdImage(mixed $img): bool
    {
        return is_resource($img) || $img instanceof \GdImage;
    }

    private function createImageFromFile(string $file)
    {
        $type = @exif_imagetype($file);

        return match ($type) {
            IMAGETYPE_JPEG => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($file) : false,
            IMAGETYPE_PNG => function_exists('imagecreatefrompng') ? @imagecreatefrompng($file) : false,
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false,
            IMAGETYPE_AVIF => function_exists('imagecreatefromavif') ? @imagecreatefromavif($file) : false,
            default => false,
        };
    }

    private function validateInputImageBounds(string $file): void
    {
        $info = @getimagesize($file);
        if (!is_array($info)) {
            AbortLogger::abort(415, 'Unsupported image', ['file' => $file]);
        }

        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);

        if ($width <= 0 || $height <= 0) {
            AbortLogger::abort(415, 'Unsupported image', ['width' => $width, 'height' => $height]);
        }

        $rsCfg = (array) config('proxy-image.allowed_ops.rs', []);
        $maxInputW = (int) ($rsCfg['max_input_width'] ?? 8000);
        $maxInputH = (int) ($rsCfg['max_input_height'] ?? 8000);
        $maxInputPixels = (int) ($rsCfg['max_input_pixels'] ?? 40_000_000);

        if ($width > $maxInputW || $height > $maxInputH || ($width * $height) > $maxInputPixels) {
            AbortLogger::abort(413, 'Origin image too large', [
                'width' => $width,
                'height' => $height,
                'max_width' => $maxInputW,
                'max_height' => $maxInputH,
                'max_pixels' => $maxInputPixels,
            ]);
        }
    }
}
