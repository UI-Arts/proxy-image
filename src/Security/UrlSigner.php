<?php

namespace UIArts\ProxyImage\Security;

class UrlSigner
{
    public static function sign(string $signedPart): string
    {
        $secret = (string) config('proxy-image.hmac_secret');

        if ($secret === '') {
            abort(500, 'IMAGES_HMAC_SECRET is empty');
        }

        $raw = hash_hmac('sha256', $signedPart, $secret, true);
        $raw = substr($raw, 0, 20);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function verify(string $signature, string $signedPart): bool
    {
        return hash_equals($signature, self::sign($signedPart));
    }
}
