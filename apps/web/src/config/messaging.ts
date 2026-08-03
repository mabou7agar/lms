import type { Locale } from "@/lib/i18n/config";

/**
 * Source-controlled messaging system — the single source of truth for HElbaron's public positioning
 * and conversion copy (English + Arabic). Marketing surfaces read from here instead of scattering
 * inconsistent strings.
 *
 * Integrity rules enforced by tests (see tests/marketing/messaging.test.ts):
 *  - every claim maps to a REAL product capability in this repository (no invented features);
 *  - NO fabricated customer counts, logos, ratings, ROI percentages, compliance certifications
 *    (ISO/SOC2/…), fake scarcity, or unqualified "AI-powered" claims;
 *  - every CTA href resolves to a real application route;
 *  - English/Arabic parity across the whole tree.
 *
 * Anything that would require real external customer evidence is modelled as a {@link ProofSlot}
 * with `status: "awaiting_real_content"` so the UI renders a neutral/empty state and NEVER displays
 * false proof.
 */

export type Localized = { readonly en: string; readonly ar: string };

export function localized(value: Localized, locale: Locale): string {
  return value[locale] ?? value.en;
}

export type CtaIntent = "primary" | "secondary";

export interface Cta {
  readonly label: Localized;
  /** Must be a real route in this app (asserted by tests). */
  readonly href: string;
  readonly intent: CtaIntent;
  /** Conversion analytics event fired on activation (see lib/analytics/events). */
  readonly event: "primary_cta_clicked" | "secondary_cta_clicked" | "enterprise_demo_started";
}

export interface Objection {
  readonly objection: Localized;
  readonly response: Localized;
}

/**
 * Capability-based proof — statements that are true because the platform implements them. These are
 * NOT customer testimonials or metrics; they are verifiable product facts.
 */
export interface CapabilityProof {
  readonly id: string;
  readonly label: Localized;
}

/**
 * A slot that can only be filled with real, externally-sourced customer evidence. Until that exists
 * the UI must render a neutral placeholder or hide the section — it must never fabricate proof.
 */
export interface ProofSlot {
  readonly id: string;
  readonly kind: "logos" | "testimonial" | "case_study" | "metric" | "rating";
  readonly status: "awaiting_real_content";
}

export type PersonaId = "companies" | "academies" | "instructors" | "public_sector";

export interface Persona {
  readonly id: PersonaId;
  readonly name: Localized;
  readonly problem: Localized;
  readonly outcome: Localized;
  /** Ids into {@link capabilities} that are most relevant to this persona. */
  readonly capabilities: readonly string[];
  readonly primaryCta: Cta;
}

export interface MessagingSystem {
  readonly brand: string;
  readonly category: Localized;
  readonly targetCustomer: Localized;
  readonly problem: Localized;
  /** Outcome-led promise (never feature-led). */
  readonly promise: Localized;
  readonly differentiation: readonly Localized[];
  readonly capabilities: readonly CapabilityProof[];
  readonly objections: readonly Objection[];
  readonly personas: readonly Persona[];
  readonly proofSlots: readonly ProofSlot[];
  readonly cta: { readonly primary: Cta; readonly secondary: Cta };
}

const CTA_BROWSE: Cta = {
  label: { en: "Browse courses", ar: "تصفّح الدورات" },
  href: "/courses",
  intent: "secondary",
  event: "secondary_cta_clicked",
};

const CTA_DEMO: Cta = {
  label: { en: "Talk to our team", ar: "تحدّث إلى فريقنا" },
  href: "/enterprise",
  intent: "primary",
  event: "enterprise_demo_started",
};

