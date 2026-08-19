<?php

namespace App\Platform\Homepage\Enums;

use App\Platform\Homepage\Models\HomepageSection;

/**
 * The set of predefined homepage blocks (PRD-scoped). This is NOT a generic page builder — only
 * these typed blocks exist and each has a fixed, documented content shape. Content is stored per
 * block as a JSON bag holding bilingual { en, ar } fields.
 *
 * The original seven blocks (hero/features/testimonials/partners/faq/footer/seo) are unchanged. The
 * expansion adds seventeen presentational block types; each carries a defaultContent() payload so a
 * newly created block renders immediately with sensible bilingual placeholder copy.
 *
 * Existing content shapes (working draft `content` / published `published_content`):
 *  - Hero:        { headline:{en,ar}, subheadline:{en,ar}, cta_primary:{label:{en,ar},href},
 *                   cta_secondary:{label:{en,ar},href}, image }
 *  - Features:    { items: [ { title:{en,ar}, description:{en,ar}, icon } ] }
 *  - Testimonials:{ items: [ { quote:{en,ar}, author, role:{en,ar}, avatar } ] }
 *  - Partners:    { items: [ { name, logo, href } ] }
 *  - Faq:         { items: [ { question:{en,ar}, answer:{en,ar} } ] }
 *  - Footer:      { tagline:{en,ar}, columns:[ { title:{en,ar}, links:[{label:{en,ar},href}] } ] }
 *  - Seo:         { meta_title:{en,ar}, meta_description:{en,ar}, og_image, canonical }
 */
enum BlockType: string
{
    // --- Original predefined blocks (unchanged) ---
    case Hero = 'hero';
    case Features = 'features';
    case Testimonials = 'testimonials';
    case Partners = 'partners';
    case Faq = 'faq';
    case Footer = 'footer';
    case Seo = 'seo';

    // --- Expansion: seventeen presentational blocks ---
    case Statistics = 'statistics';
    case Numbers = 'numbers';
    case Categories = 'categories';
    case FeaturedCourses = 'featured_courses';
    case FeaturedEvents = 'featured_events';
    case Clients = 'clients';
    case PricingPreview = 'pricing_preview';
    case Cta = 'cta';
    case Video = 'video';
    case Gallery = 'gallery';
    case Timeline = 'timeline';
    case Team = 'team';
    case Newsletter = 'newsletter';
    case ContactStrip = 'contact_strip';
    case RichText = 'rich_text';
    case LogoCloud = 'logo_cloud';
    case ComparisonTable = 'comparison_table';

    // --- Presentational bridge: renders a fixed built-in brand home section (content is just its key) ---
    case BrandSection = 'brand_section';

    public function label(): string
    {
        return match ($this) {
            self::Hero => 'Hero',
            self::Features => 'Features',
            self::Testimonials => 'Testimonials',
            self::Partners => 'Partners',
            self::Faq => 'FAQ',
            self::Footer => 'Footer',
            self::Seo => 'SEO',
            self::Statistics => 'Statistics',
            self::Numbers => 'Numbers',
            self::Categories => 'Categories',
            self::FeaturedCourses => 'Featured Courses',
            self::FeaturedEvents => 'Featured Events',
            self::Clients => 'Clients',
            self::PricingPreview => 'Pricing Preview',
            self::Cta => 'Call to Action',
            self::Video => 'Video',
            self::Gallery => 'Gallery',
            self::Timeline => 'Timeline',
            self::Team => 'Team',
            self::Newsletter => 'Newsletter',
            self::ContactStrip => 'Contact Strip',
            self::RichText => 'Rich Text',
            self::LogoCloud => 'Logo Cloud',
            self::ComparisonTable => 'Comparison Table',
            self::BrandSection => 'Brand Section',
        };
    }

    /** Whether this block resolves referenced domain entities (Catalog/Live) server-side. */
    public function resolvesEntities(): bool
    {
        return match ($this) {
            self::FeaturedCourses, self::FeaturedEvents, self::Categories => true,
            default => false,
        };
    }

