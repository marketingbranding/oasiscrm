<?php

namespace App\Services;

use RuntimeException;

final class ExternalWriteGuard
{
    public const MESSAGE = 'External write dinonaktifkan pada environment ini.';

    public function assertAllowed(): void
    {
        if (! $this->isAllowed()) {
            throw new RuntimeException(self::MESSAGE);
        }
    }

    public function isAllowed(): bool
    {
        return (bool) config('services.external_writes.enabled', false);
    }
}
