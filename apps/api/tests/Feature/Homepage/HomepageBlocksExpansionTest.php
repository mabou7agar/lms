<?php

use App\Domains\Catalog\Models\Course;
use App\Platform\Homepage\Database\Seeders\HomepageSeeder;
use App\Platform\Homepage\Enums\BlockType;
use App\Platform\Homepage\Enums\HomepageStatus;
use App\Platform\Homepage\Models\HomepageSection;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Enums\Role;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

it('provides non-empty bilingual default content for every new block type', function () {
    $newTypes = [
        BlockType::Statistics, BlockType::Numbers, BlockType::Categories, BlockType::FeaturedCourses,
        BlockType::FeaturedEvents, BlockType::Clients, BlockType::PricingPreview, BlockType::Cta,
        BlockType::Video, BlockType::Gallery, BlockType::Timeline, BlockType::Team, BlockType::Newsletter,
        BlockType::ContactStrip, BlockType::RichText, BlockType::LogoCloud, BlockType::ComparisonTable,
    ];

    expect($newTypes)->toHaveCount(17);

    foreach ($newTypes as $type) {
        $content = $type->defaultContent();
        expect($content)->toBeArray()->not->toBeEmpty("{$type->value} has default content");
    }
});

it('excludes a Draft block from the public homepage but keeps it in the admin preview', function () {
    $this->seed(RolePermissionSeeder::class);
    HomepageSection::factory()->block('hero')->published()->create();
    HomepageSection::factory()->ofType(BlockType::Cta)->draft()->create(['key' => 'cta_draft', 'position' => 15]);

    $types = collect($this->getJson('/api/v1/homepage')->assertOk()->json('data.sections'))->pluck('type');
    expect($types)->toContain('hero')->not->toContain('cta');

    $admin = User::factory()->create();
    $admin->assignRole(SpatieRole::findByName(Role::Admin->value, 'web'));
    Sanctum::actingAs($admin);

    $previewTypes = collect($this->getJson('/api/v1/homepage/preview')->assertOk()->json('data.sections'))->pluck('type');
    expect($previewTypes)->toContain('cta');
});

it('excludes a Published block whose publish window is in the future', function () {
    HomepageSection::factory()->block('hero')->published()->create();
    HomepageSection::factory()->ofType(BlockType::Statistics)->scheduledFuture()->create(['key' => 'stats_future', 'position' => 15]);

    $types = collect($this->getJson('/api/v1/homepage')->assertOk()->json('data.sections'))->pluck('type');
    expect($types)->toContain('hero')->not->toContain('statistics');
});

it('includes a Published in-window block on the public homepage', function () {
    HomepageSection::factory()->ofType(BlockType::Timeline)->published()->create(['key' => 'timeline_live', 'position' => 15]);

    $types = collect($this->getJson('/api/v1/homepage')->assertOk()->json('data.sections'))->pluck('type');
    expect($types)->toContain('timeline');
});

it('records a version on every update and can roll back to a prior one', function () {
    $hero = HomepageSection::factory()->block('hero')->create();

    $a = $hero->content;
    $a['headline']['en'] = 'VERSION A';
    $hero->update(['content' => $a]);

    $b = $hero->content;
    $b['headline']['en'] = 'VERSION B';
    $hero->update(['content' => $b]);

    expect($hero->versions()->count())->toBe(2);

    $hero->rollbackTo(1);

    expect($hero->fresh()->content['headline']['en'])->toBe('VERSION A')
        ->and($hero->versions()->count())->toBe(3); // rollback itself is recorded
});

it('sanitizes RichText block body HTML on save', function () {
    $section = HomepageSection::factory()->ofType(BlockType::RichText)->create([
        'key' => 'rich_1',
        'content' => [
            'title' => ['en' => 'T', 'ar' => 'ت'],
            'body' => [
                'en' => '<p>Safe</p><script>alert(1)</script>',
                'ar' => '<p>آمن</p><iframe src="x"></iframe>',
            ],
        ],
    ]);

    $body = $section->fresh()->content['body'];
    expect($body['en'])->not->toContain('<script>')
        ->and($body['en'])->toContain('Safe')
        ->and($body['ar'])->not->toContain('<iframe');
});