    /** Whether this block holds sanitizable rich HTML in its content (RichText body). */
    public function hasRichHtml(): bool
    {
        return $this === self::RichText;
    }

    /**
     * Sensible bilingual placeholder content for a freshly created block, so it renders immediately.
     * The original seven blocks delegate to the seeded HomepageSection::defaults() (key == value for
     * those). The seventeen new blocks define their placeholder payload here.
     *
     * @return array<string, mixed>
     */
    public function defaultContent(): array
    {
        $seed = HomepageSection::defaults();
        if (isset($seed[$this->value])) {
            /** @var array<string, mixed> $content */
            $content = $seed[$this->value]['content'];

            return $content;
        }

        return match ($this) {
            self::Statistics => [
                'heading' => ['en' => 'By the numbers', 'ar' => 'بالأرقام'],
                'items' => [
                    ['value' => '12', 'suffix' => '', 'label' => ['en' => 'Verticals', 'ar' => 'مجالات']],
                    ['value' => '100', 'suffix' => '+', 'label' => ['en' => 'Courses', 'ar' => 'دورة']],
                    ['value' => '40', 'suffix' => 'k', 'label' => ['en' => 'Learners', 'ar' => 'متعلّم']],
                    ['value' => '98', 'suffix' => '%', 'label' => ['en' => 'Satisfaction', 'ar' => 'رضا']],
                ],
            ],
            self::Numbers => [
                'heading' => ['en' => 'Impact at a glance', 'ar' => 'الأثر باختصار'],
                'items' => [
                    ['value' => '2018', 'label' => ['en' => 'Founded', 'ar' => 'التأسيس']],
                    ['value' => '3', 'label' => ['en' => 'Regional hubs', 'ar' => 'مراكز إقليمية']],
                    ['value' => '250', 'label' => ['en' => 'Enterprise clients', 'ar' => 'عميل مؤسسي']],
                ],
            ],
            self::Categories => [
                'heading' => ['en' => 'Browse by discipline', 'ar' => 'تصفّح حسب المجال'],
                'subheading' => ['en' => 'Twelve verticals for MENA professionals.', 'ar' => 'اثنا عشر مجالًا لمحترفي المنطقة.'],
                'category_slugs' => [],
                'limit' => 8,
            ],
            self::FeaturedCourses => [
                'heading' => ['en' => 'Featured courses', 'ar' => 'دورات مختارة'],
                'subheading' => ['en' => 'Hand-picked programs to start with.', 'ar' => 'برامج مختارة للبدء.'],
                'course_slugs' => [],
                'limit' => 9,
                'cta' => ['label' => ['en' => 'Browse all courses', 'ar' => 'تصفّح كل الدورات'], 'href' => '/courses'],
            ],
            self::FeaturedEvents => [
                'heading' => ['en' => 'Upcoming events', 'ar' => 'فعاليات قادمة'],
                'subheading' => ['en' => 'Live cohorts and workshops.', 'ar' => 'أفواج مباشرة وورش عمل.'],
                'limit' => 4,
                'cta' => ['label' => ['en' => 'See all events', 'ar' => 'كل الفعاليات'], 'href' => '/events'],
            ],
            self::Clients => [
                'heading' => ['en' => 'Trusted by leading teams', 'ar' => 'موثوق من فرق رائدة'],
                'items' => array_map(
                    fn (string $name) => ['name' => $name, 'logo' => null, 'href' => null],
                    ['Nile Group', 'Gulf Ventures', 'Levant Bank', 'Delta Foods', 'Atlas Energy', 'Cedar Health'],
                ),
            ],
            self::PricingPreview => [
                'heading' => ['en' => 'Simple, transparent pricing', 'ar' => 'أسعار بسيطة وشفافة'],
                'subheading' => ['en' => 'Plans for individuals and teams.', 'ar' => 'باقات للأفراد والفرق.'],
                'plans' => [
                    [
                        'name' => ['en' => 'Individual', 'ar' => 'فردي'],
                        'price' => '49',
                        'period' => ['en' => '/month', 'ar' => '/شهريًا'],
                        'highlighted' => false,
                        'features' => [
                            ['en' => 'All on-demand courses', 'ar' => 'كل الدورات عند الطلب'],
                            ['en' => 'Verifiable certificates', 'ar' => 'شهادات قابلة للتحقّق'],
                        ],
                        'cta' => ['label' => ['en' => 'Start free', 'ar' => 'ابدأ مجانًا'], 'href' => '/pricing'],
                    ],
                    [
                        'name' => ['en' => 'Team', 'ar' => 'فريق'],
                        'price' => '199',
                        'period' => ['en' => '/month', 'ar' => '/شهريًا'],
                        'highlighted' => true,
                        'features' => [
                            ['en' => 'Everything in Individual', 'ar' => 'كل ما في الفردي'],
                            ['en' => 'SSO & analytics', 'ar' => 'دخول موحّد وتحليلات'],
                        ],
                        'cta' => ['label' => ['en' => 'Contact sales', 'ar' => 'تواصل مع المبيعات'], 'href' => '/enterprise'],
                    ],
                ],
            ],
            self::Cta => [
                'headline' => ['en' => 'Ready to lead the future?', 'ar' => 'جاهز لقيادة المستقبل؟'],
                'subheadline' => ['en' => 'Join thousands of MENA professionals learning with HElbaron.', 'ar' => 'انضم إلى آلاف محترفي المنطقة الذين يتعلّمون مع HElbaron.'],
                'cta_primary' => ['label' => ['en' => 'Get started', 'ar' => 'ابدأ الآن'], 'href' => '/register'],
                'cta_secondary' => ['label' => ['en' => 'Talk to us', 'ar' => 'تواصل معنا'], 'href' => '/contact'],
            ],
            self::Video => [
                'heading' => ['en' => 'See HElbaron in action', 'ar' => 'شاهد HElbaron أثناء العمل'],
                'url' => null,
                'poster' => null,
                'caption' => ['en' => 'A two-minute tour of the academy.', 'ar' => 'جولة في دقيقتين داخل الأكاديمية.'],
            ],
            self::Gallery => [
                'heading' => ['en' => 'From our workshops', 'ar' => 'من ورشنا'],
                'items' => [
                    ['image' => null, 'caption' => ['en' => 'Cairo cohort', 'ar' => 'فوج القاهرة']],
                    ['image' => null, 'caption' => ['en' => 'Riyadh workshop', 'ar' => 'ورشة الرياض']],
                    ['image' => null, 'caption' => ['en' => 'Dubai summit', 'ar' => 'قمة دبي']],
                ],
            ],
            self::Timeline => [
                'heading' => ['en' => 'Your learning journey', 'ar' => 'رحلتك التعليمية'],
                'items' => [
                    ['date' => ['en' => 'Week 1', 'ar' => 'الأسبوع 1'], 'title' => ['en' => 'Onboarding', 'ar' => 'البدء'], 'description' => ['en' => 'Meet your cohort and set goals.', 'ar' => 'تعرّف على فوجك وحدّد أهدافك.']],
                    ['date' => ['en' => 'Weeks 2–6', 'ar' => 'الأسابيع 2–6'], 'title' => ['en' => 'Core modules', 'ar' => 'الوحدات الأساسية'], 'description' => ['en' => 'Hands-on projects and live sessions.', 'ar' => 'مشاريع عملية وجلسات مباشرة.']],
                    ['date' => ['en' => 'Week 8', 'ar' => 'الأسبوع 8'], 'title' => ['en' => 'Capstone', 'ar' => 'المشروع الختامي'], 'description' => ['en' => 'Present your work and graduate.', 'ar' => 'اعرض عملك وتخرّج.']],
                ],
            ],
            self::Team => [
                'heading' => ['en' => 'Meet our trainers', 'ar' => 'تعرّف على مدرّبينا'],
                'items' => [
                    ['name' => 'Layla Hassan', 'role' => ['en' => 'Leadership', 'ar' => 'القيادة'], 'avatar' => null, 'href' => null],
                    ['name' => 'Omar Fathi', 'role' => ['en' => 'AI for Business', 'ar' => 'الذكاء الاصطناعي للأعمال'], 'avatar' => null, 'href' => null],
                    ['name' => 'Sara Al-Amri', 'role' => ['en' => 'Product', 'ar' => 'المنتجات'], 'avatar' => null, 'href' => null],
                ],
            ],
            self::Newsletter => [
                'heading' => ['en' => 'Stay in the loop', 'ar' => 'ابقَ على اطّلاع'],
                'subheading' => ['en' => 'Monthly insights on learning and leadership.', 'ar' => 'رؤى شهرية عن التعلّم والقيادة.'],
                'placeholder' => ['en' => 'Enter your email', 'ar' => 'أدخل بريدك الإلكتروني'],
                'cta' => ['en' => 'Subscribe', 'ar' => 'اشترك'],
                'action_url' => null,
            ],
            self::ContactStrip => [
                'heading' => ['en' => 'Let’s talk', 'ar' => 'لنتحدّث'],
                'subheading' => ['en' => 'We usually reply within one business day.', 'ar' => 'نردّ عادةً خلال يوم عمل واحد.'],
                'phone' => '+20 2 0000 0000',
                'email' => 'hello@helbaron.com',
                'address' => ['en' => 'Cairo · Dubai · Riyadh', 'ar' => 'القاهرة · دبي · الرياض'],
                'cta' => ['label' => ['en' => 'Contact us', 'ar' => 'تواصل معنا'], 'href' => '/contact'],
            ],
            self::RichText => [
                'title' => ['en' => 'About HElbaron', 'ar' => 'عن HElbaron'],
                'body' => [
                    'en' => '<p>HElbaron is the MENA business academy. <strong>Master the core. Lead the future.</strong></p>',
                    'ar' => '<p>HElbaron أكاديمية الأعمال للمنطقة. <strong>أتقن الأساس. قُد المستقبل.</strong></p>',
                ],
            ],
            self::LogoCloud => [
                'heading' => ['en' => 'As featured in', 'ar' => 'ظهرنا في'],
                'items' => array_map(
                    fn (string $name) => ['name' => $name, 'logo' => null, 'href' => null],
                    ['Forbes ME', 'Wamda', 'MENAbytes', 'Arab News', 'The National'],
                ),
            ],
            self::ComparisonTable => [
                'heading' => ['en' => 'How we compare', 'ar' => 'كيف نتميّز'],
                'columns' => [
                    ['en' => 'Feature', 'ar' => 'الميزة'],
                    ['en' => 'HElbaron', 'ar' => 'HElbaron'],
                    ['en' => 'Others', 'ar' => 'غيرنا'],
                ],
                'rows' => [
                    ['cells' => [['en' => 'Bilingual EN/AR', 'ar' => 'ثنائي اللغة'], ['en' => 'Yes', 'ar' => 'نعم'], ['en' => 'Partial', 'ar' => 'جزئي']]],
                    ['cells' => [['en' => 'Live cohorts', 'ar' => 'أفواج مباشرة'], ['en' => 'Yes', 'ar' => 'نعم'], ['en' => 'No', 'ar' => 'لا']]],
                    ['cells' => [['en' => 'Enterprise SSO', 'ar' => 'دخول موحّد للمؤسسات'], ['en' => 'Yes', 'ar' => 'نعم'], ['en' => 'Limited', 'ar' => 'محدود']]],
                ],
            ],
            // Purely presentational: content is only the built-in section key it renders.
            self::BrandSection => ['key' => 'hero'],
            // Existing blocks are handled above via the seed lookup; unreachable here.
            default => [],
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }

    /** @return array<string, string> value => label, for Filament selects. */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
