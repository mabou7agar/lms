<?php

use App\Contexts\Commerce\EInvoicing\Actions\ClearInvoiceAction;
use App\Contexts\Commerce\Models\EInvoiceDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('clears via the fake provider and persists the e-invoice document', function () {
    config()->set('commerce.einvoicing.provider', 'fake');

    $doc = app(ClearInvoiceAction::class)->execute(einvoicePayload('INV-A'), 42);

    expect($doc->provider)->toBe('fake')
        ->and($doc->status)->toBe('cleared')
        ->and($doc->cleared_at)->not->toBeNull()
        ->and((int) $doc->invoice_id)->toBe(42)
        ->and($doc->hash)->toBe(einvoicePayload('INV-A')->hash())
        ->and($doc->payload['invoice_number'])->toBe('INV-A')
        ->and(EInvoiceDocument::query()->count())->toBe(1);
});

it('persists a reported document when the zatca provider is configured', function () {
    config()->set('commerce.einvoicing.provider', 'zatca');
    config()->set('commerce.einvoicing.zatca', ['base_url' => 'https://gw.zatca.test', 'mode' => 'reporting']);
    Http::fake(['*/invoices/reporting/single' => Http::response(['reportingStatus' => 'REPORTED', 'uuid' => 'z-1'])]);

    $doc = app(ClearInvoiceAction::class)->execute(einvoicePayload('INV-Z2'));

    expect($doc->provider)->toBe('zatca')
        ->and($doc->status)->toBe('reported')
        ->and($doc->provider_reference)->toBe('z-1')
        ->and($doc->cleared_at)->not->toBeNull();
});
