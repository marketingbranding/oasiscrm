<?php

namespace App\Support;

final class ConsumerIdentity
{
    public static function nikHash(?string $nik): ?string
    {
        $normalized = preg_replace('/\D+/', '', (string) $nik) ?? '';

        if ($normalized === '') {
            return null;
        }

        return hash_hmac('sha256', $normalized, (string) config('app.key'));
    }
}
