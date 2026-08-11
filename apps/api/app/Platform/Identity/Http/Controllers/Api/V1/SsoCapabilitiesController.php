<?php

namespace App\Platform\Identity\Http\Controllers\Api\V1;

use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Reports what SSO this platform can honestly do — the single, data-driven source consumed by the
 * org-admin settings UI so the "SAML unsupported" notice is never hard-coded in the frontend.
 *
 * OIDC is supported (JWKS-verified id_tokens); SAML is UNSUPPORTED (no XML-DSIG signed-assertion
 * verification). Everything here reads from config('sso.capabilities') — the same map the SAML
 * routes fail closed against.
 */
class SsoCapabilitiesController extends Controller
{
    public function show(): JsonResponse
    {
        $capabilities = (array) config('sso.capabilities', []);

        // Enabled real OIDC/Apple providers (the 'fake' local seam is never advertised).
        $providers = [];
        foreach ((array) config('sso.providers', []) as $key => $provider) {
            $driver = (string) ($provider['driver'] ?? '');
            if ($key !== 'fake' && in_array($driver, ['oidc', 'apple'], true) && (bool) ($provider['enabled'] ?? false)) {
                $providers[] = (string) $key;
            }
        }

        $oidc = (array) ($capabilities['oidc'] ?? []);
        $saml = (array) ($capabilities['saml'] ?? []);

        return ApiResponse::success([
            'sso_enabled' => (bool) config('sso.enabled', false),
            'oidc' => [
                'supported' => (bool) ($oidc['supported'] ?? false),
                'label' => (string) ($oidc['label'] ?? 'OpenID Connect (OIDC)'),
                'providers' => $providers,
            ],
            'saml' => [
                'supported' => (bool) ($saml['supported'] ?? false),
                'label' => (string) ($saml['label'] ?? 'SAML 2.0'),
                'reason' => (string) ($saml['reason'] ?? 'SAML SSO is not available; use OIDC.'),
            ],
        ]);
    }
}
