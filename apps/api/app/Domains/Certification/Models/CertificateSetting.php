<?php

namespace App\Domains\Certification\Models;

use App\Platform\Shared\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $issuer_name
 * @property string|null $signature_image_path
 */
class CertificateSetting extends Model
{
    use HasTranslations;

    protected $fillable = [
        'issuer_name', 'issuer_name_i18n', 'signature_name', 'signature_name_i18n', 'signature_title',
        'signature_title_i18n', 'signature_image_path', 'default_template_id',
    ];

    /** @var array<int, string> */
    protected array $translatable = ['issuer_name_i18n', 'signature_name_i18n', 'signature_title_i18n'];

    protected function casts(): array
    {
        return [
            'issuer_name_i18n' => 'array',
            'signature_name_i18n' => 'array',
            'signature_title_i18n' => 'array',
        ];
    }

    public static function current(): self
    {
        return static::firstOrCreate([], ['issuer_name' => (string) config('certification.issuer.name')]);
    }
}
