"use client";

import type { ReactNode } from "react";
import Link from "next/link";
import { ArrowRight } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { pickLocale } from "@/config/theme";
import { featuredHeading } from "@/config/demo";
import { Section, SectionHeading } from "@/components/landing/section";
import { Reveal } from "@/components/landing/reveal";
import { Button } from "@/components/ui/button";

/**
 * Shared frame for the homepage featured-courses surface: the section heading and the CTA-to-`/courses`
 * wrapper stay identical across every card variant (editorial / cinematic / paths); only the grid the
 * variant renders differs. Keeps one consistent section shell so the switch never duplicates chrome.
 */
export function FeaturedShell({ children }: { children: ReactNode }) {
  const { locale } = useI18n();

  return (
    <Section className="bg-card/40">
      <SectionHeading
        eyebrow={pickLocale(featuredHeading.eyebrow, locale)}
        title1={pickLocale(featuredHeading.title1, locale)}
        title2={pickLocale(featuredHeading.title2, locale)}
        subtitle={pickLocale(featuredHeading.subtitle, locale)}
      />
      {children}
      <Reveal className="mt-10 text-center">
        <Button asChild size="lg" variant="outline">
          <Link href="/courses">
            {pickLocale(featuredHeading.cta, locale)}
            <ArrowRight className="size-4 rtl:rotate-180" aria-hidden />
          </Link>
        </Button>
      </Reveal>
    </Section>
  );
}
