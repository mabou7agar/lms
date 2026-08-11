<?php

namespace App\Platform\Identity\Console\Commands;

use App\Platform\Identity\Services\OpenApiSpecGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Writes the developer-API OpenAPI 3.1 document to a JSON file so it can be committed, published to
 * a docs portal, or diffed in CI. Same generator as the served endpoint, so file and endpoint can
 * never disagree. Registered by IdentityServiceProvider (not auto-discovered).
 */
class ExportOpenApiCommand extends Command
{
    protected $signature = 'identity:openapi-export {--path= : Destination file path (default: storage/app/openapi.json)}';

    protected $description = 'Write the public developer-API OpenAPI 3.1 document to a JSON file.';

    public function handle(OpenApiSpecGenerator $generator): int
    {
        $path = (string) ($this->option('path') ?: storage_path('app/openapi.json'));

        $json = json_encode(
            $generator->generate(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $json.PHP_EOL);

        $this->info("OpenAPI document written to {$path}");

        return self::SUCCESS;
    }
}
