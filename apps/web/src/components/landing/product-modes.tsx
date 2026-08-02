"use client";

import Link from "next/link";
import {
  GraduationCap, Users, MapPin, Building2, Compass, ArrowRight, type LucideIcon,
} from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { brandTheme, pickLocale } from "@/config/theme";
import { Section, SectionHeading } from "./section";
import { Reveal } from "./reveal";

const ICONS: Record<string, LucideIcon> = {
  courses: GraduationCap,
  cohorts: Users,
  workshops: MapPin,
  enterprise: Building2,
  advisory: Compass,
};

// Per-mode accent so the five modes read as distinct products, not repeated boxes.
type Accent = "teal" | "copper" | "gold";
const ACCENT: Record<string, { ring: string; icon: string; dot: string }> = {
  teal: { ring: "hover:border-primary/40", icon: "bg-primary/10 text-primary", dot: "text-primary" },
  copper: { ring: "hover:border-copper/40", icon: "bg-copper/10 text-copper", dot: "text-copper" },
  gold: { ring: "hover:border-gold/50", icon: "bg-gold/15 text-gold", dot: "text-gold" },
};
const MODE_ACCENT: Accent[] = ["teal", "copper", "gold", "teal", "copper"];
// bento spans: Courses is the feature card (wide), rest fill a balanced grid.
const SPAN = ["lg:col-span-2", "", "", "", ""];

export function ProductModes() {
  const { locale } = useI18n();
  const modes = brandTheme.serviceLines;

  return (
    <Section id="modes" className="bg-background">
      <SectionHeading
        eyebrow={pickLocale(brandTheme.serviceHeading.eyebrow, locale)}
        title1={pickLocale(brandTheme.serviceHeading.title1, locale)}
        title2={pickLocale(brandTheme.serviceHeading.title2, locale)}
        subtitle={pickLocale(brandTheme.serviceHeading.subtitle, locale)}
      />

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {modes.map((m, i) => {
          const Icon = ICONS[m.icon] ?? GraduationCap;
          const a = ACCENT[MODE_ACCENT[i] ?? "teal"];
          const feature = i === 0;
          return (
            <Reveal as="div" key={m.no} delay={i * 60} className={SPAN[i] ?? ""}>
              <Link
                href={m.href}
                className={`group relative flex h-full flex-col rounded-2xl border border-border bg-card p-6 transition-all duration-300 hover:-translate-y-0.5 hover:shadow-lg ${a.ring} ${
                  feature ? "sm:flex-row sm:items-center sm:gap-8 sm:p-8" : ""
                }`}
              >
                <span className={`pointer-events-none absolute end-5 top-5 font-serif text-sm font-semibold tabular-nums opacity-40 ${a.dot}`} aria-hidden>
                  {m.no}
                </span>

                <div className={feature ? "sm:max-w-xs" : ""}>
                  <span className={`inline-flex size-11 items-center justify-center rounded-xl ${a.icon}`}>
                    <Icon className="size-5" aria-hidden />
                  </span>
                  <h3 className={`mt-4 font-serif font-semibold text-foreground ${feature ? "text-2xl" : "text-lg"}`}>
                    {pickLocale(m.name, locale)}
                  </h3>
                </div>

                <div className={feature ? "mt-3 sm:mt-0 sm:flex-1" : "mt-3"}>
                  <p className={`text-muted-foreground ${feature ? "text-base leading-relaxed" : "text-sm leading-relaxed"}`}>
                    {pickLocale(m.desc, locale)}
                  </p>
                  <span className={`mt-4 inline-flex items-center gap-1.5 text-sm font-semibold ${a.dot}`}>
                    {pickLocale(m.cta, locale)}
                    <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5 rtl:rotate-180 rtl:group-hover:-translate-x-0.5" aria-hidden />
                  </span>
                </div>
              </Link>
            </Reveal>
          );
        })}
      </div>
    </Section>
  );
}
