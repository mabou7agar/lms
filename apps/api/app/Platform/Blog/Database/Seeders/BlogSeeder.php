<?php

namespace App\Platform\Blog\Database\Seeders;

use App\Platform\Blog\Enums\PostStatus;
use App\Platform\Blog\Models\BlogCategory;
use App\Platform\Blog\Models\BlogPost;
use Illuminate\Database\Seeder;

/**
 * Seeds the blog with on-brand bilingual content, already published, so the public /blog routes
 * render from the CMS immediately: 3 categories (Insights / Guides / News) and 6 published
 * bilingual posts (2 featured) spread across the categories with published_at in the past.
 *
 * Covers are left null — the frontend blog card renders a gradient fallback when cover_image is
 * absent, so the seeder does not couple the Blog context to the Media context to assign images;
 * editors add covers via the CMS MediaPicker. Idempotent: firstOrCreate keyed on `slug` for both
 * categories and posts.
 */
class BlogSeeder extends Seeder
{
    public function run(): void
    {
        $categories = $this->seedCategories();

        foreach (self::posts() as $slug => $post) {
            BlogPost::firstOrCreate(
                ['slug' => $slug],
                [
                    'title' => $post['title'],
                    'excerpt' => $post['excerpt'],
                    'body' => $post['body'],
                    'cover_image' => null,
                    'blog_category_id' => $categories[$post['category']] ?? null,
                    'status' => PostStatus::Published,
                    'published_at' => now()->subDays($post['days_ago']),
                    'unpublished_at' => null,
                    'is_featured' => $post['is_featured'] ?? false,
                    'reading_minutes' => $post['reading_minutes'],
                    'seo' => null,
                ],
            );
        }
    }

    /**
     * Seed the categories and return a slug => id map for post assignment.
     *
     * @return array<string, int>
     */
    private function seedCategories(): array
    {
        $map = [];

        foreach (self::categories() as $slug => $category) {
            $model = BlogCategory::firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => $category['name'],
                    'description' => $category['description'],
                    'position' => $category['position'],
                ],
            );

