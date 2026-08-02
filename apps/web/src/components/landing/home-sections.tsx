"use client";

import Link from "next/link";
import {
  Target, Languages, Users, Route, PlayCircle, ListChecks, PenLine, Radio, BadgeCheck,
  LineChart, ShieldCheck, FileCheck2, BarChart3, Headset, Quote, ArrowRight, Star, type LucideIcon,
} from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { brandTheme, pickLocale } from "@/config/theme";
import * as V2 from "@/config/home-v2";
import { Button } from "@/components/ui/button";
import { Section, SectionHeading } from "./section";
import { Reveal } from "./reveal";
import { AppShowcase } from "./app-showcase";

const ICONS: Record<string, LucideIcon> = {
  Target, Languages, Users, Route, PlayCircle, ListChecks, PenLine, Radio, BadgeCheck,
  LineChart, ShieldCheck, FileCheck2, BarChart3, Headset,
};
const swatch = (c: string) =>
  c === "copper" ? "text-copper bg-copper/10" : c === "gold" ? "text-gold bg-gold/15" : "text-primary bg-primary/10";

/* ── Proof metrics band ─────────────────────────────────────────────── */
export function ProofBand() {
  const { locale } = useI18n();
  return (
    <section className="border-y border-border/60 bg-card/50">
      <div className="mx-auto grid max-w-6xl grid-cols-2 gap-px overflow-hidden px-4 py-2 md:grid-cols-3 lg:grid-cols-6">
        {V2.proofMetrics.map((m, i) => (
          <Reveal as="div" key={i} delay={i * 40} className="px-3 py-5 text-center">
            <p className="font-serif text-2xl font-bold text-primary sm:text-3xl">{m.value}</p>
            <p className="mx-auto mt-1 max-w-[9rem] text-[0.68rem] leading-tight text-muted-foreground">{pickLocale(m.label, locale)}</p>
          </Reveal>
        ))}
      </div>
    </section>
  );
}

/* ── Why HElbaron ───────────────────────────────────────────────────── */
export function WhyHelbaron() {
  const { locale } = useI18n();
  return (
    <Section id="why" className="bg-background">
      <div className="grid gap-10 lg:grid-cols-[0.85fr_1.15fr] lg:gap-16">
        <Reveal>
          <div className="lg:sticky lg:top-24">
            <div className="mb-4 flex"><span className="inline-flex items-center gap-3 text-xs font-semibold uppercase tracking-[0.22em] text-copper"><span className="h-px w-8 bg-copper/50" aria-hidden />{pickLocale(V2.whyHeading.eyebrow, locale)}</span></div>
            <h2 className="text-h2 font-serif">
              {pickLocale(V2.whyHeading.title1, locale)}{" "}
              <span className="italic text-copper">{pickLocale(V2.whyHeading.title2, locale)}</span>
            </h2>
            <p className="mt-4 max-w-md text-muted-foreground">{pickLocale(V2.whyHeading.subtitle, locale)}</p>
          </div>
        </Reveal>
        <div className="grid gap-4 sm:grid-cols-2">
          {V2.whyPoints.map((p, i) => {
            const Icon = ICONS[p.icon] ?? Target;
            return (
              <Reveal as="div" key={i} delay={i * 70}>
                <div className="group h-full rounded-2xl border border-border bg-card p-6 transition-all duration-300 hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-lg">
                  <span className="inline-flex size-11 items-center justify-center rounded-xl bg-primary/10 text-primary transition-transform duration-300 group-hover:scale-105">
                    <Icon className="size-5" aria-hidden />
                  </span>
                  <h3 className="mt-4 font-serif text-lg font-semibold text-foreground">{pickLocale(p.title, locale)}</h3>
                  <p className="mt-2 text-sm leading-relaxed text-muted-foreground">{pickLocale(p.body, locale)}</p>
                </div>
              </Reveal>
            );
          })}
        </div>
      </div>
    </Section>
  );
}

