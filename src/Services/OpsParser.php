<?php

namespace UIArts\ProxyImage\Services;

use UIArts\ProxyImage\Support\AbortLogger;

class OpsParser
{
    public function parse(string $ops): array
    {
        // ops format: token/token/token
        // example: rs:fill:480:640/q:85/a:1
        $tokens = array_values(array_filter(explode('/', $ops), fn($v) => $v !== ''));

        if (empty($tokens)) {
            AbortLogger::abort(400, 'Bad ops', ['ops' => $ops]);
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
                    AbortLogger::abort(400, 'Bad rs', ['token' => $t]);

                $mode = (string) $parts[1];
                $w = (int) $parts[2];

                $hRaw = $parts[3];
                if ($hRaw === 'a') {
                    $h = 'a';
                } elseif (is_numeric($hRaw) && ((int)$hRaw > 0)) {
                    $h = (int) $hRaw;
                } else {
                    AbortLogger::abort(400, 'Bad h value', ['token' => $t]);
                }

                if (!in_array($mode, config('proxy-image.resize_modes', []), true))
                    AbortLogger::abort(400, 'Bad rs mode', ['mode' => $mode]);

                if ($w <= 0)
                    AbortLogger::abort(400, 'Bad w size', ['width' => $w]);

                if ($h !== 'a') {
                    if ($h <= 0)
                        AbortLogger::abort(400, 'Bad h size', ['height' => $h]);
                } elseif ($mode === 'fill') {
                    AbortLogger::abort(400, 'Bad h value for fill mode', ['mode' => $mode, 'height' => $h]);
                }

                $rsCfg = (array) config('proxy-image.allowed_ops.rs', []);
                $maxW = (int) ($rsCfg['max_width'] ?? 3000);
                $maxH = (int) ($rsCfg['max_height'] ?? 3000);

                if ($w > $maxW)
                    AbortLogger::abort(400, 'w too big', ['width' => $w, 'max_width' => $maxW]);

                if ($h !== 'a' && $h > $maxH)
                    AbortLogger::abort(400, 'h too big', ['height' => $h, 'max_height' => $maxH]);

                $maxPixels = (int) ($rsCfg['max_pixels'] ?? 12_000_000);
                if ($h !== 'a' && ($w * $h) > $maxPixels)
                    AbortLogger::abort(400, 'Too many pixels', ['width' => $w, 'height' => $h, 'max_pixels' => $maxPixels]);

                $out['resize'] = ['mode' => $mode, 'w' => $w, 'h' => $h];
                continue;
            }

            if ($op === 'q') {
                // q:{quality}
                if (count($parts) !== 2)
                    AbortLogger::abort(400, 'Bad q', ['token' => $t]);

                $q = (int) $parts[1];
                $qMin = (int) config('proxy-image.quality_min', 40);
                $qMax = (int) config('proxy-image.quality_max', 92);

                if ($q < $qMin || $q > $qMax)
                    AbortLogger::abort(400, 'Bad quality', ['quality' => $q, 'min' => $qMin, 'max' => $qMax]);

                $out['quality'] = $q;
                continue;
            }

            if ($op === 'a') {
                // a:1 => auto orient
                if (count($parts) !== 2)
                    AbortLogger::abort(400, 'Bad a', ['token' => $t]);
                $out['auto_orient'] = ((int) $parts[1]) === 1;
                continue;
            }

            // unknown token => reject
            AbortLogger::abort(400, 'Unknown op', ['token' => $t]);
        }

        if ($out['resize'] === null && $out['quality'] === null && $out['auto_orient'] === false) {
            AbortLogger::abort(400, 'Empty ops', ['ops' => $ops]);
        }

        return $out;
    }
}
