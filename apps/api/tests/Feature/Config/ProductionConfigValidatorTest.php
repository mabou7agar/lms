<?php

use App\Platform\Shared\Config\ProductionConfigValidator;

/** Apply a fully production-safe configuration so individual cases can break exactly one thing. */
function safeProductionConfig(): void
{
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'app.env' => 'production',
        'app.debug' => false,
        'app.url' => 'https://app.example.com',
        'database.default' => 'pgsql',
        'database.connections.pgsql.host' => 'db.internal',
        'database.connections.pgsql.database' => 'helbaron',
        'database.connections.pgsql.username' => 'helbaron',
        'database.redis.default.host' => '127.0.0.1',
        'queue.default' => 'redis',
        'cache.default' => 'redis',
        'session.driver' => 'redis',
        'session.secure' => true,
        'commerce.payment.provider' => 'stripe',
        'commerce.payment.webhook_secret' => 'whsec_test',
        'media.ingestion.default' => 'mux',
        'notifications.providers.mail' => 'ses',
        'notifications.providers.sms' => 'sns',
        'notifications.providers.push' => 'fcm',
        'mail.default' => 'smtp',
        'mail.from.address' => 'no-reply@example.com',
        'logging.default' => 'stack',
        'security.trusted_proxies' => '203.0.113.0/24',
        'security.trusted_hosts' => 'app.example.com',
        'commerce.payment.allow_fake_gateway' => false,
    ]);
}

beforeEach(fn () => safeProductionConfig());

it('passes a fully production-safe configuration', function () {
    $v = new ProductionConfigValidator;
    expect($v->criticalErrors())->toBe([])
        ->and($v->errors())->toBe([]);
});

it('flags APP_DEBUG=true as a critical error', function () {
    config(['app.debug' => true]);
    expect(implode(' ', (new ProductionConfigValidator)->criticalErrors()))->toContain('APP_DEBUG');
});

it('flags a missing APP_KEY', function () {
    config(['app.key' => '']);
    expect(implode(' ', (new ProductionConfigValidator)->criticalErrors()))->toContain('APP_KEY');
});

it('flags a non-https APP_URL', function () {
    config(['app.url' => 'http://app.example.com']);
    expect(implode(' ', (new ProductionConfigValidator)->criticalErrors()))->toContain('APP_URL');
});

it('flags a synchronous queue', function () {
    config(['queue.default' => 'sync']);
    expect(implode(' ', (new ProductionConfigValidator)->criticalErrors()))->toContain('sync');
});

it('flags a non-persistent cache/session driver', function () {
    config(['cache.default' => 'array', 'session.driver' => 'array']);
    $msg = implode(' ', (new ProductionConfigValidator)->criticalErrors());
    expect($msg)->toContain('CACHE_STORE=array')->and($msg)->toContain('SESSION_DRIVER=array');
});

it('rejects the fake payment gateway unless explicitly allowed', function () {
    config(['commerce.payment.provider' => 'fake']);
    expect(implode(' ', (new ProductionConfigValidator)->criticalErrors()))->toContain('fake');

    config(['commerce.payment.allow_fake_gateway' => true]);
    expect(implode(' ', (new ProductionConfigValidator)->criticalErrors()))->not->toContain('COMMERCE_PAYMENT_PROVIDER=fake');
});

it('flags a missing webhook secret', function () {
    config(['commerce.payment.webhook_secret' => '']);
    expect(implode(' ', (new ProductionConfigValidator)->criticalErrors()))->toContain('WEBHOOK_SECRET');
});

it('flags the fake media provider', function () {
    config(['media.ingestion.default' => 'fake']);
    expect(implode(' ', (new ProductionConfigValidator)->criticalErrors()))->toContain('MEDIA');
});

it('flags a fake notification transport unless explicitly allowed', function () {
    config(['notifications.providers.mail' => 'fake']);
    expect(implode(' ', (new ProductionConfigValidator)->criticalErrors()))->toContain('NOTIFICATIONS');

    config(['notifications.allow_fake_providers' => true]);
    expect(implode(' ', (new ProductionConfigValidator)->criticalErrors()))->not->toContain('NOTIFICATIONS');
});

it('flags missing trusted proxies', function () {
    config(['security.trusted_proxies' => '']);
    expect(implode(' ', (new ProductionConfigValidator)->criticalErrors()))->toContain('TRUSTED_PROXIES');
});

it('flags an insecure session cookie', function () {
    config(['session.secure' => false]);
    expect(implode(' ', (new ProductionConfigValidator)->criticalErrors()))->toContain('SESSION_SECURE_COOKIE');
});

it('the config:validate command exits non-zero on unsafe config', function () {
    config(['app.debug' => true]);
    $this->artisan('config:validate')->assertExitCode(1);
});

it('the config:validate command exits zero on safe config', function () {
    $this->artisan('config:validate')->assertExitCode(0);
});
