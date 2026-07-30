<?php

namespace App\Contexts\Commerce\Console\Commands;

use App\Contexts\Commerce\Enums\OrderStatus;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Payments\Recovery\PaymentRecoveryService;
use Illuminate\Console\Command;

/**
 * Dunning worker: finds failed orders still inside the recovery window and asks the recovery
 * service to re-initiate payment for each. Idempotent per run — the service enforces the
 * max-attempts ceiling and per-attempt backoff, so running this hourly simply advances each
 * eligible order at most one step. Scheduled hourly.
 */
class RetryFailedPaymentsCommand extends Command
{
    protected $signature = 'commerce:retry-failed-payments';

    protected $description = 'Retry payment for failed orders still within the dunning window.';

    public function handle(PaymentRecoveryService $recovery): int
    {
        $windowHours = max(1, (int) config('commerce.dunning.window_hours', 72));
        $cutoff = now()->subHours($windowHours);

        $retried = 0;

        Order::query()
            ->where('status', OrderStatus::Failed->value)
            ->where('placed_at', '>=', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($orders) use ($recovery, &$retried): void {
                foreach ($orders as $order) {
                    if ($recovery->retryFailed($order) !== null) {
                        $retried++;
                    }
                }
            });

        $this->info("Retried {$retried} failed order(s).");

        return self::SUCCESS;
    }
}
