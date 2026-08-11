<?php

declare(strict_types=1);

namespace App\Platform\AI\Exceptions;

/**
 * A real provider adapter was asked to make a call but its credentials/base URL are not configured.
 *
 * This is the explicit LOCAL-REQUIRED boundary: live providers only reach the network when their
 * secrets are present in the environment. Unconfigured, they throw THIS rather than silently
 * succeeding — so a live provider is never mistaken for "working" without real credentials. The
 * message names the env var, never a secret value.
 */
final class ProviderCredentialsRequiredException extends AiException
{
    public function __construct(string $provider, string $envVar)
    {
        parent::__construct(
            "AI provider [{$provider}] requires credentials that are not configured (set {$envVar}). "
            .'Live providers are LOCAL REQUIRED and never call the network without real credentials.'
        );
    }
}
