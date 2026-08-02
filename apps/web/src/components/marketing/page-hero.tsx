"use client";

import {
  GraduationCap, LayoutGrid, Users, Tag, ShoppingCart, Receipt, FileSignature,
} from "lucide-react";
import type { LucideIcon } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { pickLocale } from "@/config/theme";
import { pageHeroes } from "@/config/page-heroes";
import { Reveal } from "@/components/landing/reveal";

const ICONS: Record<string, LucideIcon> = {
  GraduationCap, LayoutGrid, Users, Tag, ShoppingCart, Receipt, FileSignature,
};

/**
 * Shared premium hero for public marketing/utility pages (categories, trainers, events, pricing,
 * about, contact, …). Matches the V2 visual system: dot-grid depth, copper eyebrow, serif display,
 * optional stat. RTL-safe (logical properties), reduced-motion safe.
 */
export function PageHero({ page }: { page: keyof typeof pageHeroes }) {
  const { locale } = useI18n();
  const h = pageHeroes[page];
  const Icon = ICONS[h.icon] ?? GraduationCap;

  return (
    <Reveal
      as="section"
      className="relative mb-10 overflow-hidden rounded-3xl border border-border/70 bg-card"
    >
      {/* Layered editorial depth */}
      <div className="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(90%_120%_at_100%_-20%,oklch(0.42_0.05_185/0.10)_0%,transparent_55%)]" aria-hidden />
      <div className="pointer-events-none absolute inset-0 -z-10 opacity-40 [background-image:radial-gradient(var(--border)_1px,transparent_1px)] [background-size:22px_22px] [mask-image:radial-gradient(80%_80%_at_100%_0%,#000_0%,transparent_75%)]" aria-hidden />
      <div className="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-copper/40 to-transparent" aria-hidden />

      <div className="relative grid items-center gap-6 p-8 sm:p-10 lg:grid-cols-[1.7fr_1fr]">
        <div>
          <div className="mb-3 inline-flex items-center gap-2 rounded-full border border-copper/25 bg-copper/[0.06] ps-2 pe-3 py-1 text-[0.7rem] font-semibold uppercase tracking-[0.16em] text-copper">
            <span className="grid size-4 place-items-center rounded-full bg-copper/15">
              <Icon className="size-2.5" aria-hidden />
            </span>
            {pickLocale(h.eyebrow, locale)}
          </div>
          <h1 className="text-h1 font-serif tracking-tight">
            {pickLocale(h.title, locale)} <span className="italic text-copper">{pickLocale(h.emphasis, locale)}</span>
          </h1>
          <p className="mt-4 max-w-xl text-muted-foreground">{pickLocale(h.subtitle, locale)}</p>
        </div>

        {h.stat ? (
          <div className="hidden justify-self-end lg:block">
            <div className="rounded-2xl border border-border/70 bg-surface/60 px-6 py-5 text-center shadow-sm">
              <div className="font-serif text-4xl font-bold text-primary">{h.stat.value}</div>
              <div className="mt-1 text-xs text-muted-foreground">{pickLocale(h.stat.label, locale)}</div>
            </div>
          </div>
        ) : null}
      </div>
    </Reveal>
  );
}
