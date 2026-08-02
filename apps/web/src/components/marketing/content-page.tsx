"use client";

import Link from "next/link";
import {
  ArrowRight, Gift, BookOpen, Building2, Target, Sparkles, Languages, Award,
  Compass, Mail, MapPin, GraduationCap, Users,
} from "lucide-react";
import type { LucideIcon } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { pickLocale, type Localized, type LinkItem } from "@/config/theme";
import { Button } from "@/components/ui/button";
import { Reveal } from "@/components/landing/reveal";

const ICONS: Record<string, LucideIcon> = {
  Gift, BookOpen, Building2, Target, Sparkles, Languages, Award, Compass, Mail, MapPin,
  GraduationCap, Users,
};

export type ContentCard = {
  icon?: string;
  title: Localized;
  body: Localized;
  /** Optional supporting line, e.g. a pricing note or an email address. */
  meta?: Localized;
  cta?: LinkItem;
  highlight?: boolean;
};

export type ContentSection = { h: Localized; body: Localized[] };

export interface ContentPageProps {
  eyebrow: Localized;
  title: Localized;
  emphasis: Localized;
  subtitle: Localized;
  ctas?: LinkItem[];
  cards?: ContentCard[];
  sections?: ContentSection[];
}

/**
 * Editorial marketing content page — hero + optional feature/offer cards + prose sections.
 * Bilingual via pickLocale; matches the "Editorial Academy" design language (serif headings,
 * copper eyebrow, rounded cards).
 */
export function ContentPage({ eyebrow, title, emphasis, subtitle, ctas, cards, sections }: ContentPageProps) {
  const { locale } = useI18n();

  return (
    <div className="space-y-16 py-4">
      {/* Hero */}
      <Reveal
        as="section"
        className="relative overflow-hidden rounded-3xl border border-border/70 bg-card p-8 sm:p-12"
      >
        <div className="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(90%_120%_at_100%_-15%,oklch(0.42_0.05_185/0.10)_0%,transparent_55%)]" aria-hidden />
        <div className="pointer-events-none absolute inset-0 -z-10 opacity-40 [background-image:radial-gradient(var(--border)_1px,transparent_1px)] [background-size:22px_22px] [mask-image:radial-gradient(80%_80%_at_100%_0%,#000_0%,transparent_75%)]" aria-hidden />
        <div className="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-copper/40 to-transparent" aria-hidden />

        <div className="mb-4 inline-flex items-center gap-2 rounded-full border border-copper/25 bg-copper/[0.06] ps-2 pe-3 py-1 text-[0.7rem] font-semibold uppercase tracking-[0.16em] text-copper">
          <span className="size-1.5 rounded-full bg-copper" aria-hidden />
          {pickLocale(eyebrow, locale)}
        </div>
        <h1 className="max-w-3xl text-display font-serif leading-[1.05] tracking-tight">
          {pickLocale(title, locale)} <span className="italic text-copper">{pickLocale(emphasis, locale)}</span>
        </h1>
        <p className="mt-5 max-w-2xl text-muted-foreground sm:text-lg">{pickLocale(subtitle, locale)}</p>
        {ctas && ctas.length > 0 ? (
          <div className="mt-8 flex flex-wrap gap-3">
            {ctas.map((c, i) => (
              <Button key={c.href} asChild size="lg" variant={i === 0 ? "default" : "outline"} className={i === 0 ? "shine relative overflow-hidden" : undefined}>
                <Link href={c.href}>
                  {pickLocale(c.label, locale)}
                  {i === 0 ? <ArrowRight className="size-4 rtl:rotate-180" aria-hidden /> : null}
                </Link>
              </Button>
            ))}
          </div>
        ) : null}
      </Reveal>

      {/* Offer / feature cards */}
      {cards && cards.length > 0 ? (
        <div className="stagger-in grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {cards.map((card) => {
            const Icon = card.icon ? ICONS[card.icon] ?? GraduationCap : GraduationCap;
            return (
              <div
                key={card.title.en}
                className={
                  card.highlight
                    ? "card-hover flex flex-col rounded-2xl border border-primary/30 bg-primary text-primary-foreground p-6 shadow-lg"
                    : "card-hover flex flex-col rounded-2xl border bg-card p-6 hover:border-primary/30 hover:shadow-lg"
                }
              >
                <span
                  className={
                    card.highlight
                      ? "mb-4 flex size-11 items-center justify-center rounded-xl bg-white/15 text-primary-foreground"
                      : "mb-4 flex size-11 items-center justify-center rounded-xl bg-primary/10 text-primary"
                  }
                >
                  <Icon className="size-5" aria-hidden />
                </span>
                <h3 className="font-serif text-lg font-semibold">{pickLocale(card.title, locale)}</h3>
                <p className={card.highlight ? "mt-1.5 text-sm text-primary-foreground/80" : "mt-1.5 text-sm text-muted-foreground"}>
                  {pickLocale(card.body, locale)}
                </p>
                {card.meta ? (
                  <p className={card.highlight ? "mt-3 text-sm font-medium" : "mt-3 text-sm font-medium text-primary"}>
                    {pickLocale(card.meta, locale)}
                  </p>
                ) : null}
                {card.cta ? (
                  <div className="mt-auto pt-4">
                    <Button asChild variant={card.highlight ? "secondary" : "outline"} size="sm">
                      <Link href={card.cta.href}>
                        {pickLocale(card.cta.label, locale)}
                        <ArrowRight className="size-4 rtl:rotate-180" aria-hidden />
                      </Link>
                    </Button>
                  </div>
                ) : null}
              </div>
            );
          })}
        </div>
      ) : null}

      {/* Prose sections */}
      {sections && sections.length > 0 ? (
        <div className="mx-auto max-w-3xl space-y-10">
          {sections.map((s) => (
            <section key={s.h.en}>
              <h2 className="font-serif text-2xl font-semibold tracking-tight">{pickLocale(s.h, locale)}</h2>
              <div className="mt-3 space-y-3">
                {s.body.map((p, i) => (
                  <p key={i} className="text-sm leading-relaxed text-muted-foreground sm:text-base">
                    {pickLocale(p, locale)}
                  </p>
                ))}
              </div>
            </section>
          ))}
        </div>
      ) : null}
    </div>
  );
}
