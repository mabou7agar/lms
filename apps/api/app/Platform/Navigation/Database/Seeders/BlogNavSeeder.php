<?php

namespace App\Platform\Navigation\Database\Seeders;

use App\Platform\Navigation\Enums\MenuLocation;
use App\Platform\Navigation\Enums\NavAuthVisibility;
use App\Platform\Navigation\Enums\NavUrlType;
use App\Platform\Navigation\Models\NavItem;
use App\Platform\Navigation\Models\NavMenu;
use Illuminate\Database\Seeder;

/**
 * Adds a "Blog" item to the public-header navigation (DB-driven), positioned after the existing
 * items. Internal relative URL (/blog), visible to everyone. Idempotent: firstOrCreate keyed by
 * (menu_id, parent_id, position), mirroring NavigationSeeder so re-running never duplicates.
 */
class BlogNavSeeder extends Seeder
{
    public function run(): void
    {
        // The public header must exist (seeded by NavigationSeeder); create the menu row if missing.
        $menu = NavMenu::firstOrCreate(
            ['location' => MenuLocation::PublicHeader->value],
            ['is_active' => true],
        );

        NavItem::firstOrCreate(
            [
                'menu_id' => $menu->id,
                'parent_id' => null,
                'position' => 70,
            ],
            [
                'label' => ['en' => 'Blog', 'ar' => 'المدوّنة'],
                'url_type' => NavUrlType::Internal->value,
                'url' => '/blog',
                'icon' => null,
                'is_enabled' => true,
                'open_new_tab' => false,
                'visibility_auth' => NavAuthVisibility::Any->value,
                'visibility_roles' => null,
            ],
        );
    }
}
