<?php

namespace App\Contexts\Commerce\Database\Seeders;

use App\Contexts\Commerce\Enums\CommercePermission;
use App\Contexts\Commerce\Enums\ProductStatus;
use App\Contexts\Commerce\Enums\ProductType;
use App\Contexts\Commerce\Models\ContractTemplate;
use App\Contexts\Commerce\Models\Product;
use App\Domains\Catalog\Models\Course;
use App\Platform\Shared\Helpers\Slug;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds commerce permissions, the active terms contract, and a product per published course.
 *
 * Idempotent in both directions: the product is keyed by slug, the course link uses
 * syncWithoutDetaching, and a price is only created when the product has none — so re-running never
 * duplicates a product and never overwrites a price an admin has since changed.
 */
class CommerceSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CommerceTaxSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (CommercePermission::values() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        SpatieRole::findByName('admin', 'web')->givePermissionTo(CommercePermission::values());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        ContractTemplate::firstOrCreate(
            ['key' => 'terms', 'version' => 1],
            ['title' => 'Terms & Conditions', 'body' => 'By enrolling you accept the HElbaron terms.', 'is_active' => true],
        );

        // EVERY published course, not the first three. The limit was a demo-era convenience that
        // silently stopped applying as the catalogue grew: courses 4-12 had no product, so the public
        // card and detail page both said "Not available yet" for three quarters of the catalogue.
        // There are no free courses here — a published course that cannot be bought is a defect.
        Course::query()->where('status', 'published')->orderBy('id')->get()->each(function (Course $course): void {
            $product = Product::firstOrCreate(
                ['slug' => Slug::make($course->title).'-product'],
                ['type' => ProductType::Course->value, 'title' => $course->title, 'status' => ProductStatus::Active->value],
            );
            $product->courses()->syncWithoutDetaching([$course->id]);
            if ($product->prices()->doesntExist()) {
                $product->prices()->create(['currency' => 'SAR', 'amount_minor' => 19900, 'is_default' => true]);
            }
        });
    }
}
