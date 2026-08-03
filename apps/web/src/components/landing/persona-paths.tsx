"use client";

import Link from "next/link";
import { ArrowRight, GitCompareArrows, Tag } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { Reveal } from "@/components/landing/reveal";
import { personaById, localized } from "@/config/messaging";
import { personaOrder, personaSlug } from "@/config/personas-content";
import { track } from "@/lib/analytics/track";

const T = {
  eyebrow: { en: "Find your path", ar: "اعثر على مسارك" },
  title: { en: "Built for how you deliver learning", ar: "مصمّمة لطريقتك في تقديم التعلّم" },
  compare: { en: "Compare platforms", ar: "قارن المنصّات" },
  pricing: { en: "See pricing", ar: "اطّلع على الأسعار" },
} as const;

/** Homepage conversion band: routes visitors to persona journeys, comparison, and pricing. */
export function PersonaPaths() {
  const { locale } = useI18n();
  return (
    <section aria-labelledby="home-personas" className="mx-auto w-full max-w-6xl px-4 py-20 sm:py-24">
      <Reveal>
        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-copper">{T.eyebrow[locale]}</p>
        <h2 id="home-personas" className="mt-2 text-h2 font-serif">{T.title[locale]}</h2>
      </Reveal>

      <div className="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {personaOrder.map((id) => {
          const persona = personaById[id];
          return (
            <Reveal key={id}>
              <Link
                href={`/solutions/${personaSlug[id]}`}
                onClick={() => track("persona_selected", { persona: id, locale })}
                className="group flex h-full flex-col rounded-2xl border border-border/70 bg-card p-5 transition-colors hover:border-primary/40"
              >
                <h3 className="font-serif text-base font-semibold">{localized(persona.name, locale)}</h3>
                <p className="mt-1 flex-1 text-sm text-muted-foreground">{localized(persona.outcome, locale)}</p>
                <span className="mt-3 inline-flex items-center gap-1.5 text-sm font-medium text-primary">
                  <ArrowRight className="size-4 rtl:-scale-x-100 transition-transform group-hover:translate-x-0.5 rtl:group-hover:-translate-x-0.5" aria-hidden />
                </span>
              </Link>
            </Reveal>
          );
        })}
      </div>

      <div className="mt-6 flex flex-wrap gap-3">
        <Link
          href="/compare"
          onClick={() => track("secondary_cta_clicked", { locale, intent: "secondary", to: "/compare" })}
          className="inline-flex items-center gap-2 rounded-full border border-border/70 bg-surface/50 px-4 py-2 text-sm font-medium transition-colors hover:border-primary/40"
        >
          <GitCompareArrows className="size-4 text-copper" aria-hidden />
          {T.compare[locale]}
        </Link>
        <Link
          href="/pricing"
          onClick={() => track("secondary_cta_clicked", { locale, intent: "secondary", to: "/pricing" })}
          className="inline-flex items-center gap-2 rounded-full border border-border/70 bg-surface/50 px-4 py-2 text-sm font-medium transition-colors hover:border-primary/40"
        >
          <Tag className="size-4 text-copper" aria-hidden />
          {T.pricing[locale]}
        </Link>
      </div>
    </section>
  );
}
