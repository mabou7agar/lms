<?php

use App\Platform\Navigation\Database\Seeders\NavigationSeeder;
use App\Platform\Navigation\Enums\MenuLocation;
use App\Platform\Navigation\Enums\NavAuthVisibility;
use App\Platform\Navigation\Enums\NavUrlType;
use App\Platform\Navigation\Models\NavItem;
use App\Platform\Navigation\Models\NavMenu;
use App\Platform\Navigation\Rules\SafeUrl;
use App\Platform\Navigation\Support\NavUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

function navMenu(MenuLocation $location = MenuLocation::PublicHeader): NavMenu
{
    return NavMenu::factory()->location($location)->create();
}

it('returns enabled items in position order for a location', function () {
    $menu = navMenu();
    NavItem::factory()->forMenu($menu)->create(['label' => ['en' => 'Second', 'ar' => 'ثانٍ'], 'url' => '/b', 'position' => 20]);
    NavItem::factory()->forMenu($menu)->create(['label' => ['en' => 'First', 'ar' => 'أول'], 'url' => '/a', 'position' => 10]);

    $res = $this->getJson('/api/v1/navigation/public-header')->assertOk();

    expect($res->json('data.location'))->toBe('public-header')
        ->and($res->json('data.items'))->toHaveCount(2)
        ->and($res->json('data.items.0.label.en'))->toBe('First')
        ->and($res->json('data.items.1.label.en'))->toBe('Second')
        ->and($res->json('data.items.0.url'))->toBe('/a');
});

it('excludes disabled items from the payload', function () {
    $menu = navMenu();
    NavItem::factory()->forMenu($menu)->create(['label' => ['en' => 'Shown', 'ar' => 'ظاهر'], 'url' => '/on', 'position' => 10]);
    NavItem::factory()->forMenu($menu)->disabled()->create(['label' => ['en' => 'Hidden', 'ar' => 'مخفي'], 'url' => '/off', 'position' => 20]);

    $labels = collect($this->getJson('/api/v1/navigation/public-header')->assertOk()->json('data.items'))->pluck('label.en');

    expect($labels)->toContain('Shown')->not->toContain('Hidden');
});

it('nests children under their parent', function () {
    $menu = navMenu(MenuLocation::PublicFooter);
    $parent = NavItem::factory()->forMenu($menu)->create(['label' => ['en' => 'Learn', 'ar' => 'تعلّم'], 'url' => '#', 'position' => 10]);
    NavItem::factory()->childOf($parent)->create(['label' => ['en' => 'Courses', 'ar' => 'الدورات'], 'url' => '/courses', 'position' => 10]);

    $res = $this->getJson('/api/v1/navigation/public-footer')->assertOk();

    expect($res->json('data.items'))->toHaveCount(1)
        ->and($res->json('data.items.0.label.en'))->toBe('Learn')
        ->and($res->json('data.items.0.children'))->toHaveCount(1)
        ->and($res->json('data.items.0.children.0.label.en'))->toBe('Courses')
        ->and($res->json('data.items.0.children.0.url'))->toBe('/courses');
});

it('emits visibility metadata (roles / auth / locales / feature flag)', function () {
    $menu = navMenu(MenuLocation::UtilityMenu);
    NavItem::factory()->forMenu($menu)->create([
        'label' => ['en' => 'Members', 'ar' => 'الأعضاء'], 'url' => '/dashboard', 'position' => 10,
        'visibility_roles' => ['admin', 'instructor'],
        'visibility_auth' => NavAuthVisibility::Authenticated,
        'visibility_locales' => ['en'],
        'feature_flag' => 'beta_dashboard',
    ]);

    $item = $this->getJson('/api/v1/navigation/utility-menu')->assertOk()->json('data.items.0');

    expect($item['visibility']['roles'])->toBe(['admin', 'instructor'])
        ->and($item['visibility']['auth'])->toBe('authenticated')
        ->and($item['visibility']['locales'])->toBe(['en'])
        ->and($item['visibility']['feature_flag'])->toBe('beta_dashboard');
});

it('auto-applies noopener noreferrer + _blank target for external new-tab links', function () {
    $menu = navMenu();
    NavItem::factory()->forMenu($menu)->external('https://partner.example.com')->create([
        'label' => ['en' => 'Partner', 'ar' => 'شريك'], 'position' => 10,
    ]);

    $item = $this->getJson('/api/v1/navigation/public-header')->assertOk()->json('data.items.0');

    expect($item['target'])->toBe('_blank')
        ->and($item['rel'])->toContain('noopener')
        ->and($item['rel'])->toContain('noreferrer')
        ->and($item['url'])->toBe('https://partner.example.com');
});

it('returns 404 for an unknown location', function () {
    $this->getJson('/api/v1/navigation/not-a-location')->assertNotFound();
});

