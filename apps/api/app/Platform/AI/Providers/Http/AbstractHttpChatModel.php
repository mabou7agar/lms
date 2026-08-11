<?php

declare(strict_types=1);

namespace App\Platform\AI\Providers\Http;

use App\Platform\AI\Contracts\ChatModel;
use App\Platform\AI\Data\ChatMessage;
use App\Platform\AI\Data\ChatResult;
use App\Platform\AI\Data\ModelOptions;
use App\Platform\AI\Exceptions\AiException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;

/**
 * Shared scaffolding for real (LOCAL REQUIRED) chat adapters. It maps the provider-neutral call
 * onto the vendor's HTTP API SHAPE using Laravel's Http client, applying the configured timeout and
 * retry. The network is ONLY reached after assertConfigured() passes — with no credentials
 * (the sandbox/CI default) the call throws before any request is made. Concrete adapters supply the
 * endpoint, headers, request body, and response parsing for their vendor.
 */
abstract class AbstractHttpChatModel implements ChatModel
{
    use GuardsCredentials;

    /** @param array<string, mixed> $config */
    public function __construct(
        protected readonly Factory $http,
        protected readonly array $config,
    ) {}

    public function chat(array $messages, ModelOptions $options): ChatResult
    {
        $this->assertConfigured();

        $model = $options->model ?? $this->defaultModel();

        $response = $this->http
            ->timeout($options->timeout)
            ->retry(max(1, $options->retries + 1), 200, null, false)
            ->withHeaders($this->headers())
            ->post($this->endpoint($model), $this->payload($messages, $options, $model));

        if ($response->failed()) {
            throw new AiException(sprintf(
                'AI provider [%s] chat request failed (HTTP %d).',
                $this->provider()->value,
                $response->status(),
            ));
        }

        return $this->parse($response, $model);
    }

    protected function defaultModel(): string
    {
        return $this->stringConfig($this->config, 'chat_model', 'default');
    }

    /** Throw ProviderCredentialsRequiredException when the adapter is not fully configured. */
    abstract protected function assertConfigured(): void;

    abstract protected function endpoint(string $model): string;

    /** @return array<string, string> */
    abstract protected function headers(): array;

    /**
     * @param  list<ChatMessage>  $messages
     * @return array<string, mixed>
     */
    abstract protected function payload(array $messages, ModelOptions $options, string $model): array;

    abstract protected function parse(Response $response, string $model): ChatResult;
}
