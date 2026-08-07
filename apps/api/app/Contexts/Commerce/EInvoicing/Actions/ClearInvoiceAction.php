<?php

declare(strict_types=1);

namespace App\Contexts\Commerce\EInvoicing\Actions;

use App\Contexts\Commerce\EInvoicing\Data\EInvoicePayload;
use App\Contexts\Commerce\EInvoicing\EInvoiceManager;
use App\Contexts\Commerce\Models\EInvoiceDocument;
use App\Platform\Shared\Actions\BaseAction;

/**
 * Submits a canonical invoice to the configured tax authority and persists the resulting e-invoice
 * document (provider, hash, reference, outcome, canonical payload). The provider call happens outside
 * the DB transaction so a slow authority round-trip never holds a write lock.
 */
class ClearInvoiceAction extends BaseAction
{
    public function __construct(private readonly EInvoiceManager $manager) {}

    public function execute(EInvoicePayload $payload, ?int $invoiceId = null): EInvoiceDocument
    {
        $provider = $this->manager->resolve();
        $result = $provider->clear($payload);

        return $this->transaction(fn (): EInvoiceDocument => EInvoiceDocument::create([
            'invoice_id' => $invoiceId,
            'provider' => $provider->key(),
            'status' => $result->status,
            'provider_reference' => $result->providerReference,
            'hash' => $result->hash,
            'payload' => $payload->canonicalArray(),
            'cleared_at' => $result->accepted ? now() : null,
        ]));
    }
}
