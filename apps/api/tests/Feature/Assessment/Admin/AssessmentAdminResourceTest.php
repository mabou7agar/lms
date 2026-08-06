<?php

use App\Domains\Assessment\Database\Seeders\AssessmentSeeder;
use App\Domains\Assessment\Enums\AssessmentStatus;
use App\Domains\Assessment\Enums\QuestionType;
use App\Domains\Assessment\Filament\Resources\AssessmentResource;
use App\Domains\Assessment\Filament\Resources\AssessmentResource\Pages\CreateAssessment;
use App\Domains\Assessment\Filament\Resources\AssessmentResource\Pages\EditAssessment;
use App\Domains\Assessment\Filament\Resources\AssessmentResource\RelationManagers\QuestionsRelationManager;
use App\Domains\Assessment\Filament\Resources\AssignmentResource;
use App\Domains\Assessment\Models\Assessment;
use App\Domains\Catalog\Enums\CourseStatus;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Database\Seeders\IdentitySeeder;
use App\Platform\Identity\Models\User;
use Filament\Actions\Testing\TestAction;
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
     * for the duration of the test, so CreateRecord redirects can resolve their routes. Production
     * registration is the integration owner's job in AdminPanelProvider.
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

it('lets an admin create a bilingual assessment through the resource', function () {
    $admin = assessmentPanelUser('admin');
    assessmentPanelBoot($admin);
    $course = Course::factory()->create(['status' => CourseStatus::Draft]);

    Livewire::test(CreateAssessment::class)
        ->fillForm([
            'course_id' => $course->id,
            'scope' => 'lesson',
            'title_i18n' => ['en' => 'Module One Quiz', 'ar' => 'اختبار الوحدة الأولى'],
            'description_i18n' => ['en' => 'Covers the basics.', 'ar' => 'يغطي الأساسيات.'],
            'passing_score' => 60,
            'feedback_mode' => 'after_submit',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $assessment = Assessment::query()->where('course_id', $course->id)->firstOrFail();

    expect($assessment->title_i18n['en'])->toBe('Module One Quiz')
        ->and($assessment->title_i18n['ar'])->toBe('اختبار الوحدة الأولى')
        ->and($assessment->title)->toBe('Module One Quiz')          // legacy scalar synced from EN
        ->and($assessment->description_i18n['ar'])->toBe('يغطي الأساسيات.')
        ->and($assessment->status)->toBe(AssessmentStatus::Draft)   // never born published
        ->and($assessment->created_by)->toBe($admin->id);
});

it('denies a student access to the assessment resource', function () {
    $student = assessmentPanelUser('student');
    $this->actingAs($student);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    expect(AssessmentResource::canAccess())->toBeFalse()
        ->and(AssessmentResource::canViewAny())->toBeFalse()
        ->and(AssessmentResource::canCreate())->toBeFalse();
});

it('enforces instructor course ownership for editing through the resource policy', function () {
    $owner = assessmentPanelUser('instructor');
    $intruder = assessmentPanelUser('instructor');
    $admin = assessmentPanelUser('admin');

    $ownedCourse = Course::factory()->create(['status' => CourseStatus::Draft]);
    $ownedCourse->syncTrainers([$owner->id]);
    $assessment = Assessment::factory()->create(['course_id' => $ownedCourse->id]);

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->actingAs($owner);
    expect(AssessmentResource::canEdit($assessment))->toBeTrue();

    $this->actingAs($intruder);
    expect(AssessmentResource::canEdit($assessment))->toBeFalse();

    $this->actingAs($admin);
    expect(AssessmentResource::canEdit($assessment))->toBeTrue();
});

it('rejects an out-of-range passing score at the form level', function () {
    $admin = assessmentPanelUser('admin');
    assessmentPanelBoot($admin);
    $assessment = Assessment::factory()->create();

    Livewire::test(EditAssessment::class, ['record' => $assessment->public_id])
        ->fillForm(['passing_score' => 150])
        ->call('save')
        ->assertHasFormErrors(['passing_score']);
});

it('persists a bilingual question with a correct option through the questions relationship', function () {
    // The relation manager binds its form directly to this relationship (persistence, the per-locale
    // sanitizer and the legacy-scalar sync all run on the model). Driving Filament's nested
    // `->relationship()` options repeater from a test `callAction` payload is unreliable in the test
    // harness, so we assert the same data guarantees the resource relies on at the model boundary.
    $assessment = Assessment::factory()->create();

    $question = $assessment->questions()->create([
        'type' => QuestionType::SingleChoice,
        'prompt_i18n' => ['en' => 'Capital of France?', 'ar' => 'ما هي عاصمة فرنسا؟'],
        'points' => 2,
        'position' => 1,
    ]);
    $question->options()->create(['label_i18n' => ['en' => 'Paris', 'ar' => 'باريس'], 'is_correct' => true, 'position' => 1]);
    $question->options()->create(['label_i18n' => ['en' => 'Berlin'], 'is_correct' => false, 'position' => 2]);

    $question->refresh();

    expect($question->type)->toBe(QuestionType::SingleChoice)
        ->and($question->prompt_i18n['ar'])->toContain('عاصمة')
        ->and($question->prompt)->toContain('Capital of France?')   // legacy scalar synced from EN
        ->and($question->options()->count())->toBe(2)
        ->and($question->correctOptions()->count())->toBe(1)
        ->and($question->options()->orderBy('position')->first()->label_i18n['ar'])->toBe('باريس');
});

it('rejects a single-choice question that has two correct options', function () {
    $admin = assessmentPanelUser('admin');
    assessmentPanelBoot($admin);
    $assessment = Assessment::factory()->create();

    Livewire::test(QuestionsRelationManager::class, [
        'ownerRecord' => $assessment,
        'pageClass' => EditAssessment::class,
    ])->callAction(TestAction::make('create')->table(), data: [
        'type' => QuestionType::SingleChoice->value,
        'prompt_i18n' => ['en' => 'Pick one'],
        'points' => 1,
        'options' => [
            ['label_i18n' => ['en' => 'A'], 'is_correct' => true, 'group_index' => 0],
            ['label_i18n' => ['en' => 'B'], 'is_correct' => true, 'group_index' => 0],
        ],
    ])->assertHasActionErrors();

    expect($assessment->questions()->count())->toBe(0);
});

it('preserves question and option order by position', function () {
    $assessment = Assessment::factory()->create();

    foreach (['First' => 1, 'Second' => 2] as $label => $pos) {
        $question = $assessment->questions()->create([
            'type' => QuestionType::SingleChoice,
            'prompt_i18n' => ['en' => $label],
            'points' => 1,
            'position' => $pos,
        ]);
        $question->options()->create(['label_i18n' => ['en' => 'Alpha'], 'is_correct' => true, 'position' => 1]);
        $question->options()->create(['label_i18n' => ['en' => 'Bravo'], 'is_correct' => false, 'position' => 2]);
    }

    $positions = $assessment->questions()->orderBy('position')->pluck('position')->all();
    expect($positions)->toHaveCount(2)
        ->and($positions[0])->toBeLessThan($positions[1]);

    $options = $assessment->questions()->orderBy('position')->first()->options()->orderBy('position')->get();
    expect($options->pluck('position')->all())->toBe([1, 2])
        ->and($options->first()->label)->toBe('Alpha')          // legacy scalar synced from EN
        ->and($options->last()->label)->toBe('Bravo');
});
