<?php

namespace App\Platform\Shared\Enterprise\Data;

/**
 * Immutable learning-report snapshot for an enterprise manager's scope (whole org, a department, or a
 * team). Owned by Shared, PRODUCED by the Learning context (which owns enrollments/progress and reads
 * certificates/assessments through their own Shared ports), CONSUMED by the CRM enterprise portal.
 *
 * Every field is a scalar. Seat utilisation is carried here so a single report object answers the
 * whole manager dashboard, but the seat numbers themselves are supplied by the CRM caller (from the
 * Commerce org-subscription exposure port) — Learning never reaches into Commerce. `null` seats mean
 * the organization has no active subscription.
 */
final readonly class ManagerReport
{
    public function __construct(
        public int $organizationId,
        public int $learners,
        public int $enrollments,
        public int $started,
        public int $completions,
        public float $avgProgress,
        public int $watchTimeSeconds,
        public int $avgWatchTimeSecondsPerLearner,
        public int $inactiveLearners,
        public int $assessmentsPassed,
        public int $assessmentsFailed,
        public int $certificatesIssued,
        public ?int $seatsPurchased = null,
        public ?int $seatsUsed = null,
        public ?int $seatsAvailable = null,
    ) {}

    /**
     * @return array{
     *     organization_id: int,
     *     learners: int,
     *     enrollments: int,
     *     started: int,
     *     completions: int,
     *     avg_progress: float,
     *     watch_time_seconds: int,
     *     avg_watch_time_seconds_per_learner: int,
     *     inactive_learners: int,
     *     assessments_passed: int,
     *     assessments_failed: int,
     *     certificates_issued: int,
     *     seats: array{purchased: int, used: int, available: int}|null
     * }
     */
    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'learners' => $this->learners,
            'enrollments' => $this->enrollments,
            'started' => $this->started,
            'completions' => $this->completions,
            'avg_progress' => $this->avgProgress,
            'watch_time_seconds' => $this->watchTimeSeconds,
            'avg_watch_time_seconds_per_learner' => $this->avgWatchTimeSecondsPerLearner,
            'inactive_learners' => $this->inactiveLearners,
            'assessments_passed' => $this->assessmentsPassed,
            'assessments_failed' => $this->assessmentsFailed,
            'certificates_issued' => $this->certificatesIssued,
            'seats' => $this->seatsPurchased === null ? null : [
                'purchased' => $this->seatsPurchased,
                'used' => (int) $this->seatsUsed,
                'available' => (int) $this->seatsAvailable,
            ],
        ];
    }
}
