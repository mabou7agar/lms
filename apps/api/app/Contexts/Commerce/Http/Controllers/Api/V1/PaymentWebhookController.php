<?php

namespace App\Contexts\Commerce\Http\Controllers\Api\V1;

use App\Contexts\Commerce\Actions\Payment\ProcessWebhookAction;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Public payment webhook endpoints. Thin: they extract the raw body + signature header and
 * delegate to ProcessWebhookAction, which verifies the signature INSIDE the gateway adapter
 * (fail closed) and advances the order idempotently. No persistence here.
 */
class PaymentWebhookController extends Controller
{
    /**
     * Legacy single-provider webhook (/api/v1/payment/webhook). Uses the config-selected gateway.
     */
    public function handle(Request $request, ProcessWebhookAction $action): JsonResponse
    {
        $action->execute($request->getContent(), $this->signature($request));

        return ApiResponse::success(null, 'ok');
    }

    /**
     * Per-provider webhook (/api/v1/payment/webhook/{provider}). The provider slug selects the
     * adapter via the GatewayManager inside the action; the signature is still verified inside
     * that adapter.
     */
    public function handleProvider(Request $request, string $provider, ProcessWebhookAction $action): JsonResponse
    {
        $action->execute($request->getContent(), $this->signature($request, $provider), $provider);

        return ApiResponse::success(null, 'ok');
    }

    /**
     * Best-effort extraction of the provider's signature header. The header name varies per
     * provider; the adapter performs the authoritative verification, so this only needs to hand
     * the raw header value through. Falls back to the generic headers used by the legacy route.
     */
    private function signature(Request $request, ?string $provider = null): ?string
    {
        $header = match ($provider) {
            'stripe' => 'Stripe-Signature',
            'paymob' => 'X-Hmac',
            'tap' => 'Hashstring',
            'moyasar', 'hyperpay', 'aps' => 'X-Signature',
            default => null,
        };

        if ($header !== null) {
            $value = $request->header($header);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $generic = $request->header('X-Signature') ?? $request->header('Stripe-Signature');

        return is_string($generic) ? $generic : null;
    }
}
