"use client";

import Link from "next/link";
import { ArrowRight } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { brandTheme, pickLocale } from "@/config/theme";
import { Button } from "@/components/ui/button";
import { Reveal } from "./reveal";

/**
 * Closing call-to-action on a deep brand surface — a decisive end to the page, not another
 * generic card. Uses the real finalCta brand copy; RTL-safe and reduced-motion friendly.
 */
export function FinalCta() {
  const { locale } = useI18n();
  const c = brandTheme.finalCta;

  return (
    <section className="px-4 py-20 sm:py-24">
      <Reveal className="relative mx-auto max-w-6xl overflow-hidden rounded-3xl bg-primary px-6 py-16 text-center sm:px-12 sm:py-20">
        <div
          className="pointer-events-none absolute inset-0 bg-[radial-gradient(80%_120%_at_50%_-20%,oklch(0.46_0.05_185)_0%,transparent_60%)]"
          aria-hidden
        />
        <div
          className="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-gold/50 to-transparent"
          aria-hidden
        />
        <div className="relative">
          <p className="mb-4 text-xs font-semibold uppercase tracking-[0.24em] text-gold">{brandTheme.name}</p>
          <h2 className="text-h2 mx-auto max-w-3xl font-serif text-primary-foreground">
            {pickLocale(c.title1, locale)}{" "}
            <span className="italic text-gold">{pickLocale(c.title2, locale)}</span>
          </h2>
          <p className="mx-auto mt-5 max-w-xl text-base leading-relaxed text-primary-foreground/80">
            {pickLocale(c.subtitle, locale)}
          </p>
          <div className="mt-9 flex flex-wrap items-center justify-center gap-3">
            <Button asChild size="lg" className="bg-gold text-gold-foreground hover:bg-gold/90">
              <Link href={c.primary.href}>
                {pickLocale(c.primary.label, locale)}
                <ArrowRight className="size-4 rtl:rotate-180" aria-hidden />
              </Link>
            </Button>
            <Button
              asChild
              size="lg"
              variant="outline"
              className="border-primary-foreground/30 bg-transparent text-primary-foreground hover:bg-primary-foreground/10 hover:text-primary-foreground"
            >
              <Link href={c.secondary.href}>{pickLocale(c.secondary.label, locale)}</Link>
            </Button>
          </div>
        </div>
      </Reveal>
    </section>
  );
}
