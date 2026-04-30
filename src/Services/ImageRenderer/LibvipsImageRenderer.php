<?php

namespace UIArts\ProxyImage\Services\ImageRenderer;

use UIArts\ProxyImage\Support\AbortLogger;

class LibvipsImageRenderer implements ImageRendererInterface
{
    public function render($originStream, array $ops, string $ext): array
    {
        if (!is_resource($originStream)) {
            AbortLogger::abort(502, 'Bad origin stream');
        }

        $ext = strtolower($ext);
        $ext = $ext === 'jpeg' ? 'jpg' : $ext;

        $tmpIn = tempnam(sys_get_temp_dir(), 'imgin_');
        $tmpOutBase = tempnam(sys_get_temp_dir(), 'imgout_');

        if ($tmpIn === false || $tmpOutBase === false) {
            if (is_string($tmpIn)) {
                @unlink($tmpIn);
            }
            if (is_string($tmpOutBase)) {
                @unlink($tmpOutBase);
            }
            AbortLogger::abort(500, 'Tmp failed');
        }

        $tmpOut = $tmpOutBase . '.' . $ext;
        @unlink($tmpOutBase);

        $in = fopen($tmpIn, 'wb');
        if (!is_resource($in)) {
            $this->cleanupFiles($tmpIn, $tmpOut);
            AbortLogger::abort(500, 'Tmp open failed', ['tmp_in' => $tmpIn, 'tmp_out' => $tmpOut]);
        }

        try {
            $copied = stream_copy_to_stream($originStream, $in);
            if ($copied === false || $copied === 0) {
                $this->cleanupFiles($tmpIn, $tmpOut);
                AbortLogger::abort(502, 'Bad origin stream');
            }
        } finally {
            fclose($in);
            fclose($originStream);
        }

        $resize = $this->buildResizeContext($ops['resize'] ?? null);
        $outputArg = $this->buildOutputArg($tmpOut, $ext, (int) ($ops['quality'] ?? 85));
        $autoOrient = !empty($ops['auto_orient']);
        $mode = $this->resolveMode();

        $primaryCommand = $mode === 'thumbnail_source'
            ? $this->buildThumbnailSourceCommand($tmpIn, $outputArg, $resize, $autoOrient)
            : $this->buildVipsthumbnailCommand($tmpIn, $outputArg, $resize, $autoOrient);

        $primaryResult = $this->runCommand($primaryCommand);
        if (!$this->isSuccessfulResult($primaryResult, $tmpOut)) {
            $this->cleanupFiles($tmpIn, $tmpOut);
            AbortLogger::abort(500, 'libvips render failed', [
                'mode' => $mode,
                'exit_code' => $primaryResult['exit_code'],
                'stderr' => trim($primaryResult['stderr']),
            ]);
        }

        return [
            'tmp_out' => $tmpOut,
            'content_type' => $this->contentType($ext),
            'cleanup' => function () use ($tmpIn, $tmpOut) {
                $this->cleanupFiles($tmpIn, $tmpOut);
            },
        ];
    }

    /**
     * @param array<string,mixed>|null $resize
     * @return array{width:int,height:int|null,mode:string,only_down:bool}
     */
    private function buildResizeContext(?array $resize): array
    {
        if (!is_array($resize)) {
            return [
                'width' => 100000,
                'height' => 100000,
                'mode' => 'fit',
                'only_down' => true,
            ];
        }

        $width = (int) ($resize['w'] ?? 0);
        if ($width <= 0) {
            AbortLogger::abort(400, 'Bad resize width for libvips', ['width' => $width]);
        }

        $mode = strtolower((string) ($resize['mode'] ?? 'fit'));
        $heightRaw = $resize['h'] ?? 'a';
        $height = null;

        if ($heightRaw !== 'a') {
            $height = (int) $heightRaw;
            if ($height <= 0) {
                AbortLogger::abort(400, 'Bad resize height for libvips', ['height' => $height]);
            }
        } elseif ($mode === 'fill') {
            AbortLogger::abort(400, 'Bad h value for fill mode', ['height' => $heightRaw]);
        }

        return [
            'width' => $width,
            'height' => $height,
            'mode' => $mode,
            'only_down' => false,
        ];
    }

    private function buildVipsthumbnailCommand(
        string $tmpIn,
        string $outputArg,
        array $resize,
        bool $autoOrient
    ): string {
        $binary = (string) config('proxy-image.libvips.binary', 'vipsthumbnail');
        $parts = [
            escapeshellarg($binary),
            '--size=' . escapeshellarg($this->buildVipsthumbnailSizeArg($resize)),
            '-o ' . escapeshellarg($outputArg),
        ];

        if ($autoOrient) {
            $parts[] = '--rotate';
        }

        if ($resize['mode'] === 'fill' && is_int($resize['height'])) {
            $parts[] = '--crop';
        }

        $parts[] = escapeshellarg($tmpIn);

        return implode(' ', $parts);
    }

    private function buildThumbnailSourceCommand(
        string $tmpIn,
        string $outputArg,
        array $resize,
        bool $autoOrient
    ): string {
        $binary = (string) config('proxy-image.libvips.binary', 'vips');
        $parts = [
            escapeshellarg($binary),
            'thumbnail_source',
            escapeshellarg('[descriptor=0]'),
            escapeshellarg($outputArg),
            (string) $resize['width'],
        ];

        if (is_int($resize['height'])) {
            $parts[] = '--height';
            $parts[] = escapeshellarg((string) $resize['height']);
        }

        if ($resize['mode'] === 'fill' && is_int($resize['height'])) {
            $parts[] = '--crop';
            $parts[] = escapeshellarg('centre');
        }

        if (!$autoOrient) {
            $parts[] = '--no-rotate';
        }

        if ($resize['only_down']) {
            $parts[] = '--size';
            $parts[] = escapeshellarg('down');
        }

        return implode(' ', $parts) . ' < ' . escapeshellarg($tmpIn);
    }

    private function buildVipsthumbnailSizeArg(array $resize): string
    {
        if (is_int($resize['height'])) {
            $suffix = $resize['only_down'] ? '>' : '';
            return "{$resize['width']}x{$resize['height']}{$suffix}";
        }

        return "{$resize['width']}x";
    }

    private function buildOutputArg(string $tmpOut, string $ext, int $quality): string
    {
        $quality = max(1, min(100, $quality));

        return match ($ext) {
            'jpg', 'webp', 'avif' => "{$tmpOut}[Q={$quality}]",
            default => $tmpOut,
        };
    }

    private function resolveMode(): string
    {
        $mode = strtolower(trim((string) config('proxy-image.libvips.mode', 'vipsthumbnail')));

        if (in_array($mode, ['vipsthumbnail', 'thumbnail_source'], true)) {
            return $mode;
        }

        return 'vipsthumbnail';
    }

    /**
     * @param array{exit_code:int,stdout:string,stderr:string} $result
     */
    private function isSuccessfulResult(array $result, string $tmpOut): bool
    {
        return $result['exit_code'] === 0 && $this->hasValidOutput($tmpOut);
    }

    private function hasValidOutput(string $tmpOut): bool
    {
        return is_file($tmpOut) && filesize($tmpOut) > 0;
    }

    /**
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runCommand(string $command): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Failed to start process'];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'exit_code' => (int) $exitCode,
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    private function cleanupFiles(string $tmpIn, string $tmpOut): void
    {
        @unlink($tmpIn);
        @unlink($tmpOut);
    }

    private function contentType(string $ext): string
    {
        return (string) (config("proxy-image.content_types.$ext") ?? 'application/octet-stream');
    }
}
