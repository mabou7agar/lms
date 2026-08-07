<?php

declare(strict_types=1);

namespace App\Contexts\Commerce\EInvoicing\Providers;

use App\Contexts\Commerce\EInvoicing\Contracts\EInvoiceProvider;
use App\Contexts\Commerce\EInvoicing\Data\EInvoicePayload;
use App\Contexts\Commerce\EInvoicing\Data\EInvoiceResult;
use Illuminate\Http\Client\Factory;

/**
 * Egypt ETA e-invoicing adapter. Submits the canonical document and returns the submission outcome.
 *
 * LOCAL REQUIRED: production submission requires ETA's exact signed-document JSON and the document
 * signature (issuer certificate), plus live credentials/tokens. The canonical-hash + submit + result
 * handling here is provider logic, covered in CI with a faked gateway.
 */
final class EtaProvider implements EInvoiceProvider
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
        return 'eta';
    }

    public function clear(EInvoicePayload $payload): EInvoiceResult
    {
        $hash = $payload->hash();

        $response = $this->http->acceptJson()
            ->post(rtrim((string) ($this->config['base_url'] ?? ''), '/').'/api/v1/documentsubmissions', [
                'documents' => [$payload->canonicalArray() + ['documentHash' => $hash]],
            ])
            ->throw()
            ->json();

        $body = is_array($response) ? $response : [];
        $accepted = ($body['acceptedDocuments'] ?? null) !== null && $body['acceptedDocuments'] !== [];
        $reference = (string) ($body['submissionId'] ?? $body['submissionUuid'] ?? $hash);

        if (! $accepted && ($body['submissionId'] ?? null) === null) {
            return EInvoiceResult::rejected($body);
        }

        return EInvoiceResult::accepted('submitted', $reference, $hash, $body);
    }
}
