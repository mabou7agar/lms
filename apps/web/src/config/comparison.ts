import type { Localized } from "@/config/messaging";

export type { Localized };

/**
 * Maintainable competitor-comparison data.
 *
 * Editorial rules (asserted by tests/marketing/comparison.test.ts):
 *  - compare CATEGORIES and OPERATING MODELS, not unverified current prices;
 *  - use `varies` (with a note) whenever a competitor capability depends on plan/plugin/hosting;
 *  - no absolute superiority claims and no defamatory language;
 *  - every competitor carries a `lastReviewed` date and "best for" guidance for BOTH products, so the
 *    page helps the reader choose honestly rather than selling.
 *
 * Prices are intentionally absent — they change and vary by region/plan.
 */

export type CellSupport = "yes" | "partial" | "varies" | "no";

export interface ComparisonCell {
  readonly support: CellSupport;
  /** Optional qualifier, e.g. "via plugins" or "on higher plans". Required when support is `varies`. */
  readonly note?: Localized;
}

export interface ComparisonRow {
  readonly id: string;
  readonly dimension: Localized;
  readonly helbaron: ComparisonCell;
  readonly competitor: ComparisonCell;
}

export interface Competitor {
  readonly slug: string;
  readonly name: string;
  readonly category: Localized;
  readonly operatingModel: Localized;
  /** Honest guidance: when the competitor is the better fit. */
  readonly bestFor: Localized;
  /** Honest guidance: when HElbaron is the better fit. */
  readonly helbaronBestFor: Localized;
  readonly rows: readonly ComparisonRow[];
  /** ISO date the comparison was last editorially reviewed. Not a live-verification claim. */
  readonly lastReviewed: string;
}

const ROW_BILINGUAL = (competitor: ComparisonCell): ComparisonRow => ({
  id: "bilingual_rtl",
  dimension: { en: "Arabic-first & full RTL", ar: "عربي أولًا ودعم كامل للاتجاه من اليمين إلى اليسار" },
  helbaron: { support: "yes" },
  competitor,
});

const ROW_MODEL = (competitor: ComparisonCell): ComparisonRow => ({
  id: "courses_and_cohorts",
  dimension: { en: "Self-paced courses + live cohorts in one platform", ar: "دورات ذاتية + مجموعات مباشرة في منصّة واحدة" },
  helbaron: { support: "yes" },
  competitor,
});

const ROW_CERTS = (competitor: ComparisonCell): ComparisonRow => ({
  id: "certificates",
  dimension: { en: "Verifiable certificates", ar: "شهادات قابلة للتحقّق" },
  helbaron: { support: "yes" },
  competitor,
});

const ROW_ORG = (competitor: ComparisonCell): ComparisonRow => ({
  id: "org_admin",
  dimension: { en: "Organization administration & reporting", ar: "إدارة المؤسسة والتقارير" },
  helbaron: { support: "yes" },
  competitor,
});

const ROW_HOSTING = (competitor: ComparisonCell): ComparisonRow => ({
  id: "hosting",
  dimension: { en: "Hosted (no servers to run)", ar: "مُستضاف (بدون خوادم تديرها)" },
  helbaron: { support: "yes" },
  competitor,
});

export const comparisons: Readonly<Record<string, Competitor>> = {
  moodle: {
    slug: "moodle",
    name: "Moodle",
    category: { en: "Open-source learning management system", ar: "نظام إدارة تعلّم مفتوح المصدر" },
    operatingModel: { en: "Self-hosted or partner-hosted; highly extensible via plugins.", ar: "استضافة ذاتية أو عبر شريك؛ قابل للتوسّع بشدّة عبر الإضافات." },
    bestFor: { en: "Institutions with technical teams that want maximum control and self-hosting.", ar: "المؤسسات ذات الفرق التقنية التي تريد أقصى تحكّم واستضافة ذاتية." },
    helbaronBestFor: { en: "Teams that want an Arabic-first, hosted platform for professional courses and cohorts without operating infrastructure.", ar: "الفرق التي تريد منصّة عربية أولًا ومُستضافة للدورات والمجموعات الاحترافية دون إدارة بنية تحتية." },
    rows: [
      ROW_HOSTING({ support: "varies", note: { en: "Self-hosted or partner-hosted", ar: "استضافة ذاتية أو عبر شريك" } }),
      ROW_BILINGUAL({ support: "yes", note: { en: "Mature RTL & Arabic language packs", ar: "حزم لغة عربية ودعم RTL ناضجة" } }),
      ROW_MODEL({ support: "varies", note: { en: "Cohorts/live sessions via plugins/integrations", ar: "المجموعات/الجلسات المباشرة عبر إضافات/تكاملات" } }),
      ROW_CERTS({ support: "varies", note: { en: "Via certificate plugins", ar: "عبر إضافات الشهادات" } }),
      ROW_ORG({ support: "yes" }),
    ],
    lastReviewed: "2026-08-01",
  },
  thinkific: {
    slug: "thinkific",
    name: "Thinkific",
    category: { en: "Hosted course-creation platform", ar: "منصّة مُستضافة لإنشاء الدورات" },
    operatingModel: { en: "Hosted SaaS focused on creators selling self-paced courses.", ar: "خدمة سحابية مُستضافة موجّهة لصانعي المحتوى لبيع الدورات الذاتية." },
    bestFor: { en: "Individual creators selling primarily English self-paced courses.", ar: "صانعو المحتوى الأفراد الذين يبيعون دورات ذاتية باللغة الإنجليزية أساسًا." },
    helbaronBestFor: { en: "Academies and organizations delivering Arabic-first courses AND live cohorts with administration.", ar: "الأكاديميات والمؤسسات التي تقدّم دورات عربية أولًا ومجموعات مباشرة مع الإدارة." },
    rows: [
      ROW_HOSTING({ support: "yes" }),
      ROW_BILINGUAL({ support: "varies", note: { en: "RTL/Arabic support varies", ar: "دعم العربية/الاتجاه من اليمين إلى اليسار يختلف" } }),
      ROW_MODEL({ support: "partial", note: { en: "Primarily self-paced; live cohorts limited", ar: "ذاتية أساسًا؛ المجموعات المباشرة محدودة" } }),
      ROW_CERTS({ support: "yes" }),
      ROW_ORG({ support: "varies", note: { en: "Organization features on higher plans", ar: "ميزات المؤسسة على الخطط الأعلى" } }),
    ],
    lastReviewed: "2026-08-01",
  },
};

export const competitorSlugs = Object.keys(comparisons);

export function getCompetitor(slug: string): Competitor | null {
  return comparisons[slug] ?? null;
}
