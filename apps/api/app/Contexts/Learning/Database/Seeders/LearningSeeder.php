<?php

namespace App\Contexts\Learning\Database\Seeders;

use App\Contexts\Learning\Actions\Enrollment\GrantEnrollmentAction;
use App\Contexts\Learning\Enums\EnrollmentSource;
use App\Contexts\Learning\Enums\LearningPermission;
use App\Contexts\Learning\Enums\LessonProgressStatus;
use App\Contexts\Learning\Services\ProgressService;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * Enrolls a sample student in the first published course and records some progress. Idempotent.
 */
class LearningSeeder extends Seeder
{
    public function run(): void
    {
        // Learning's permissions were never persisted by any seeder. Seeded before the early return
        // below, because whether a permission row exists must not depend on whether demo content
        // happens to have been created first.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (LearningPermission::values() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Enrollment administration is an admin capability. `learning.self.view` is deliberately
        // NOT granted to a role: a learner's access to their own learning is established by
        // enrollment, not by a permission, and granting it would imply otherwise.
        SpatieRole::findByName('admin', 'web')
            ->givePermissionTo(LearningPermission::ManageEnrollments->value);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $course = Course::query()->orderBy('id')->first();
        if ($course === null) {
            return;
        }

        $student = User::firstOrCreate(
            ['email' => 'student@helbaron.local'],
            ['name' => 'Sample Student', 'password' => Hash::make('password'), 'is_active' => true, 'email_verified_at' => now()],
        );
        $student->assignRole('student'); // Identity 'student' role slug (was Role::Student->value)

        $enrollment = app(GrantEnrollmentAction::class)->executeByUserId($student->id, $course->id, EnrollmentSource::Free);

        $firstLesson = Lesson::whereIn('section_id', Section::where('course_id', $course->id)->pluck('id'))
            ->published()->orderBy('position')->first();

        if ($firstLesson !== null) {
            app(ProgressService::class)->recordByLessonId($enrollment, $firstLesson->id, LessonProgressStatus::Completed);
        }
    }
}