/* ── Learning experience (real app) ─────────────────────────────────── */
export function LearningExperience() {
  const { locale } = useI18n();
  return (
    <Section id="experience" className="relative overflow-hidden bg-surface/40">
      <div className="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(70%_60%_at_85%_0%,oklch(0.42_0.05_185/0.08)_0%,transparent_60%)]" aria-hidden />
      <SectionHeading
        eyebrow={pickLocale(V2.experienceHeading.eyebrow, locale)}
        title1={pickLocale(V2.experienceHeading.title1, locale)}
        title2={pickLocale(V2.experienceHeading.title2, locale)}
        subtitle={pickLocale(V2.experienceHeading.subtitle, locale)}
      />
      <div className="grid items-center gap-10 lg:grid-cols-[1.1fr_0.9fr] lg:gap-14">
        <Reveal>
          <AppShowcase />
        </Reveal>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-1">
          {V2.experiencePanels.map((p, i) => {
            const Icon = ICONS[p.icon] ?? PlayCircle;
            return (
              <Reveal as="div" key={i} delay={i * 50}>
                <div className="flex gap-4 rounded-xl border border-transparent p-3 transition-colors hover:border-border hover:bg-card">
                  <span className="grid size-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><Icon className="size-5" aria-hidden /></span>
                  <div>
                    <h3 className="font-serif text-base font-semibold text-foreground">{pickLocale(p.title, locale)}</h3>
                    <p className="mt-0.5 text-sm leading-relaxed text-muted-foreground">{pickLocale(p.body, locale)}</p>
                  </div>
                </div>
              </Reveal>
            );
          })}
        </div>
      </div>
    </Section>
  );
}

/* ── Learning journey timeline ──────────────────────────────────────── */
export function LearningJourney() {
  const { locale } = useI18n();
  return (
    <Section id="journey" className="bg-background">
      <SectionHeading
        eyebrow={pickLocale(V2.journeyHeading.eyebrow, locale)}
        title1={pickLocale(V2.journeyHeading.title1, locale)}
        title2={pickLocale(V2.journeyHeading.title2, locale)}
        subtitle={pickLocale(V2.journeyHeading.subtitle, locale)}
      />
      <div className="relative">
        {/* connecting line (desktop) */}
        <div className="pointer-events-none absolute inset-x-0 top-7 hidden h-px bg-gradient-to-r from-transparent via-border to-transparent lg:block" aria-hidden />
        <ol className="grid gap-6 sm:grid-cols-2 lg:grid-cols-5 lg:gap-4">
          {V2.journeySteps.map((s, i) => (
            <Reveal as="li" key={s.step} delay={i * 80} className="relative">
              <span className="relative z-10 mx-auto flex size-14 items-center justify-center rounded-2xl border border-border bg-card font-serif text-lg font-bold text-primary shadow-sm">
                {s.step}
              </span>
              <div className="mt-4 text-center lg:px-1">
                <span className="text-[0.65rem] font-semibold uppercase tracking-[0.14em] text-copper">{pickLocale(s.meta, locale)}</span>
                <h3 className="mt-1 font-serif text-base font-semibold text-foreground">{pickLocale(s.title, locale)}</h3>
                <p className="mt-1 text-sm leading-relaxed text-muted-foreground">{pickLocale(s.body, locale)}</p>
              </div>
            </Reveal>
          ))}
        </ol>
      </div>
    </Section>
  );
}

/* ── Testimonials ───────────────────────────────────────────────────── */
export function Testimonials() {
  const { locale } = useI18n();
  return (
    <Section id="testimonials" className="bg-surface/40">
      <SectionHeading
        eyebrow={pickLocale(V2.testimonialsHeading.eyebrow, locale)}
        title1={pickLocale(V2.testimonialsHeading.title1, locale)}
        title2={pickLocale(V2.testimonialsHeading.title2, locale)}
      />
      <div className="grid gap-5 lg:grid-cols-3">
        {V2.testimonials.map((tst, i) => (
          <Reveal as="figure" key={i} delay={i * 80} className={`flex h-full flex-col rounded-2xl border border-border bg-card p-6 shadow-sm ${i === 0 ? "lg:row-span-1" : ""}`}>
            <Quote className="size-7 text-copper/40" aria-hidden />
            <blockquote className="mt-3 flex-1 text-[0.95rem] leading-relaxed text-foreground">{pickLocale(tst.quote, locale)}</blockquote>
            <div className="mt-5 flex items-center gap-1 text-gold" aria-hidden>
              {[0, 1, 2, 3, 4].map((s) => <Star key={s} className="size-3.5 fill-current" />)}
            </div>
            <figcaption className="mt-4 flex items-center gap-3 border-t border-border/70 pt-4">
              <span className={`grid size-10 place-items-center rounded-full font-serif text-sm font-bold ${swatch(tst.color)}`}>{tst.initial}</span>
              <div>
                <p className="text-sm font-semibold text-foreground">{tst.name}</p>
                <p className="text-xs text-muted-foreground">{pickLocale(tst.role, locale)}</p>
              </div>
            </figcaption>
          </Reveal>
        ))}
      </div>
    </Section>
  );
}

