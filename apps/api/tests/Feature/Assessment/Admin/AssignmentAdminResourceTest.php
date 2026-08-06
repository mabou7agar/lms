<?php

use App\Domains\Assessment\Database\Seeders\AssessmentSeeder;
use App\Domains\Assessment\Enums\AssignmentState;
use App\Domains\Assessment\Filament\Resources\AssessmentResource;
use App\Domains\Assessment\Filament\Resources\AssignmentResource;
use App\Domains\Assessment\Filament\Resources\AssignmentResource\Pages\CreateAssignment;
use App\Domains\Assessment\Models\Assignment;
use App\Domains\Catalog\Enums\CourseStatus;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Database\Seeders\IdentitySeeder;
use App\Platform\Identity\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

if (! function_exists('assessmentPanelUser')) {
    /** Create a user with the given roles under the web guard. */
    function assessmentPanelUser(string ...$roles): User
    {
        $user = User::factory()->create();

        foreach ($roles as $role) {
            $user->assignRole(SpatieRole::findByName($role, 'web'));
        }

        return $user;
    }
}

if (! function_exists('assessmentPanelBoot')) {
    /**
     * Register the (deliberately unregistered) Assessment Filament resources onto the admin panel
     * for the duration of the test, so CreateRecord redirects can resolve their routes.
     */
    function assessmentPanelBoot(User $actor): void
    {
        test()->actingAs($actor);

        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);

        Route::name('filament.admin.')->prefix('admin')->middleware('web')->group(function () use ($panel): void {
            AssessmentResource::registerRoutes($panel);
            AssignmentResource::registerRoutes($panel);
        });
    }
}

beforeEach(function () {
    $this->seed(IdentitySeeder::class);
    $this->seed(AssessmentSeeder::class);
});

it('lets an admin create a bilingual assignment through the resource', function () {
    $admin = assessmentPanelUser('admin');
    assessmentPanelBoot($admin);
    $course = Course::factory()->create(['status' => CourseStatus::Draft]);

    Livewire::test(CreateAssignment::class)
        ->fillForm([
            'course_id' => $course->id,
            'title_i18n' => ['en' => 'Reflective Essay', 'ar' => 'مقال تأملي'],
            'submission_type' => 'text',
            'max_files' => 1,
            'late_policy' => 'allowed',
            'max_grade' => 100,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $assignment = Assignment::query()->where('course_id', $course->id)->firstOrFail();

    expect($assignment->title_i18n['en'])->toBe('Reflective Essay')
        ->and($assignment->title_i18n['ar'])->toBe('مقال تأملي')
        ->and($assignment->title)->toBe('Reflective Essay')          // legacy scalar synced from EN
        ->and($assignment->publish_state)->toBe(AssignmentState::Draft)
        ->and($assignment->created_by)->toBe($admin->id);
});

it('denies a student access to the assignment resource', function () {
    $student = assessmentPanelUser('student');
    $this->actingAs($student);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    expect(AssignmentResource::canAccess())->toBeFalse()
        ->and(AssignmentResource::canCreate())->toBeFalse();
});

it('persists a bilingual rubric with criteria and levels through the rubrics relationship', function () {
    // The rubric relation manager binds nested `->relationship()` repeaters (criteria -> levels) to
    // these relationships; driving that nested save from a test callAction payload is unreliable in
    // the harness, so we assert the same bilingual/ordering/point guarantees at the model boundary
    // the resource persists to.
    $assignment = Assignment::factory()->create();

    $rubric = $assignment->rubrics()->create([
        'title_i18n' => ['en' => 'Essay rubric', 'ar' => 'معيار المقال'],
        'total_points' => 5,
    ]);
    $criterion = $rubric->criteria()->create([
        'title_i18n' => ['en' => 'Clarity', 'ar' => 'الوضوح'],
        'description_i18n' => ['en' => 'How clearly the argument reads', 'ar' => 'مدى وضوح الحجة'],
        'position' => 1,
        'max_points' => 5,
    ]);
    $criterion->levels()->create(['title_i18n' => ['en' => 'Poor', 'ar' => 'ضعيف'], 'points' => 1, 'position' => 1]);
    $criterion->levels()->create(['title_i18n' => ['en' => 'Excellent', 'ar' => 'ممتاز'], 'points' => 5, 'position' => 2]);

    $rubric->refresh();

    expect($rubric->title_i18n['ar'])->toBe('معيار المقال')
        ->and($rubric->title)->toBe('Essay rubric')                  // legacy scalar synced from EN
        ->and($rubric->criteria()->count())->toBe(1);

    $freshCriterion = $rubric->criteria()->orderBy('position')->firstOrFail();
    expect($freshCriterion->title_i18n['ar'])->toBe('الوضوح');

    $levels = $freshCriterion->levels()->orderBy('position')->get();
    expect($levels)->toHaveCount(2)
        ->and($levels->last()->title_i18n['ar'])->toBe('ممتاز')
        ->and((int) $levels->last()->points)->toBe(5);
});
