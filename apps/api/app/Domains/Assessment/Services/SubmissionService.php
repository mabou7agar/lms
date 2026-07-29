<?php

namespace App\Domains\Assessment\Services;

use App\Domains\Assessment\Enums\LatePolicy;
use App\Domains\Assessment\Enums\SubmissionStatus;
use App\Domains\Assessment\Events\AssignmentResubmitted;
use App\Domains\Assessment\Events\AssignmentSubmitted;
use App\Domains\Assessment\Exceptions\SubmissionNotAllowedException;
use App\Domains\Assessment\Models\Assignment;
use App\Domains\Assessment\Models\AssignmentSubmission;
use App\Domains\Assessment\Models\SubmissionFile;
use App\Domains\Assessment\Support\RubricSnapshot;
use App\Platform\Shared\Learning\Contracts\CourseEnrollmentPort;
use App\Platform\Shared\Media\Contracts\MediaReferencePort;
use Illuminate\Support\Facades\DB;

/**
 * The learner-side write surface. Every state transition is transactional and row-locked so
 * concurrent requests (double-tap submit, two tabs saving a draft) cannot fork attempts or bypass
 * the attempt limit. A SUBMITTED attempt is immutable — content edits are only accepted on a draft.
 */
class SubmissionService
{
    public function __construct(
        private readonly MediaReferencePort $media,
        private readonly CourseEnrollmentPort $enrollment,
    ) {}

    /**
     * Create-or-resume the learner's open draft and store its text/url content.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveDraft(Assignment $assignment, int $userId, array $data): AssignmentSubmission
    {
        return DB::transaction(function () use ($assignment, $userId, $data): AssignmentSubmission {
            $draft = $this->openDraft($assignment, $userId, requireResubmittable: false);

            $draft->forceFill([
                'text_response' => array_key_exists('text_response', $data)
                    ? $data['text_response'] : $draft->text_response,
                'external_url' => array_key_exists('external_url', $data)
                    ? $data['external_url'] : $draft->external_url,
            ])->save();

            return $draft->refresh();
        });
    }

    /**
     * Explicitly open a fresh attempt after changes were requested / the work was returned. Unlike
     * saveDraft this refuses to start a first attempt — it is only a re-do of a reviewed one.
     */
    public function resubmitDraft(Assignment $assignment, int $userId): AssignmentSubmission
    {
        return DB::transaction(
            fn (): AssignmentSubmission => $this->openDraft($assignment, $userId, requireResubmittable: true)
        );
    }

    /**
     * Attach an uploaded media file to a DRAFT submission. Ownership/tenant is enforced by the Media
     * port: another learner's or another tenant's asset is rejected before any row is written.
     */
    public function attachFile(AssignmentSubmission $submission, string $mediaPublicId, int $actorId): SubmissionFile
    {
        if (! $submission->isEditable()) {
            throw new SubmissionNotAllowedException('Files may only be changed on a draft submission.');
        }

        // Throws if the actor does not own the asset or it is a different tenant's / not ready.
        $this->media->assertUsableBy($mediaPublicId, $actorId);

        $assignment = $submission->assignment;
        $max = $assignment->max_files ?? 1;

        return DB::transaction(function () use ($submission, $mediaPublicId, $assignment, $max): SubmissionFile {
            // Serialize concurrent attach calls by locking the PARENT submission row; Postgres
            // rejects FOR UPDATE combined with an aggregate, so the file count itself runs unlocked.
            AssignmentSubmission::query()->whereKey($submission->id)->lockForUpdate()->first();

            $current = SubmissionFile::query()
                ->where('submission_id', $submission->id)
                ->count();

            if ($current >= $max) {
                throw new SubmissionNotAllowedException("This assignment accepts at most {$max} file(s).");
            }

            $reference = $this->media->reference($mediaPublicId);
            $filename = $reference?->originalFilename;

            $this->assertFileTypeAllowed($assignment, $filename);

            $file = new SubmissionFile;
            $file->forceFill([
                'submission_id' => $submission->id,
                'media_public_id' => $mediaPublicId,
                'original_filename' => $filename,
            ]);
            $file->save();

            return $file;
        });
    }

    public function detachFile(AssignmentSubmission $submission, SubmissionFile $file): void
    {
        if (! $submission->isEditable()) {
            throw new SubmissionNotAllowedException('Files may only be changed on a draft submission.');
        }

        if ((int) $file->submission_id !== (int) $submission->id) {
            throw new SubmissionNotAllowedException('That file does not belong to this submission.');
        }

        $file->delete();
    }

