<?php

namespace UIArts\ProxyImage\Support;

use Illuminate\Support\Facades\Log;

final class AbortLogger
{
    public static function abort(
        int $status,
        string $message = '',
        array $context = [],
        string $level = 'warning'
    ): never {
        $logMessage = $message !== '' ? $message : 'Request aborted';
        $payload = array_merge(['status' => $status], $context);

        Log::log($level, 'proxy-image: ' . $logMessage, $payload);

        abort($status, $message);
    }
}
