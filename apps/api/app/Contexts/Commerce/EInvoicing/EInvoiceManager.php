<?php

declare(strict_types=1);

namespace App\Contexts\Commerce\EInvoicing;

use App\Contexts\Commerce\EInvoicing\Contracts\EInvoiceProvider;
use App\Contexts\Commerce\EInvoicing\Providers\EtaProvider;
use App\Contexts\Commerce\EInvoicing\Providers\FakeEInvoiceProvider;
use App\Contexts\Commerce\EInvoicing\Providers\ZatcaProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use RuntimeException;

/**
 * Resolves the configured fiscal e-invoicing provider, mirroring the payment GatewayManager: every
 * adapter is built uniformly with the shared HTTP client + its config block, an unknown provider
 * fails closed, and the `fake` provider is refused in production unless explicitly permitted — so a
 * misconfigured deploy can never mark real invoices "cleared" without contacting the tax authority.
 */
class EInvoiceManager
{
    public function __construct(
        private readonly Factory $http,
        private readonly Application $app,
    ) {}

    public function resolve(): EInvoiceProvider
    {
        return $this->resolveProvider((string) config('commerce.einvoicing.provider', 'fake'));
    }

    public function resolveProvider(string $provider): EInvoiceProvider
    {
        if ($provider === 'fake'
            && $this->app->make('config')->get('app.env') === 'production'
            && (bool) config('commerce.einvoicing.allow_fake_provider', false) !== true) {
            throw new RuntimeException('The fake e-invoicing provider is not permitted in production.');
        }

        return match ($provider) {
            'fake' => new FakeEInvoiceProvider,
            'zatca' => new ZatcaProvider($this->http, $this->configFor('zatca')),
            'eta' => new EtaProvider($this->http, $this->configFor('eta')),
            default => throw new InvalidArgumentException("Unsupported e-invoicing provider [{$provider}]."),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function configFor(string $provider): array
    {
        $config = config('commerce.einvoicing.'.$provider, []);

        return is_array($config) ? $config : [];
    }
}
