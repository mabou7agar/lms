<?php

namespace App\Domains\Crm\Enums;

/**
 * Lifecycle of an org BI/data export: queued at request time, processing on the worker, then a
 * terminal completed/failed. A failure is always recorded (never left processing) so the requester
 * sees a resolved outcome rather than an export that silently never arrives.
 */
enum OrgExportStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
