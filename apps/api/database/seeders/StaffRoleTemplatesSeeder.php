<?php

namespace Database\Seeders;

use App\Contexts\Analytics\Enums\AnalyticsPermission;
use App\Contexts\Commerce\Enums\CommercePermission;
use App\Contexts\Learning\Enums\LearningPermission;
use App\Domains\Assessment\Enums\AssessmentPermission;
use App\Domains\Assessment\Enums\AssignmentPermission;
use App\Domains\Authoring\Enums\AuthoringPermission;
use App\Domains\Catalog\Enums\CatalogPermission;
use App\Domains\Certification\Enums\CertificationPermission;
use App\Domains\Crm\Enums\CrmPermission;
use App\Domains\Live\Enums\LivePermission;
use App\Platform\Identity\Enums\Permission as IdentityPermission;
use App\Platform\Notifications\Enums\NotificationsPermission;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Editable staff-role templates. Unlike the four protected system roles (super_admin, admin,
 * instructor, student), these are starting points: seeded once with a least-privilege default
 * permission set, then owned and freely edited from the admin panel. Re-running never overwrites a
 * template an administrator has already customised — a role is only populated on first creation.
 * Lives in database/seeders (the composition layer) because it spans every domain by design.
 */
class StaffRoleTemplatesSeeder extends Seeder
{
    /**
     * @return array<string, list<string>>
     */
    public static function templates(): array
    {
        return [
            'content_author' => [
                AuthoringPermission::ViewCurriculum->value,
                AuthoringPermission::ManageCurriculum->value,
                CatalogPermission::ViewCourses->value,
            ],
            'content_editor' => [
                AuthoringPermission::ViewCurriculum->value,
                AuthoringPermission::ManageCurriculum->value,
                CatalogPermission::ViewCourses->value,
                CatalogPermission::ManageCourses->value,
                CatalogPermission::ManageCategories->value,
                CatalogPermission::ManageTaxonomy->value,
            ],
            'content_publisher' => [
                CatalogPermission::ViewCourses->value,
                CatalogPermission::ManageCourses->value,
                CatalogPermission::ManageCategories->value,
            ],
            'content_reviewer' => [
                AuthoringPermission::ViewCurriculum->value,
                CatalogPermission::ViewCourses->value,
                AssessmentPermission::ViewResults->value,
            ],
            'translator' => [
                AuthoringPermission::ViewCurriculum->value,
                CatalogPermission::ViewCourses->value,
            ],
            'assessment_manager' => [
                AssessmentPermission::Manage->value,
                AssessmentPermission::ViewResults->value,
                AssignmentPermission::Manage->value,
                AssignmentPermission::Grade->value,
            ],
            'certification_manager' => [
                CertificationPermission::ManageCertificates->value,
                CertificationPermission::ManageBadges->value,
                CertificationPermission::ManageTemplates->value,
            ],
            'enrollment_manager' => [
                LearningPermission::ManageEnrollments->value,
            ],
            'live_manager' => [
                LivePermission::ViewLive->value,
                LivePermission::ManageLive->value,
            ],
            'finance_manager' => [
                CommercePermission::ViewOrders->value,
                CommercePermission::ManageProducts->value,
                CommercePermission::ManageCoupons->value,
                CommercePermission::ManageRefunds->value,
                CommercePermission::ManageCreditNotes->value,
                CommercePermission::ManageSubscriptions->value,
                CommercePermission::ManageContracts->value,
            ],
            'sales_agent' => [
                CrmPermission::ViewCrm->value,
                CrmPermission::ManageLeads->value,
            ],
            'crm_manager' => [
                CrmPermission::ViewCrm->value,
                CrmPermission::ManageLeads->value,
                CrmPermission::ManageOrganizations->value,
                CrmPermission::ManageConsulting->value,
            ],
            'marketing_manager' => [
                NotificationsPermission::ManageTemplates->value,
                NotificationsPermission::ManageAutomation->value,
                NotificationsPermission::ViewLogs->value,
            ],
            'analytics_viewer' => [
                AnalyticsPermission::ViewAnalytics->value,
            ],
            'support_agent' => [
                CommercePermission::ViewOrders->value,
                CrmPermission::ViewCrm->value,
                // Support may impersonate ordinary users to reproduce their view; the
                // ImpersonationManager still forbids self- and super_admin-targets below the gate.
                IdentityPermission::ImpersonateUsers->value,
            ],
        ];
    }

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::templates() as $roleName => $permissions) {
            $existing = Role::where('name', $roleName)->where('guard_name', 'web')->first();

            if ($existing !== null) {
                continue;
            }

            $role = Role::create(['name' => $roleName, 'guard_name' => 'web']);

            $grantable = Permission::whereIn('name', $permissions)
                ->where('guard_name', 'web')
                ->pluck('name')
                ->all();

            if ($grantable !== []) {
                $role->givePermissionTo($grantable);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}