<?php

namespace App\Platform\Shared\Publishing\Data;

/**
 * A structured draft-vs-published comparison.
 *
 * TODO: Course Versioning & Publication Snapshots
 * ----------------------------------------------
 * There is currently NO persisted published baseline for a course. Publishing mutates the live
 * record in place; nothing captures what learners saw before the edit. Until a snapshot exists this
 * summary can only ever report `available: false`, and that is the correct answer rather than a
 * limitation to paper over.
 *
 * What must NOT be done in the meantime, and why:
 *   - Comparing the course to ITSELF. Every field would be identical and the panel would report
 *     "no changes", which is not the same statement as "we cannot tell" and is actively misleading
 *     to an author deciding whether to re-publish.
 *   - Inferring changes from `updated_at` vs `published_at`. A timestamp says something changed, not
 *     WHAT changed. Rendering "3 lessons modified" from a timestamp delta would be fabrication.
 *   - Reconstructing a baseline from audit logs. Publishing is not audited today (see the audit
 *     findings), so there is nothing to reconstruct from.
 *
 * When snapshots land, `fromBaseline()` becomes the real constructor and the shape below is already
 * the contract the frontend consumes — so the UI does not change, only the answer does.
 */
final readonly class ChangeSummary
{
    /**
     * @param  array<string, mixed>  $changes  structured, per category; empty when unavailable
     */
    private function __construct(
        public bool $available,
        public ?string $reason = null,
        public array $changes = [],
        public ?string $baselinePublishedAt = null,
    ) {}

    /** No snapshot exists to compare against. The only truthful answer today. */
    public static function noBaseline(): self
    {
        return new self(
            available: false,
            reason: 'No published baseline available.',
        );
    }

    /**
     * A real comparison against a captured baseline.
     *
     * Unused until snapshots exist — present so the shape is fixed now and the eventual change is
     * confined to the producer.
     *
     * @param  array<string, mixed>  $changes
     */
    public static function fromBaseline(array $changes, string $baselinePublishedAt): self
    {
        return new self(
            available: true,
            changes: $changes,
            baselinePublishedAt: $baselinePublishedAt,
        );
    }

    /** The categories a real summary reports. Fixed here so producer and consumer agree. */
    public const CATEGORIES = [
        'metadata_changed',
        'sections_added',
        'sections_removed',
        'lessons_added',
        'lessons_removed',
        'lesson_content_changed',
        'assessment_reference_changed',
        'pricing_changed',
        'access_settings_changed',
    ];

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        if (! $this->available) {
            return ['available' => false, 'reason' => $this->reason];
        }

        return [
            'available' => true,
            'baseline_published_at' => $this->baselinePublishedAt,
            'changes' => $this->changes,
        ];
    }
}
