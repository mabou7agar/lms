<?php

namespace App\Domains\Assessment\Providers;

use App\Domains\Assessment\Analytics\AssessmentStatsAdapter;
use App\Domains\Assessment\Enums\AssessmentPermission;
use App\Domains\Assessment\Enums\AssignmentPermission;
use App\Domains\Assessment\Grading\AnswerNormalizer;
use App\Domains\Assessment\Grading\GraderRegistry;
use App\Domains\Assessment\Grading\Graders\FillInBlankGrader;
use App\Domains\Assessment\Grading\Graders\MultipleChoiceGrader;
use App\Domains\Assessment\Grading\Graders\ShortAnswerGrader;
use App\Domains\Assessment\Grading\Graders\SingleChoiceGrader;
use App\Domains\Assessment\Grading\Graders\TrueFalseGrader;
use App\Domains\Assessment\Listeners\AssessmentNotificationSubscriber;
use App\Domains\Assessment\Models\Assessment;
use App\Domains\Assessment\Models\Assignment;
use App\Domains\Assessment\Models\AssignmentSubmission;
use App\Domains\Assessment\Policies\AssessmentPolicy;
use App\Domains\Assessment\Policies\AssignmentPolicy;
use App\Domains\Assessment\Policies\SubmissionPolicy;
use App\Domains\Assessment\Support\AssessmentResultAdapter;
use App\Domains\Assessment\Support\AssignmentRequirementAdapter;
use App\Domains\Assessment\Support\LessonAssessmentAdapter;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Identity\Contracts\CourseAccessPort;
use App\Platform\Shared\Assessment\Contracts\AssessmentResultPort;
use App\Platform\Shared\Assessment\Contracts\AssessmentStatsPort;
use App\Platform\Shared\Assessment\Contracts\LessonAssessmentPort;
use App\Platform\Shared\Learning\Contracts\AssignmentRequirementPort;
use App\Platform\Shared\Providers\BaseDomainServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

class AssessmentServiceProvider extends BaseDomainServiceProvider
{
    /** @var list<string> */
    protected array $routeFiles = [
        'routes/assessment_admin.php',
        'routes/assessment_learner.php',
        'routes/assignments.php',
    ];

    /** @var array<class-string, class-string> */
    protected array $policies = [
        Assessment::class => AssessmentPolicy::class,
        Assignment::class => AssignmentPolicy::class,
        AssignmentSubmission::class => SubmissionPolicy::class,
    ];

    protected function domainPath(): string
    {
        return dirname(__DIR__);
    }

    public function register(): void
    {
        // Assessment owns the lesson↔assessment contract; Authoring consumes it without ever
        // importing an Assessment class.
        $this->app->bind(LessonAssessmentPort::class, LessonAssessmentAdapter::class);

        // Reporting is a separate contract from authoring: LessonAssessmentPort is pinned to two
        // methods by an ArchitectureTest, and widening it would turn a narrow authoring surface
        // into a general repository.
        $this->app->bind(AssessmentStatsPort::class, AssessmentStatsAdapter::class);

        // Assessment implements Learning's AssignmentRequirementPort so Learning can gate
        // lesson/course completion on required assignments without importing an Assessment model.
        $this->app->bind(AssignmentRequirementPort::class, AssignmentRequirementAdapter::class);

        // Assessment implements the learner-result port so Learning's course-completion policy engine
        // can gate on passed quizzes / a final exam without importing an Assessment model. Learning's
        // provider binds a completion-safe null via bindIf, so this real binding always wins when the
        // Assessment context is present.
        $this->app->bind(AssessmentResultPort::class, AssessmentResultAdapter::class);

        // The grader registry is the single extension point for question types. Adding a type is:
        // add the enum case, write a grader, register it on the line below. Nothing else changes.
        $this->app->singleton(GraderRegistry::class, function ($app): GraderRegistry {
            $normalizer = $app->make(AnswerNormalizer::class);

            return new GraderRegistry([
                new SingleChoiceGrader,
                new TrueFalseGrader,
                new MultipleChoiceGrader,
                new ShortAnswerGrader($normalizer),
                new FillInBlankGrader($normalizer),
            ]);
        });
    }

    protected function bootDomain(): void
    {
        // Learner notifications for assignment grade-release / changes-requested and quiz pass/fail.
        // The subscriber lives in this domain and reaches Notifications only through the Shared
        // LearningNotificationPort, so no Notifications<->Assessment Deptrac edge is introduced.
        Event::subscribe(AssessmentNotificationSubscriber::class);

        // Single source of truth for "may this actor manage this assessment".
        //
        //   1. super_admin          — genuine role bypass; the seeders grant it no permissions.
        //   2. assessment.manage    — explicit global grant, held by admin and honoured for any
        //                             other principal granted it. Checked with hasPermission()
        //                             rather than can(), which is guard-sensitive under Sanctum.
        //   3. course ownership     — an instructor may manage the assessments of a course they
        //                             train, delegated to CourseAccessPort so this domain never
        //                             touches the Course model.
        //
        // A course-less assessment (a future platform-wide bank) is admin-only by construction:
        // rule 3 cannot apply, so nobody reaches it through ownership.
        //
        // NOTE the gate name must NOT equal the permission name. `$user->can('x')` consults gates
        // before Spatie permissions, so a gate called `assessment.manage` checking the permission
        // `assessment.manage` re-enters itself without the model argument and fatals. Authoring
        // avoids this the same way: gate `authoring.manage-curriculum`, permission
        // `authoring.curriculum.manage`.
        Gate::define('assessment.manage-assessment', function (Actor $user, Assessment $assessment): bool {
            if ($user->hasRole('super_admin') || $user->hasPermission(AssessmentPermission::Manage->value)) {
                return true;
            }

            $courseId = $assessment->course_id;

            return $courseId !== null
                && app(CourseAccessPort::class)->canManageContent($user, (int) $courseId);
        });

        // Mirror of assessment.manage-assessment for the assignment surface: super_admin, the global
        // assignment.manage permission (admin), or course ownership via CourseAccessPort. The gate
        // name deliberately differs from the `assignment.manage` permission to avoid the same
        // self-re-entrancy fatal documented above. course_id is non-null on an Assignment.
        Gate::define('assignment.manage-assignment', function (Actor $user, Assignment $assignment): bool {
            return $user->hasRole('super_admin')
                || $user->hasPermission(AssignmentPermission::Manage->value)
                || app(CourseAccessPort::class)->canManageContent($user, (int) $assignment->course_id);
        });
    }
}
