<?php

use App\Domains\Catalog\Actions\Category\ArchiveCategoryAction;
use App\Domains\Catalog\Actions\Category\DeleteCategoryAction;
use App\Domains\Catalog\Exceptions\CategoryNotDeletableException;
use App\Domains\Catalog\Filament\Resources\CategoryResource\Pages\ListCategories;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

if (! function_exists('categoryAdminPanel')) {
    /** Sign a super_admin into the admin panel so the Category resource pages resolve. */
    function categoryAdminPanel(): User
    {
        test()->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        test()->actingAs($admin, 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $admin;
    }
}

// --- Delete-safety at the action (domain) layer -----------------------------------------------

it('refuses to delete a category that still has courses attached', function () {
    $category = Category::factory()->create();
    $course = Course::factory()->create();
    $category->courses()->attach($course);

    expect(fn () => app(DeleteCategoryAction::class)->execute($category))
        ->toThrow(CategoryNotDeletableException::class);

    // Both the category and the course survive, and the link is intact.
    expect(Category::query()->whereKey($category->id)->exists())->toBeTrue()
        ->and(Course::query()->whereKey($course->id)->exists())->toBeTrue()
        ->and($category->courses()->count())->toBe(1);
});

it('deletes an empty category (no courses, no children)', function () {
    $category = Category::factory()->create();

    app(DeleteCategoryAction::class)->execute($category);

    expect(Category::query()->whereKey($category->id)->exists())->toBeFalse()
        ->and(Category::withTrashed()->whereKey($category->id)->exists())->toBeTrue();
});

it('refuses to delete a parent that still has child categories (refuse rule)', function () {
    $parent = Category::factory()->create();
    Category::factory()->childOf($parent)->create();

    expect(fn () => app(DeleteCategoryAction::class)->execute($parent))
        ->toThrow(CategoryNotDeletableException::class);

    expect(Category::query()->whereKey($parent->id)->exists())->toBeTrue();

    // Reporting helper agrees it is not deletable while children exist.
    expect(app(DeleteCategoryAction::class)->isDeletable($parent))->toBeFalse();
});

// --- Delete-safety wired through the Filament list action -------------------------------------

it('blocks the delete action for a category with courses, leaving both rows intact', function () {
    categoryAdminPanel();

    $category = Category::factory()->create();
    $course = Course::factory()->create();
    $category->courses()->attach($course);

    Livewire::test(ListCategories::class)
        ->callAction(TestAction::make('delete')->table($category));

    expect(Category::query()->whereKey($category->id)->exists())->toBeTrue()
        ->and($category->courses()->count())->toBe(1);
});

it('allows the delete action for an empty category', function () {
    categoryAdminPanel();

    $category = Category::factory()->create();

    Livewire::test(ListCategories::class)
        ->callAction(TestAction::make('delete')->table($category));

    expect(Category::query()->whereKey($category->id)->exists())->toBeFalse()
        ->and(Category::withTrashed()->whereKey($category->id)->exists())->toBeTrue();
});

// --- Archive is reversible and controls the active listing ------------------------------------

it('archives a category (is_active=false) reversibly and toggles it back', function () {
    $category = Category::factory()->create(['is_active' => true]);

    app(ArchiveCategoryAction::class)->archive($category);
    expect($category->fresh()->is_active)->toBeFalse();

    app(ArchiveCategoryAction::class)->activate($category);
    expect($category->fresh()->is_active)->toBeTrue();
});

it('hides an archived category from the active catalog listing and restores it on activate', function () {
    $category = Category::factory()->create(['name' => 'Archivable Root', 'is_active' => true]);

    $names = fn (): array => collect($this->getJson('/api/v1/categories')->assertOk()->json('data'))
        ->pluck('name')->all();

    expect($names())->toContain('Archivable Root');

    app(ArchiveCategoryAction::class)->archive($category);
    expect($names())->not->toContain('Archivable Root');

    app(ArchiveCategoryAction::class)->activate($category);
    expect($names())->toContain('Archivable Root');
});
