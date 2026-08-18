<?php

use App\Domains\Catalog\Actions\Category\ReorderCategoriesAction;
use App\Domains\Catalog\Filament\Resources\CategoryResource\Pages\EditCategory;
use App\Domains\Catalog\Filament\Resources\CategoryResource\Pages\ListCategories;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

uses(RefreshDatabase::class);

if (! function_exists('categoryResourceAdminPanel')) {
    /** Sign a super_admin into the admin panel so the Category resource pages resolve. */
    function categoryResourceAdminPanel(): User
    {
        test()->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        test()->actingAs($admin, 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $admin;
    }
}

// --- Schema ------------------------------------------------------------------------------------

it('adds the categories.icon column, distinct from image_path', function () {
    expect(Schema::hasColumn('categories', 'icon'))->toBeTrue()
        ->and(Schema::hasColumn('categories', 'image_path'))->toBeTrue();
});

// --- Reorder -----------------------------------------------------------------------------------

it('persists new positions through ReorderCategoriesAction (0-based, by public_id)', function () {
    $c1 = Category::factory()->create(['position' => 0]);
    $c2 = Category::factory()->create(['position' => 1]);
    $c3 = Category::factory()->create(['position' => 2]);

    app(ReorderCategoriesAction::class)->execute([$c3->public_id, $c1->public_id, $c2->public_id]);

    expect($c3->fresh()->position)->toBe(0)
        ->and($c1->fresh()->position)->toBe(1)
        ->and($c2->fresh()->position)->toBe(2);
});

it('wires a drag-reorder on the list page through the reorder action', function () {
    categoryResourceAdminPanel();

    $c1 = Category::factory()->create(['position' => 0]);
    $c2 = Category::factory()->create(['position' => 1]);
    $c3 = Category::factory()->create(['position' => 2]);

    // Filament hands reorderTable the table record keys (primary ids) in their new order.
    Livewire::test(ListCategories::class)
        ->call('reorderTable', [$c3->id, $c1->id, $c2->id]);

    expect($c3->fresh()->position)->toBe(0)
        ->and($c1->fresh()->position)->toBe(1)
        ->and($c2->fresh()->position)->toBe(2);
});

// --- Form completeness: SEO + editable/unique slug + icon -------------------------------------

it('persists SEO fields, an edited slug, and an icon through a save', function () {
    categoryResourceAdminPanel();

    $category = Category::factory()->create([
        'name_i18n' => ['en' => 'SEO Category'],
        'slug' => 'seo-category-original',
    ]);

    Livewire::test(EditCategory::class, ['record' => $category->public_id])
        ->fillForm([
            'name_i18n' => ['en' => 'SEO Category'],
            'slug' => 'seo-category-edited',
            'icon' => 'academic-cap',
            'seo' => [
                'meta_title' => 'Custom Meta Title',
                'meta_description' => 'Custom meta description.',
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $category->fresh();
    expect($fresh->slug)->toBe('seo-category-edited')
        ->and($fresh->icon)->toBe('academic-cap')
        ->and($fresh->seo('meta_title'))->toBe('Custom Meta Title')
        ->and($fresh->seo('meta_description'))->toBe('Custom meta description.');
});

it('rejects a slug that collides with another category (slug stays unique)', function () {
    categoryResourceAdminPanel();

    Category::factory()->create(['slug' => 'taken-slug']);
    $editable = Category::factory()->create([
        'name_i18n' => ['en' => 'Editable'],
        'slug' => 'editable-slug',
    ]);

    Livewire::test(EditCategory::class, ['record' => $editable->public_id])
        ->fillForm(['slug' => 'taken-slug'])
        ->call('save')
        ->assertHasFormErrors(['slug']);

    expect($editable->fresh()->slug)->toBe('editable-slug');
});

// --- Table: courses_count ---------------------------------------------------------------------

it('exposes a courses_count column on the list table', function () {
    categoryResourceAdminPanel();

    $category = Category::factory()->create();
    $category->courses()->attach(Course::factory()->create());

    Livewire::test(ListCategories::class)
        ->assertOk()
        ->assertTableColumnExists('courses_count');

    // The count is genuinely aggregable off the relation the column reads.
    expect(Category::query()->withCount('courses')->find($category->id)->courses_count)->toBe(1);
});
