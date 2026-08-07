<?php

declare(strict_types=1);

namespace App\Contexts\Commerce\EInvoicing\Providers;

use App\Contexts\Commerce\EInvoicing\Contracts\EInvoiceProvider;
use App\Contexts\Commerce\EInvoicing\Data\EInvoicePayload;
use App\Contexts\Commerce\EInvoicing\Data\EInvoiceResult;
use Illuminate\Http\Client\Factory;

/**
 * KSA ZATCA "Fatoora" adapter. Computes the canonical document hash and submits it to the reporting
 * (B2C simplified) or clearance (B2B standard) endpoint, returning the authority's outcome.
 *
 * LOCAL REQUIRED: a production submission additionally requires the UBL 2.1 XML rendering and the
 * cryptographic stamp (onboarded CSID / XAdES signature) built from ZATCA-issued certificates, plus
 * the live gateway credentials. Those are exercised locally; the canonical-hash + submit + result
 * handling here is provider logic and is covered in CI with a faked gateway.
 */
final class ZatcaProvider implements EInvoiceProvider
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly Factory $http,
        private readonly array $config,
    ) {}

    public function key(): string
    {
        return 'zatca';
    }

    public function clear(EInvoicePayload $payload): EInvoiceResult
    {
        $hash = $payload->hash();
        $clearance = (string) ($this->config['mode'] ?? 'reporting') === 'clearance';
        $path = $clearance ? '/invoices/clearance/single' : '/invoices/reporting/single';

        $response = $this->http->acceptJson()
            ->post(rtrim((string) ($this->config['base_url'] ?? ''), '/').$path, [
                'invoiceHash' => $hash,
                'invoice' => $payload->canonicalArray(),
            ])
            ->throw()
            ->json();

        $body = is_array($response) ? $response : [];
        $status = strtoupper((string) ($body['clearanceStatus'] ?? $body['reportingStatus'] ?? ''));

        if (! in_array($status, ['CLEARED', 'REPORTED', 'ACCEPTED'], true)) {
            return EInvoiceResult::rejected($body);
        }

        $reference = (string) ($body['uuid'] ?? $body['invoiceHash'] ?? $hash);

        return EInvoiceResult::accepted($clearance ? 'cleared' : 'reported', $reference, $hash, $body);
    }
}
