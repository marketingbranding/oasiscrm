<?php

namespace App\Support;

use App\Models\ContentItem;

final class SalesAgendaEvidenceRules
{
    public const MAX_FILES = 2;

    public const MAX_BYTES = 10 * 1024 * 1024;

    public const MAX_DIMENSION = 1600;

    public const MAX_PIXELS = 40_000_000;

    public const WEBP_QUALITY = 75;

    public const PURGE_MIN_DAYS = 60;

    public const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public static function isSalesAgenda(ContentItem $agenda): bool
    {
        return $agenda->item_type === 'agenda' && $agenda->agenda_type === ContentItem::SALES_AGENDA_TYPE;
    }

    public static function isMutable(ContentItem $agenda): bool
    {
        return ! $agenda->isFinished();
    }
}
