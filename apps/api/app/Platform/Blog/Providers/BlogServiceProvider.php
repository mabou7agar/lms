<?php

namespace App\Platform\Blog\Providers;

use App\Platform\Shared\Providers\BaseDomainServiceProvider;

/**
 * Wires the Blog CMS module: loads its migrations and the public/preview route file. A small,
 * self-contained Platform module — depends only on the Shared kernel (HtmlSanitizer / audit /
 * media resolver) and Identity contracts for the admin preview gate. The admin editor lives in
 * this module's Filament/Resources (auto-discovered by the panel). No cross-context coupling.
 */
class BlogServiceProvider extends BaseDomainServiceProvider
{
    /** @var array<int, string> */
    protected array $routeFiles = ['routes/blog.php'];

    protected function domainPath(): string
    {
        return dirname(__DIR__);
    }
}
