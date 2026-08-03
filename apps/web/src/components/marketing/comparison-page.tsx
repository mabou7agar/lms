"use client";

import Link from "next/link";
import { Check, Minus, CircleDot, ArrowRight, Clock } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { Button } from "@/components/ui/button";
import { Reveal } from "@/components/landing/reveal";
import { comparisons, getCompetitor, type CellSupport, type ComparisonCell } from "@/config/comparison";
import { localized } from "@/config/messaging";
import { track } from "@/lib/analytics/track";

const T = {
  overviewTitle: { en: "How HElbaron compares", ar: "كيف تُقارَن HElbaron" },
  overviewLead: {
    en: "Honest, category-level comparisons — operating models, not unverified prices. Pick what fits your program.",
    ar: "مقارنات صادقة على مستوى الفئة — نماذج التشغيل، لا أسعار غير مُتحقَّق منها. اختر ما يناسب برنامجك.",
  },
  helbaron: { en: "HElbaron", ar: "HElbaron" },
  bestForHelbaron: { en: "Choose HElbaron when", ar: "اختر HElbaron عندما" },
  bestForCompetitor: { en: "Choose the other when", ar: "اختر الآخر عندما" },
  operatingModel: { en: "Operating model", ar: "نموذج التشغيل" },
  lastReviewed: { en: "Last reviewed", ar: "آخر مراجعة" },
  reviewNote: {
    en: "Editorial review date. Capabilities vary by plan/plugin/hosting; verify current details with each vendor.",
    ar: "تاريخ المراجعة التحريرية. تختلف القدرات حسب الخطة/الإضافة/الاستضافة؛ تحقّق من التفاصيل الحالية مع كل مزوّد.",
  },
  ctaPrimary: { en: "Talk to our team", ar: "تحدّث إلى فريقنا" },
  ctaSecondary: { en: "See pricing", ar: "اطّلع على الأسعار" },
  evidenceTitle: { en: "Customer evidence", ar: "أدلّة العملاء" },
  evidenceEmpty: {
    en: "Verified case studies will appear here as customers publish results. We don't display proof we can't stand behind.",
    ar: "ستظهر دراسات الحالة المُوثّقة هنا عندما ينشر العملاء نتائجهم. لا نعرض أدلّة لا يمكننا ضمانها.",
  },
  vs: { en: "vs", ar: "مقابل" },
} as const;

function SupportBadge({ cell }: { cell: ComparisonCell }) {
  const { locale } = useI18n();
  const map: Record<CellSupport, { icon: typeof Check; cls: string; label: { en: string; ar: string } }> = {
    yes: { icon: Check, cls: "text-primary", label: { en: "Yes", ar: "نعم" } },
    partial: { icon: CircleDot, cls: "text-gold", label: { en: "Partial", ar: "جزئي" } },
    varies: { icon: CircleDot, cls: "text-copper", label: { en: "Varies", ar: "يختلف" } },
    no: { icon: Minus, cls: "text-muted-foreground", label: { en: "No", ar: "لا" } },
  };
  const { icon: Icon, cls, label } = map[cell.support];
  return (
    <div className="flex flex-col gap-0.5">
      <span className={`inline-flex items-center gap-1.5 text-sm font-medium ${cls}`}>
        <Icon className="size-4" aria-hidden />
        {label[locale] ?? label.en}
      </span>
      {cell.note ? <span className="text-xs text-muted-foreground">{localized(cell.note, locale)}</span> : null}
    </div>
  );
}

export function ComparisonIndex() {
  const { locale } = useI18n();
  return (
    <div className="space-y-12 py-2">
      <Reveal>
        <header className="max-w-2xl">
          <h1 className="font-serif text-3xl font-semibold tracking-tight sm:text-4xl">{T.overviewTitle[locale]}</h1>
          <p className="mt-3 text-muted-foreground">{T.overviewLead[locale]}</p>
        </header>
      </Reveal>

      <div className="grid gap-4 sm:grid-cols-2">
        {Object.values(comparisons).map((c) => (
          <Reveal key={c.slug}>
            <Link
              href={`/compare/${c.slug}`}
              className="group flex h-full flex-col rounded-2xl border border-border/70 bg-card p-6 transition-colors hover:border-primary/40"
            >
              <p className="text-xs font-semibold uppercase tracking-[0.16em] text-copper">
                {T.helbaron[locale]} <span className="text-muted-foreground">{T.vs[locale]}</span> {c.name}
              </p>
              <h2 className="mt-2 font-serif text-xl font-semibold">{localized(c.category, locale)}</h2>
              <p className="mt-2 flex-1 text-sm text-muted-foreground">{localized(c.operatingModel, locale)}</p>
              <span className="mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-primary">
                {T.vs[locale]} {c.name} <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5 rtl:group-hover:-translate-x-0.5" aria-hidden />
              </span>
            </Link>
          </Reveal>
        ))}
      </div>

      {/* Neutral evidence state — never fabricated proof. */}
      <Reveal>
        <section aria-labelledby="evidence-heading" className="rounded-2xl border border-dashed border-border bg-surface/40 p-6 text-center">
          <h2 id="evidence-heading" className="text-sm font-semibold uppercase tracking-[0.14em] text-muted-foreground">
            {T.evidenceTitle[locale]}
          </h2>
          <p className="mx-auto mt-2 max-w-xl text-sm text-muted-foreground">{T.evidenceEmpty[locale]}</p>
        </section>
      </Reveal>
    </div>
  );
}