/** The one messaging system consumed by every public surface. */
export const messaging: MessagingSystem = {
  brand: "HElbaron",
  category: {
    en: "Professional & enterprise learning platform for MENA",
    ar: "منصّة تعلّم احترافي ومؤسسي لمنطقة الشرق الأوسط وشمال أفريقيا",
  },
  targetCustomer: {
    en: "Organizations, training academies, and expert instructors delivering professional education across Arabic and English.",
    ar: "المؤسسات وأكاديميات التدريب والخبراء المتخصّصون الذين يقدّمون تعليمًا احترافيًا بالعربية والإنجليزية.",
  },
  problem: {
    en: "Professional learning in the region is scattered across tools that were never built Arabic-first — so programs are hard to run, certificates hard to trust, and outcomes hard to measure.",
    ar: "التعلّم الاحترافي في المنطقة موزّع على أدوات لم تُبنَ للعربية أولًا؛ فتصعُب إدارة البرامج، ويصعُب الوثوق بالشهادات، ويصعُب قياس النتائج.",
  },
  promise: {
    en: "Run credible, Arabic-first learning programs end to end — from enrollment to verifiable certificate — and see the outcomes.",
    ar: "أدِر برامج تعلّم موثوقة بالعربية أولًا من البداية إلى النهاية — من التسجيل حتى الشهادة القابلة للتحقّق — وقِس النتائج.",
  },
  differentiation: [
    { en: "Arabic-first and fully bilingual, with complete right-to-left support across every screen.", ar: "عربي أولًا وثنائي اللغة بالكامل، مع دعم كامل للاتجاه من اليمين إلى اليسار في كل الشاشات." },
    { en: "One platform for self-paced courses, live cohorts, and workshops — not three disconnected tools.", ar: "منصّة واحدة للدورات الذاتية والمجموعات المباشرة والورش — بدل ثلاث أدوات منفصلة." },
    { en: "Verifiable certificates learners and employers can trust.", ar: "شهادات قابلة للتحقّق يثق بها المتعلّمون وأصحاب العمل." },
    { en: "Built for organizations: administration, cohorts, and reporting in one place.", ar: "مصمّمة للمؤسسات: الإدارة والمجموعات والتقارير في مكان واحد." },
  ],
  capabilities: [
    { id: "bilingual_rtl", label: { en: "Bilingual Arabic/English with full RTL", ar: "ثنائية اللغة عربي/إنجليزي مع دعم كامل للاتجاه من اليمين إلى اليسار" } },
    { id: "courses", label: { en: "Self-paced course catalog & player", ar: "كتالوج دورات ذاتية ومشغّل تعلّم" } },
    { id: "cohorts", label: { en: "Live cohorts & workshops", ar: "مجموعات مباشرة وورش عمل" } },
    { id: "assessments", label: { en: "Assessments, assignments & gradebook", ar: "اختبارات وواجبات وسجلّ درجات" } },
    { id: "certificates", label: { en: "Verifiable certificates", ar: "شهادات قابلة للتحقّق" } },
    { id: "org_admin", label: { en: "Organization & learner administration", ar: "إدارة المؤسسة والمتعلّمين" } },
    { id: "reporting", label: { en: "Reporting & analytics", ar: "تقارير وتحليلات" } },
    { id: "instructor_studio", label: { en: "Instructor authoring studio", ar: "استوديو تأليف للمدرّبين" } },
    { id: "commerce", label: { en: "Course purchases, subscriptions & invoicing", ar: "شراء الدورات والاشتراكات والفوترة" } },
  ],
  objections: [
    {
      objection: { en: "Does it really work in Arabic, or is it a translation?", ar: "هل يعمل فعلًا بالعربية أم مجرّد ترجمة؟" },
      response: { en: "Arabic is a first-class language across the entire product, with full right-to-left layout — not a bolted-on translation.", ar: "العربية لغة أساسية عبر المنتج بالكامل، مع تخطيط كامل من اليمين إلى اليسار — وليست ترجمة مُضافة." },
    },
    {
      objection: { en: "Can we run both self-paced courses and live cohorts?", ar: "هل يمكننا تشغيل دورات ذاتية ومجموعات مباشرة معًا؟" },
      response: { en: "Yes — self-paced courses, live cohorts, and workshops run on the same platform.", ar: "نعم — الدورات الذاتية والمجموعات المباشرة والورش تعمل على المنصّة نفسها." },
    },
    {
      objection: { en: "How do we administer many learners and prove completion?", ar: "كيف ندير عددًا كبيرًا من المتعلّمين ونُثبت الإتمام؟" },
      response: { en: "Organization administration, cohorts, and reporting are built in, and completion is captured as a verifiable certificate.", ar: "إدارة المؤسسة والمجموعات والتقارير مدمجة، ويُوثَّق الإتمام بشهادة قابلة للتحقّق." },
    },
  ],
  personas: [
    {
      id: "companies",
      name: { en: "Companies & enterprise L&D", ar: "الشركات وفرق التعلّم والتطوير" },
      problem: { en: "Upskilling teams with credible, trackable programs — in Arabic — without stitching tools together.", ar: "تطوير مهارات الفرق ببرامج موثوقة وقابلة للتتبّع — بالعربية — دون الجمع بين أدوات متفرّقة." },
      outcome: { en: "Launch role-based programs, administer learners at scale, and report on completion and outcomes.", ar: "أطلق برامج قائمة على الأدوار، وأدِر المتعلّمين على نطاق واسع، وارفع تقارير الإتمام والنتائج." },
      capabilities: ["org_admin", "cohorts", "reporting", "certificates", "bilingual_rtl"],
      primaryCta: CTA_DEMO,
    },
    {
      id: "academies",
      name: { en: "Training academies & centers", ar: "أكاديميات ومراكز التدريب" },
      problem: { en: "Selling and delivering professional courses and cohorts online, with certificates learners trust.", ar: "بيع وتقديم دورات ومجموعات احترافية عبر الإنترنت، بشهادات يثق بها المتعلّمون." },
      outcome: { en: "Publish a branded catalog, sell courses and memberships, and run live cohorts end to end.", ar: "انشر كتالوجًا بهويتك، وبِع الدورات والاشتراكات، وأدِر مجموعات مباشرة من البداية إلى النهاية." },
      capabilities: ["courses", "commerce", "cohorts", "certificates", "reporting"],
      primaryCta: { label: { en: "See pricing", ar: "اطّلع على الأسعار" }, href: "/pricing", intent: "primary", event: "primary_cta_clicked" },
    },
    {
      id: "instructors",
      name: { en: "Independent instructors & experts", ar: "المدرّبون والخبراء المستقلّون" },
      problem: { en: "Turning expertise into a professional course or cohort without building infrastructure.", ar: "تحويل الخبرة إلى دورة أو مجموعة احترافية دون بناء بنية تحتية." },
      outcome: { en: "Author courses in the studio, publish to a bilingual audience, and issue certificates.", ar: "ألّف دوراتك في الاستوديو، وانشرها لجمهور ثنائي اللغة، وأصدر الشهادات." },
      capabilities: ["instructor_studio", "courses", "assessments", "certificates", "commerce"],
      primaryCta: { label: { en: "Apply to teach", ar: "تقدّم للتدريس" }, href: "/teach/apply", intent: "primary", event: "primary_cta_clicked" },
    },
    {
      id: "public_sector",
      name: { en: "Government & public-sector programs", ar: "برامج القطاع الحكومي والعام" },
      problem: { en: "Delivering large public training programs in Arabic with administration and reporting.", ar: "تقديم برامج تدريب عامة واسعة بالعربية مع الإدارة والتقارير." },
      outcome: { en: "Run cohort-based programs, administer participants, and report on completion.", ar: "أدِر برامج قائمة على المجموعات، ونظّم المشاركين، وارفع تقارير الإتمام." },
      capabilities: ["cohorts", "org_admin", "reporting", "certificates", "bilingual_rtl"],
      primaryCta: CTA_DEMO,
    },
  ],
  proofSlots: [
    { id: "customer_logos", kind: "logos", status: "awaiting_real_content" },
    { id: "featured_testimonial", kind: "testimonial", status: "awaiting_real_content" },
    { id: "case_studies", kind: "case_study", status: "awaiting_real_content" },
    { id: "outcome_metrics", kind: "metric", status: "awaiting_real_content" },
  ],
  cta: {
    primary: CTA_DEMO,
    secondary: CTA_BROWSE,
  },
};

/** Personas keyed by id, for persona landing pages. */
export const personaById: Readonly<Record<PersonaId, Persona>> = Object.fromEntries(
  messaging.personas.map((p) => [p.id, p]),
) as Readonly<Record<PersonaId, Persona>>;
