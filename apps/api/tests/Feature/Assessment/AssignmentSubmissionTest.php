<?php

use App\Domains\Assessment\Enums\LatePolicy;
use App\Domains\Assessment\Enums\SubmissionStatus;
use App\Domains\Assessment\Events\AssignmentSubmitted;
use App\Domains\Assessment\Exceptions\SubmissionNotAllowedException;
use App\Domains\Assessment\Models\Assignment;
use App\Domains\Assessment\Models\AssignmentSubmission;
use App\Domains\Assessment\Services\SubmissionService;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Learning\Contracts\CourseEnrollmentPort;
use App\Platform\Shared\Media\Contracts\MediaReferencePort;
use App\Platform\Shared\Media\Data\MediaReference;
use App\Platform\Shared\Media\Enums\MediaStatus;
use App\Platform\Shared\Media\Enums\MediaType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/** In-memory media port: owner map decides assertUsableBy; reference returns a pdf filename. */
function asgFakeMedia(array $owners = []): MediaReferencePort
{
    return new class($owners) implements MediaReferencePort
    {
        public function __construct(private array $owners) {}

        public function reference(string $id): ?MediaReference
        {
            return new MediaReference($id, MediaType::Document, MediaStatus::Ready, $this->owners[$id] ?? 0, 'essay.pdf', 2048);
        }

        public function assertUsableBy(string $id, int $actorId): void
        {
            $owner = $this->owners[$id] ?? $actorId;
            if ($owner !== $actorId) {
                throw new SubmissionNotAllowedException('That file is not yours.');
            }
        }
    };
}

function asgFakeEnrollment(bool $enrolled = true): CourseEnrollmentPort
{
    return new class($enrolled) implements CourseEnrollmentPort
    {
        public function __construct(private bool $enrolled) {}

        public function isEnrolled(int $courseId, int $userId): bool
        {
            return $this->enrolled;
        }

        public function enrolledLearnerIds(int $courseId): array
        {
            return [];
        }
    };
}

function asgSubmissionService(?MediaReferencePort $media = null, ?CourseEnrollmentPort $enrollment = null): SubmissionService
{
    return new SubmissionService($media ?? asgFakeMedia(), $enrollment ?? asgFakeEnrollment());
}

it('saves a single draft and refuses to fork a second', function () {
    $learner = User::factory()->create();
    $assignment = Assignment::factory()->published()->textType()->create();

    $svc = asgSubmissionService();
    $a = $svc->saveDraft($assignment, $learner->id, ['text_response' => 'first']);
    $b = $svc->saveDraft($assignment, $learner->id, ['text_response' => 'second']);

    expect($a->id)->toBe($b->id)
        ->and($b->fresh()->text_response)->toBe('second')
        ->and(AssignmentSubmission::where('assignment_id', $assignment->id)->where('status', 'draft')->count())->toBe(1);
});

it('submits a text response and emits AssignmentSubmitted', function () {
    Event::fake([AssignmentSubmitted::class]);
    $learner = User::factory()->create();
    $assignment = Assignment::factory()->published()->textType()->create();

    $svc = asgSubmissionService();
    $svc->saveDraft($assignment, $learner->id, ['text_response' => 'my answer']);
    $submission = $svc->submit($assignment, $learner->id);

    expect($submission->status)->toBe(SubmissionStatus::Submitted)
        ->and($submission->submitted_at)->not->toBeNull();
    Event::assertDispatched(AssignmentSubmitted::class);
});

it('attaches an owned file and submits a file assignment', function () {
    $learner = User::factory()->create();
    $assignment = Assignment::factory()->published()->create(['submission_type' => 'file', 'max_files' => 2]);

    $media = asgFakeMedia(['file-1' => $learner->id]);
    $svc = asgSubmissionService($media);
    $draft = $svc->saveDraft($assignment, $learner->id, []);
    $svc->attachFile($draft, 'file-1', $learner->id);

    $submission = $svc->submit($assignment, $learner->id);
    expect($submission->files()->count())->toBe(1)
        ->and($submission->status)->toBe(SubmissionStatus::Submitted);
});

it("rejects attaching another learner's file", function () {
    $learner = User::factory()->create();
    $other = User::factory()->create();
    $assignment = Assignment::factory()->published()->create(['submission_type' => 'file']);

    $media = asgFakeMedia(['foreign' => $other->id]);
    $svc = asgSubmissionService($media);
    $draft = $svc->saveDraft($assignment, $learner->id, []);

    expect(fn () => $svc->attachFile($draft, 'foreign', $learner->id))
        ->toThrow(SubmissionNotAllowedException::class);
});

it('blocks a late submission under a blocking due date', function () {
    $learner = User::factory()->create();
    $assignment = Assignment::factory()->published()->textType()
        ->create(['due_at' => now()->subDay(), 'late_policy' => LatePolicy::Blocked->value]);

    $svc = asgSubmissionService();
    $svc->saveDraft($assignment, $learner->id, ['text_response' => 'late work']);

    expect(fn () => $svc->submit($assignment, $learner->id))->toThrow(SubmissionNotAllowedException::class);
});

it('flags a late submission when the policy allows it', function () {
    $learner = User::factory()->create();
    $assignment = Assignment::factory()->published()->textType()
        ->create(['due_at' => now()->subDay(), 'late_policy' => LatePolicy::Allowed->value]);

    $svc = asgSubmissionService();
    $svc->saveDraft($assignment, $learner->id, ['text_response' => 'late work']);
    $submission = $svc->submit($assignment, $learner->id);

    expect($submission->is_late)->toBeTrue()
        ->and($submission->status)->toBe(SubmissionStatus::Late);
});

it('enforces the attempt limit', function () {
    $learner = User::factory()->create();
    $assignment = Assignment::factory()->published()->textType()->create(['attempt_limit' => 1]);

    $svc = asgSubmissionService();
    $svc->saveDraft($assignment, $learner->id, ['text_response' => 'one']);
    $svc->submit($assignment, $learner->id);

    // Latest is submitted/under review: a new draft is refused.
    expect(fn () => $svc->saveDraft($assignment, $learner->id, ['text_response' => 'two']))
        ->toThrow(SubmissionNotAllowedException::class);
});

it('refuses a submit when the learner is not enrolled', function () {
    $learner = User::factory()->create();
    $assignment = Assignment::factory()->published()->textType()->create();

    $svc = asgSubmissionService(null, asgFakeEnrollment(enrolled: false));
    $svc->saveDraft($assignment, $learner->id, ['text_response' => 'x']);

    expect(fn () => $svc->submit($assignment, $learner->id))->toThrow(SubmissionNotAllowedException::class);
});

it('keeps a submitted attempt immutable (no draft edits)', function () {
    $learner = User::factory()->create();
    $assignment = Assignment::factory()->published()->create(['submission_type' => 'file']);

    $svc = asgSubmissionService(asgFakeMedia(['f' => $learner->id]));
    $draft = $svc->saveDraft($assignment, $learner->id, []);
    $svc->attachFile($draft, 'f', $learner->id);
    $svc->submit($assignment, $learner->id);

    expect($draft->fresh()->isEditable())->toBeFalse()
        ->and(fn () => $svc->attachFile($draft->fresh(), 'f', $learner->id))
        ->toThrow(SubmissionNotAllowedException::class);
});
