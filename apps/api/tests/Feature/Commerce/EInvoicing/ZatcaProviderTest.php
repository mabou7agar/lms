<?php

use App\Contexts\Commerce\EInvoicing\Providers\ZatcaProvider;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

function zatca(string $mode = 'reporting'): ZatcaProvider
{
    return new ZatcaProvider(app(Factory::class), ['base_url' => 'https://gw.zatca.test', 'mode' => $mode]);
}

it('reports a simplified invoice and returns the authority reference + hash', function () {
    Http::fake(['*/invoices/reporting/single' => Http::response(['reportingStatus' => 'REPORTED', 'uuid' => 'zatca-uuid-1'])]);

    $result = zatca('reporting')->clear(einvoicePayload('INV-Z'));

    expect($result->accepted)->toBeTrue()
        ->and($result->status)->toBe('reported')
        ->and($result->providerReference)->toBe('zatca-uuid-1')
        ->and($result->hash)->toBe(einvoicePayload('INV-Z')->hash());

    Http::assertSent(fn ($r) => str_contains($r->url(), '/invoices/reporting/single')
        && $r['invoiceHash'] === einvoicePayload('INV-Z')->hash());
});

it('clears a standard invoice in clearance mode', function () {
    Http::fake(['*/invoices/clearance/single' => Http::response(['clearanceStatus' => 'CLEARED', 'uuid' => 'c-1'])]);

    $result = zatca('clearance')->clear(einvoicePayload('INV-C'));

    expect($result->status)->toBe('cleared')->and($result->providerReference)->toBe('c-1');
});

it('marks a rejected document as rejected', function () {
    Http::fake(['*/invoices/reporting/single' => Http::response(['reportingStatus' => 'REJECTED', 'errors' => ['bad']])]);

    $result = zatca('reporting')->clear(einvoicePayload());

    expect($result->accepted)->toBeFalse()->and($result->status)->toBe('rejected');
});
