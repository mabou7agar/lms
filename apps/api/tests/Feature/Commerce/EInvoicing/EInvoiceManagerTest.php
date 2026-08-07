<?php

use App\Contexts\Commerce\EInvoicing\EInvoiceManager;
use App\Contexts\Commerce\EInvoicing\Providers\EtaProvider;
use App\Contexts\Commerce\EInvoicing\Providers\FakeEInvoiceProvider;
use App\Contexts\Commerce\EInvoicing\Providers\ZatcaProvider;

function einvoiceManager(): EInvoiceManager
{
    return app(EInvoiceManager::class);
}

it('resolves the fake provider by default', function () {
    config()->set('commerce.einvoicing.provider', 'fake');

    expect(einvoiceManager()->resolve())->toBeInstanceOf(FakeEInvoiceProvider::class);
});

it('resolves zatca and eta by config', function () {
    config()->set('commerce.einvoicing.provider', 'zatca');
    expect(einvoiceManager()->resolve())->toBeInstanceOf(ZatcaProvider::class);

    config()->set('commerce.einvoicing.provider', 'eta');
    expect(einvoiceManager()->resolve())->toBeInstanceOf(EtaProvider::class);
});

it('fails closed on an unknown provider', function () {
    expect(fn () => einvoiceManager()->resolveProvider('nope'))->toThrow(InvalidArgumentException::class);
});

it('refuses the fake provider in production unless explicitly allowed', function () {
    config()->set('app.env', 'production');
    config()->set('commerce.einvoicing.provider', 'fake');
    config()->set('commerce.einvoicing.allow_fake_provider', false);

    expect(fn () => einvoiceManager()->resolve())->toThrow(RuntimeException::class);
});

it('permits the fake provider in production when explicitly allowed', function () {
    config()->set('app.env', 'production');
    config()->set('commerce.einvoicing.provider', 'fake');
    config()->set('commerce.einvoicing.allow_fake_provider', true);

    expect(einvoiceManager()->resolve())->toBeInstanceOf(FakeEInvoiceProvider::class);
});
