"use client";

import Link from "next/link";
import {
  Users, CalendarClock, Video, CheckCircle2, MessageSquare, Award, Wrench, Presentation,
  MapPin, Package, Handshake, Layers, ShieldCheck, FileText, Headset, BarChart3, LifeBuoy,
  Compass, Settings, TrendingUp, Rocket, GraduationCap, ArrowRight,
} from "lucide-react";
import type { LucideIcon } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { brandTheme, pickLocale, type BrandTheme } from "@/config/theme";
import { Button } from "@/components/ui/button";
import { Reveal } from "@/components/landing/reveal";
import { CountUp } from "@/components/landing/count-up";

const ICONS: Record<string, LucideIcon> = {
  Users, CalendarClock, Video, CheckCircle2, MessageSquare, Award, Wrench, Presentation,
  MapPin, Package, Handshake, Layers, ShieldCheck, FileText, Headset, BarChart3, LifeBuoy,
  Compass, Settings, TrendingUp, Rocket, GraduationCap,
};

// Per-service accent so cohorts / workshops / enterprise / advisory read as distinct products.
type Accent = { chip: string; icon: string; ring: string; dot: string };
const ACCENTS: Record<string, Accent> = {
  cohorts: { chip: "border-copper/25 bg-copper/[0.06] text-copper", icon: "bg-copper/10 text-copper", ring: "hover:border-copper/40", dot: "bg-copper" },
  workshops: { chip: "border-gold/30 bg-gold/10 text-gold", icon: "bg-gold/15 text-gold", ring: "hover:border-gold/50", dot: "bg-gold" },
  enterprise: { chip: "border-primary/25 bg-primary/[0.06] text-primary", icon: "bg-primary/10 text-primary", ring: "hover:border-primary/40", dot: "bg-primary" },
  advisory: { chip: "border-copper/25 bg-copper/[0.06] text-copper", icon: "bg-copper/10 text-copper", ring: "hover:border-copper/40", dot: "bg-copper" },
};

export function ServicePage({ pageKey }: { pageKey: keyof BrandTheme["servicePages"] }) {
  const { locale } = useI18n();
  const p = brandTheme.servicePages[pageKey];
  const a = ACCENTS[pageKey] ?? ACCENTS.enterprise;

  return (
    <div className="space-y-16 py-4 sm:space-y-20">
      {/* Hero */}
      <section className="relative overflow-hidden rounded-3xl border border-border/70 bg-card">
        <div className="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(90%_120%_at_100%_-10%,oklch(0.42_0.05_185/0.10)_0%,transparent_55%)]" aria-hidden />
        <div className="pointer-events-none absolute inset-0 -z-10 opacity-40 [background-image:radial-gradient(var(--border)_1px,transparent_1px)] [background-size:22px_22px] [mask-image:radial-gradient(80%_80%_at_100%_0%,#000_0%,transparent_75%)]" aria-hidden />
        <div className="relative grid items-center gap-10 p-8 sm:p-12 lg:grid-cols-[1.1fr_0.9fr]">
          <Reveal>
            <div className={`mb-4 inline-flex items-center gap-2 rounded-full border ps-2 pe-3 py-1 text-[0.7rem] font-semibold uppercase tracking-[0.16em] ${a.chip}`}>
              <span className={`size-1.5 rounded-full ${a.dot}`} aria-hidden />
              {pickLocale(p.eyebrow, locale)}
            </div>
            <h1 className="text-display font-serif leading-[1.05] tracking-tight">
              {pickLocale(p.title, locale)} <span className="italic text-copper">{pickLocale(p.emphasis, locale)}</span>
            </h1>
            <p className="mt-5 max-w-xl text-muted-foreground sm:text-lg">{pickLocale(p.subtitle, locale)}</p>
            <div className="mt-8 flex flex-wrap gap-3">
              <Button asChild size="lg" className="shine relative overflow-hidden">
                <Link href={p.primaryCta.href}>
                  {pickLocale(p.primaryCta.label, locale)}
                  <ArrowRight className="size-4 rtl:rotate-180" aria-hidden />
                </Link>
              </Button>
              <Button asChild size="lg" variant="outline">
                <Link href={p.secondaryCta.href}>{pickLocale(p.secondaryCta.label, locale)}</Link>
              </Button>
            </div>
          </Reveal>

          {/* Composed highlight panel (real data) replaces the generic illustration */}
          <Reveal delay={120}>
            <div className="rounded-2xl border border-border/70 bg-surface/50 p-2 shadow-xl shadow-primary/10">
              <ul className="divide-y divide-border/70 overflow-hidden rounded-xl bg-card">
                {p.highlights.map((h) => (
                  <li key={h.label.en} className="flex items-center justify-between gap-4 p-5">
                    <span className="text-sm font-medium text-muted-foreground">{pickLocale(h.label, locale)}</span>
                    <span className="font-serif text-2xl font-bold text-primary">
                      <CountUp to={h.num} suffix={h.suffix} />
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          </Reveal>
        </div>
      </section>

      {/* Features */}
      <div className="stagger-in grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {p.features.map((f) => {
          const Icon = ICONS[f.icon] ?? Users;
          return (
            <div key={f.icon + f.title.en} className={`group rounded-2xl border border-border bg-card p-6 transition-all duration-300 hover:-translate-y-0.5 hover:shadow-lg ${a.ring}`}>
              <span className={`mb-4 flex size-11 items-center justify-center rounded-xl transition-transform duration-300 group-hover:scale-105 ${a.icon}`}>
                <Icon className="size-5" aria-hidden />
              </span>
              <h3 className="font-serif text-lg font-semibold">{pickLocale(f.title, locale)}</h3>
              <p className="mt-1.5 text-sm leading-relaxed text-muted-foreground">{pickLocale(f.desc, locale)}</p>
            </div>
          );
        })}
      </div>

      {/* Highlights band */}
      <Reveal>
        <div className="relative overflow-hidden rounded-3xl bg-primary px-6 py-14 text-primary-foreground">
          <div className="pointer-events-none absolute -end-10 -top-10 size-56 rounded-full bg-gold/10 blur-2xl" aria-hidden />
          <div className="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-gold/40 to-transparent" aria-hidden />
          <div className="relative grid gap-8 sm:grid-cols-3">
            {p.highlights.map((h) => (
              <div key={h.label.en} className="text-center">
                <div className="font-serif text-4xl font-bold text-gold sm:text-5xl">
                  <CountUp to={h.num} suffix={h.suffix} />
                </div>
                <div className="mt-2 text-sm text-primary-foreground/75">{pickLocale(h.label, locale)}</div>
              </div>
            ))}
          </div>
        </div>
      </Reveal>

      {/* CTA */}
      <Reveal className="relative mx-auto max-w-3xl overflow-hidden rounded-3xl border border-border bg-card px-6 py-14 text-center shadow-sm">
        <div className="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-copper/40 to-transparent" aria-hidden />
        <h2 className="text-h2 font-serif">
          {pickLocale(brandTheme.finalCta.title1, locale)}{" "}
          <span className="italic text-copper">{pickLocale(brandTheme.finalCta.title2, locale)}</span>
        </h2>
        <div className="mt-7 flex flex-wrap justify-center gap-3">
          <Button asChild size="lg">
            <Link href={brandTheme.finalCta.primary.href}>{pickLocale(brandTheme.finalCta.primary.label, locale)}</Link>
          </Button>
          <Button asChild size="lg" variant="outline">
            <Link href={p.primaryCta.href}>{pickLocale(p.primaryCta.label, locale)}</Link>
          </Button>
        </div>
      </Reveal>
    </div>
  );
}
