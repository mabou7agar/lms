import type { Localized, PersonaId, Cta } from "@/config/messaging";
import { personaById } from "@/config/messaging";

/**
 * Rich, DISTINCT per-persona landing content — extends (never recreates) the Batch 1 messaging
 * foundation. Each persona has its own pain points, implementation path, and FAQ so every landing
 * page is a distinct conversion journey rather than one page with nouns swapped (asserted by
 * tests/marketing/persona-pages.test.tsx).
 */

export interface FaqItem {
  readonly q: Localized;
  readonly a: Localized;
}

export interface ImplementationStep {
  readonly title: Localized;
  readonly body: Localized;
}

export interface PersonaContent {
  readonly id: PersonaId;
  /** Distinct, concrete pains for this persona (not the generic product problem). */
  readonly painPoints: readonly Localized[];
  /** Capability ids (from messaging.capabilities) to spotlight for this persona. */
  readonly spotlight: readonly string[];
  readonly steps: readonly ImplementationStep[];
  readonly faqs: readonly FaqItem[];
  readonly secondaryCta: Cta;
  /** Whether this persona is genuinely supported by real platform capabilities. */
  readonly supported: boolean;
}

const CTA_BROWSE: Cta = { label: { en: "Browse courses", ar: "تصفّح الدورات" }, href: "/courses", intent: "secondary", event: "secondary_cta_clicked" };
const CTA_PRICING: Cta = { label: { en: "See pricing", ar: "اطّلع على الأسعار" }, href: "/pricing", intent: "secondary", event: "secondary_cta_clicked" };
const CTA_COMPARE: Cta = { label: { en: "Compare platforms", ar: "قارن المنصّات" }, href: "/compare", intent: "secondary", event: "secondary_cta_clicked" };

