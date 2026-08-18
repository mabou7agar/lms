<?php

namespace App\Domains\Certification\Pdf\Providers;

use App\Domains\Certification\Contracts\PdfGenerator;
use App\Domains\Certification\Pdf\Data\PdfRenderOptions;
use App\Domains\Certification\Pdf\Data\PdfResult;
use RuntimeException;

/**
 * Real HTML→PDF via Browsershot (headless Chromium). The ONLY class permitted to reference the
 * rendering engine. Not wired yet — requires spatie/browsershot + Chromium; throws until then.
 * When wired, $options->orientation / pageSize map to Browsershot's landscape()/format().
 */
class BrowsershotPdfGenerator implements PdfGenerator
{
    public function render(string $html, PdfRenderOptions $options = new PdfRenderOptions): PdfResult
    {
        throw new RuntimeException('Browsershot PDF rendering is not configured (install spatie/browsershot + Chromium).');
    }
}