it('round-trips presentation and device-visibility fields through the public API', function () {
    HomepageSection::factory()->ofType(BlockType::Cta)->published()->create([
        'key' => 'cta_pres',
        'position' => 15,
        'layout_variant' => 'split',
        'spacing' => 'spacious',
        'alignment' => 'center',
        'container_width' => 'wide',
        'animation' => 'fade',
        'theme_variant' => 'inverted',
        'background' => ['color' => '#0a0a0a', 'image' => null, 'video' => null, 'overlay' => null],
        'accessibility_label' => ['en' => 'Call to action', 'ar' => 'دعوة لاتخاذ إجراء'],
        'visible_desktop' => true,
        'visible_tablet' => true,
        'visible_mobile' => false,
    ]);

    $section = collect($this->getJson('/api/v1/homepage')->assertOk()->json('data.sections'))
        ->firstWhere('type', 'cta');

    expect($section['presentation']['layout_variant'])->toBe('split')
        ->and($section['presentation']['spacing'])->toBe('spacious')
        ->and($section['presentation']['alignment'])->toBe('center')
        ->and($section['presentation']['container_width'])->toBe('wide')
        ->and($section['presentation']['animation'])->toBe('fade')
        ->and($section['presentation']['theme_variant'])->toBe('inverted')
        ->and($section['presentation']['background']['color'])->toBe('#0a0a0a')
        ->and($section['accessibility_label']['ar'])->toBe('دعوة لاتخاذ إجراء')
        ->and($section['visibility']['desktop'])->toBeTrue()
        ->and($section['visibility']['mobile'])->toBeFalse();
});

it('resolves featured courses server-side for the FeaturedCourses block', function () {
    Course::factory()->create([
        'title' => 'Leadership Essentials',
        'status' => 'published',
        'visibility' => 'public',
        'is_featured' => true,
        'featured_at' => now()->subDay(),
        'published_at' => now()->subDay(),
    ]);

    HomepageSection::factory()->ofType(BlockType::FeaturedCourses)->published()->create([
        'key' => 'fc_1',
        'position' => 15,
    ]);

    $section = collect($this->getJson('/api/v1/homepage')->assertOk()->json('data.sections'))
        ->firstWhere('type', 'featured_courses');

    expect($section['resolved'])->toBeArray()
        ->and($section['resolved']['courses'])->toBeArray()
        ->and(collect($section['resolved']['courses'])->pluck('title.en'))->toContain('Leadership Essentials');
});

it('resolves only the latest nine featured courses for the FeaturedCourses block', function () {
    $oldFeatured = Course::factory()->create([
        'title' => 'Old Featured',
        'status' => 'published',
        'visibility' => 'public',
        'is_featured' => true,
        'featured_at' => now()->subDays(30),
        'published_at' => now()->subDays(30),
    ]);

    Course::factory()->count(9)->sequence(fn ($sequence) => [
        'title' => 'Featured '.$sequence->index,
        'status' => 'published',
        'visibility' => 'public',
        'is_featured' => true,
        'featured_at' => now()->subMinutes($sequence->index),
        'published_at' => now()->subDays(10 - $sequence->index),
    ])->create();

    Course::factory()->create([
        'title' => 'Recent But Not Featured',
        'status' => 'published',
        'visibility' => 'public',
        'is_featured' => false,
        'published_at' => now(),
    ]);

    HomepageSection::factory()->ofType(BlockType::FeaturedCourses)->published()->create([
        'key' => 'fc_latest',
        'position' => 15,
    ]);

    $courses = collect($this->getJson('/api/v1/homepage')->assertOk()->json('data.sections'))
        ->firstWhere('type', 'featured_courses')['resolved']['courses'];

    $titles = collect($courses)->pluck('title.en');

    expect($courses)->toHaveCount(9)
        ->and($titles)->not->toContain($oldFeatured->title)
        ->and($titles)->not->toContain('Recent But Not Featured')
        ->and($titles->first())->toBe('Featured 0');
});

it('leaves the existing seven-block behavior unchanged', function () {
    HomepageSection::factory()->block('hero')->published()->create(['position' => 20]);
    HomepageSection::factory()->block('features')->published()->create(['position' => 10]);
    HomepageSection::factory()->block('seo')->published()->create(['position' => 90]);

    $res = $this->getJson('/api/v1/homepage')->assertOk();

    expect($res->json('data.sections'))->toHaveCount(2)
        ->and($res->json('data.sections.0.type'))->toBe('features')
        ->and($res->json('data.sections.1.type'))->toBe('hero')
        ->and($res->json('data.seo.meta_title.en'))->not->toBeNull();
});

it('backfills existing homepage rows to Published status via the seeder', function () {
    $this->seed(HomepageSeeder::class);

    expect(HomepageSection::where('key', 'hero')->value('status'))->toBe(HomepageStatus::Published)
        ->and(HomepageSection::where('key', 'statistics_example')->value('status'))->toBe(HomepageStatus::Draft);
});
