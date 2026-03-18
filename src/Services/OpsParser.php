<?php

namespace UIArts\ProxyImage\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;

class OpsParser
{
    public function parse(string $ops): array
    {
        // ops format: token/token/token
        // example: rs:fill:480:640/q:85/a:1
        $tokens = array_values(array_filter(explode('/', $ops), fn($v) => $v !== ''));

        if (empty($tokens)) {
            abort(400, 'Bad ops');
        }

        $out = [
            'resize' => null,       // ['mode' => 'fill', 'w' => 480, 'h' => 640]
            'quality' => null,      // int
            'auto_orient' => false, // bool
        ];

        foreach ($tokens as $t) {
            $parts = explode(':', $t);
            $op = $parts[0] ?? '';

            if ($op === 'rs') {
                if (count($parts) !== 4)
                    abort(400, 'Bad rs');

                $mode = (string) $parts[1];
                $w = (int) $parts[2];
                $h = (int) $parts[3];

                if (!in_array($mode, config('proxy-image.resize_modes', []), true))
                    abort(400, 'Bad rs mode');
                if ($w <= 0 || $h <= 0)
                    abort(400, 'Bad rs size');

                $rsCfg = (array) config('proxy-image.allowed_ops.rs', []);
                $maxW = (int) ($rsCfg['max_width'] ?? 3000);
                $maxH = (int) ($rsCfg['max_height'] ?? 3000);

                if ($w > $maxW || $h > $maxH)
                    abort(400, 'Too big');

                $maxPixels = (int) ($rsCfg['max_pixels'] ?? 12_000_000);
                if (($w * $h) > $maxPixels)
                    abort(400, 'Too many pixels');

                $out['resize'] = ['mode' => $mode, 'w' => $w, 'h' => $h];
                continue;
            }

            if ($op === 'q') {
                // q:{quality}
                if (count($parts) !== 2)
                    abort(400, 'Bad q');

                $q = (int) $parts[1];
                $qMin = (int) config('proxy-image.quality_min', 40);
                $qMax = (int) config('proxy-image.quality_max', 92);

                if ($q < $qMin || $q > $qMax)
                    abort(400, 'Bad quality');

                $out['quality'] = $q;
                continue;
            }

            if ($op === 'a') {
                // a:1 => auto orient
                if (count($parts) !== 2)
                    abort(400, 'Bad a');
                $out['auto_orient'] = ((int) $parts[1]) === 1;
                continue;
            }

            // unknown token => reject
            abort(400, 'Unknown op');
        }

        if ($out['resize'] === null && $out['quality'] === null && $out['auto_orient'] === false) {
            abort(400, 'Empty ops');
        }

        return $out;
    }
}