it('lists all active menus on the index endpoint', function () {
    $menu = navMenu(MenuLocation::LearnerSidebar);
    NavItem::factory()->forMenu($menu)->create(['label' => ['en' => 'Dashboard', 'ar' => 'لوحة'], 'url' => '/dashboard', 'position' => 10]);
    NavMenu::factory()->location(MenuLocation::LegalMenu)->inactive()->create();

    $locations = collect($this->getJson('/api/v1/navigation')->assertOk()->json('data.menus'))->pluck('location');

    expect($locations)->toContain('learner-sidebar')->not->toContain('legal-menu');
});

it('rejects unsafe URLs via the SafeUrl validation rule', function () {
    foreach (['javascript:alert(1)', 'data:text/html,x', 'vbscript:msgbox', 'java\tscript:alert(1)'] as $bad) {
        $v = Validator::make(['url_type' => 'internal', 'url' => $bad], ['url' => [new SafeUrl]]);
        expect($v->fails())->toBeTrue("expected '{$bad}' to be rejected");
    }

    // Internal must be a path/anchor; external must be http(s).
    expect(Validator::make(['url_type' => 'internal', 'url' => 'https://x.com'], ['url' => [new SafeUrl]])->fails())->toBeTrue()
        ->and(Validator::make(['url_type' => 'internal', 'url' => '//evil.com'], ['url' => [new SafeUrl]])->fails())->toBeTrue()
        ->and(Validator::make(['url_type' => 'external', 'url' => '/relative'], ['url' => [new SafeUrl]])->fails())->toBeTrue();

    // Safe values pass.
    expect(Validator::make(['url_type' => 'internal', 'url' => '/courses'], ['url' => [new SafeUrl]])->fails())->toBeFalse()
        ->and(Validator::make(['url_type' => 'internal', 'url' => '#lang'], ['url' => [new SafeUrl]])->fails())->toBeFalse()
        ->and(Validator::make(['url_type' => 'external', 'url' => 'https://ok.com'], ['url' => [new SafeUrl]])->fails())->toBeFalse();
});

it('never renders an unsafe URL through the safeUrl accessor', function () {
    $item = NavItem::factory()->make(['url_type' => NavUrlType::Internal, 'url' => 'javascript:alert(1)']);

    expect($item->safeUrl())->toBe('#')
        ->and(NavUrl::isSafe(NavUrlType::External, 'https://ok.com'))->toBeTrue()
        ->and(NavUrl::isSafe(NavUrlType::Internal, 'javascript:alert(1)'))->toBeFalse();
});

/*
 | AppShell renders the CMS menu INSTEAD of the frontend's nav.ts when a menu exists for the location,
 | so an instructor surface that is missing from the seeded menu is unreachable from the sidebar no
 | matter what the frontend config lists. /teach/questions was exactly that: present in nav.ts,
 | absent from the seeded menu, and therefore invisible.
 */
it('seeds the instructor Q&A queue into the instructor sidebar', function () {
    $this->seed(NavigationSeeder::class);

    $menu = NavMenu::query()->forLocation(MenuLocation::InstructorSidebar->value)->firstOrFail();
    $item = NavItem::query()->where('menu_id', $menu->id)->where('url', '/teach/questions')->firstOrFail();

    expect($item->label['en'])->toBe('Questions')
        ->and($item->label['ar'])->toBe('الأسئلة')
        ->and($item->is_enabled)->toBeTrue();

    // Between Students and Profile, so the queue sits with the other teaching surfaces.
    $students = NavItem::query()->where('menu_id', $menu->id)->where('url', '/teach/students')->firstOrFail();
    $profile = NavItem::query()->where('menu_id', $menu->id)->where('url', '/profile')->firstOrFail();

    expect($item->position)->toBeGreaterThan($students->position)
        ->and($item->position)->toBeLessThan($profile->position);
});

it('does not duplicate navigation when the seeder runs twice', function () {
    $this->seed(NavigationSeeder::class);
    $this->seed(NavigationSeeder::class);

    $menu = NavMenu::query()->forLocation(MenuLocation::InstructorSidebar->value)->firstOrFail();

    expect(NavItem::query()->where('menu_id', $menu->id)->where('url', '/teach/questions')->count())->toBe(1);
});

it('adds a newly shipped entry to an environment seeded before it existed', function () {
    // Simulate the deployed state: everything seeded, then the Questions row removed as it would be
    // on an environment provisioned before this entry shipped.
    $this->seed(NavigationSeeder::class);
    $menu = NavMenu::query()->forLocation(MenuLocation::InstructorSidebar->value)->firstOrFail();
    NavItem::query()->where('menu_id', $menu->id)->where('url', '/teach/questions')->delete();

    // Re-running the seeder is the whole upgrade path — no migration needed.
    $this->seed(NavigationSeeder::class);

    expect(NavItem::query()->where('menu_id', $menu->id)->where('url', '/teach/questions')->exists())->toBeTrue();
});
