<?php

namespace App\Domains\Certification\Pdf\Providers;

use App\Domains\Certification\Contracts\PdfGenerator;
use App\Domains\Certification\Pdf\Data\PdfRenderOptions;
use App\Domains\Certification\Pdf\Data\PdfResult;

/**
 * Default generator for local/test. Produces a minimal valid-ish PDF byte stream (no headless
 * browser). Enough to store and stream; not a designed document. The requested orientation and
 * text direction are RECORDED in the byte stream so tests can assert the render pipeline honoured
 * the template's page setup without a real rendering engine.
 */
class FakePdfGenerator implements PdfGenerator
{
    public function render(string $html, PdfRenderOptions $options = new PdfRenderOptions): PdfResult
    {
        $text = trim(strip_tags($html));
        $bytes = "%PDF-1.4\n"
            ."% HElbaron fake certificate orientation={$options->orientation} size={$options->pageSize} dir={$options->direction}\n"
            .substr($text, 0, 2000)
            ."\n%%EOF";

        return new PdfResult(bytes: $bytes);
    }
}
