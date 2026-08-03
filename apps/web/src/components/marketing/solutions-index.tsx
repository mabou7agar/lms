"use client";

import { useEffect, useRef } from "react";
import Link from "next/link";
import { ArrowRight } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { Reveal } from "@/components/landing/reveal";
import { messaging, personaById, localized } from "@/config/messaging";
import { personaOrder, personaSlug } from "@/config/personas-content";
import { track } from "@/lib/analytics/track";

const T = {
  title: { en: "Solutions for your team", ar: "حلول لفريقك" },
  lead: {
    en: "One Arabic-first platform, tuned to how you deliver professional learning. Choose the path that fits.",
    ar: "منصّة عربية أولًا، مضبوطة على طريقتك في تقديم التعلّم الاحترافي. اختر المسار الذي يناسبك.",
  },
} as const;

export function SolutionsIndex() {
  const { locale } = useI18n();
  const fired = useRef(false);
  useEffect(() => {
    if (fired.current) return;
    fired.current = true;
    track("page_view", { locale, path: "/solutions" });
  }, [locale]);

  return (
    <div className="space-y-12 py-2">
      <Reveal>
        <header className="max-w-2xl">
          <h1 className="font-serif text-3xl font-semibold tracking-tight sm:text-4xl">{T.title[locale]}</h1>
          <p className="mt-3 text-muted-foreground">{T.lead[locale]}</p>
        </header>
      </Reveal>

      <div className="grid gap-4 sm:grid-cols-2">
        {personaOrder.map((id) => {
          const persona = personaById[id];
          return (
            <Reveal key={id}>
              <Link
                href={`/solutions/${personaSlug[id]}`}
                onClick={() => track("persona_selected", { persona: id, locale })}
                className="group flex h-full flex-col rounded-2xl border border-border/70 bg-card p-6 transition-colors hover:border-primary/40"
              >
                <h2 className="font-serif text-xl font-semibold">{localized(persona.name, locale)}</h2>
                <p className="mt-2 flex-1 text-sm text-muted-foreground">{localized(persona.outcome, locale)}</p>
                <span className="mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-primary">
                  {localized(messaging.cta.secondary.label, locale)}
                  <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5 rtl:-scale-x-100 rtl:group-hover:-translate-x-0.5" aria-hidden />
                </span>
              </Link>
            </Reveal>
          );
        })}
      </div>
    </div>
  );
}
