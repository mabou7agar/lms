<?php

namespace App\Platform\Homepage\Models;

use App\Platform\Homepage\Database\Factories\HomepageSectionFactory;
use App\Platform\Homepage\Enums\BlockType;
use App\Platform\Homepage\Enums\HomepageStatus;
use App\Platform\Shared\Html\HtmlSanitizer;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A single predefined homepage block. Blocks are seeded/created from the fixed BlockType set and are
 * edited/toggled/reordered/published by admins in the Filament builder. The public homepage reads the
 * enabled blocks that are currently live (published status + inside their schedule window) and their
 * published snapshot (falling back to the draft when never published).
 *
 * Every update snapshots the block into homepage_section_versions (version history) so any prior
 * state can be restored with rollbackTo() — mirroring App\Platform\Pages\Models\StaticPage. RichText
 * block bodies are sanitized via the shared HtmlSanitizer on every write.
 *
 * @property int $id
 * @property string $public_id
 * @property string $key
 * @property BlockType $type
 * @property int $position
 * @property bool $is_enabled
 * @property HomepageStatus $status
 * @property array<string, mixed> $content
 * @property array<string, mixed>|null $published_content
 * @property Carbon|null $published_at
 * @property Carbon|null $unpublished_at
 * @property string|null $layout_variant
 * @property string|null $spacing
 * @property string|null $alignment
 * @property string|null $container_width
 * @property string|null $animation
 * @property string|null $theme_variant
 * @property array<string, mixed>|null $background
 * @property array<string, string>|null $accessibility_label
 * @property bool $visible_desktop
 * @property bool $visible_tablet
 * @property bool $visible_mobile
 */
class HomepageSection extends Model
{
    /** @use HasFactory<HomepageSectionFactory> */
    use HasFactory;

    use HasPublicId;

    protected $fillable = [
        'key', 'type', 'position', 'is_enabled', 'status', 'content', 'published_content',
        'published_at', 'unpublished_at', 'layout_variant', 'spacing', 'alignment', 'container_width',
        'animation', 'theme_variant', 'background', 'accessibility_label',
        'visible_desktop', 'visible_tablet', 'visible_mobile',
    ];

    /** Fields captured in a version snapshot (everything an editor can change). */
    private const VERSIONED_FIELDS = [
        'key', 'type', 'position', 'is_enabled', 'status', 'content', 'published_content',
        'published_at', 'unpublished_at', 'layout_variant', 'spacing', 'alignment', 'container_width',
        'animation', 'theme_variant', 'background', 'accessibility_label',
        'visible_desktop', 'visible_tablet', 'visible_mobile',
    ];

    protected function casts(): array
    {
        return [
            'type' => BlockType::class,
            'position' => 'integer',
            'is_enabled' => 'boolean',
            'status' => HomepageStatus::class,
            'content' => 'array',
            'published_content' => 'array',
            'published_at' => 'datetime',
            'unpublished_at' => 'datetime',
            'background' => 'array',
            'accessibility_label' => 'array',
            'visible_desktop' => 'boolean',
            'visible_tablet' => 'boolean',
            'visible_mobile' => 'boolean',
        ];
    }

    protected static function newFactory(): HomepageSectionFactory
    {
        return HomepageSectionFactory::new();
    }

    protected static function booted(): void
    {
        // Sanitize RichText HTML on every write — the single write-time sanitization point for
        // homepage block HTML (Filament, the Action, and the seeder all pass through here).
        static::saving(function (HomepageSection $section): void {
            $section->sanitizeRichContent();
        });

        // Snapshot the block into its version history after each update (incl. rollback restores).
        static::updated(function (HomepageSection $section): void {
            $section->recordVersion();
        });
    }

    /** @return HasMany<HomepageSectionVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(HomepageSectionVersion::class, 'homepage_section_id')->orderByDesc('version');
    }

    /**
     * Enabled blocks in render order (does NOT apply the editorial/schedule gate — used by the admin
     * preview, which shows drafts too).
     *
     * @param  Builder<HomepageSection>  $query
     * @return Builder<HomepageSection>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true)->orderBy('position');
    }

    /**
     * Live blocks only: Published status, past/absent published_at, and not yet unpublished. Combined
     * with enabled() for the public homepage. Mirrors StaticPage::published().
     *
     * @param  Builder<HomepageSection>  $query
     * @return Builder<HomepageSection>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', HomepageStatus::Published->value)
            ->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('unpublished_at')->orWhere('unpublished_at', '>', now()));
    }

    /** Whether THIS instance is currently live (mirrors the published() scope). */
    public function isLive(): bool
    {
        if ($this->status !== HomepageStatus::Published) {
            return false;
        }

        if ($this->published_at !== null && $this->published_at->isFuture()) {
            return false;
        }

        if ($this->unpublished_at !== null && ! $this->unpublished_at->isFuture()) {
            return false;
        }

        return true;
    }