            $map[$slug] = $model->id;
        }

        return $map;
    }

    /**
     * @return array<string, array{name: array<string,string>, description: array<string,string>, position: int}>
     */
    private static function categories(): array
    {
        return [
            'insights' => [
                'position' => 10,
                'name' => ['en' => 'Insights', 'ar' => 'رؤى'],
                'description' => [
                    'en' => 'Perspectives on learning, leadership, and the future of work across the region.',
                    'ar' => 'رؤى حول التعلّم والقيادة ومستقبل العمل في المنطقة.',
                ],
            ],
            'guides' => [
                'position' => 20,
                'name' => ['en' => 'Guides', 'ar' => 'أدلة'],
                'description' => [
                    'en' => 'Practical, step-by-step guides you can apply the next day.',
                    'ar' => 'أدلة عملية خطوة بخطوة يمكنك تطبيقها في اليوم التالي.',
                ],
            ],
            'news' => [
                'position' => 30,
                'name' => ['en' => 'News', 'ar' => 'أخبار'],
                'description' => [
                    'en' => 'Announcements and updates from the HElbaron academy.',
                    'ar' => 'إعلانات وتحديثات من أكاديمية HElbaron.',
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{
     *     category: string, days_ago: int, reading_minutes: int, is_featured?: bool,
     *     title: array<string,string>, excerpt: array<string,string>, body: array<string,string>
     * }>
     */
    private static function posts(): array
    {
        return [
            'why-bilingual-learning-matters' => [
                'category' => 'insights',
                'days_ago' => 3,
                'reading_minutes' => 6,
                'is_featured' => true,
                'title' => [
                    'en' => 'Why bilingual learning matters for MENA professionals',
                    'ar' => 'لماذا يهمّ التعلّم ثنائي اللغة لمحترفي المنطقة',
                ],
                'excerpt' => [
                    'en' => 'Learning in your own language is not a nice-to-have — it changes how deeply you understand and how confidently you apply new skills.',
                    'ar' => 'التعلّم بلغتك ليس رفاهية — بل يغيّر عمق فهمك وثقتك في تطبيق المهارات الجديدة.',
                ],
                'body' => [
                    'en' => '<p>For too long, ambitious professionals across the region have been asked to learn business fundamentals in a language that is not their own, from material that does not reflect their market. The cost is quiet but real: slower comprehension, shallower retention, and less confidence to apply what was learned.</p>'
                        .'<h2>Language shapes understanding</h2><p>When a concept is taught in your first language, you spend your mental energy on the idea itself rather than on translation. Nuance survives. Examples land. You remember more, for longer.</p>'
                        .'<h2>Bilingual, not translated</h2><p>A translation bolted on after the fact is not the same as a course designed to work in both Arabic and English from the first line. We build for both — full right-to-left support, regional examples, and terminology that practitioners actually use.</p>'
                        .'<h2>The outcome</h2><p>Learners who study in their own language finish more courses, apply more of what they learn, and lead with more confidence. That is the whole point.</p>',
                    'ar' => '<p>لوقت طويل، طُلب من المحترفين الطموحين في المنطقة تعلّم أساسيات الأعمال بلغة ليست لغتهم ومن مواد لا تعكس سوقهم. والكلفة هادئة لكنها حقيقية: فهم أبطأ، واحتفاظ أضعف، وثقة أقل في التطبيق.</p>'
                        .'<h2>اللغة تشكّل الفهم</h2><p>حين يُدرَّس المفهوم بلغتك الأولى، تصرف طاقتك الذهنية على الفكرة نفسها لا على الترجمة. تبقى الفروق الدقيقة، وتترسّخ الأمثلة، وتتذكّر أكثر ولمدة أطول.</p>'
                        .'<h2>ثنائية اللغة لا مترجمة</h2><p>الترجمة المُضافة لاحقًا ليست كالدورة المصمّمة لتعمل بالعربية والإنجليزية من السطر الأول. نحن نبني للّغتين — بدعم كامل للكتابة من اليمين لليسار وأمثلة إقليمية ومصطلحات يستخدمها الممارسون فعلًا.</p>'
                        .'<h2>النتيجة</h2><p>من يتعلّمون بلغتهم يكملون دورات أكثر، ويطبّقون أكثر ممّا تعلّموه، ويقودون بثقة أكبر. وهذا هو المقصد كلّه.</p>',
                ],
            ],
            'building-a-learning-habit' => [
                'category' => 'guides',
                'days_ago' => 7,
                'reading_minutes' => 5,
                'title' => [
                    'en' => 'A simple guide to building a lasting learning habit',
                    'ar' => 'دليل بسيط لبناء عادة تعلّم دائمة',
                ],
                'excerpt' => [
                    'en' => 'Consistency beats intensity. Here is a practical, low-friction routine that keeps you learning even in a busy week.',
                    'ar' => 'الاستمرارية تتغلّب على الحدّة. إليك روتينًا عمليًا منخفض الاحتكاك يبقيك متعلّمًا حتى في أسبوع مزدحم.',
                ],
                'body' => [
                    'en' => '<p>Most people do not fail to learn because they lack ability — they fail because they rely on motivation instead of a system. A habit removes the daily negotiation.</p>'
                        .'<h2>Start absurdly small</h2><p>Commit to ten minutes a day, not two hours a week. A small daily action is easier to keep and compounds faster than an occasional heroic session.</p>'
                        .'<h2>Anchor it to something you already do</h2><p>Attach your study block to an existing routine — after your morning coffee, before you check email. The anchor does the remembering for you.</p>'
                        .'<h2>Make progress visible</h2><p>Track completed lessons. A simple streak is a surprisingly strong motivator, and our dashboard saves your progress automatically as you go.</p>'
                        .'<h2>Forgive the miss</h2><p>Missing one day is a normal part of any habit. Missing two is how habits die. Just return the next day — the goal is the long run, not a perfect record.</p>',
                    'ar' => '<p>معظم الناس لا يفشلون في التعلّم لنقص القدرة — بل لأنهم يعتمدون على الحماس بدل النظام. والعادة تُلغي المفاوضة اليومية.</p>'
                        .'<h2>ابدأ صغيرًا جدًا</h2><p>التزم بعشر دقائق يوميًا لا بساعتين أسبوعيًا. الفعل اليومي الصغير أسهل في الاستمرار ويتراكم أسرع من جلسة بطولية عابرة.</p>'
                        .'<h2>اربطه بشيء تفعله أصلًا</h2><p>اربط وقت دراستك بروتين قائم — بعد قهوة الصباح، قبل فتح البريد. المرساة تتذكّر عنك.</p>'
                        .'<h2>اجعل التقدّم مرئيًا</h2><p>تابِع الدروس المكتملة. سلسلة بسيطة محفّز قوي بشكل مدهش، ولوحتنا تحفظ تقدّمك تلقائيًا أثناء تقدّمك.</p>'
                        .'<h2>سامح التفويت</h2><p>تفويت يوم واحد جزء طبيعي من أي عادة. تفويت يومين هو ما يقتل العادات. عُد في اليوم التالي فقط — الهدف هو المدى الطويل لا السجلّ المثالي.</p>',
                ],
            ],
            'from-course-to-certificate' => [
                'category' => 'guides',
                'days_ago' => 12,
                'reading_minutes' => 4,
                'title' => [
                    'en' => 'From course to certificate: how verification works',
                    'ar' => 'من الدورة إلى الشهادة: كيف يعمل التحقّق',
                ],
                'excerpt' => [
                    'en' => 'Every HElbaron certificate carries a unique code anyone can verify online. Here is what that means for you and your employer.',
                    'ar' => 'كل شهادة من HElbaron تحمل رمزًا فريدًا يمكن لأي شخص التحقّق منه عبر الإنترنت. إليك ما يعنيه ذلك لك ولصاحب عملك.',
                ],
                'body' => [
                    'en' => '<p>A certificate is only as useful as it is trustworthy. That is why every credential you earn is verifiable — not just a PDF, but a record anyone can confirm.</p>'
                        .'<h2>Complete the work</h2><p>When you finish a course or cohort and meet its requirements, your certificate is issued automatically to your account.</p>'
                        .'<h2>Share the code</h2><p>Each certificate carries a unique verification code. Add it to your CV or LinkedIn, and anyone can confirm it on our public verify page.</p>'
                        .'<h2>Why it matters</h2><p>Verifiable credentials protect the value of your effort. An employer can trust the certificate at a glance, without calling anyone or taking your word for it.</p>',
                    'ar' => '<p>الشهادة نافعة بقدر ما هي جديرة بالثقة. لذلك كل مؤهّل تحصل عليه قابل للتحقّق — ليس مجرّد ملف PDF، بل سجلّ يمكن لأي شخص تأكيده.</p>'
                        .'<h2>أكمل العمل</h2><p>عند إنهاء دورة أو فوج واستيفاء متطلّباته، تصدر شهادتك تلقائيًا إلى حسابك.</p>'
                        .'<h2>شارك الرمز</h2><p>كل شهادة تحمل رمز تحقّق فريدًا. أضفه إلى سيرتك أو LinkedIn، ويمكن لأي شخص تأكيده في صفحة التحقّق العامة.</p>'
                        .'<h2>لماذا يهمّ</h2><p>المؤهّلات القابلة للتحقّق تحمي قيمة جهدك. يمكن لصاحب العمل الوثوق بالشهادة من نظرة واحدة، دون اتصال بأحد أو الاعتماد على كلامك.</p>',
                ],
            ],
            'skills-that-lead-2026' => [
                'category' => 'insights',
                'days_ago' => 18,
                'reading_minutes' => 7,
                'is_featured' => true,
                'title' => [
                    'en' => 'The skills that will lead teams in 2026',
                    'ar' => 'المهارات التي ستقود الفرق في 2026',
                ],
                'excerpt' => [
                    'en' => 'Tools change fast, but the durable skills that make leaders effective are remarkably stable. These are the ones worth investing in.',
                    'ar' => 'تتغيّر الأدوات بسرعة، لكن المهارات الراسخة التي تصنع القادة الفاعلين مستقرّة بشكل لافت. وهذه هي التي تستحق الاستثمار.',
                ],
                'body' => [
                    'en' => '<p>It is tempting to chase every new tool, but the professionals who lead well tend to be strong in a small set of durable skills that outlast any single technology.</p>'
                        .'<h2>Clear communication</h2><p>The ability to write and speak plainly — in Arabic and English — is a multiplier. Clarity builds trust and moves work forward faster than any tool.</p>'
                        .'<h2>Judgment under uncertainty</h2><p>Good decisions rarely come with complete information. Practicing structured thinking helps you act sensibly when the picture is incomplete.</p>'
                        .'<h2>Working with, not around, AI</h2><p>The point is not to fear new tools or to defer to them blindly, but to use them well: as an assistant whose output you can direct, check, and improve.</p>'
                        .'<h2>Learning how to learn</h2><p>The half-life of specific skills keeps shrinking. The meta-skill of learning quickly and deliberately is the one that compounds for a whole career.</p>',
                    'ar' => '<p>من المغري مطاردة كل أداة جديدة، لكن من يقودون جيّدًا غالبًا أقوياء في مجموعة صغيرة من المهارات الراسخة التي تتجاوز أي تقنية بعينها.</p>'
                        .'<h2>تواصل واضح</h2><p>القدرة على الكتابة والتحدّث بوضوح — بالعربية والإنجليزية — مضاعِف. الوضوح يبني الثقة ويحرّك العمل أسرع من أي أداة.</p>'
                        .'<h2>الحكم تحت عدم اليقين</h2><p>نادرًا ما تأتي القرارات الجيّدة بمعلومات كاملة. تمرين التفكير المنظّم يساعدك على التصرّف بحكمة حين تكون الصورة ناقصة.</p>'
                        .'<h2>العمل مع الذكاء الاصطناعي لا حوله</h2><p>القصد ليس الخوف من الأدوات الجديدة ولا الانقياد لها عمياءً، بل استخدامها جيّدًا: كمساعد توجّه مخرجاته وتتحقّق منها وتحسّنها.</p>'
                        .'<h2>تعلّم كيف تتعلّم</h2><p>عمر المهارات المحدّدة يتقلّص باستمرار. مهارة التعلّم السريع والمقصود هي التي تتراكم على مدى مهنة كاملة.</p>',
                ],
            ],
            'introducing-live-cohorts' => [
                'category' => 'news',
                'days_ago' => 25,
                'reading_minutes' => 3,
                'title' => [
                    'en' => 'Introducing live cohorts at HElbaron',
                    'ar' => 'نقدّم الأفواج المباشرة في HElbaron',
                ],
                'excerpt' => [
                    'en' => 'Learn alongside a group, guided by a practitioner, on a schedule. Our new live cohorts bring accountability and community to online learning.',
                    'ar' => 'تعلّم ضمن مجموعة، بإرشاد ممارس، وفق جدول. أفواجنا المباشرة الجديدة تضيف المساءلة والمجتمع إلى التعلّم عبر الإنترنت.',
                ],
                'body' => [
                    'en' => '<p>Self-paced learning is flexible, but for many people a schedule and a group are what turn intention into completion. Today we are introducing live cohorts.</p>'
                        .'<h2>What a cohort is</h2><p>A small group moves through a program together over a set number of weeks, led by a practitioner, with live sessions and shared deadlines.</p>'
                        .'<h2>Why it works</h2><p>Accountability and community are powerful. Learning with others keeps you on pace, and the live sessions let you ask real questions and get real answers.</p>'
                        .'<h2>How to join</h2><p>Browse the catalog for programs marked as cohorts. Seats are limited by design, so each group stays small enough for real interaction.</p>',
                    'ar' => '<p>التعلّم الذاتي مرن، لكن للكثيرين يكون الجدول والمجموعة هما ما يحوّل النية إلى إنجاز. واليوم نقدّم الأفواج المباشرة.</p>'
                        .'<h2>ما هو الفوج</h2><p>تتقدّم مجموعة صغيرة في برنامج معًا عبر عدد محدّد من الأسابيع، بقيادة ممارس، مع جلسات مباشرة ومواعيد نهائية مشتركة.</p>'
                        .'<h2>لماذا ينجح</h2><p>المساءلة والمجتمع قويّان. التعلّم مع آخرين يبقيك على الإيقاع، والجلسات المباشرة تتيح لك طرح أسئلة حقيقية والحصول على إجابات حقيقية.</p>'
                        .'<h2>كيف تنضمّ</h2><p>تصفّح الكتالوج بحثًا عن البرامج المميّزة كأفواج. المقاعد محدودة بالتصميم، لتبقى كل مجموعة صغيرة بما يكفي لتفاعل حقيقي.</p>',
                ],
            ],
            'how-to-choose-a-course' => [
                'category' => 'guides',
                'days_ago' => 30,
                'reading_minutes' => 5,
                'title' => [
                    'en' => 'How to choose your next course',
                    'ar' => 'كيف تختار دورتك القادمة',
                ],
                'excerpt' => [
                    'en' => 'With a full catalog in front of you, the hardest part is choosing. A simple framework to pick the course that actually moves you forward.',
                    'ar' => 'مع كتالوج كامل أمامك، يصبح الاختيار أصعب جزء. إطار بسيط لاختيار الدورة التي تدفعك فعلًا للأمام.',
                ],
                'body' => [
                    'en' => '<p>A big catalog is a gift and a burden. Here is a simple way to cut through the options and choose a course you will actually finish and use.</p>'
                        .'<h2>Start from the outcome</h2><p>Ask what you want to be able to do in three months, then work backwards. The best course is the one that closes the gap between where you are and that outcome.</p>'
                        .'<h2>Match the format to your life</h2><p>Be honest about your schedule. A self-paced course suits an unpredictable week; a live cohort suits someone who thrives on structure and accountability.</p>'
                        .'<h2>Check the level</h2><p>A course that is too basic bores you and one that is too advanced loses you. Use the level and language labels to find the right fit.</p>'
                        .'<h2>Commit and start small</h2><p>Once you choose, begin with a single lesson today. Momentum, not the perfect choice, is what carries you to the certificate.</p>',
                    'ar' => '<p>الكتالوج الكبير هبة وعبء. إليك طريقة بسيطة لاختصار الخيارات واختيار دورة ستكملها وتستخدمها فعلًا.</p>'
                        .'<h2>ابدأ من النتيجة</h2><p>اسأل ماذا تريد أن تكون قادرًا على فعله بعد ثلاثة أشهر، ثم اعمل بشكل عكسي. أفضل دورة هي التي تسدّ الفجوة بين موضعك وتلك النتيجة.</p>'
                        .'<h2>طابِق الصيغة مع حياتك</h2><p>كن صادقًا حيال جدولك. الدورة الذاتية تناسب أسبوعًا غير متوقّع؛ والفوج المباشر يناسب من يزدهر بالبنية والمساءلة.</p>'
                        .'<h2>تحقّق من المستوى</h2><p>الدورة البسيطة جدًا تُملّك والمتقدّمة جدًا تُفقدك. استخدم وسمَي المستوى واللغة لإيجاد الملاءمة الصحيحة.</p>'
                        .'<h2>التزم وابدأ صغيرًا</h2><p>بعد الاختيار، ابدأ بدرس واحد اليوم. الزخم لا الاختيار المثالي هو ما يحملك إلى الشهادة.</p>',
                ],
            ],
        ];
    }
}
