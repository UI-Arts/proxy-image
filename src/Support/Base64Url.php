<?php

namespace UIArts\ProxyImage\Support;

class Base64Url
{
    public static function decode(string $value): string
    {
        $b64 = strtr($value, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad !== 0)
            $b64 .= str_repeat('=', 4 - $pad);

        $out = base64_decode($b64, true);
        if ($out === false) {
            AbortLogger::abort(400, 'Bad base64', ['encoded' => $value]);
        }

        return $out;
    }
}