/* ── Instructor credibility ─────────────────────────────────────────── */
export function Instructors() {
  const { locale } = useI18n();
  return (
    <Section id="instructors" className="bg-background">
      <SectionHeading
        eyebrow={pickLocale(V2.instructorsHeading.eyebrow, locale)}
        title1={pickLocale(V2.instructorsHeading.title1, locale)}
        title2={pickLocale(V2.instructorsHeading.title2, locale)}
        subtitle={pickLocale(V2.instructorsHeading.subtitle, locale)}
      />
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {V2.instructors.map((ins, i) => (
          <Reveal as="div" key={i} delay={i * 60}>
            <div className="group h-full rounded-2xl border border-border bg-card p-6 text-center transition-all duration-300 hover:-translate-y-0.5 hover:shadow-lg">
              <span className={`mx-auto grid size-16 place-items-center rounded-2xl font-serif text-2xl font-bold ${swatch(ins.color)}`}>{ins.initial}</span>
              <h3 className="mt-4 font-serif text-base font-semibold text-foreground">{ins.name}</h3>
              <p className="mt-0.5 text-sm text-copper">{pickLocale(ins.field, locale)}</p>
              <p className="mt-2 text-xs text-muted-foreground">{pickLocale(ins.credential, locale)}</p>
            </div>
          </Reveal>
        ))}
      </div>
      <Reveal className="mt-10 text-center">
        <Button asChild size="lg" variant="outline">
          <Link href="/trainers">
            {pickLocale({ en: "Meet the instructors", ar: "تعرّف على المدرّبين" }, locale)}
            <ArrowRight className="size-4 rtl:rotate-180" aria-hidden />
          </Link>
        </Button>
      </Reveal>
    </Section>
  );
}

/* ── Enterprise trust ───────────────────────────────────────────────── */
export function EnterpriseTrust() {
  const { locale } = useI18n();
  return (
    <Section id="enterprise" className="bg-surface/40">
      <div className="overflow-hidden rounded-3xl border border-border bg-card">
        <div className="grid gap-10 p-8 sm:p-12 lg:grid-cols-[1.05fr_0.95fr] lg:gap-14">
          <Reveal>
            <div className="mb-4 flex"><span className="inline-flex items-center gap-3 text-xs font-semibold uppercase tracking-[0.22em] text-copper"><span className="h-px w-8 bg-copper/50" aria-hidden />{pickLocale(V2.enterpriseHeading.eyebrow, locale)}</span></div>
            <h2 className="text-h2 font-serif">
              {pickLocale(V2.enterpriseHeading.title1, locale)}{" "}
              <span className="italic text-copper">{pickLocale(V2.enterpriseHeading.title2, locale)}</span>
            </h2>
            <p className="mt-4 max-w-md text-muted-foreground">{pickLocale(V2.enterpriseHeading.subtitle, locale)}</p>

            <div className="mt-7 grid grid-cols-3 gap-3">
              {V2.enterpriseMetrics.map((m, i) => (
                <div key={i} className="rounded-xl border border-border/70 bg-surface/50 px-3 py-3 text-center">
                  <p className="font-serif text-xl font-bold text-primary sm:text-2xl">{m.value}</p>
                  <p className="mt-0.5 text-[0.65rem] leading-tight text-muted-foreground">{pickLocale(m.label, locale)}</p>
                </div>
              ))}
            </div>
            <div className="mt-7 flex flex-wrap gap-3">
              <Button asChild size="lg"><Link href="/enterprise">{pickLocale({ en: "Book a demo", ar: "احجز عرضًا" }, locale)}<ArrowRight className="size-4 rtl:rotate-180" aria-hidden /></Link></Button>
              <Button asChild size="lg" variant="outline"><Link href="/advisory">{pickLocale({ en: "HElbaron Advisory", ar: "استشارات HElbaron" }, locale)}</Link></Button>
            </div>
          </Reveal>

          <Reveal delay={100}>
            <ul className="grid h-full gap-3 sm:grid-cols-2 lg:grid-cols-1">
              {V2.enterpriseTrust.map((e, i) => {
                const Icon = ICONS[e.icon] ?? ShieldCheck;
                return (
                  <li key={i} className="flex items-center gap-4 rounded-xl border border-border bg-surface/40 p-4">
                    <span className="grid size-11 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary"><Icon className="size-5" aria-hidden /></span>
                    <span className="text-sm font-medium text-foreground">{pickLocale(e.label, locale)}</span>
                  </li>
                );
              })}
            </ul>
          </Reveal>
        </div>
      </div>
    </Section>
  );
}
