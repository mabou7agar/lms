<?php

namespace App\Platform\Shared\Media\Enums;

/**
 * P2/W04 - The backend that physically stores/serves an asset. Selected per asset from the media
 * type + configuration; the concrete adapter is resolved behind IngestionProvider so credentials
 * live only in the adapter.
 */
enum MediaProvider: string
{
    case Mux = 'mux';
    case S3 = 's3';
    case External = 'external';
    case Fake = 'fake';
}
