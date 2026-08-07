<?php

use App\Platform\Shared\Config\ProductionConfigValidator;

function hasSsoFakeError(array $errors): bool
{
    foreach ($errors as $message) {
        if (str_contains($message, 'SSO fake provider')) {
            return true;
        }
    }

    return false;
}

it('flags the fake SSO provider as a critical production error when enabled', function () {
    config(['sso.enabled' => true, 'sso.providers.fake.enabled' => true, 'sso.allow_fake_provider' => false]);

    expect(hasSsoFakeError((new ProductionConfigValidator)->criticalErrors()))->toBeTrue();
});

it('permits the fake SSO provider when explicitly allowed', function () {
    config(['sso.enabled' => true, 'sso.providers.fake.enabled' => true, 'sso.allow_fake_provider' => true]);

    expect(hasSsoFakeError((new ProductionConfigValidator)->criticalErrors()))->toBeFalse();
});

it('does not flag SSO when the feature is disabled', function () {
    config(['sso.enabled' => false, 'sso.providers.fake.enabled' => true]);

    expect(hasSsoFakeError((new ProductionConfigValidator)->criticalErrors()))->toBeFalse();
});
