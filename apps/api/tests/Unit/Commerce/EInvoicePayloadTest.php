<?php

it('produces a stable canonical shape', function () {
    $canonical = einvoicePayload('INV-9')->canonicalArray();

    expect($canonical['invoice_number'])->toBe('INV-9')
        ->and($canonical['currency'])->toBe('SAR')
        ->and($canonical['seller']['tax_id'])->toBe('300000000000003')
        ->and($canonical['totals']['total'])->toBe(11500)
        ->and($canonical['lines'][0]['tax'])->toBe(1500);
});

it('hashes deterministically and changes with content', function () {
    expect(einvoicePayload('INV-9')->hash())->toBe(einvoicePayload('INV-9')->hash())
        ->and(einvoicePayload('INV-9')->hash())->not->toBe(einvoicePayload('INV-10')->hash());
});
