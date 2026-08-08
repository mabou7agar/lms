<?php

namespace App\Platform\Shared\Moderation\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Wires the shared moderation substrate. Its only job is to register the content_reports migration
 * so the substrate is self-contained and NOT coupled to whichever domain (Reviews, Qna, Forum)
 * happens to be the first consumer. The ModerationQueueResource is auto-discovered by the admin
 * panel from Platform/Shared/Filament/Resources (already in AdminPanelProvider::RESOURCE_PATHS), so
 * no Filament wiring lives here.
 *
 * INTEGRATOR: register this provider ONCE in bootstrap/providers.php (after SharedServiceProvider).
 */
class ModerationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }
}
