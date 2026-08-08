<?php

namespace App\Domains\Certification\Contracts;

use App\Domains\Certification\Pdf\Data\PdfRenderOptions;
use App\Domains\Certification\Pdf\Data\PdfResult;

/**
 * Renders HTML into PDF bytes. Only concrete adapters reference a rendering engine; domain code
 * depends on this contract. Resolved by config (fake | browsershot).
 *
 * Orientation / page size / direction are passed via PdfRenderOptions. The parameter is optional
 * and defaults to landscape/A4/ltr, so existing single-argument callers keep working unchanged.
 */
interface PdfGenerator
{
    public function render(string $html, PdfRenderOptions $options = new PdfRenderOptions()): PdfResult;
}
