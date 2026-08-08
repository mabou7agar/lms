<?php

namespace App\Platform\Branding\Models;

use App\Platform\Branding\Database\Factories\BrandSettingFactory;
use App\Platform\Shared\Media\Contracts\PublicAssetUrlResolver;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The single-row white-label / branding settings record. Mirrors the CertificateSetting singleton
 * pattern: current() = firstOrCreate([]). Every configurable surface is a JSON group cast to array
 * (identity, logos, theme, email, certificate). Stored values are always merged OVER the built-in
 * defaults() so the public API (toPublicArray) returns a complete, render-ready payload even when
 * the admin has set nothing — the frontend can theme the whole site from it and never breaks.
 *
 * Presentation only: no secrets, no credentials. All fields are safe to expose publicly.
 *
 * @property int $id
 * @property string $public_id
 * @property array<string, mixed>|null $identity
 * @property array<string, mixed>|null $logos
 * @property array<string, mixed>|null $theme
 * @property array<string, mixed>|null $email
 * @property array<string, mixed>|null $certificate
 */
class BrandSetting extends Model
{
    /** @use HasFactory<BrandSettingFactory> */
    use HasFactory;

    use HasPublicId;

    protected $fillable = ['identity', 'logos', 'theme', 'email', 'certificate'];

    protected function casts(): array
    {
        return [
            'identity' => 'array',
            'logos' => 'array',
            'theme' => 'array',
            'email' => 'array',
            'certificate' => 'array',
        ];
    }

    protected static function newFactory(): BrandSettingFactory
    {
        return BrandSettingFactory::new();
    }

    /**
     * The singleton accessor. Creates the row on first use (mirror of CertificateSetting::current).
     */
    public static function current(): self
    {
        return static::firstOrCreate([]);
    }

