<?php

namespace App\Platform\Shared\Media\Data;

use Carbon\CarbonInterface;

/**
 * P2/W04 - Everything the browser needs to upload directly to the provider, and the provider
 * reference the backend stores to later verify the upload. The signed URL/fields are opaque to
 * the client and expire.
 */
final readonly class DirectUploadInstructions
{
    /**
     * @param  'PUT'|'POST'  $method
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $fields
     */
    public function __construct(
        public string $providerRef,
        public string $uploadUrl,
        public string $method,
        public array $headers,
        public array $fields,
        public CarbonInterface $expiresAt,
    ) {}
}
