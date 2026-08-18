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
     * @param  string|null  $localDisk  Dev-only: when set, a SERVER-SIDE admin upload writes the bytes
     *                                  straight to this filesystem disk (at $localKey) instead of
     *                                  forwarding them to $uploadUrl over HTTP. Lets the credential-free
     *                                  local provider avoid a self-request (the dev server is a single
     *                                  `artisan serve` worker, which a loopback PUT would deadlock).
     *                                  Never serialized to the client (see DirectUploadTicketResource).
     * @param  string|null  $localKey  Storage key on $localDisk to write to.
     */
    public function __construct(
        public string $providerRef,
        public string $uploadUrl,
        public string $method,
        public array $headers,
        public array $fields,
        public CarbonInterface $expiresAt,
        public ?string $localDisk = null,
        public ?string $localKey = null,
    ) {}
}
