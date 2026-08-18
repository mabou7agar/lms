<?php

declare(strict_types=1);

namespace App\Platform\AI\Providers\Fake;

use App\Platform\AI\Contracts\ChatModel;
use App\Platform\AI\Data\ChatResult;
use App\Platform\AI\Data\ModelOptions;
use App\Platform\AI\Data\TokenUsage;
use App\Platform\AI\Enums\AiProvider;
use App\Platform\AI\Enums\ChatRole;
use App\Platform\AI\Support\TokenEstimator;

/**
 * Deterministic, network-free chat model — the local/testing seam that lets every AI feature and
 * every test run without credentials (mirrors FakeSocialProvider / FakeIngestionProvider).
 *
 * The reply is a templated echo of the last user message, so assertions are stable and features
 * can be exercised end-to-end. Token usage is estimated from the prompt so metering/quotas see a
 * realistic, reproducible number.
 */
final class FakeChatModel implements ChatModel
{
    public function __construct(
        private readonly TokenEstimator $estimator,
        private readonly string $model = 'fake-chat-v1',
    ) {}

    public function chat(array $messages, ModelOptions $options): ChatResult
    {
        $lastUser = '';
        $system = '';

        foreach ($messages as $message) {
            if ($message->role === ChatRole::User) {
                $lastUser = $message->content;
            } elseif ($message->role === ChatRole::System) {
                $system = $message->content;
            }
        }

        $content = $this->compose($system, $lastUser);

        $inputTokens = $this->estimator->estimateMessages($messages);
        $outputTokens = min($options->maxTokens, $this->estimator->estimate($content));

        return new ChatResult(
            content: $content,
            usage: new TokenUsage($inputTokens, $outputTokens),
            provider: AiProvider::Fake->value,
            model: $options->model ?? $this->model,
            finishReason: 'stop',
        );
    }

    public function provider(): AiProvider
    {
        return AiProvider::Fake;
    }

    private function compose(string $system, string $user): string
    {
        $prefix = trim($system) !== '' ? '[fake:'.mb_substr(trim($system), 0, 24).'] ' : '[fake] ';

        return $prefix.'You said: '.trim($user);
    }
}
