<?php

namespace App\Domains\Assessment\Database\Seeders;

use App\Domains\Assessment\Enums\AssessmentPermission;
use App\Domains\Assessment\Enums\AssignmentPermission;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Registers assessment permissions and grants them to `admin` ONLY.
 *
 * Instructors are deliberately NOT granted these. They reach their own courses' assessments
 * through the ownership branch of the `assessment.manage-assessment` gate; handing them the global permission
 * would satisfy the gate's first branch and give every instructor access to every course's
 * assessments — the exact defect found and fixed in the Authoring seeder during Step 2.
 */
class AssessmentSeeder extends Seeder
{
    public function run(): void
    {
        foreach (AssessmentPermission::values() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        SpatieRole::findByName('admin', 'web')->givePermissionTo(AssessmentPermission::values());

        foreach (AssignmentPermission::values() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        SpatieRole::findByName('admin', 'web')->givePermissionTo(AssignmentPermission::values());
    }
}