    /**
     * Live copy: the published snapshot, or the draft when never published.
     *
     * @return array<string, mixed>
     */
    public function resolvedContent(): array
    {
        return $this->published_content ?? $this->content ?? [];
    }

    /** Flip the enabled flag (kept on the model so the Filament builder stays UI-only). */
    public function toggleEnabled(): static
    {
        $this->is_enabled = ! $this->is_enabled;
        $this->save();

        return $this;
    }

    /**
     * Snapshot the working draft as the live copy and mark the block Published now (stamping
     * published_at when unset and clearing any prior unpublish). Preserves the original snapshot
     * behavior while layering on the editorial status/schedule.
     */
    public function publish(): static
    {
        $this->forceFill([
            'published_content' => $this->content,
            'status' => HomepageStatus::Published,
            'published_at' => $this->published_at ?? now(),
            'unpublished_at' => null,
        ])->save();

        return $this;
    }

    /**
     * Snapshot the current block fields into homepage_section_versions as the next sequential
     * version. Append-only; never overwrites an existing version row.
     */
    public function recordVersion(): HomepageSectionVersion
    {
        $next = (int) $this->versions()->max('version') + 1;

        $authId = auth()->id();

        return $this->versions()->create([
            'version' => $next,
            'snapshot' => $this->versionSnapshot(),
            'author_id' => $authId !== null ? (int) $authId : null,
            'created_at' => now(),
        ]);
    }

    /**
     * Restore the fields from a prior version snapshot. Saving triggers a fresh version record, so a
     * rollback is itself captured in history (history is never rewritten).
     */
    public function rollbackTo(int $version): static
    {
        $snapshot = $this->versions()->where('version', $version)->firstOrFail();

        /** @var array<string, mixed> $data */
        $data = $snapshot->snapshot;

        $this->fill(array_intersect_key($data, array_flip(self::VERSIONED_FIELDS)));
        $this->save();

        return $this;
    }

    /**
     * The subset of fields captured in a version snapshot.
     *
     * @return array<string, mixed>
     */
    private function versionSnapshot(): array
    {
        $out = [];
        foreach (self::VERSIONED_FIELDS as $field) {
            $value = $this->getAttribute($field);
            $out[$field] = $value instanceof \BackedEnum
                ? $value->value
                : ($value instanceof Carbon ? $value->toIso8601String() : $value);
        }

        return $out;
    }

    /** Sanitize the RichText block body HTML for every locale in-place. No-op for other block types. */
    private function sanitizeRichContent(): void
    {
        $type = $this->getAttribute('type');
        if (! $type instanceof BlockType || ! $type->hasRichHtml()) {
            return;
        }

        $content = $this->getAttribute('content');
        if (! is_array($content) || ! isset($content['body']) || ! is_array($content['body'])) {
            return;
        }

        $sanitizer = app(HtmlSanitizer::class);

        foreach ($content['body'] as $locale => $html) {
            if (is_string($html)) {
                $content['body'][$locale] = $sanitizer->sanitize($html);
            }
        }

        $this->setAttribute('content', $content);
    }

