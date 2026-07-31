<?php

namespace App\Console\Commands;

use App\Platform\Shared\Config\ProductionConfigValidator;
use Illuminate\Console\Command;

/**
 * Deploy gate: `php artisan config:validate`. Exits non-zero (with an actionable list) when the
 * runtime configuration is unsafe for production, so CI and the deploy script can block a bad
 * release BEFORE it serves traffic. Prints only variable names, never secret values.
 */
class ValidateProductionConfigCommand extends Command
{
    protected $signature = 'config:validate {--strict : Treat advisory problems as failures too}';

    protected $description = 'Validate that the runtime configuration is safe for a production deployment.';

    public function handle(ProductionConfigValidator $validator): int
    {
        $problems = $this->option('strict') ? $validator->errors() : $validator->criticalErrors();

        if ($problems === []) {
            $this->info('Configuration OK'.($this->option('strict') ? ' (strict)' : '').'.');

            return self::SUCCESS;
        }

        $this->error('Production configuration is NOT safe:');
        foreach ($problems as $problem) {
            $this->line('  - '.$problem);
        }

        return self::FAILURE;
    }
}
