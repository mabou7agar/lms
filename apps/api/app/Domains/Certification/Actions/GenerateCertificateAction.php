<?php

namespace App\Domains\Certification\Actions;

use App\Domains\Catalog\Models\Course;
use App\Domains\Certification\Enums\CertificateStatus;
use App\Domains\Certification\Events\CertificateIssued;
use App\Domains\Certification\Models\Certificate;
use App\Domains\Certification\Models\CertificateSetting;
use App\Domains\Certification\Models\CertificateTemplate;
use App\Domains\Certification\Services\CertificateNumberService;
use App\Domains\Certification\Services\SignatureService;
use App\Domains\Certification\Services\VerificationCodeService;
use App\Platform\Shared\Actions\BaseAction;
use App\Platform\Shared\Analytics\AnalyticsEventName;
use App\Platform\Shared\Analytics\Contracts\AnalyticsEventRecorder;
use App\Platform\Shared\Analytics\Data\AnalyticsEventInput;
use App\Platform\Shared\Commerce\Contracts\CertificatePolicyPort;

/**
 * Issues a certificate for a completed course. Idempotent per (user, course): a repeated
 * CourseCompleted never mints a duplicate. PDF is rendered lazily (EnsureCertificatePdfAction).
 *
 * Whether a certificate is included at all, when it lapses, and whose marks it carries are
 * COMMERCIAL facts, so they are asked of the Shared certificate-policy port rather than decided
 * here. Certification stores the answer and never learns what a product or a seat pool is. A course
 * nobody sells resolves to the unrestricted default, so free courses behave exactly as before.
 *
 * Returns null when the policy says no certificate is included — the caller must treat that as a
 * legitimate outcome, not a failure.
 */
class GenerateCertificateAction extends BaseAction
{
    public function __construct(
        private readonly CertificateNumberService $numbers,
        private readonly VerificationCodeService $codes,
        private readonly SignatureService $signatures,
        private readonly CertificatePolicyPort $policies,
        private readonly AnalyticsEventRecorder $analytics,
    ) {}

    public function executeByUserId(int $userId, Course $course, ?int $enrollmentId = null): ?Certificate
    {
        $issuedAt = now();
        $policy = $this->policies->certificatePolicyFor($userId, (int) $course->id, $issuedAt->toIso8601String());

        [$certificate, $created] = $this->transaction(function () use ($userId, $course, $enrollmentId, $policy, $issuedAt): array {
            $existing = Certificate::where('user_id', $userId)
                ->where('course_id', $course->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return [$existing, false];
            }

            // The product this course is sold under does not include a credential. Nothing is
            // written: an empty certificate would be worse than none, and the learner's course page
            // says plainly that no certificate is included.
            if (! $policy->enabled) {
                return [null, false];
            }

            $settings = CertificateSetting::current();
            $template = CertificateTemplate::where('is_active', true)->orderByDesc('version')->first();

            $certificate = Certificate::create([
                'user_id' => $userId,
                'course_id' => $course->id,
                'enrollment_id' => $enrollmentId,
                'template_id' => $template?->id ?? $settings->default_template_id,
                'template_version' => $template?->version,
                'number' => $this->numbers->next(),
                'verification_code' => $this->codes->generate(),
                'status' => CertificateStatus::Issued->value,
                'signature_name' => $settings->signature_name,
                'signature_title' => $settings->signature_title,
                'rendered_snapshot' => $this->snapshotOf($template),
                'issued_at' => $issuedAt,
                'expires_at' => $policy->expiresAt,
                // Company context, snapshotted: the credential must still name the right company
                // years later, whatever happens to the organization record afterwards.
                'organization_id' => $policy->organizationId,
                'company_name' => $policy->companyName,
                'company_logo_url' => $policy->companyLogoUrl,
                'branding_mode' => $policy->brandingMode,
            ]);

            // The signature payload is number|verification_code|user_id|course_id|issued_at ONLY —
            // the snapshot is deliberately excluded so it never affects tamper-evidence.
            $certificate->forceFill(['signature_hash' => $this->signatures->hash($certificate)])->save();

            return [$certificate, true];
        });

        if ($created && $certificate instanceof Certificate) {
            $this->analytics->record(new AnalyticsEventInput(
                name: AnalyticsEventName::CertificateIssued->value,
                userId: $userId,
                organizationId: $policy->organizationId,
                courseId: (int) $course->id,
                metadata: ['company_branded' => $certificate->isCompanyBranded()],
                dedupKey: 'certificate_issued:'.$certificate->public_id,
            ));

            CertificateIssued::dispatch($certificate);
        }

        return $certificate;
    }

    /**
     * Freeze the template body (localized map or legacy scalar) + design + orientation at issuance,
     * so a later edit to the live template never mutates this certificate's rendered document.
     *
     * @return array{html: array<string, mixed>|string, design: array<string, mixed>, orientation: string, version: int}|null
     */
    private function snapshotOf(?CertificateTemplate $template): ?array
    {
        if ($template === null) {
            return null;
        }

        // The template's own property types now say what this is, so the annotation that used to
        // stand in for them would only be a weaker restatement.
        $html = is_array($template->html_i18n) && $template->html_i18n !== []
            ? $template->html_i18n
            : (string) $template->html;

        /** @var array<string, mixed> $design */
        $design = is_array($template->design) ? $template->design : [];

        return [
            'html' => $html,
            'design' => $design,
            'orientation' => (string) ($template->orientation ?: 'landscape'),
            'version' => (int) $template->version,
        ];
    }
}
