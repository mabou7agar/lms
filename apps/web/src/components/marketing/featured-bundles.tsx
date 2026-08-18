"use client";

import Link from "next/link";
import { ArrowRight } from "lucide-react";
import { useBundles } from "@/lib/commerce/hooks";
import { useI18n } from "@/lib/i18n/i18n-context";
import { pickLocale } from "@/config/theme";
import { Section, SectionHeading } from "@/components/landing/section";
import { Reveal } from "@/components/landing/reveal";
import { Button } from "@/components/ui/button";
import { BundleCard } from "@/components/commerce/bundle-card";

const heading = {
  eyebrow: { en: "BUY THE PATH, NOT THE LESSON", ar: "اشترِ المسار لا الدرس" },
  title1: { en: "Several courses,", ar: "عدة دورات،" },
  title2: { en: "one purchase.", ar: "بعملية شراء واحدة." },
  subtitle: {
    en: "Bundles for a person building a skill, and bundles a company buys as seats for its team.",
    ar: "باقات لمن يبني مهارته بنفسه، وباقات تشتريها الشركات مقاعدَ لفريقها.",
  },
  cta: { en: "See all bundles", ar: "استعرض كل الباقات" },
};

/**
 * Homepage bundle surface. Bundles are the larger purchase and had no presence on the homepage at
 * all — a visitor had to already know /bundles existed to find them.
 *
 * The cards are the same ones the catalogue uses, so each bundle states its own terms: an individual
 * bundle reads as a personal purchase, and a company bundle carries its "For companies" badge and
 * seat line. Nothing here decides who may buy what — that stays with the product's audience.
 *
 * Renders nothing when the catalogue has no bundles, so the homepage never shows an empty rail.
 */
export function FeaturedBundles() {
  const { locale } = useI18n();
  const query = useBundles(1);
  const bundles = (query.data?.data ?? []).slice(0, 3);

  if (bundles.length === 0) return null;

  return (
    <Section id="bundles" className="bg-background">
      <SectionHeading
        eyebrow={pickLocale(heading.eyebrow, locale)}
        title1={pickLocale(heading.title1, locale)}
        title2={pickLocale(heading.title2, locale)}
        subtitle={pickLocale(heading.subtitle, locale)}
      />
      <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        {bundles.map((bundle, index) => (
          <Reveal as="div" key={bundle.id} delay={index * 60} className="h-full">
            <BundleCard bundle={bundle} />
          </Reveal>
        ))}
      </div>
      <Reveal className="mt-10 text-center">
        <Button asChild size="lg" variant="outline">
          <Link href="/bundles">
            {pickLocale(heading.cta, locale)}
            <ArrowRight className="size-4 rtl:rotate-180" aria-hidden />
          </Link>
        </Button>
      </Reveal>
    </Section>
  );
}
