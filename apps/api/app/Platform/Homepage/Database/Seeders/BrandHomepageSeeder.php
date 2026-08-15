<?php

namespace App\Platform\Homepage\Database\Seeders;

use App\Platform\Homepage\Enums\BlockType;
use App\Platform\Homepage\Enums\HomepageStatus;
use App\Platform\Homepage\Models\HomepageSection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the PUBLIC homepage as an ordered list of `brand_section` blocks that reproduces the current
 * hardcoded fallback home EXACTLY — same sections, same order — but now reorderable / toggleable /
 * extendable from /admin. Each brand_section is purely presentational: its content is just the key of
 * the built-in bespoke section the frontend renders (see BrandSectionBlock). No visual change.
 *
 * IMPORTANT: persona-paths / home-analytics are rendered by the page shell (outside the CMS body), so
 * they are deliberately NOT seeded as brand_section — that would double-render. The SEO block (page
 * metadata) and the Footer block (consumed by the page shell's LandingFooter, not the ordered body)
 * are re-seeded from defaults so <head> and the footer stay intact after the wipe.
 *
 * Idempotent: wipes all homepage_sections (version history cascades) and re-inserts inside one
 * transaction, so it is safe to re-run and always converges on this exact ordered set.
 */
class BrandHomepageSeeder extends Seeder
{
    /**
     * The built-in brand home sections, in the exact fallback render order. Keys mirror the frontend
     * BrandSectionBlock key map; positions leave gaps (10,20,…) so admins can slot new blocks between.
     *
     * @var array<int, string>
     */
    private const SECTIONS = [
        'hero',
        'proof_band',
        'trusted_by',
        'product_modes',
        'why_helbaron',
        'learning_experience',
        'learning_journey',
        'featured_courses',
        'testimonials',
        'instructors',
        'enterprise_trust',
        'final_cta',
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            // Wipe-and-reinsert: the homepage becomes exactly this ordered brand_section set. Version
            // rows cascade on delete (homepage_section_versions.homepage_section_id).
            HomepageSection::query()->delete();

            $position = 10;
            foreach (self::SECTIONS as $key) {
                $content = ['key' => $key];

                HomepageSection::create([
                    'key' => 'brand_'.$key,
                    'type' => BlockType::BrandSection,
                    'position' => $position,
                    'is_enabled' => true,
                    'status' => HomepageStatus::Published,
                    'content' => $content,
                    'published_content' => $content,
                    'published_at' => now(),
                ]);

                $position += 10;
            }

            // Re-seed the page-shell blocks consumed OUTSIDE the ordered body: SEO (page <head>
            // metadata) and Footer (rendered by LandingFooter). Both use their brand-default content so
            // nothing changes visually, and both stay admin-editable.
            $defaults = HomepageSection::defaults();
            foreach (['seo', 'footer'] as $shellKey) {
                $definition = $defaults[$shellKey];
                $position += 10;

                HomepageSection::create([
                    'key' => $shellKey,
                    'type' => $definition['type'],
                    'position' => $position,
                    'is_enabled' => true,
                    'status' => HomepageStatus::Published,
                    'content' => $definition['content'],
                    'published_content' => $definition['content'],
                    'published_at' => now(),
                ]);
            }
        });
    }
}
