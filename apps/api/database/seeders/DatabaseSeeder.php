<?php

namespace Database\Seeders;

use App\Contexts\Analytics\Database\Seeders\AnalyticsSeeder;
use App\Contexts\Commerce\Database\Seeders\CommerceSeeder;
use App\Contexts\Learning\Database\Seeders\LearningSeeder;
use App\Domains\Assessment\Database\Seeders\AssessmentSeeder;
use App\Domains\Authoring\Database\Seeders\AuthoringSeeder;
use App\Domains\Catalog\Database\Seeders\CatalogSeeder;
use App\Domains\Certification\Database\Seeders\CertificationSeeder;
use App\Domains\Crm\Database\Seeders\CrmSeeder;
use App\Domains\Live\Database\Seeders\LiveSeeder;
use App\Platform\AI\Database\Seeders\AiPromptSeeder;
use App\Platform\Branding\Database\Seeders\BrandingSeeder;
use App\Platform\Features\Database\Seeders\FeatureFlagsSeeder;
use App\Platform\Homepage\Database\Seeders\HomepageSeeder;
use App\Platform\Identity\Database\Seeders\IdentitySeeder;
use App\Platform\Navigation\Database\Seeders\NavigationSeeder;
use App\Platform\Notifications\Database\Seeders\NotificationsSeeder;
use App\Platform\Pages\Database\Seeders\StaticPagesSeeder;
use App\Platform\Seo\Database\Seeders\SeoSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            IdentitySeeder::class,
            CatalogSeeder::class,
            AuthoringSeeder::class,
            AssessmentSeeder::class,
            LearningSeeder::class,
            CommerceSeeder::class,
            CertificationSeeder::class,
            LiveSeeder::class,
            CrmSeeder::class,
            AnalyticsSeeder::class,
            NotificationsSeeder::class,
            // Versioned library prompts the AI tutor + copilot resolve at runtime (idempotent).
            AiPromptSeeder::class,
            StaffRoleTemplatesSeeder::class,
            HomepageSeeder::class,
            BrandingSeeder::class,
            NavigationSeeder::class,
            StaticPagesSeeder::class,
            FeatureFlagsSeeder::class,
            // Runs last: derives sitemap-enabled SEO rows from the content seeded above (courses,
            // categories, trainers, events, static pages) so the sitemap is non-empty on a seeded DB.
            SeoSeeder::class,
        ]);
    }
}
