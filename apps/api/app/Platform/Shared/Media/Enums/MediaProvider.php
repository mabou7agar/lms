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
    // Local disk ingestion for development: stores/serves object bytes on the framework's local
    // filesystem. Credential-free like Fake, but (unlike Fake) it persists the REAL uploaded bytes and
    // serves them back over a plain public URL, so admin uploads actually display. Dev-only — never
    // selected in production, where stored files go to S3 and streamed media to Mux.
    case Local = 'local';
}
