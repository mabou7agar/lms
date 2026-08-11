<?php

namespace App\Platform\Identity\Http\Controllers\Api\V1;

use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * SAML is UNSUPPORTED — honestly. There is NO XML-DSIG (signed-assertion) verification in this
 * platform, so accepting a SAML assertion we cannot cryptographically verify would be an
 * authentication bypass. Both the metadata and the Assertion Consumer Service (ACS) endpoints
 * therefore fail closed with 501 and NEVER read or trust a posted assertion.
 *
 * The stance is data-driven from config('sso.capabilities.saml'); use OIDC instead.
 */
class SamlController extends Controller
{
    public function metadata(): JsonResponse
    {
        return $this->unsupported();
    }

    /**
     * ACS endpoint. Deliberately ignores any SAMLResponse in the request — no assertion is ever
     * consumed, parsed, or trusted. Returns 501 unconditionally.
     */
    public function acs(): JsonResponse
    {
        return $this->unsupported();
    }

    private function unsupported(): JsonResponse
    {
        $reason = (string) config(
            'sso.capabilities.saml.reason',
            'SAML SSO is not available — no signed-assertion (XML-DSIG) support; use OIDC.',
        );

        return ApiResponse::error('SSO_SAML_UNSUPPORTED', $reason, ['use' => 'oidc'], 501);
    }
}
