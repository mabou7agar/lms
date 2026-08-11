<?php

declare(strict_types=1);

namespace App\Platform\AI\Tutor;

use App\Platform\AI\AiClient;
use App\Platform\AI\Enums\AiFeature;
use App\Platform\AI\Features\GroundedAnswer;
use App\Platform\AI\Governance\AiGovernance;
use App\Platform\AI\Governance\AssessmentAnswerGuard;
use App\Platform\AI\Governance\GradingPolicy;
use App\Platform\Shared\Search\Contracts\KnowledgeRetrievalPort;
use App\Platform\Shared\Search\Data\RetrievedChunk;
use App\Platform\Shared\Search\Enums\SearchSourceType;
use App\Platform\Shared\Search\Enums\SearchVisibility;
use App\Platform\Shared\Tenancy\TenantContext;

/**
 * The STUDENT AI TUTOR. Answers a learner's question about ONE course, grounded ONLY in that course's
 * indexed content (lesson text + accepted Q&A + the course's own prose), and returns the retrieved
 * chunks as citations.
 *
 * The caller (controller) is responsible for authorization — the learner MUST be enrolled in the
 * course; this service assumes an already-resolved, already-authorized internal course id. Everything
 * downstream fails closed:
 *   - governance is asserted up-front (feature/tenant/course kill-switch) before any work;
 *   - a request soliciting assessment answers / an answer key is REFUSED deterministically, with no
 *     retrieval and no provider call (never leaks a key, costs nothing) — see {@see GradingPolicy};
 *   - grounding is retrieved ONLY through the Shared {@see KnowledgeRetrievalPort}, confined to this
 *     course + public/authenticated visibility, so unpublished, private, other-course or other-tenant
 *     content can never reach the model or the citations;
 *   - the actual completion, quota and usage metering run inside {@see AiClient}.
 */
final class TutorService
{
    public function __construct(
        private readonly KnowledgeRetrievalPort $retrieval,
        private readonly AiClient $ai,
        private readonly AiGovernance $governance,
        private readonly AssessmentAnswerGuard $answerGuard,
        private readonly GradingPolicy $gradingPolicy,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  string  $question  the learner's untrusted question
     * @param  int  $courseId  internal id of a course the learner is enrolled in (authorized by caller)
     */
    public function answer(string $question, int $courseId, int $userId, ?string $locale = null): GroundedAnswer
    {
        $organizationId = $this->organizationId();

        // Kill-switch gate first (feature/tenant/course). Throws AiFeatureDisabledException — mapped to
        // a clear disabled response by the controller. AiClient re-asserts this too (defence in depth).
        $this->governance->assertEnabled(AiFeature::Tutor, $organizationId, $courseId);

        // Guardrail: the tutor is an explainer, never an answer key. Refuse before any retrieval/provider
        // work. The tutor also never grades — assert the policy structurally.
        $this->gradingPolicy->assertNotFinalGrading(false);
        if ($this->answerGuard->solicitsAnswers($question)) {
            return GroundedAnswer::refusal(
                'I can help you understand the material, but I can\'t provide quiz or exam answers or '
                .'an answer key. Ask me to explain a concept from this course and I\'ll walk you through it.'
            );
        }

        $limit = (int) config('ai.retrieval.tutor_snippets', 5);

        // Grounding confined to THIS course + learner-visible audiences (course prose is public,
        // lesson text + accepted Q&A are authenticated). Course scoping keeps every other course,
        // tenant and unpublished record out of the context and the citations.
        $citations = $this->retrieval->retrieve(
            query: $question,
            organizationId: $organizationId,
            visibilities: [SearchVisibility::Public->value, SearchVisibility::Authenticated->value],
            sourceTypes: SearchSourceType::values(),
            courseId: $courseId,
            limit: $limit,
        );

        $result = $this->ai->chat(
            feature: AiFeature::Tutor,
            promptKey: 'tutor.answer',
            variables: [
                'question' => $question,
                'context' => $this->composeContext($citations),
            ],
            userId: $userId,
            courseId: $courseId,
            locale: $locale,
        );

        return new GroundedAnswer(
            content: $result->content,
            label: $result->label,
            refused: false,
            citations: $citations,
            requestId: $result->requestId,
            promptKey: $result->promptKey,
            promptVersion: $result->promptVersion,
        );
    }

    /**
     * Fold the retrieved chunks into a numbered context block for the prompt. Empty when nothing was
     * retrieved — the prompt instructs the model to say it doesn't have that in the course material.
     *
     * @param  list<RetrievedChunk>  $chunks
     */
    private function composeContext(array $chunks): string
    {
        if ($chunks === []) {
            return '';
        }

        $lines = [];
        foreach ($chunks as $i => $chunk) {
            $title = $chunk->title !== null && $chunk->title !== '' ? $chunk->title.': ' : '';
            $lines[] = '['.($i + 1).'] '.$title.$chunk->snippet;
        }

        return implode("\n", $lines);
    }

    private function organizationId(): ?int
    {
        $id = $this->tenant->id();
        if ($id === null) {
            return null;
        }

        return is_numeric($id->value) ? (int) $id->value : null;
    }
}
