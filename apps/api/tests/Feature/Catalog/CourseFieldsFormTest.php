<?php

use App\Domains\Catalog\Filament\Resources\CourseResource\Pages\CreateCourse;
use App\Domains\Catalog\Filament\Resources\CourseResource\Pages\EditCourse;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Course;
use App\Domains\Catalog\Models\CourseTag;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** Sign in as a super_admin on the admin panel so the Catalog resource pages resolve. */
function actAsCourseFormAdmin(): User
{
    test()->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    test()->actingAs($admin, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    return $admin;
}

it('exposes SEO, slug, categories, tags and marketing fields on the course form', function () {
    actAsCourseFormAdmin();
    $course = Course::factory()->create(['title_i18n' => ['en' => 'Form Fields']]);

    Livewire::test(EditCourse::class, ['record' => $course->public_id])
        ->assertFormFieldExists('slug')
        ->assertFormFieldExists('categories')
        ->assertFormFieldExists('tags')
        ->assertFormFieldExists('seo.meta_title')
        ->assertFormFieldExists('learning_objectives_i18n.en')
        ->assertFormFieldExists('learning_objectives_i18n.ar')
        ->assertFormFieldExists('requirements_i18n.en')
        ->assertFormFieldExists('target_audience_i18n.en')
        ->assertFormFieldExists('duration_minutes')
        ->assertFormFieldExists('trailer_path');
});

it('saves SEO, slug, categories, tags and marketing lists through the resource', function () {
    actAsCourseFormAdmin();
    $cat = Category::factory()->create();
    $tag = CourseTag::factory()->create();
    $course = Course::factory()->create(['title_i18n' => ['en' => 'Editable Course']]);

    Livewire::test(EditCourse::class, ['record' => $course->public_id])
        ->fillForm([
            'slug' => 'editable-course-slug',
            'categories' => [$cat->id],
            'tags' => [$tag->id],
            'learning_objectives_i18n.en' => ['Objective A', 'Objective B'],
            'requirements_i18n.en' => ['Requirement A'],
            'target_audience_i18n.en' => ['Audience A'],
            'duration_minutes' => 120,
            'seo.meta_title' => 'Course Meta Title',
            'seo.meta_description' => 'Course meta description.',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $course->fresh();

    expect($fresh->slug)->toBe('editable-course-slug')
        ->and($fresh->duration_minutes)->toBe(120)
        ->and($fresh->learning_objectives_i18n['en'])->toBe(['Objective A', 'Objective B'])
        ->and($fresh->requirements_i18n['en'])->toBe(['Requirement A'])
        ->and($fresh->target_audience_i18n['en'])->toBe(['Audience A'])
        ->and($fresh->seo['meta_title'])->toBe('Course Meta Title')
        ->and($fresh->categories()->pluck('categories.id')->all())->toBe([$cat->id])
        ->and($fresh->tags()->pluck('course_tags.id')->all())->toBe([$tag->id]);
});

it('enforces slug uniqueness on the course form', function () {
    actAsCourseFormAdmin();
    Course::factory()->create(['slug' => 'taken-slug', 'title_i18n' => ['en' => 'Taken Course']]);

    Livewire::test(CreateCourse::class)
        ->fillForm([
            'title_i18n.en' => 'A Brand New Course',
            'slug' => 'taken-slug',
        ])
        ->call('create')
        ->assertHasFormErrors(['slug']);
});
