<?php

namespace App\Platform\AI\Filament\Widgets;

use App\Platform\AI\Metering\AiUsageSummary;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * AI settings + spend at a glance: whether AI is on, the default provider/model, this month's
 * estimated spend, and global-quota usage. Reads config + the usage summary only — NEVER an API key
 * or any secret (it reports whether a provider is "configured", never the credential itself).
 */
class AiProviderStatusWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'AI status & spend';

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $enabled = (bool) config('ai.enabled', false);
        $default = (string) config('ai.default_provider', 'fake');
        $chatModel = (string) config('ai.providers.'.$default.'.chat_model', 'n/a');

        $overview = app(AiUsageSummary::class)->overview();
        $spend = '$'.number_format($overview['total_spend_micros'] / 1_000_000, 2);

        $globalLimit = $overview['quota']['global_monthly_tokens'];
        $globalUsed = $overview['quota']['global_used_tokens'];
        $quotaLabel = $globalLimit > 0
            ? number_format($globalUsed).' / '.number_format($globalLimit)
            : number_format($globalUsed).' (unlimited)';

        return [
            Stat::make('AI', $enabled ? 'Enabled' : 'Disabled')
                ->description('Global master switch')
                ->descriptionIcon($enabled ? 'heroicon-o-check-circle' : 'heroicon-o-no-symbol')
                ->color($enabled ? 'success' : 'gray'),

            Stat::make('Default provider', ucfirst($default))
                ->description('Model: '.$chatModel)
                ->descriptionIcon('heroicon-o-cpu-chip')
                ->color($default === 'fake' ? 'warning' : 'primary'),

            Stat::make('Spend this month', $spend)
                ->description($overview['calls'].' calls, '.number_format($overview['total_tokens']).' tokens')
                ->descriptionIcon('heroicon-o-currency-dollar'),

            Stat::make('Global monthly quota', $quotaLabel)
                ->description('Tokens used / limit')
                ->descriptionIcon('heroicon-o-chart-bar'),

            Stat::make('Configured providers', (string) $this->configuredProviders())
                ->description('Enabled provider adapters')
                ->descriptionIcon('heroicon-o-server-stack'),
        ];
    }

    private function configuredProviders(): int
    {
        /** @var array<string, array<string, mixed>> $providers */
        $providers = (array) config('ai.providers', []);
        $count = 0;

        foreach ($providers as $config) {
            if ((bool) ($config['enabled'] ?? false) === true) {
                $count++;
            }
        }

        return $count;
    }
}
