<?php

namespace App\Domains\Certification\Services;

use App\Domains\Certification\Models\Certificate;
use App\Domains\Certification\Models\CertificateSetting;
use App\Platform\Identity\Contracts\UserLookupPort;
use App\Platform\Shared\Helpers\Uuid;
use App\Platform\Shared\Media\Contracts\MediaPickerPort;
use App\Platform\Shared\Services\BaseService;
use Throwable;

/**
 * Constrained, safe token-substitution engine for certificate templates. Replaces the previous
 * blind strtr with a fixed ALLOWLIST:
 *
 *   • Text values (holder/learner name, course title, number, dates, instructor names, score,
 *     custom signature text) are HTML-ESCAPED — a malicious course title can never inject markup.
 *   • Image/QR values (qr, company logo, signature images, background) are TRUSTED, server-generated
 *     markup and are injected verbatim (they are built here, never authored by a designer).
 *   • Any unknown / unsupported `{{ token }}` renders to an EMPTY string — an unresolved token is
 *     never leaked literally into the document.
 *
 * Backward compatibility: every historical token keeps working, and each has a friendlier alias
 * (holder_name/learner_name, number/certificate_number, verify_url/verification_url,
 * qr_svg/qr_code, issued_at/issued_date).
 */
class CertificateVariableRenderer extends BaseService
{
    public function __construct(
        private readonly QrCodeService $qr,
        private readonly VerificationUrlService $urls,
        private readonly UserLookupPort $users,
    ) {}

    /**
     * Fill a template body with a real certificate's values.
     *
     * @param  array<string, mixed>  $design  Designer image references (company_logo, signature_image,
     *                                         background_image, signature_2_*), from the template/snapshot.
     */
    public function render(string $html, Certificate $certificate, array $design = [], ?string $locale = null): string
    {
        return $this->apply($html, $this->resolveForCertificate($certificate, $design));
    }

    /**
     * Fill a template body with representative SAMPLE values through the exact same allowlist path.
     * Used by the admin PREVIEW so a designer sees a faithful, safe render without issuing anything.
     *
     * @param  array<string, mixed>  $design
     */
    public function renderSample(string $html, array $design = []): string
    {
        return $this->apply($html, $this->resolveSample($design));
    }

