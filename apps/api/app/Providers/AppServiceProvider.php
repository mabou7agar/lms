<?php

namespace App\Providers;

use App\Console\Commands\ValidateProductionConfigCommand;
use App\Platform\Shared\Config\ProductionConfigValidator;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->registerQueueFailureLogging();
        $this->commands([ValidateProductionConfigCommand::class]);
        $this->guardProductionConfig();
    }

    /**
     * Fail fast: a production WEB process must not serve traffic on an unsafe configuration. Scoped
     * to the HTTP path (`! runningInConsole()`) so console commands — including `config:validate`
     * itself, migrations and the deploy pipeline — still run and can report the problems. No-op
     * outside production, so local/testing are unaffected.
     */
    private function guardProductionConfig(): void
    {
        $this->app->booted(function (): void {
            if (! $this->app->environment('production') || $this->app->runningInConsole()) {
                return;
            }

            $errors = $this->app->make(ProductionConfigValidator::class)->criticalErrors();
            if ($errors !== []) {
                throw new RuntimeException('Unsafe production configuration: '.implode(' | ', $errors));
            }
        });
    }

    /**
     * Dead-letter visibility (Sprint 5): a single, domain-agnostic hook that records EVERY job that
     * exhausts its retries and lands in `failed_jobs`. Before this the queue had no generic failure
     * signal — a job could die permanently and nothing outside its own `failed()` handler noticed.
     * Logged at error level (structured, metadata only) so the container log channel surfaces it to
     * alerting. It observes the queue; it changes no job behavior and touches no domain code.
     */
    private function registerQueueFailureLogging(): void
    {
        Queue::failing(function (JobFailed $event): void {
            $job = $event->job;

            Log::error('queue.job_failed', [
                'job' => $job->resolveName(),
                'connection' => $event->connectionName,
                'queue' => $job->getQueue(),
                'uuid' => $job->uuid(),
                'attempts' => $job->attempts(),
                'exception_class' => $event->exception::class,
                'exception_message' => $event->exception->getMessage(),
            ]);
        });
    }
}
