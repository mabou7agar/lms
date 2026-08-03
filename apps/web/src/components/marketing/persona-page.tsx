"use client";

import { useEffect, useRef } from "react";
import Link from "next/link";
import { ArrowRight, Check, ChevronRight } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { Button } from "@/components/ui/button";
import { Reveal } from "@/components/landing/reveal";
import { messaging, personaById, localized, type Cta, type PersonaId } from "@/config/messaging";
import { personasContent, personaSlug } from "@/config/personas-content";
import { track } from "@/lib/analytics/track";

const capabilityLabel = new Map(messaging.capabilities.map((c) => [c.id, c.label] as const));

const T = {
  problem: { en: "The challenge", ar: "التحدّي" },
  outcome: { en: "What you get", ar: "ما ستحصل عليه" },
  capabilities: { en: "Capabilities that matter here", ar: "قدرات تهمّ هنا" },
  how: { en: "How it works", ar: "كيف يعمل" },
  faq: { en: "Frequently asked", ar: "الأسئلة الشائعة" },
  finalTitle: { en: "Ready to start?", ar: "جاهز للبدء؟" },
} as const;

function CtaLink({ cta, path, locale, className, variant }: {
  cta: Cta;
  path: string;
  locale: "en" | "ar";
  className?: string;
  variant?: "default" | "outline";
}) {
  const fire = () => {
    if (cta.event === "enterprise_demo_started") track("enterprise_demo_started", { locale, path });
    else track(cta.event, { locale, intent: cta.intent, to: cta.href });
  };
  return (
    <Button asChild size="lg" variant={variant} className={className}>
      <Link href={cta.href} onClick={fire}>
        {localized(cta.label, locale)}
        <ArrowRight className="size-4 rtl:-scale-x-100" aria-hidden />
      </Link>
    </Button>
  );
}

export function PersonaPage({ id }: { id: PersonaId }) {
  const { locale } = useI18n();
  const persona = personaById[id];
  const content = personasContent[id];
  const path = `/solutions/${personaSlug[id]}`;

  const fired = useRef(false);
  useEffect(() => {
    if (fired.current) return;
    fired.current = true;
    track("page_view", { locale, path });
    track("persona_selected", { persona: id, locale });
  }, [id, locale, path]);

  return (
    <div className="space-y-16 py-2 sm:space-y-20">
      {/* Hero */}
      <section className="relative overflow-hidden rounded-3xl border border-border/70 bg-card p-8 sm:p-12">
        <div className="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(90%_120%_at_100%_-10%,oklch(0.42_0.05_185/0.10)_0%,transparent_55%)]" aria-hidden />
        <div className="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-copper/40 to-transparent" aria-hidden />
        <Reveal>
          <p className="text-xs font-semibold uppercase tracking-[0.18em] text-copper">{localized(persona.name, locale)}</p>
          <h1 className="mt-3 max-w-3xl font-serif text-3xl font-semibold tracking-tight sm:text-4xl">
            {localized(persona.outcome, locale)}
          </h1>
          <p className="mt-4 max-w-2xl text-muted-foreground">{localized(persona.problem, locale)}</p>
          <div className="mt-7 flex flex-wrap gap-3">
            <CtaLink cta={persona.primaryCta} path={path} locale={locale} />
            <CtaLink cta={content.secondaryCta} path={path} locale={locale} variant="outline" />
          </div>
        </Reveal>
      </section>

      {/* Problem → pain points */}
      <section aria-labelledby="persona-problem">
        <Reveal>
          <h2 id="persona-problem" className="font-serif text-2xl font-semibold">{T.problem[locale]}</h2>
        </Reveal>
        <div className="mt-6 grid gap-4 sm:grid-cols-3">
          {content.painPoints.map((p, i) => (
            <Reveal key={i}>
              <div className="h-full rounded-2xl border border-border/70 bg-card p-5 text-sm text-muted-foreground">
                {localized(p, locale)}
              </div>
            </Reveal>
          ))}
        </div>
      </section>

      {/* Capability spotlight */}
      <section aria-labelledby="persona-caps">
        <Reveal>
          <h2 id="persona-caps" className="font-serif text-2xl font-semibold">{T.capabilities[locale]}</h2>
        </Reveal>
        <ul className="mt-6 grid gap-3 sm:grid-cols-2">
          {content.spotlight.map((capId) => {
            const label = capabilityLabel.get(capId);
            if (!label) return null;
            return (
              <Reveal key={capId}>
                <li className="flex items-start gap-3 rounded-xl border border-border/60 bg-surface/40 p-4">
                  <span className="mt-0.5 grid size-6 shrink-0 place-items-center rounded-full bg-primary/10 text-primary">
                    <Check className="size-3.5" aria-hidden />
                  </span>
                  <span className="text-sm font-medium">{localized(label, locale)}</span>
                </li>
              </Reveal>
            );
          })}
        </ul>
      </section>

      {/* Implementation path */}
      <section aria-labelledby="persona-how">
        <Reveal>
          <h2 id="persona-how" className="font-serif text-2xl font-semibold">{T.how[locale]}</h2>
        </Reveal>
        <ol className="mt-6 grid gap-4 sm:grid-cols-3">
          {content.steps.map((step, i) => (
            <Reveal key={i}>
              <li className="h-full rounded-2xl border border-border/70 bg-card p-6">
                <span className="grid size-8 place-items-center rounded-full bg-copper/10 font-serif text-sm font-semibold text-copper">{i + 1}</span>
                <h3 className="mt-3 font-semibold">{localized(step.title, locale)}</h3>
                <p className="mt-1 text-sm text-muted-foreground">{localized(step.body, locale)}</p>
              </li>
            </Reveal>
          ))}
        </ol>
      </section>

      {/* FAQ */}
      <section aria-labelledby="persona-faq">
        <Reveal>
          <h2 id="persona-faq" className="font-serif text-2xl font-semibold">{T.faq[locale]}</h2>
        </Reveal>
        <div className="mt-6 divide-y divide-border/60 rounded-2xl border border-border/70">
          {content.faqs.map((f, i) => (
            <details key={i} className="group p-5">
              <summary className="flex cursor-pointer list-none items-center justify-between gap-4 font-medium">
                {localized(f.q, locale)}
                <ChevronRight className="size-4 shrink-0 text-muted-foreground transition-transform group-open:rotate-90 rtl:-scale-x-100 rtl:group-open:-rotate-90" aria-hidden />
              </summary>
              <p className="mt-3 text-sm text-muted-foreground">{localized(f.a, locale)}</p>
            </details>
          ))}
        </div>
      </section>

      {/* Final CTA */}
      <section className="rounded-3xl border border-primary/20 bg-primary/[0.04] p-8 text-center sm:p-12">
        <Reveal>
          <h2 className="font-serif text-2xl font-semibold sm:text-3xl">{T.finalTitle[locale]}</h2>
          <div className="mt-6 flex flex-wrap justify-center gap-3">
            <CtaLink cta={persona.primaryCta} path={path} locale={locale} />
            <CtaLink cta={content.secondaryCta} path={path} locale={locale} variant="outline" />
          </div>
        </Reveal>
      </section>
    </div>
  );
}
