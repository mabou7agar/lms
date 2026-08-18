<?php

namespace App\Domains\Certification\Pdf\Data;

/**
 * Page-level rendering options handed to a PdfGenerator. Orientation + page size drive the
 * physical layout; direction lets an RTL (e.g. Arabic) document render correctly. Defaults
 * reproduce the historical behaviour (landscape / A4 / ltr) so existing single-argument
 * render() callers are unaffected.
 */
final readonly class PdfRenderOptions
{
    public function __construct(
        public string $orientation = 'landscape', // landscape | portrait
        public string $pageSize = 'A4',
        public string $direction = 'ltr',          // ltr | rtl
        public ?string $locale = null,
    ) {}

    public static function landscape(): self
    {
        return new self;
    }
}