    /**
     * Finalize the learner's draft: validate enrollment, publication, availability, due/late policy,
     * attempt limit and required content shape; snapshot the rubric immutably; move to Submitted
     * (or Late). After this the attempt cannot be edited — a redo requires a new attempt.
     */
    public function submit(Assignment $assignment, int $userId): AssignmentSubmission
    {
        if (! $assignment->isPublished()) {
            throw new SubmissionNotAllowedException('This assignment is not open for submission.');
        }

        if (! $this->enrollment->isEnrolled((int) $assignment->course_id, $userId)) {
            throw new SubmissionNotAllowedException('You are not enrolled in this course.');
        }

        return DB::transaction(function () use ($assignment, $userId): AssignmentSubmission {
            $draft = AssignmentSubmission::query()
                ->where('assignment_id', $assignment->id)
                ->where('user_id', $userId)
                ->where('status', SubmissionStatus::Draft->value)
                ->lockForUpdate()
                ->first();

            if ($draft === null) {
                throw new SubmissionNotAllowedException('There is no draft to submit.');
            }

            $isLate = $assignment->isPastDue();

            if ($isLate && $assignment->late_policy === LatePolicy::Blocked) {
                throw new SubmissionNotAllowedException('The due date has passed; submissions are closed.');
            }

            $this->assertContentShape($assignment, $draft);

            $draft->forceFill([
                'status' => $isLate ? SubmissionStatus::Late->value : SubmissionStatus::Submitted->value,
                'submitted_at' => now(),
                'is_late' => $isLate,
                'rubric_snapshot' => RubricSnapshot::forRubric($assignment->rubric()),
            ])->save();

            $payload = [
                (int) $draft->id, (int) $assignment->id, $userId, (int) $draft->attempt_no, $isLate,
            ];

            if ((int) $draft->attempt_no > 1) {
                AssignmentResubmitted::dispatch(...$payload);
            } else {
                AssignmentSubmitted::dispatch(...$payload);
            }

            return $draft->refresh();
        });
    }

    /**
     * Row-locked create-or-resume of a learner's single open draft, enforcing the attempt limit.
     */
    private function openDraft(Assignment $assignment, int $userId, bool $requireResubmittable): AssignmentSubmission
    {
        $existing = AssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->orderByDesc('attempt_no')
            ->get();

        $openDraft = $existing->firstWhere(fn (AssignmentSubmission $s) => $s->status->isDraft());

        if ($openDraft !== null) {
            return $openDraft;
        }

        $latest = $existing->first();

        // A pending (submitted / under review) attempt blocks starting another.
        if ($latest !== null && $latest->status->isSubmitted()) {
            throw new SubmissionNotAllowedException('You already have a submission awaiting review.');
        }

        if ($requireResubmittable) {
            if ($latest === null || ! $latest->status->allowsResubmission()) {
                throw new SubmissionNotAllowedException('There is nothing to resubmit.');
            }
        } elseif ($latest !== null && $latest->status->isTerminal() && ! $latest->status->allowsResubmission()) {
            // A graded/cancelled attempt is final; no new draft unless changes were requested.
            throw new SubmissionNotAllowedException('This assignment has already been completed.');
        }

        if ($assignment->attempt_limit !== null && $existing->count() >= $assignment->attempt_limit) {
            throw new SubmissionNotAllowedException('You have used all available attempts.');
        }

        $draft = new AssignmentSubmission;
        $draft->forceFill([
            'assignment_id' => $assignment->id,
            'user_id' => $userId,
            'attempt_no' => ((int) $existing->max('attempt_no')) + 1,
            'status' => SubmissionStatus::Draft->value,
            'is_late' => false,
        ]);
        $draft->save();

        return $draft;
    }

    private function assertContentShape(Assignment $assignment, AssignmentSubmission $draft): void
    {
        $type = $assignment->submission_type;

        if ($type->requiresText() && trim((string) $draft->text_response) === '') {
            throw new SubmissionNotAllowedException('A written response is required.');
        }

        if ($type->requiresFile() && $draft->files()->count() === 0) {
            throw new SubmissionNotAllowedException('At least one file is required.');
        }

        if ($type->requiresUrl() && trim((string) $draft->external_url) === '') {
            throw new SubmissionNotAllowedException('A submission URL is required.');
        }
    }

    private function assertFileTypeAllowed(?Assignment $assignment, ?string $filename): void
    {
        $allowed = $assignment?->allowed_file_types;

        if (! is_array($allowed) || $allowed === [] || $filename === null) {
            return;
        }

        $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        $normalised = array_map(fn ($t) => strtolower(ltrim((string) $t, '.')), $allowed);

        if ($ext === '' || ! in_array($ext, $normalised, true)) {
            throw new SubmissionNotAllowedException('That file type is not accepted for this assignment.');
        }
    }
}
