<?php

use App\Contexts\Commerce\EInvoicing\Providers\EtaProvider;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

function eta(): EtaProvider
{
    return new EtaProvider(app(Factory::class), ['base_url' => 'https://api.eta.test']);
}

it('submits a document and returns the submission id', function () {
    Http::fake(['*/api/v1/documentsubmissions' => Http::response([
        'submissionId' => 'eta-sub-1',
        'acceptedDocuments' => [['uuid' => 'd1']],
    ])]);

    $result = eta()->clear(einvoicePayload('INV-E'));

    expect($result->accepted)->toBeTrue()
        ->and($result->status)->toBe('submitted')
        ->and($result->providerReference)->toBe('eta-sub-1')
        ->and($result->hash)->toBe(einvoicePayload('INV-E')->hash());
});

it('rejects when there is neither a submission id nor accepted documents', function () {
    Http::fake(['*/api/v1/documentsubmissions' => Http::response(['rejectedDocuments' => [['error' => 'x']]])]);

    expect(eta()->clear(einvoicePayload())->accepted)->toBeFalse();
});
