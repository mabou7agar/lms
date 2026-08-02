"use client";

import Link from "next/link";
import { ArrowRight, Star, ShieldCheck } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { brandTheme, pickLocale } from "@/config/theme";
import type { HeroContent } from "@/lib/homepage/api";
import { Button } from "@/components/ui/button";
import { Reveal } from "./reveal";
import { PlatformPreview } from "./platform-preview";

const AVATARS = ["var(--primary)", "var(--copper)", "var(--gold)", "oklch(0.45 0.08 30)"];

/**
 * Landing hero. Renders CMS-managed content when provided (via the Homepage builder); otherwise
 * falls back to the built-in brand default so the section is never empty. The right column is a
 * composed preview of the real learner product (see PlatformPreview) — not a generic illustration.
 */
export function Hero({ content }: { content?: HeroContent }) {
  const { locale } = useI18n();
  const h = brandTheme.hero;

  const cmsHeadline = content?.headline ? pickLocale(content.headline, locale) : null;
  const subtitle = content?.subheadline ? pickLocale(content.subheadline, locale) : pickLocale(h.subtitle, locale);
  const primary = content?.cta_primary ?? h.primaryCta;
  const secondary = content?.cta_secondary ?? h.secondaryCta;
  const stats = brandTheme.stats.slice(0, 3);

  return (
    <section className="relative overflow-hidden border-b border-border/60">
      {/* Layered editorial background — controlled depth, not a generic gradient wash */}
      <div
        className="pointer-events-none absolute inset-0 -z-20 bg-[radial-gradient(90%_80%_at_82%_-10%,oklch(0.42_0.05_185/0.12)_0%,transparent_55%),radial-gradient(70%_60%_at_0%_0%,oklch(0.985_0.012_88)_0%,var(--background)_60%)]"
        aria-hidden
      />
      {/* Fine dot grid, faded toward the edges */}
      <div
        className="pointer-events-none absolute inset-0 -z-10 opacity-50 [background-image:radial-gradient(var(--border)_1px,transparent_1px)] [background-size:22px_22px] [mask-image:radial-gradient(70%_60%_at_50%_0%,#000_0%,transparent_75%)]"
        aria-hidden
      />
      {/* Warm glow behind the product preview */}
      <div
        className="pointer-events-none absolute end-[-6rem] top-8 -z-10 hidden size-[34rem] rounded-full bg-copper/[0.07] blur-3xl lg:block"
        aria-hidden
      />
      <div
        className="pointer-events-none absolute inset-x-0 top-0 -z-10 h-px bg-gradient-to-r from-transparent via-copper/40 to-transparent"
        aria-hidden
      />

      <div className="mx-auto grid max-w-6xl items-center gap-x-12 gap-y-10 px-4 py-14 sm:py-16 lg:grid-cols-[1.05fr_1fr] lg:py-20">
        {/* Left */}
        <Reveal>
          <div className="mb-5 inline-flex items-center gap-2 rounded-full border border-copper/25 bg-copper/[0.06] ps-2 pe-3 py-1 text-[0.7rem] font-semibold uppercase tracking-[0.16em] text-copper">
            <span className="grid size-4 place-items-center rounded-full bg-copper/15">
              <span className="size-1.5 rounded-full bg-copper" aria-hidden />
            </span>
            {pickLocale(h.eyebrow, locale)}
          </div>

          {cmsHeadline ? (
            <h1 className="text-display font-serif text-primary">{cmsHeadline}</h1>
          ) : (
            <h1 className="text-display font-serif tracking-tight">
              <span className="text-primary">{pickLocale(h.headlineLine1, locale)} </span>
              <span className="italic text-copper">{pickLocale(h.headlineEmphasis, locale)}</span>
              <span className="block text-primary">{pickLocale(h.headlineLine2, locale)}</span>
            </h1>
          )}

          <p className="mt-5 max-w-xl text-base leading-relaxed text-muted-foreground sm:text-lg">{subtitle}</p>

          <div className="mt-7 flex flex-wrap items-center gap-3">
            <Button asChild size="lg" className="shine relative overflow-hidden">
              <Link href={primary.href}>
                {pickLocale(primary.label, locale)}
                <ArrowRight className="size-4 rtl:rotate-180" aria-hidden />
              </Link>
            </Button>
            <Button asChild size="lg" variant="outline">
              <Link href={secondary.href}>{pickLocale(secondary.label, locale)}</Link>
            </Button>
          </div>

          {/* Social proof row */}
          <div className="mt-6 flex flex-wrap items-center gap-x-5 gap-y-3">
            <div className="flex items-center gap-2.5">
              <div className="flex -space-x-2 rtl:space-x-reverse">
                {AVATARS.map((c, i) => (
                  <span key={i} className="inline-block size-8 rounded-full border-2 border-background" style={{ backgroundColor: c }} aria-hidden />
                ))}
              </div>
              <div className="flex items-center gap-1.5">
                <span className="flex text-gold">
                  {[0, 1, 2, 3, 4].map((i) => <Star key={i} className="size-3.5 fill-current" aria-hidden />)}
                </span>
                <span className="text-sm font-semibold">{h.rating.value}</span>
              </div>
            </div>
            <span className="inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
              <ShieldCheck className="size-4 text-success" aria-hidden />
              {pickLocale(h.rating.text, locale)}
            </span>
          </div>

          {/* Inline stat band — kills dead space, adds density + proof above the fold */}
          <dl className="mt-8 grid max-w-lg grid-cols-3 gap-px overflow-hidden rounded-xl border border-border/70 bg-border/70">
            {stats.map((s) => (
              <div key={s.display} className="bg-card px-3 py-3 text-center">
                <dt className="font-serif text-xl font-bold text-primary sm:text-2xl">{s.display}</dt>
                <dd className="mt-0.5 text-[0.68rem] leading-tight text-muted-foreground">{pickLocale(s.label, locale)}</dd>
              </div>
            ))}
          </dl>
        </Reveal>

        {/* Right: composed real-product preview, in a subtle gradient frame for depth */}
        <Reveal delay={120}>
          <div className="relative mx-auto w-full max-w-lg lg:max-w-none">
            <div className="rounded-[1.4rem] bg-gradient-to-br from-copper/25 via-border/40 to-primary/20 p-px shadow-2xl shadow-primary/10">
              <div className="rounded-[1.35rem] bg-background/40 p-1.5 backdrop-blur-sm">
                <PlatformPreview />
              </div>
            </div>
          </div>
        </Reveal>
      </div>
    </section>
  );
}
