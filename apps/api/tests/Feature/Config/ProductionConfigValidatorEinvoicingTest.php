<?php

use App\Platform\Shared\Config\ProductionConfigValidator;

function hasEinvoiceFakeError(array $errors): bool
{
    foreach ($errors as $message) {
        if (str_contains($message, 'COMMERCE_EINVOICING_PROVIDER=fake')) {
            return true;
        }
    }

    return false;
}

it('flags the fake e-invoicing provider as a critical production error when enabled', function () {
    config()->set('commerce.einvoicing.enabled', true);
    config()->set('commerce.einvoicing.provider', 'fake');
    config()->set('commerce.einvoicing.allow_fake_provider', false);

    expect(hasEinvoiceFakeError((new ProductionConfigValidator)->criticalErrors()))->toBeTrue();
});

it('permits the fake e-invoicing provider when explicitly allowed', function () {
    config()->set('commerce.einvoicing.enabled', true);
    config()->set('commerce.einvoicing.provider', 'fake');
    config()->set('commerce.einvoicing.allow_fake_provider', true);

    expect(hasEinvoiceFakeError((new ProductionConfigValidator)->criticalErrors()))->toBeFalse();
});

it('does not flag e-invoicing when the feature is disabled', function () {
    config()->set('commerce.einvoicing.enabled', false);
    config()->set('commerce.einvoicing.provider', 'fake');

    expect(hasEinvoiceFakeError((new ProductionConfigValidator)->criticalErrors()))->toBeFalse();
});
