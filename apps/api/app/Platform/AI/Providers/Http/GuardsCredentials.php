<?php

declare(strict_types=1);

namespace App\Platform\AI\Providers\Http;

use App\Platform\AI\Enums\AiProvider;
use App\Platform\AI\Exceptions\ProviderCredentialsRequiredException;

/**
 * Shared credential-guard for real provider adapters. Reading a required config value that is
 * absent throws ProviderCredentialsRequiredException — the explicit LOCAL-REQUIRED boundary that
 * keeps a live provider from ever being mistaken for "working" without real credentials. Secret
 * values are never included in the message; only the env var name is named.
 */
trait GuardsCredentials
{
    /** @param array<string, mixed> $config */
    protected function requireString(array $config, string $key, AiProvider $provider, string $envVar): string
    {
        $value = $config[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new ProviderCredentialsRequiredException($provider->value, $envVar);
        }

        return $value;
    }

    /** @param array<string, mixed> $config */
    protected function stringConfig(array $config, string $key, string $default = ''): string
    {
        $value = $config[$key] ?? null;

        return is_string($value) ? $value : $default;
    }
}
