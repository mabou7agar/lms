<?php

declare(strict_types=1);

namespace App\Platform\AI\Copilot;

use App\Platform\AI\AiClient;
use App\Platform\AI\Enums\AiFeature;
use App\Platform\AI\Features\GroundedAnswer;
use App\Platform\AI\Governance\AiGovernance;
use App\Platform\AI\Governance\GradingPolicy;
use App\Platform\Shared\Search\Contracts\KnowledgeRetrievalPort;
use App\Platform\Shared\Search\Data\RetrievedChunk;
use App\Platform\Shared\Search\Enums\SearchSourceType;
use App\Platform\Shared\Search\Enums\SearchVisibility;
use App\Platform\Shared\Tenancy\TenantContext;

/**
 * The INSTRUCTOR AI COPILOT. Produces SUGGESTIONS ONLY — draft/improve lesson copy, summarize the
 * course's learner questions, suggest what to teach next — grounded in the instructor's OWN course.
 *
 * The caller (controller) is responsible for authorization — the instructor MUST own/teach the course
 * (resolved via the Identity CourseAccessPort); this service assumes an already-authorized internal
 * course id. It is deliberately incapable of mutating anything: it only READS grounding through the
 * Shared {@see KnowledgeRetrievalPort} and calls {@see AiClient} for a labelled completion. It never
 * writes to a student/grade/enrollment record and never auto-grades ({@see GradingPolicy}). Governance,
 * quota and usage metering are enforced by the same guarded pipeline the tutor uses.
 */
final class CopilotService
{
    public function __construct(
        private readonly KnowledgeRetrievalPort $retrieval,
        private readonly AiClient $ai,
        private readonly AiGovernance $governance,
        private readonly GradingPolicy $gradingPolicy,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  string  $brief  the instructor's untrusted instruction/context for the suggestion
     * @param  int  $courseId  internal id of a course the instructor owns (authorized by caller)
     */
    public function assist(
        CopilotMode $mode,
        string $brief,
        int $courseId,
        int $userId,
        ?string $locale = null,
    ): GroundedAnswer {
        $organizationId = $this->organizationId();

        $this->governance->assertEnabled(AiFeature::Copilot, $organizationId, $courseId);
        // Suggestions only — the copilot is never a grading authority.
        $this->gradingPolicy->assertNotFinalGrading(false);

        $limit = (int) config('ai.retrieval.copilot_snippets', 6);

        // Grounding confined to the instructor's own course. The instructor may see authenticated
        // knowledge (lesson text + Q&A) as well as the public course prose.
        $citations = $this->retrieval->retrieve(
            query: $brief !== '' ? $brief : $mode->directive(),
            organizationId: $organizationId,
            visibilities: [SearchVisibility::Public->value, SearchVisibility::Authenticated->value],
            sourceTypes: SearchSourceType::values(),
            courseId: $courseId,
            limit: $limit,
        );

        $result = $this->ai->chat(
            feature: AiFeature::Copilot,
            promptKey: 'copilot.assist',
            variables: [
                'task' => $mode->directive(),
                'brief' => $brief,
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
