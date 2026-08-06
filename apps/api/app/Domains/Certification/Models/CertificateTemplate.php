<?php

namespace App\Domains\Certification\Models;

use App\Domains\Certification\Database\Factories\CertificateTemplateFactory;
use App\Platform\Shared\Traits\HasPublicId;
use App\Platform\Shared\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CertificateTemplate extends Model
{
    /** @use HasFactory<CertificateTemplateFactory> */
    use HasFactory;

    use HasPublicId;
    use HasTranslations;

    protected $fillable = ['key', 'version', 'name', 'name_i18n', 'html', 'html_i18n', 'orientation', 'is_active'];

    /** @var array<int, string> */
    protected array $translatable = ['name_i18n', 'html_i18n'];

    /**
     * Rich-HTML translatable maps sanitized per locale on write (via HasTranslations). `name_i18n`
     * is plain text and is deliberately excluded.
     *
     * @var array<int, string>
     */
    protected array $translatableHtml = ['html_i18n'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_active' => 'boolean',
            'name_i18n' => 'array',
            'html_i18n' => 'array',
        ];
    }

    protected static function newFactory(): CertificateTemplateFactory
    {
        return CertificateTemplateFactory::new();
    }
}
