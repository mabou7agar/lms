<?php

namespace App\Domains\Certification\Services;

use App\Domains\Certification\Contracts\PdfGenerator;
use App\Domains\Certification\Models\Certificate;
use App\Domains\Certification\Models\CertificateTemplate;
use App\Domains\Certification\Pdf\Data\PdfRenderOptions;
use App\Platform\Shared\Helpers\LocaleHelper;
use App\Platform\Shared\I18n\TranslationResolver;
use App\Platform\Shared\Services\BaseService;

/**
 * Builds certificate HTML from the template + data, then renders PDF bytes via the PdfGenerator
 * abstraction. No storage concerns here.
 *
 * The token substitution is delegated to the constrained {@see CertificateVariableRenderer}
 * (allowlist + escaping). Rendering is:
 *   • SNAPSHOT-AWARE — an already-issued certificate renders from the frozen template body captured
 *     at issuance (visual immutability); a later edit to the live template never mutates it. Certs
 *     issued before the snapshot column existed fall back to the live template.
 *   • LOCALE-AWARE — the localized `html_i18n` value for the active locale is resolved (falling back
 *     to the legacy scalar), and RTL locales render with dir="rtl".
 *   • ORIENTATION-AWARE — the template's orientation/page setup is passed to the generator.
 */
class CertificateRenderService extends BaseService
{
    public function __construct(
        private readonly PdfGenerator $pdf,
        private readonly CertificateVariableRenderer $variables,
        private readonly TranslationResolver $translations,
    ) {}

    public function renderBytes(Certificate $certificate): string
    {
        $certificate->loadMissing(['course', 'template']);

        $locale = LocaleHelper::current();
        $source = $this->resolveSource($certificate);

        $html = $this->fill((string) $this->translations->resolve($source['html'], $locale), $certificate, $source['design'], $locale);

        $options = new PdfRenderOptions(
            orientation: $source['orientation'],
            direction: LocaleHelper::direction($locale),
            locale: $locale,
        );

        return $this->pdf->render($html, $options)->bytes;
    }

    /**
     * Resolve the (frozen or live) template body map, design assets, and orientation for a cert.
     *
     * @return array{html: array<string, mixed>|string, design: array<string, mixed>, orientation: string}
     */
    private function resolveSource(Certificate $certificate): array
    {
        $snapshot = $certificate->rendered_snapshot;

        if (is_array($snapshot) && isset($snapshot['html'])) {
            $html = $snapshot['html'];
            $orientation = $snapshot['orientation'] ?? null;

            /** @var array<string, mixed>|string $htmlOut */
            $htmlOut = is_array($html) || is_string($html) ? $html : '';

            /** @var array<string, mixed> $design */
            $design = is_array($snapshot['design'] ?? null) ? $snapshot['design'] : [];

            return [
                'html' => $htmlOut,
                'design' => $design,
                'orientation' => is_string($orientation) ? $orientation : 'landscape',
            ];
        }

        $template = $certificate->template ?? $this->fallbackTemplate();

        /** @var array<string, mixed> $design */
        $design = is_array($template->design) ? $template->design : [];

        return [
            'html' => $this->templateHtmlMap($template),
            'design' => $design,
            'orientation' => (string) ($template->orientation ?: 'landscape'),
        ];
    }

    /**
     * The template body as a locale => html map, so the TranslationResolver can pick the active
     * locale. Falls back to the legacy scalar `html` column for pre-localization rows.
     *
     * @return array<string, mixed>|string
     */
    private function templateHtmlMap(CertificateTemplate $template): array|string
    {
        if (is_array($template->html_i18n) && $template->html_i18n !== []) {
            return $template->html_i18n;
        }

        return (string) $template->html;
    }

    /**
     * @param  array<string, mixed>  $design
     */
    private function fill(string $html, Certificate $certificate, array $design, ?string $locale): string
    {
        return $this->variables->render($html, $certificate, $design, $locale);
    }

    private function fallbackTemplate(): CertificateTemplate
    {
        return CertificateTemplate::where('is_active', true)->orderByDesc('version')->firstOrNew([
            'html' => '<html><body>{{ holder_name }} — {{ course_title }} — {{ number }}</body></html>',
        ]);
    }
}
