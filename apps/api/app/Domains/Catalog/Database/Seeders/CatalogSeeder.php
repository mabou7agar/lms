<?php

namespace App\Domains\Catalog\Database\Seeders;

use App\Domains\Catalog\Enums\CatalogPermission;
use App\Domains\Catalog\Enums\CourseStatus;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Course;
use App\Domains\Catalog\Models\CourseLanguage;
use App\Domains\Catalog\Models\CourseLevel;
use App\Domains\Catalog\Models\CourseTag;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Enums\Visibility;
use App\Platform\Shared\Helpers\Slug;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds realistic HElbaron demo content — the 12 business verticals, levels/languages, a handful of
 * trainers, and one published course per vertical. Idempotent (keyed by slug/email).
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        // Catalog's own permissions were never persisted by any seeder, so `catalog.courses.manage`
        // — checked three times in CoursePolicy — had no row in the permissions table. Every check
        // against it could only ever pass through the super_admin before() bypass, meaning an admin
        // granted the capability did not actually hold it. Seeded here following AuthoringSeeder.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (CatalogPermission::values() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        SpatieRole::findByName('admin', 'web')->givePermissionTo(CatalogPermission::values());

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $levels = collect(['Beginner', 'Intermediate', 'Advanced'])
            ->mapWithKeys(fn ($name, $i) => [$name => CourseLevel::firstOrCreate(['slug' => Slug::make($name)], ['name' => $name, 'position' => $i])]);

        $languages = collect([['en', 'English'], ['ar', 'العربية']])
            ->mapWithKeys(fn ($l, $i) => [$l[0] => CourseLanguage::firstOrCreate(['code' => $l[0]], ['name' => $l[1], 'position' => $i])]);

        // 12 MENA business verticals (top-level categories).
        $verticals = [
            'project-management' => 'Project Management',
            'agile-mindset' => 'Agile Mindset',
            'business-development' => 'Business Development',
            'business-strategies' => 'Business Strategies',
            'entrepreneurship' => 'Entrepreneurship',
            'business-skills' => 'Business Skills',
            'leadership' => 'Leadership',
            'marketing-strategies' => 'Marketing Strategies',
            'sales-management' => 'Sales Management',
            'finance-analysis' => 'Finance & Analysis',
            'business-ai' => 'Business AI',
            'investment-trading' => 'Investment & Trading',
        ];
        $categories = collect($verticals)->mapWithKeys(function ($name, $slug) {
            static $i = 0;

            return [$slug => Category::firstOrCreate(['slug' => $slug], ['name' => $name, 'position' => $i++])];
        });

        collect(['strategy', 'leadership', 'growth', 'mena', 'finance', 'ai'])
            ->each(fn ($t) => CourseTag::firstOrCreate(['slug' => Slug::make($t)], ['name' => ucfirst($t)]));

        // Trainers (the first one is also the demo login: trainer@helbaron.local / password).
        $trainers = collect([
            ['trainer@helbaron.local', 'Yara Adel', 'PMP-certified program lead, 12 years across MENA delivery.'],
            ['omar.farouk@helbaron.local', 'Omar Farouk', 'Leadership coach and former regional operations director.'],
            ['nour.hassan@helbaron.local', 'Nour Hassan', 'AI product strategist helping teams ship with data.'],
            ['laila.mansour@helbaron.local', 'Laila Mansour', 'Growth marketer for MENA consumer brands.'],
            ['karim.saleh@helbaron.local', 'Karim Saleh', 'CFA charterholder, finance and analysis educator.'],
        ])->map(function ($t) {
            [$email, $name, $headline] = $t;
            $user = User::firstOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => Hash::make('password'), 'is_active' => true, 'email_verified_at' => now()],
            );
            $user->assignRole('instructor');
            [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');
            $user->profile()->firstOrCreate([], ['first_name' => $first, 'last_name' => $last, 'bio' => $headline]);

            return $user;
        })->values();

        // One published course per vertical (realistic titles/subtitles).
        $courses = [
            ['Project Management Foundations', 'Plan, execute, and deliver projects with confidence.', 'project-management', 'Beginner', true],
            ['Agile & Scrum in Practice', 'Run agile teams that ship value every sprint.', 'agile-mindset', 'Intermediate', false],
            ['Business Development Essentials', 'Build pipeline, partnerships, and sustainable revenue.', 'business-development', 'Beginner', false],
            ['Competitive Business Strategy', 'Frameworks to position and win in the MENA market.', 'business-strategies', 'Advanced', true],
            ['From Idea to Startup', 'Validate, build, and launch your first venture.', 'entrepreneurship', 'Beginner', false],
            ['Essential Business Skills', 'Communication, negotiation, and personal productivity.', 'business-skills', 'Beginner', false],
            ['Leadership for New Managers', 'Lead people, not just tasks — your first 90 days.', 'leadership', 'Intermediate', true],
            ['Modern Marketing Strategy', 'Positioning, funnels, and growth for MENA brands.', 'marketing-strategies', 'Intermediate', false],
            ['Sales Management Playbook', 'Coach your reps, forecast, and hit the number.', 'sales-management', 'Intermediate', false],
            ['Finance & Analysis for Managers', 'Read the numbers and make better decisions.', 'finance-analysis', 'Beginner', false],
            ['Business AI for Decision Makers', 'Apply AI to real business problems, responsibly.', 'business-ai', 'Intermediate', true],
            ['Investment & Trading Basics', 'Markets, risk, and portfolio fundamentals.', 'investment-trading', 'Beginner', false],
        ];

        foreach ($courses as $i => [$title, $subtitle, $catSlug, $level, $featured]) {
            if (Course::where('slug', Slug::make($title))->exists()) {
                continue;
            }
            $course = Course::create([
                'title' => $title,
                'slug' => Slug::make($title),
                'subtitle' => $subtitle,
                'description' => $subtitle.' A hands-on, MENA-focused program built for professionals, founders, and teams.',
                'status' => CourseStatus::Published->value,
                'visibility' => Visibility::Public->value,
                'is_featured' => $featured,
                'position' => $i,
                'published_at' => now(),
                'level_id' => $levels[$level]->id,
                'language_id' => $languages['en']->id,
            ]);
            $course->categories()->sync([$categories[$catSlug]->id]);
            $course->syncTrainers([$trainers[$i % $trainers->count()]->id]);
        }
    }
}