export function ComparisonDetail({ slug }: { slug: string }) {
  const { locale } = useI18n();
  const c = getCompetitor(slug);
  if (!c) return null;

  return (
    <div className="space-y-12 py-2">
      <Reveal>
        <header className="max-w-2xl">
          <p className="text-xs font-semibold uppercase tracking-[0.16em] text-copper">
            {T.helbaron[locale]} {T.vs[locale]} {c.name}
          </p>
          <h1 className="mt-2 font-serif text-3xl font-semibold tracking-tight sm:text-4xl">
            {localized(c.category, locale)}
          </h1>
          <p className="mt-3 text-muted-foreground">
            <span className="font-medium text-foreground">{T.operatingModel[locale]}: </span>
            {localized(c.operatingModel, locale)}
          </p>
        </header>
      </Reveal>

      {/* Comparison table (mobile: stacked cards). */}
      <Reveal>
        <div className="overflow-hidden rounded-2xl border border-border/70">
          <table className="w-full border-collapse text-start">
            <caption className="sr-only">
              {T.helbaron[locale]} {T.vs[locale]} {c.name}
            </caption>
            <thead>
              <tr className="bg-surface/60 text-start text-sm">
                <th scope="col" className="p-4 text-start font-semibold">{" "}</th>
                <th scope="col" className="p-4 text-start font-semibold text-primary">{T.helbaron[locale]}</th>
                <th scope="col" className="p-4 text-start font-semibold">{c.name}</th>
              </tr>
            </thead>
            <tbody>
              {c.rows.map((row) => (
                <tr key={row.id} className="border-t border-border/60">
                  <th scope="row" className="p-4 text-start align-top text-sm font-medium">
                    {localized(row.dimension, locale)}
                  </th>
                  <td className="p-4 align-top"><SupportBadge cell={row.helbaron} /></td>
                  <td className="p-4 align-top"><SupportBadge cell={row.competitor} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Reveal>

      {/* Honest "best for" guidance. */}
      <div className="grid gap-4 sm:grid-cols-2">
        <Reveal>
          <div className="h-full rounded-2xl border border-primary/25 bg-primary/[0.04] p-6">
            <h2 className="font-serif text-lg font-semibold text-primary">{T.bestForHelbaron[locale]}</h2>
            <p className="mt-2 text-sm text-muted-foreground">{localized(c.helbaronBestFor, locale)}</p>
          </div>
        </Reveal>
        <Reveal>
          <div className="h-full rounded-2xl border border-border/70 bg-card p-6">
            <h2 className="font-serif text-lg font-semibold">{T.bestForCompetitor[locale]}</h2>
            <p className="mt-2 text-sm text-muted-foreground">{localized(c.bestFor, locale)}</p>
          </div>
        </Reveal>
      </div>

      <Reveal>
        <div className="flex flex-col items-start gap-4 rounded-2xl border border-border/70 bg-card p-6 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex flex-wrap items-center gap-3">
            <Button asChild size="lg">
              <Link href="/enterprise" onClick={() => track("enterprise_demo_started", { locale, path: `/compare/${c.slug}` })}>
                {T.ctaPrimary[locale]}
              </Link>
            </Button>
            <Button asChild variant="outline" size="lg">
              <Link href="/pricing" onClick={() => track("primary_cta_clicked", { locale, intent: "primary", to: "/pricing" })}>
                {T.ctaSecondary[locale]}
              </Link>
            </Button>
          </div>
          <p className="inline-flex items-center gap-1.5 text-xs text-muted-foreground" title={T.reviewNote[locale]}>
            <Clock className="size-3.5" aria-hidden />
            {T.lastReviewed[locale]}: <time dateTime={c.lastReviewed}>{c.lastReviewed}</time>
          </p>
        </div>
      </Reveal>
    </div>
  );
}
