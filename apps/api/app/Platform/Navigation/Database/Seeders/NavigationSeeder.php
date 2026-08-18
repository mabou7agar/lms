<?php

namespace App\Platform\Navigation\Database\Seeders;

use App\Platform\Navigation\Enums\MenuLocation;
use App\Platform\Navigation\Enums\NavAuthVisibility;
use App\Platform\Navigation\Enums\NavUrlType;
use App\Platform\Navigation\Models\NavItem;
use App\Platform\Navigation\Models\NavMenu;
use Illuminate\Database\Seeder;

/**
 * Migrates the CURRENT hardcoded frontend navigation into CMS records so admins can edit it — while
 * the frontend keeps the same hardcoded config as a fallback (nav never disappears). Values are
 * replicated from apps/web: brandTheme.nav / brandTheme.footer (public header/footer) and the
 * nav.ts arrays (learner/instructor/organization sidebars), plus a legal and utility menu.
 *
 * Idempotent: firstOrCreate the menu by location, then firstOrCreate each item keyed by
 * (menu_id, parent_id, position). Re-running never duplicates. All 10 MenuLocation cases get a
 * menu row (some intentionally empty — MobileMenu/AdminQuickLinks/MegaMenu — ready for admin use).
 */
class NavigationSeeder extends Seeder
{
    public function run(): void
    {
        // Ensure every location has a menu row (empty ones are valid mount points for admins).
        foreach (MenuLocation::cases() as $location) {
            NavMenu::firstOrCreate(['location' => $location->value], ['is_active' => true]);
        }

        foreach ($this->definitions() as $location => $items) {
            $menu = NavMenu::query()->forLocation($location)->first();
            if ($menu !== null) {
                $this->seedItems($menu, $items, null);
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function seedItems(NavMenu $menu, array $items, ?int $parentId): void
    {
        foreach ($items as $def) {
            /** @var array<string, mixed> $def */
            $children = $def['children'] ?? [];
            unset($def['children']);

            $item = NavItem::firstOrCreate(
                [
                    'menu_id' => $menu->id,
                    'parent_id' => $parentId,
                    'position' => $def['position'],
                ],
                [
                    'label' => $def['label'],
                    'url_type' => $def['url_type'] ?? NavUrlType::Internal->value,
                    'url' => $def['url'] ?? '#',
                    'icon' => $def['icon'] ?? null,
                    'is_enabled' => true,
                    'open_new_tab' => $def['open_new_tab'] ?? false,
                    'visibility_auth' => $def['visibility_auth'] ?? NavAuthVisibility::Any->value,
                    'visibility_roles' => $def['visibility_roles'] ?? null,
                ],
            );

            if ($children !== []) {
                $this->seedItems($menu, $children, $item->id);
            }
        }
    }

    /**
     * The hardcoded nav, replicated from apps/web (theme.ts brandTheme.nav/footer + nav.ts arrays).
     * Icon keys match the frontend Lucide map (icon-map.ts).
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function definitions(): array
    {
        return [
            // brandTheme.nav
            MenuLocation::PublicHeader->value => [
                ['position' => 10, 'label' => ['en' => 'Courses', 'ar' => 'الدورات'], 'url' => '/courses'],
                ['position' => 20, 'label' => ['en' => 'Cohorts', 'ar' => 'الأفواج'], 'url' => '/cohorts'],
                ['position' => 30, 'label' => ['en' => 'Workshops', 'ar' => 'الورش'], 'url' => '/workshops'],
                ['position' => 40, 'label' => ['en' => 'Events', 'ar' => 'الفعاليات'], 'url' => '/events'],
                ['position' => 50, 'label' => ['en' => 'B2B / B2G Training', 'ar' => 'تدريب المؤسسات'], 'url' => '/enterprise'],
                ['position' => 60, 'label' => ['en' => 'Consulting', 'ar' => 'الاستشارات'], 'url' => '/advisory'],
            ],

            // brandTheme.footer.columns — each column is a parent (heading), links are its children.
            MenuLocation::PublicFooter->value => [
                [
                    'position' => 10, 'url' => '#', 'label' => ['en' => 'Learn', 'ar' => 'تعلّم'],
                    'children' => [
                        ['position' => 10, 'label' => ['en' => 'Courses', 'ar' => 'الدورات'], 'url' => '/courses'],
                        // "Bundles", not "Products": the public surface sells Courses and Bundles, and
                        // /products is an internal listing name that means nothing to a buyer.
                        ['position' => 20, 'label' => ['en' => 'Bundles', 'ar' => 'الباقات'], 'url' => '/bundles'],
                        ['position' => 30, 'label' => ['en' => 'Live cohorts', 'ar' => 'الأفواج'], 'url' => '/cohorts'],
                        ['position' => 40, 'label' => ['en' => 'Workshops', 'ar' => 'الورش'], 'url' => '/workshops'],
                        ['position' => 50, 'label' => ['en' => 'Events', 'ar' => 'الفعاليات'], 'url' => '/events'],
                        ['position' => 60, 'label' => ['en' => 'Certificates', 'ar' => 'الشهادات'], 'url' => '/verify'],
                        ['position' => 70, 'label' => ['en' => 'Pricing', 'ar' => 'الأسعار'], 'url' => '/pricing'],
                        ['position' => 80, 'label' => ['en' => 'Become an instructor', 'ar' => 'كن مدرّبًا'], 'url' => '/teach/apply'],
                    ],
                ],
                [
                    'position' => 20, 'url' => '#', 'label' => ['en' => 'For Business', 'ar' => 'للأعمال'],
                    'children' => [
                        ['position' => 10, 'label' => ['en' => 'B2B / B2G Training', 'ar' => 'تدريب المؤسسات'], 'url' => '/enterprise'],
                        ['position' => 20, 'label' => ['en' => 'HElbaron Advisory', 'ar' => 'استشارات HElbaron'], 'url' => '/advisory'],
                        ['position' => 30, 'label' => ['en' => 'Government partnerships', 'ar' => 'شراكات حكومية'], 'url' => '/enterprise'],
                        ['position' => 40, 'label' => ['en' => 'Case studies', 'ar' => 'دراسات حالة'], 'url' => '/enterprise'],
                    ],
                ],
                [
                    'position' => 30, 'url' => '#', 'label' => ['en' => 'Company', 'ar' => 'الشركة'],
                    'children' => [
                        ['position' => 10, 'label' => ['en' => 'About', 'ar' => 'من نحن'], 'url' => '/about'],
                        ['position' => 20, 'label' => ['en' => 'Solutions', 'ar' => 'الحلول'], 'url' => '/solutions'],
                        ['position' => 30, 'label' => ['en' => 'Organizations', 'ar' => 'المؤسسات'], 'url' => '/enterprise'],
                        ['position' => 40, 'label' => ['en' => 'Trainers', 'ar' => 'المدرّبون'], 'url' => '/trainers'],
                        ['position' => 50, 'label' => ['en' => 'Contact', 'ar' => 'تواصل'], 'url' => '/contact'],
                    ],
                ],
            ],

            // nav.ts learningNav
            MenuLocation::LearnerSidebar->value => [
                ['position' => 10, 'label' => ['en' => 'Dashboard', 'ar' => 'لوحة التحكم'], 'url' => '/dashboard', 'icon' => 'LayoutDashboard'],
                ['position' => 20, 'label' => ['en' => 'My Learning', 'ar' => 'تعلّمي'], 'url' => '/my-learning', 'icon' => 'GraduationCap'],
                ['position' => 30, 'label' => ['en' => 'Continue Learning', 'ar' => 'متابعة التعلّم'], 'url' => '/continue-learning', 'icon' => 'PlayCircle'],
                ['position' => 40, 'label' => ['en' => 'Certificates', 'ar' => 'الشهادات'], 'url' => '/certificates', 'icon' => 'Award'],
            ],

            // nav.ts instructorNav
            MenuLocation::InstructorSidebar->value => [
                ['position' => 10, 'label' => ['en' => 'Dashboard', 'ar' => 'لوحة التدريس'], 'url' => '/teach', 'icon' => 'LayoutDashboard'],
                ['position' => 20, 'label' => ['en' => 'My Courses', 'ar' => 'دوراتي'], 'url' => '/teach/courses', 'icon' => 'Presentation'],
                ['position' => 30, 'label' => ['en' => 'Media', 'ar' => 'الوسائط'], 'url' => '/teach/media', 'icon' => 'Film'],
                ['position' => 40, 'label' => ['en' => 'Students', 'ar' => 'الطلاب'], 'url' => '/teach/students', 'icon' => 'Users'],
                ['position' => 70, 'label' => ['en' => 'Profile', 'ar' => 'الملف الشخصي'], 'url' => '/profile', 'icon' => 'User'],
            ],

            // nav.ts organizationNav
            MenuLocation::OrganizationSidebar->value => [
                ['position' => 10, 'label' => ['en' => 'Organization', 'ar' => 'المؤسسة'], 'url' => '/org', 'icon' => 'Building2'],
                ['position' => 20, 'label' => ['en' => 'Organizations', 'ar' => 'المؤسسات'], 'url' => '/org/organizations', 'icon' => 'Building'],
                ['position' => 30, 'label' => ['en' => 'Consulting', 'ar' => 'الاستشارات'], 'url' => '/org/consulting', 'icon' => 'Headset'],
            ],

            // brandTheme.footer.legal (privacy / terms)
            MenuLocation::LegalMenu->value => [
                ['position' => 10, 'label' => ['en' => 'Verify certificate', 'ar' => 'التحقق من الشهادات'], 'url' => '/verify'],
                ['position' => 20, 'label' => ['en' => 'Privacy', 'ar' => 'الخصوصية'], 'url' => '/privacy'],
                ['position' => 30, 'label' => ['en' => 'Terms', 'ar' => 'الشروط'], 'url' => '/terms'],
            ],

            // login / register / locale (auth-state gated)
            MenuLocation::UtilityMenu->value => [
                ['position' => 10, 'label' => ['en' => 'Sign in', 'ar' => 'تسجيل الدخول'], 'url' => '/login', 'visibility_auth' => NavAuthVisibility::Guest->value],
                ['position' => 20, 'label' => ['en' => 'Start free', 'ar' => 'ابدأ مجانًا'], 'url' => '/register', 'visibility_auth' => NavAuthVisibility::Guest->value],
                ['position' => 30, 'label' => ['en' => 'Dashboard', 'ar' => 'لوحة التحكم'], 'url' => '/dashboard', 'visibility_auth' => NavAuthVisibility::Authenticated->value],
                ['position' => 40, 'label' => ['en' => 'العربية', 'ar' => 'English'], 'url' => '#lang'],
            ],
        ];
    }
}