    /**
     * Substitute every `{{ token }}` occurrence with its resolved value; unknown tokens collapse to
     * empty. Flexible inner whitespace is tolerated. This single regex pass guarantees no literal
     * token can survive into the output.
     *
     * @param  array<string, string>  $resolved  Already-escaped text / trusted markup, keyed by bare token.
     */
    private function apply(string $html, array $resolved): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            static fn (array $m): string => $resolved[$m[1]] ?? '',
            $html,
        );
    }

    /**
     * @param  array<string, mixed>  $design
     * @return array<string, string>
     */
    private function resolveForCertificate(Certificate $certificate, array $design): array
    {
        $certificate->loadMissing(['course']);
        $settings = CertificateSetting::current();
        $verifyUrl = $this->urls->forCertificate($certificate);

        /** @var array<string, mixed> $metadata */
        $metadata = is_array($certificate->metadata) ? $certificate->metadata : [];

        $text = [
            'holder_name' => (string) ($this->users->refById($certificate->user_id)?->name ?? ''),
            'course_title' => (string) ($certificate->course?->title ?? ''),
            'number' => (string) $certificate->number,
            'verification_code' => (string) $certificate->verification_code,
            'verify_url' => $verifyUrl,
            'issuer_name' => (string) $settings->issuer_name,
            'signature_name' => (string) $certificate->signature_name,
            'signature_title' => (string) $certificate->signature_title,
            'issued_at' => (string) (optional($certificate->issued_at)->toFormattedDateString() ?? ''),
            // Nullable extras — sourced from the certificate's own metadata snapshot because no
            // Shared port exposes course-instructor names or an enrollment score. Blank when absent.
            'score' => $this->scalar($metadata['score'] ?? null),
            'instructor_names' => $this->names($metadata['instructor_names'] ?? null),
            'signature_2_name' => $this->scalar($design['signature_2_name'] ?? null),
            'signature_2_title' => $this->scalar($design['signature_2_title'] ?? null),
        ];

        $markup = [
            'qr_svg' => $this->qr->svgFor($verifyUrl),
            'company_logo' => $this->imageMarkup($design['company_logo'] ?? null, 'Logo'),
            'background_image' => $this->imageMarkup($design['background_image'] ?? null, ''),
            'signature_image' => $this->imageMarkup(
                $design['signature_image'] ?? $settings->signature_image_path,
                'Signature',
            ),
            'signature_2_image' => $this->imageMarkup($design['signature_2_image'] ?? null, 'Signature'),
        ];

        return $this->assemble($text, $markup);
    }

    /**
     * @param  array<string, mixed>  $design
     * @return array<string, string>
     */
    private function resolveSample(array $design): array
    {
        $verifyUrl = $this->urls->forCode('SAMPLE-VERIFY-CODE');

        $text = [
            'holder_name' => 'Sample Learner',
            'course_title' => 'Sample Course Title',
            'number' => 'CERT-SAMPLE-0001',
            'verification_code' => 'SAMPLE-VERIFY-CODE',
            'verify_url' => $verifyUrl,
            'issuer_name' => (string) CertificateSetting::current()->issuer_name,
            'signature_name' => 'Academy Director',
            'signature_title' => 'Director',
            'issued_at' => now()->toFormattedDateString(),
            'score' => '95%',
            'instructor_names' => 'Jane Instructor, John Trainer',
            'signature_2_name' => $this->scalar($design['signature_2_name'] ?? 'Second Signatory'),
            'signature_2_title' => $this->scalar($design['signature_2_title'] ?? 'Head of Program'),
        ];

        $markup = [
            'qr_svg' => $this->qr->svgFor($verifyUrl),
            'company_logo' => $this->imageMarkup($design['company_logo'] ?? null, 'Logo'),
            'background_image' => $this->imageMarkup($design['background_image'] ?? null, ''),
            'signature_image' => $this->imageMarkup($design['signature_image'] ?? null, 'Signature'),
            'signature_2_image' => $this->imageMarkup($design['signature_2_image'] ?? null, 'Signature'),
        ];

        return $this->assemble($text, $markup);
    }

    /**
     * Escape all text values, keep image/QR markup trusted, then add legacy aliases.
     *
     * @param  array<string, string>  $text
     * @param  array<string, string>  $markup
     * @return array<string, string>
     */
    private function assemble(array $text, array $markup): array
    {
        $resolved = [];

        foreach ($text as $token => $value) {
            $resolved[$token] = e($value); // HTML-escape every text substitution.
        }

        foreach ($markup as $token => $value) {
            $resolved[$token] = $value;    // Trusted, server-generated markup — never escaped.
        }

        // Backward-compatible aliases (both spellings resolve identically).
        $resolved['learner_name'] = $resolved['holder_name'];
        $resolved['certificate_number'] = $resolved['number'];
        $resolved['verification_url'] = $resolved['verify_url'];
        $resolved['issued_date'] = $resolved['issued_at'];
        $resolved['qr_code'] = $resolved['qr_svg'];

        return $resolved;
    }

    /**
     * Build a trusted <img> for a stored media value: a MediaPicker reference (UUID public_id) is
     * resolved to a short-lived signed URL through the Shared MediaPickerPort; a legacy URL/path is
     * used as-is. The resolved URL is attribute-escaped even though the tag itself is trusted. An
     * empty/unresolvable value renders to an empty string.
     */
    private function imageMarkup(mixed $ref, string $alt): string
    {
        $url = $this->resolveMediaUrl($ref);

        if ($url === null || $url === '') {
            return '';
        }

        return '<img src="'.htmlspecialchars($url, ENT_QUOTES).'" alt="'.htmlspecialchars($alt, ENT_QUOTES).'" />';
    }

    private function resolveMediaUrl(mixed $ref): ?string
    {
        if (! is_string($ref) || trim($ref) === '') {
            return null;
        }

        if (Uuid::isValid($ref)) {
            if (! app()->bound(MediaPickerPort::class)) {
                return null;
            }

            try {
                return app(MediaPickerPort::class)->previewUrl($ref);
            } catch (Throwable) {
                return null;
            }
        }

        return $ref; // legacy URL / storage path
    }

    private function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function names(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(fn (mixed $v): string => $this->scalar($v), $value));
        }

        return $this->scalar($value);
    }
}
