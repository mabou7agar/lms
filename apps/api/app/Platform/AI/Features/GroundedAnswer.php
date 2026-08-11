<?php

declare(strict_types=1);

namespace App\Platform\AI\Features;

use App\Platform\Shared\Search\Data\RetrievedChunk;

/**
 * The result of a grounded AI feature call (tutor answer / copilot suggestion): the AI-labelled
 * content plus the exact retrieved chunks it was grounded in (its CITATIONS), and the audit handles
 * (request id + prompt key/version) that tie it back to its ai_usages row.
 *
 * A refusal (e.g. a learner asking for an answer key) is also a GroundedAnswer — with `refused=true`,
 * no citations, and NO provider call behind it — so the controller renders one shape for both.
 */
final class GroundedAnswer
{
    /**
     * @param  list<RetrievedChunk>  $citations
     */
    public function __construct(
        public readonly string $content,
        public readonly ?string $label,
        public readonly bool $refused,
        public readonly array $citations = [],
        public readonly ?string $requestId = null,
        public readonly ?string $promptKey = null,
        public readonly ?int $promptVersion = null,
    ) {}

    /** A deterministic refusal that never reached a provider (no cost, no citations). */
    public static function refusal(string $message): self
    {
        return new self(content: $message, label: null, refused: true);
    }

    /**
     * The API payload: the answer, its disclosure label, whether it was refused, and the citations
     * (stable public ids only — never internal ids).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'answer' => $this->content,
            'label' => $this->label,
            'refused' => $this->refused,
            'citations' => array_map(
                static fn (RetrievedChunk $c): array => $c->toCitation(),
                $this->citations,
            ),
            'request_id' => $this->requestId,
            'prompt' => $this->promptKey === null ? null : [
                'key' => $this->promptKey,
                'version' => $this->promptVersion,
            ],
        ];
    }
}
