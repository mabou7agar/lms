<?php

namespace App\Contexts\Commerce\Database\Seeders;

use App\Contexts\Commerce\Enums\AccessDurationType;
use App\Contexts\Commerce\Enums\CertificateExpiryType;
use App\Contexts\Commerce\Enums\CommercePermission;
use App\Contexts\Commerce\Enums\PricingBasis;
use App\Contexts\Commerce\Enums\ProductAudience;
use App\Contexts\Commerce\Enums\ProductStatus;
use App\Contexts\Commerce\Enums\ProductType;
use App\Contexts\Commerce\Enums\SeatMode;
use App\Contexts\Commerce\Models\ContractTemplate;
use App\Contexts\Commerce\Models\Product;
use App\Domains\Catalog\Models\Course;
use App\Platform\Shared\Helpers\Slug;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds commerce permissions, the active terms contract, a product per published course, and one
 * demo bundle an individual can buy.
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
        $published = Course::query()->where('status', 'published')->orderBy('id')->get();

        $published->each(function (Course $course): void {
            $product = Product::firstOrCreate(
                ['slug' => Slug::make($course->title).'-product'],
                ['type' => ProductType::Course->value, 'title' => $course->title, 'status' => ProductStatus::Active->value],
            );
            $product->courses()->syncWithoutDetaching([$course->id]);
            if ($product->prices()->doesntExist()) {
                $product->prices()->create(['currency' => 'SAR', 'amount_minor' => 19900, 'is_default' => true]);
            }
        });

        $this->seedIndividualBundle($published);
    }

    /**
     * One bundle a person can buy for themselves.
     *
     * Every bundle in the local catalogue sold by the seat, and a per-seat price is a company price,
     * so nothing on /bundles was buyable by an individual at all. This is the other half of the
     * bundle rule made real in demo data: audience `individual`, one fixed price for the whole
     * package, no seats — the shape that grants the buyer every included course outright.
     *
     * Keyed by slug and skipped unless its courses are actually in the catalogue, so it never
     * appears half-built and never lands on a database seeded with different content (a factory-made
     * test catalogue, for instance). Idempotent like the course products above: re-running adds
     * nothing and rewrites no price an admin has since changed.
     *
     * @param  Collection<int, Course>  $published
     */
    private function seedIndividualBundle(Collection $published): void
    {
        // Filtered from the courses already loaded above rather than queried again: Commerce reaches
        // into Catalog exactly once here, and that one crossing is the one the architecture rules
        // already account for.
        $courses = $published
            ->whereIn('slug', ['project-management-foundations', 'agile-scrum-in-practice', 'leadership-for-new-managers'])
            ->values();

        // A "bundle" of one course is just a course. Nothing to sell here.
        if ($courses->count() < 2) {
            return;
        }

        $bundle = Product::firstOrCreate(
            ['slug' => 'management-essentials-bundle'],
            [
                'type' => ProductType::Bundle->value,
                'title' => 'Management Essentials Bundle',
                'description' => 'Three foundations for a first management role — planning, delivery and leading people.',
                'status' => ProductStatus::Active->value,
                'audience' => ProductAudience::Individual->value,
                // One price buys the package. Seats are a company idea and this is not sold to
                // companies, so there is nothing for a seat mode to mean.
                'pricing_basis' => PricingBasis::FixedBundlePrice->value,
                'seat_mode' => SeatMode::NotApplicable->value,
                // Access and certificate terms come from the same admin-controlled policy fields the
                // product form edits — not from anything special to seeding.
                'access_duration_type' => AccessDurationType::FixedMonths->value,
                'access_duration_value' => 12,
                'certificate_enabled' => true,
                'certificate_expiry_type' => CertificateExpiryType::FixedYears->value,
                'certificate_expiry_value' => 2,
            ],
        );

        $bundle->courses()->syncWithoutDetaching($courses->pluck('id')->all());

        if ($bundle->prices()->doesntExist()) {
            // Under the sum of the three courses bought separately, which is the reason to buy a
            // bundle at all.
            $bundle->prices()->create(['currency' => 'SAR', 'amount_minor' => 49900, 'is_default' => true]);
        }
    }
}