    /**
     * Built-in defaults for every group. Colors mirror the current apps/web globals.css OKLCH design
     * tokens (light + `.dark`), brand is "HElbaron", locale defaults are MENA-oriented (SAR, en,
     * Asia/Riyadh). These guarantee the frontend always receives a full, on-brand set.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function defaults(): array
    {
        return [
            'identity' => [
                'brand_name' => ['en' => 'HElbaron', 'ar' => 'إلبارون'],
                'short_name' => 'HElbaron',
                'company_name' => 'HElbaron Academy',
                'copyright' => [
                    'en' => 'All rights reserved.',
                    'ar' => 'جميع الحقوق محفوظة.',
                ],
                'address' => [
                    'en' => 'Cairo · Dubai · Riyadh',
                    'ar' => 'القاهرة · دبي · الرياض',
                ],
                'support_email' => 'support@helbaron.com',
                'support_phone' => '',
                'social_links' => [
                    'twitter' => '',
                    'linkedin' => '',
                    'facebook' => '',
                    'instagram' => '',
                    'youtube' => '',
                ],
                'default_language' => 'en',
                'timezone' => 'Asia/Riyadh',
                'currency' => 'SAR',
                'date_format' => 'd M Y',
                'time_format' => 'H:i',
            ],
            'logos' => [
                'logo_light' => '',
                'logo_dark' => '',
                'favicon' => '',
                'apple_icon' => '',
                'pwa_icon' => '',
                'email_logo' => '',
                'certificate_logo' => '',
                'loader' => '',
                'login_background' => '',
            ],
            'theme' => [
                'colors' => [
                    'primary' => 'oklch(0.36 0.045 185)',
                    'secondary' => 'oklch(0.91 0.03 86)',
                    'accent' => 'oklch(0.90 0.035 70)',
                    'success' => 'oklch(0.55 0.11 165)',
                    'warning' => 'oklch(0.74 0.12 82)',
                    'danger' => 'oklch(0.55 0.19 30)',
                    'info' => 'oklch(0.60 0.11 240)',
                    'background' => 'oklch(0.962 0.017 88)',
                    'surface' => 'oklch(0.99 0.008 88)',
                    'sidebar' => 'oklch(0.36 0.045 185)',
                    'header' => 'oklch(0.962 0.017 88)',
                    'footer' => 'oklch(0.36 0.045 185)',
                ],
                'radius' => '0.75rem',
                'container_width' => '72rem',
                'shadow_preset' => 'soft',
                'font_body' => 'Inter',
                'font_heading' => 'Fraunces',
                'google_font' => '',
                'spacing_scale' => 'default',
                'dark' => [
                    'primary' => 'oklch(0.62 0.07 183)',
                    'secondary' => 'oklch(0.30 0.03 190)',
                    'accent' => 'oklch(0.33 0.035 60)',
                    'success' => 'oklch(0.68 0.12 165)',
                    'warning' => 'oklch(0.80 0.13 84)',
                    'danger' => 'oklch(0.62 0.18 28)',
                    'info' => 'oklch(0.66 0.11 240)',
                    'background' => 'oklch(0.21 0.022 190)',
                    'surface' => 'oklch(0.25 0.026 190)',
                    'sidebar' => 'oklch(0.25 0.026 190)',
                    'header' => 'oklch(0.21 0.022 190)',
                    'footer' => 'oklch(0.25 0.026 190)',
                ],
                'preset' => 'helbaron',
            ],
            'email' => [
                'header' => ['en' => '', 'ar' => ''],
                'footer' => [
                    'en' => 'HElbaron Academy — Master the core. Lead the future.',
                    'ar' => 'أكاديمية إلبارون — أتقن الأساس. قُد المستقبل.',
                ],
                'colors' => [
                    'background' => '#F7F1E3',
                    'text' => '#21302E',
                    'button' => '#134E4A',
                ],
                'signature' => [
                    'en' => 'The HElbaron Team',
                    'ar' => 'فريق إلبارون',
                ],
                'social_links' => [
                    'twitter' => '',
                    'linkedin' => '',
                    'facebook' => '',
                    'instagram' => '',
                    'youtube' => '',
                ],
            ],
            'certificate' => [
                'background' => '',
                'logo' => '',
                'signature' => '',
                'stamp' => '',
                'qr_position' => 'bottom-right',
                'font' => 'Fraunces',
                'colors' => [
                    'text' => '#21302E',
                    'accent' => '#134E4A',
                ],
                'margins' => [
                    'top' => 48,
                    'right' => 48,
                    'bottom' => 48,
                    'left' => 48,
                ],
            ],
        ];
    }

    /**
     * The public-safe branding + theme payload: each group's stored values deep-merged over
     * defaults() so callers always get a complete set. Null/absent stored keys keep the default,
     * which is what lets the frontend fall back per-value for partial settings.
     *
     * P1: every logo key and the certificate image keys (background/logo/signature/stamp) may hold a
     * MediaAsset public_id reference (chosen via the MediaPicker) OR a legacy URL/path. Those are
     * resolved to public URLs through PublicAssetUrlResolver — a PUBLIC asset yields a stable URL, a
     * legacy value passes through unchanged. Anything not resolvable (empty / private / missing)
     * collapses to '' so the group keeps its complete, render-ready shape and no raw reference or
     * storage key ever leaks. Field names are unchanged.
     *
     * @return array<string, array<string, mixed>>
     */
    public function toPublicArray(): array
    {
        $defaults = self::defaults();

        $logos = self::deepMergeDefined($defaults['logos'], $this->logos);
        $certificate = self::deepMergeDefined($defaults['certificate'], $this->certificate);

        return [
            'identity' => self::deepMergeDefined($defaults['identity'], $this->identity),
            'logos' => self::resolveMediaKeys($logos, array_keys($defaults['logos'])),
            'theme' => self::deepMergeDefined($defaults['theme'], $this->theme),
            'email' => self::deepMergeDefined($defaults['email'], $this->email),
            'certificate' => self::resolveMediaKeys($certificate, ['background', 'logo', 'signature', 'stamp']),
        ];
    }

    /**
     * Resolve the given media-reference keys of a branding group to public URLs, coalescing a null
     * resolution back to '' so the group stays a complete map (the frontend contract). Non-string and
     * absent keys are left untouched.
     *
     * @param  array<string, mixed>  $group
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    private static function resolveMediaKeys(array $group, array $keys): array
    {
        $resolver = app(PublicAssetUrlResolver::class);

        foreach ($keys as $key) {
            if (! array_key_exists($key, $group) || ! is_string($group[$key])) {
                continue;
            }

            $group[$key] = $resolver->resolve($group[$key]) ?? '';
        }

        return $group;
    }

    /**
     * Recursively overlay $override onto $default, skipping null values in $override so the default
     * always wins for anything the admin has not set. Associative-map semantics (branding groups
     * are maps, not lists) — this keeps the returned payload complete.
     *
     * @param  array<string, mixed>  $default
     * @param  array<string, mixed>|null  $override
     * @return array<string, mixed>
     */
    private static function deepMergeDefined(array $default, ?array $override): array
    {
        if ($override === null) {
            return $default;
        }

        $result = $default;

        foreach ($override as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_array($value) && isset($default[$key]) && is_array($default[$key])) {
                $result[$key] = self::deepMergeDefined($default[$key], $value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
