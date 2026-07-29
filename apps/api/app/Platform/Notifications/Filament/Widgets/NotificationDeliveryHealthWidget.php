<?php

namespace App\Platform\Notifications\Filament\Widgets;

use App\Platform\Notifications\Enums\DeliveryStatus;
use App\Platform\Notifications\Models\NotificationDelivery;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * H5 — makes notification delivery outcomes visible in the admin panel. Read-only aggregate counts
 * over the delivery ledger (the authoritative source), with dead-letters surfaced prominently so a
 * growing backlog of buried failures cannot go unnoticed. No secrets, no content, no storage paths.
 */
class NotificationDeliveryHealthWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Notification delivery health';

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        /** @var array<string, int> $counts */
        $counts = NotificationDelivery::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(static fn ($value): int => (int) $value)
            ->all();

        $count = static fn (DeliveryStatus $status): int => $counts[$status->value] ?? 0;

        $dead = $count(DeliveryStatus::Dead);

        return [
            Stat::make('Sent', (string) $count(DeliveryStatus::Sent))
                ->description('Accepted by a provider')
                ->descriptionIcon('heroicon-o-check-circle')
                ->color('success'),

            Stat::make('Dead-lettered', (string) $dead)
                ->description('Exhausted all retries')
                ->descriptionIcon('heroicon-o-exclamation-triangle')
                ->color($dead > 0 ? 'danger' : 'gray'),

            Stat::make('Failed (config)', (string) $count(DeliveryStatus::FailedConfiguration))
                ->description('Required channel, no provider')
                ->descriptionIcon('heroicon-o-wrench-screwdriver')
                ->color('warning'),

            Stat::make('Skipped (disabled)', (string) $count(DeliveryStatus::SkippedDisabled))
                ->description('Channel off or unconfigured')
                ->descriptionIcon('heroicon-o-no-symbol')
                ->color('gray'),

            Stat::make('In flight', (string) ($count(DeliveryStatus::Pending) + $count(DeliveryStatus::Processing)))
                ->description('Queued or processing')
                ->descriptionIcon('heroicon-o-clock'),
        ];
    }
}