    /**
     * Seeded default content for every ORIGINAL predefined block, keyed by block key. Bilingual and
     * on-brand so the public homepage is never empty. Positions define the default render order. The
     * seventeen expansion blocks carry their own placeholder payloads on BlockType::defaultContent().
     *
     * @return array<string, array{type: BlockType, position: int, content: array<string, mixed>}>
     */
    public static function defaults(): array
    {
        return [
            'hero' => [
                'type' => BlockType::Hero,
                'position' => 10,
                'content' => [
                    'headline' => [
                        'en' => 'Master the core. Lead the future.',
                        'ar' => 'أتقن الأساس. قُد المستقبل.',
                    ],
                    'subheadline' => [
                        'en' => 'Twelve disciplines. One academy. Built for MENA professionals, founders, and enterprises — courses, cohorts, workshops, enterprise training, and advisory under one roof.',
                        'ar' => 'اثنتا عشرة تخصصًا. أكاديمية واحدة. مصمّمة لمحترفي وروّاد ومؤسسات المنطقة — دورات وأفواج وورش وتدريب مؤسسي واستشارات تحت سقف واحد.',
                    ],
                    'cta_primary' => [
                        'label' => ['en' => 'Explore courses', 'ar' => 'استكشف الدورات'],
                        'href' => '/courses',
                    ],
                    'cta_secondary' => [
                        'label' => ['en' => 'HElbaron Advisory', 'ar' => 'استشارات HElbaron'],
                        'href' => '/advisory',
                    ],
                    'image' => null,
                ],
            ],
            'features' => [
                'type' => BlockType::Features,
                'position' => 20,
                'content' => [
                    'items' => [
                        [
                            'title' => ['en' => 'Courses', 'ar' => 'الدورات'],
                            'description' => ['en' => '100+ on-demand courses across 12 verticals.', 'ar' => 'أكثر من 100 دورة عند الطلب عبر 12 مجالًا.'],
                            'icon' => 'courses',
                        ],
                        [
                            'title' => ['en' => 'Live Cohorts', 'ar' => 'الأفواج المباشرة'],
                            'description' => ['en' => '8–12 week intensives led by MENA practitioners.', 'ar' => 'مكثّفات من 8–12 أسبوعًا يقودها ممارسون من المنطقة.'],
                            'icon' => 'cohorts',
                        ],
                        [
                            'title' => ['en' => 'Workshops', 'ar' => 'الورش'],
                            'description' => ['en' => 'Hands-on 1–2 day intensives in Cairo, Dubai, Riyadh.', 'ar' => 'مكثّفات عملية ليوم أو يومين في القاهرة ودبي والرياض.'],
                            'icon' => 'workshops',
                        ],
                        [
                            'title' => ['en' => 'B2B / B2G Training', 'ar' => 'تدريب المؤسسات والحكومات'],
                            'description' => ['en' => 'Custom enterprise & government programs with SSO and SCORM.', 'ar' => 'برامج مخصّصة للمؤسسات والحكومات مع الدخول الموحّد وSCORM.'],
                            'icon' => 'enterprise',
                        ],
                        [
                            'title' => ['en' => 'HElbaron Advisory', 'ar' => 'استشارات HElbaron'],
                            'description' => ['en' => 'Strategy, operations, and BD consulting that ships.', 'ar' => 'استشارات في الاستراتيجية والعمليات وتطوير الأعمال تُنفَّذ فعلًا.'],
                            'icon' => 'advisory',
                        ],
                        [
                            'title' => ['en' => 'Certificates', 'ar' => 'الشهادات'],
                            'description' => ['en' => 'Verifiable certificates on completion.', 'ar' => 'شهادات قابلة للتحقّق عند الإتمام.'],
                            'icon' => 'award',
                        ],
                    ],
                ],
            ],
            'partners' => [
                'type' => BlockType::Partners,
                'position' => 30,
                'content' => [
                    'items' => array_map(
                        fn (string $name) => ['name' => $name, 'logo' => null, 'href' => null],
                        ['Nile Group', 'Gulf Ventures', 'Levant Bank', 'Delta Foods', 'Atlas Energy', 'Cedar Health', 'Sahara Retail', 'Bosphorus Tech', 'Marina Logistics'],
                    ),
                ],
            ],
            'testimonials' => [
                'type' => BlockType::Testimonials,
                'position' => 40,
                'content' => [
                    'items' => [
                        [
                            'quote' => ['en' => 'HElbaron rebuilt how our managers lead. The cohort format actually stuck.', 'ar' => 'أعادت HElbaron تشكيل طريقة قيادة مديرينا. وأسلوب الأفواج ترسّخ فعلًا.'],
                            'author' => 'Layla Hassan',
                            'role' => ['en' => 'People Director, Nile Group', 'ar' => 'مديرة الموارد البشرية، مجموعة النيل'],
                            'avatar' => null,
                        ],
                        [
                            'quote' => ['en' => 'The AI-for-business track paid for itself in a quarter.', 'ar' => 'مسار الذكاء الاصطناعي للأعمال عوّض تكلفته خلال ربع سنة.'],
                            'author' => 'Omar Fathi',
                            'role' => ['en' => 'COO, Bosphorus Tech', 'ar' => 'مدير العمليات، بوسفورس تِك'],
                            'avatar' => null,
                        ],
                        [
                            'quote' => ['en' => 'Practical, regional, and genuinely hands-on. Exactly what our team needed.', 'ar' => 'عملي وإقليمي وتطبيقي بحق. تمامًا ما احتاجه فريقنا.'],
                            'author' => 'Sara Al-Amri',
                            'role' => ['en' => 'Head of L&D, Gulf Ventures', 'ar' => 'رئيسة التعلّم والتطوير، غلف فينتشرز'],
                            'avatar' => null,
                        ],
                    ],
                ],
            ],
            'faq' => [
                'type' => BlockType::Faq,
                'position' => 50,
                'content' => [
                    'items' => [
                        [
                            'question' => ['en' => 'Who are the courses for?', 'ar' => 'لمن هذه الدورات؟'],
                            'answer' => ['en' => 'Professionals, founders, and enterprise teams across MENA — from individual learners to whole organizations.', 'ar' => 'للمحترفين والروّاد وفِرَق المؤسسات في المنطقة — من المتعلّم الفرد إلى المؤسسة بأكملها.'],
                        ],
                        [
                            'question' => ['en' => 'Are the courses in Arabic or English?', 'ar' => 'هل الدورات بالعربية أم الإنجليزية؟'],
                            'answer' => ['en' => 'The platform is fully bilingual (English and Arabic) with right-to-left support throughout.', 'ar' => 'المنصّة ثنائية اللغة بالكامل (العربية والإنجليزية) مع دعم الكتابة من اليمين لليسار في كل مكان.'],
                        ],
                        [
                            'question' => ['en' => 'Do I get a certificate?', 'ar' => 'هل أحصل على شهادة؟'],
                            'answer' => ['en' => 'Yes — completed courses and cohorts issue a verifiable certificate you can share and validate online.', 'ar' => 'نعم — تصدر الدورات والأفواج المكتملة شهادة قابلة للتحقّق يمكنك مشاركتها والتحقّق منها عبر الإنترنت.'],
                        ],
                        [
                            'question' => ['en' => 'Can you train my whole team?', 'ar' => 'هل يمكنكم تدريب فريقي بالكامل؟'],
                            'answer' => ['en' => 'Yes. Our B2B / B2G programs offer custom curricula, SSO, SCORM, and a dedicated success manager.', 'ar' => 'نعم. تقدّم برامجنا للمؤسسات والحكومات مناهج مخصّصة ودخولًا موحّدًا وSCORM ومدير نجاح مخصّصًا.'],
                        ],
                    ],
                ],
            ],
            'footer' => [
                'type' => BlockType::Footer,
                'position' => 60,
                'content' => [
                    'tagline' => [
                        'en' => 'Master the core. Lead the future. The MENA business academy for individuals, teams, and enterprises across twelve verticals.',
                        'ar' => 'أتقن الأساس. قُد المستقبل. أكاديمية الأعمال للمنطقة للأفراد والفرق والمؤسسات عبر اثني عشر مجالًا.',
                    ],
                    'columns' => [
                        [
                            'title' => ['en' => 'Learn', 'ar' => 'تعلّم'],
                            'links' => [
                                ['label' => ['en' => 'Courses', 'ar' => 'الدورات'], 'href' => '/courses'],
                                ['label' => ['en' => 'Live cohorts', 'ar' => 'الأفواج'], 'href' => '/cohorts'],
                                ['label' => ['en' => 'Workshops', 'ar' => 'الورش'], 'href' => '/workshops'],
                                ['label' => ['en' => 'Certificates', 'ar' => 'الشهادات'], 'href' => '/verify'],
                                ['label' => ['en' => 'Pricing', 'ar' => 'الأسعار'], 'href' => '/pricing'],
                            ],
                        ],
                        [
                            'title' => ['en' => 'For Business', 'ar' => 'للأعمال'],
                            'links' => [
                                ['label' => ['en' => 'B2B / B2G Training', 'ar' => 'تدريب المؤسسات'], 'href' => '/enterprise'],
                                ['label' => ['en' => 'HElbaron Advisory', 'ar' => 'استشارات HElbaron'], 'href' => '/advisory'],
                                ['label' => ['en' => 'Case studies', 'ar' => 'دراسات حالة'], 'href' => '/enterprise'],
                            ],
                        ],
                        [
                            'title' => ['en' => 'Company', 'ar' => 'الشركة'],
                            'links' => [
                                ['label' => ['en' => 'About', 'ar' => 'من نحن'], 'href' => '/about'],
                                ['label' => ['en' => 'Trainers', 'ar' => 'المدرّبون'], 'href' => '/trainers'],
                                ['label' => ['en' => 'Contact', 'ar' => 'تواصل'], 'href' => '/contact'],
                            ],
                        ],
                    ],
                ],
            ],
            'seo' => [
                'type' => BlockType::Seo,
                'position' => 70,
                'content' => [
                    'meta_title' => [
                        'en' => 'HElbaron — Master the core. Lead the future.',
                        'ar' => 'HElbaron — أتقن الأساس. قُد المستقبل.',
                    ],
                    'meta_description' => [
                        'en' => 'The MENA business academy for individuals, teams, and enterprises across twelve verticals — courses, cohorts, workshops, enterprise training, and advisory.',
                        'ar' => 'أكاديمية الأعمال للمنطقة للأفراد والفرق والمؤسسات عبر اثني عشر مجالًا — دورات وأفواج وورش وتدريب مؤسسي واستشارات.',
                    ],
                    'og_image' => null,
                    'canonical' => '/',
                ],
            ],
        ];
    }
}