export const personasContent: Readonly<Record<PersonaId, PersonaContent>> = {
  companies: {
    id: "companies",
    supported: true,
    painPoints: [
      { en: "Training is spread across tools that don't talk to each other, so nothing is measurable.", ar: "التدريب موزّع على أدوات لا تتكامل، فلا شيء قابل للقياس." },
      { en: "English-first tools make Arabic programs feel second-class to your teams.", ar: "الأدوات التي تُبنى للإنجليزية أولًا تجعل البرامج العربية تبدو من الدرجة الثانية لفِرقك." },
      { en: "Proving completion and impact to leadership is manual and slow.", ar: "إثبات الإتمام والأثر للإدارة عملية يدوية وبطيئة." },
    ],
    spotlight: ["org_admin", "cohorts", "reporting", "certificates", "bilingual_rtl"],
    steps: [
      { title: { en: "Set up your organization", ar: "جهّز مؤسستك" }, body: { en: "Add teams and administer learners from one place.", ar: "أضِف الفِرق وأدِر المتعلّمين من مكان واحد." } },
      { title: { en: "Launch role-based programs", ar: "أطلق برامج قائمة على الأدوار" }, body: { en: "Assign self-paced courses and live cohorts to the right people.", ar: "خصّص الدورات الذاتية والمجموعات المباشرة للأشخاص المناسبين." } },
      { title: { en: "Report on outcomes", ar: "ارفع تقارير النتائج" }, body: { en: "Track completion and issue verifiable certificates.", ar: "تابِع الإتمام وأصدِر شهادات قابلة للتحقّق." } },
    ],
    faqs: [
      { q: { en: "Can we administer learners at scale?", ar: "هل يمكننا إدارة المتعلّمين على نطاق واسع؟" }, a: { en: "Yes — organization administration and reporting are built in.", ar: "نعم — إدارة المؤسسة والتقارير مدمجة." } },
      { q: { en: "Do we get both self-paced and live formats?", ar: "هل نحصل على الصيغتين الذاتية والمباشرة؟" }, a: { en: "Both run on the same platform, assignable per team.", ar: "الصيغتان تعملان على المنصّة نفسها، وقابلتان للتخصيص لكل فريق." } },
      { q: { en: "Is completion verifiable for compliance-style tracking?", ar: "هل الإتمام قابل للتحقّق لأغراض التتبّع؟" }, a: { en: "Completion is captured as a verifiable certificate; we do not claim any specific regulatory certification.", ar: "يُوثَّق الإتمام بشهادة قابلة للتحقّق؛ ولا نَدّعي أي اعتماد تنظيمي محدّد." } },
    ],
    secondaryCta: CTA_PRICING,
  },
  academies: {
    id: "academies",
    supported: true,
    painPoints: [
      { en: "Stitching a website, payments, and a course tool together is fragile and expensive.", ar: "الجمع بين موقع ومدفوعات وأداة دورات هشّ ومكلِف." },
      { en: "Learners hesitate to pay when certificates can't be verified.", ar: "يتردّد المتعلّمون في الدفع عندما لا يمكن التحقّق من الشهادات." },
      { en: "Running live cohorts alongside on-demand courses means two systems.", ar: "تشغيل مجموعات مباشرة إلى جانب الدورات عند الطلب يعني نظامين." },
    ],
    spotlight: ["courses", "commerce", "cohorts", "certificates", "reporting"],
    steps: [
      { title: { en: "Publish your catalog", ar: "انشر كتالوجك" }, body: { en: "Bring courses and cohorts online under your brand.", ar: "انقل الدورات والمجموعات إلى الإنترنت بهويتك." } },
      { title: { en: "Sell and enroll", ar: "بِع وسجّل" }, body: { en: "Take course purchases and subscriptions with invoicing.", ar: "استقبل شراء الدورات والاشتراكات مع الفوترة." } },
      { title: { en: "Deliver and certify", ar: "قدّم وشهّد" }, body: { en: "Run the program end to end and issue verifiable certificates.", ar: "أدِر البرنامج من البداية إلى النهاية وأصدِر شهادات قابلة للتحقّق." } },
    ],
    faqs: [
      { q: { en: "Can I sell courses and memberships?", ar: "هل يمكنني بيع الدورات والاشتراكات؟" }, a: { en: "Yes — course purchases and subscriptions are supported with invoicing.", ar: "نعم — شراء الدورات والاشتراكات مدعوم مع الفوترة." } },
      { q: { en: "Can I run live cohorts too?", ar: "هل يمكنني تشغيل مجموعات مباشرة أيضًا؟" }, a: { en: "Yes — self-paced courses and live cohorts run together.", ar: "نعم — الدورات الذاتية والمجموعات المباشرة تعملان معًا." } },
      { q: { en: "Do learners get recognized certificates?", ar: "هل يحصل المتعلّمون على شهادات معترف بها؟" }, a: { en: "Learners receive verifiable certificates they and employers can check.", ar: "يحصل المتعلّمون على شهادات قابلة للتحقّق يمكنهم وأصحاب العمل مراجعتها." } },
    ],
    secondaryCta: CTA_COMPARE,
  },
  instructors: {
    id: "instructors",
    supported: true,
    painPoints: [
      { en: "Building your own course infrastructure pulls you away from teaching.", ar: "بناء بنية تحتية لدوراتك يُبعدك عن التدريس." },
      { en: "Reaching an Arabic and English audience usually means two setups.", ar: "الوصول إلى جمهور عربي وإنجليزي يعني عادةً إعدادين." },
      { en: "Assessments and certificates are hard to run credibly on your own.", ar: "تشغيل الاختبارات والشهادات بمصداقية بمفردك أمرٌ صعب." },
    ],
    spotlight: ["instructor_studio", "courses", "assessments", "certificates", "commerce"],
    steps: [
      { title: { en: "Author in the studio", ar: "ألّف في الاستوديو" }, body: { en: "Build your course with lessons, media, and assessments.", ar: "ابنِ دورتك بالدروس والوسائط والاختبارات." } },
      { title: { en: "Publish bilingually", ar: "انشر بلغتين" }, body: { en: "Reach an Arabic-first and English audience from one course.", ar: "اصِل إلى جمهور عربي أولًا وإنجليزي من دورة واحدة." } },
      { title: { en: "Earn and certify", ar: "اربح وشهّد" }, body: { en: "Sell your course and issue verifiable certificates.", ar: "بِع دورتك وأصدِر شهادات قابلة للتحقّق." } },
    ],
    faqs: [
      { q: { en: "Do I need my own website or payments?", ar: "هل أحتاج موقعي أو مدفوعاتي الخاصة؟" }, a: { en: "No — authoring, publishing, and course purchases are handled for you.", ar: "لا — التأليف والنشر وشراء الدورات مُتكفَّل بها." } },
      { q: { en: "Can I assess learners?", ar: "هل يمكنني تقييم المتعلّمين؟" }, a: { en: "Yes — assessments and assignments are part of the studio.", ar: "نعم — الاختبارات والواجبات جزء من الاستوديو." } },
      { q: { en: "How do I start teaching?", ar: "كيف أبدأ التدريس؟" }, a: { en: "Apply to teach and start authoring once approved.", ar: "تقدّم للتدريس وابدأ التأليف بعد الموافقة." } },
    ],
    secondaryCta: CTA_BROWSE,
  },
  public_sector: {
    id: "public_sector",
    supported: true,
    painPoints: [
      { en: "Large public programs need Arabic delivery with real administration.", ar: "تحتاج البرامج العامة الكبيرة إلى تقديم بالعربية مع إدارة حقيقية." },
      { en: "Tracking participation and completion across many cohorts is hard.", ar: "تتبّع المشاركة والإتمام عبر مجموعات كثيرة أمرٌ صعب." },
      { en: "Issuing trustworthy completion records at scale is manual.", ar: "إصدار سجلّات إتمام موثوقة على نطاق واسع عملية يدوية." },
    ],
    spotlight: ["cohorts", "org_admin", "reporting", "certificates", "bilingual_rtl"],
    steps: [
      { title: { en: "Structure the program", ar: "هيكِل البرنامج" }, body: { en: "Organize participants into cohorts with administration.", ar: "نظّم المشاركين في مجموعات مع الإدارة." } },
      { title: { en: "Deliver in Arabic", ar: "قدّم بالعربية" }, body: { en: "Run bilingual, RTL-first learning at scale.", ar: "أدِر تعلّمًا ثنائي اللغة وعربي أولًا على نطاق واسع." } },
      { title: { en: "Report and certify", ar: "ارفع تقارير وشهّد" }, body: { en: "Track completion and issue verifiable certificates.", ar: "تابِع الإتمام وأصدِر شهادات قابلة للتحقّق." } },
    ],
    faqs: [
      { q: { en: "Can we run cohort-based public programs?", ar: "هل يمكننا تشغيل برامج عامة قائمة على المجموعات؟" }, a: { en: "Yes — cohorts, administration, and reporting support program delivery.", ar: "نعم — المجموعات والإدارة والتقارير تدعم تقديم البرامج." } },
      { q: { en: "Is Arabic a first-class experience?", ar: "هل العربية تجربة من الدرجة الأولى؟" }, a: { en: "Arabic is first-class with full RTL across the product.", ar: "العربية من الدرجة الأولى مع دعم كامل للاتجاه من اليمين إلى اليسار عبر المنتج." } },
      { q: { en: "Do you hold specific government compliance certifications?", ar: "هل لديكم اعتمادات امتثال حكومية محدّدة؟" }, a: { en: "We support program delivery, administration, and verifiable certificates; we do not claim any specific regulatory certification here.", ar: "ندعم تقديم البرامج والإدارة والشهادات القابلة للتحقّق؛ ولا نَدّعي هنا أي اعتماد تنظيمي محدّد." } },
    ],
    secondaryCta: CTA_COMPARE,
  },
};

export const personaOrder: readonly PersonaId[] = ["companies", "academies", "instructors", "public_sector"];

/** URL slug ↔ persona id. Stable routes under /solutions. */
export const personaSlug: Readonly<Record<PersonaId, string>> = {
  companies: "enterprise",
  academies: "academies",
  instructors: "instructors",
  public_sector: "government",
};

export const slugToPersona: Readonly<Record<string, PersonaId>> = Object.fromEntries(
  Object.entries(personaSlug).map(([id, slug]) => [slug, id as PersonaId]),
) as Readonly<Record<string, PersonaId>>;

export function personaFromSlug(slug: string) {
  const id = slugToPersona[slug];
  if (!id) return null;
  return { id, persona: personaById[id], content: personasContent[id] };
}
