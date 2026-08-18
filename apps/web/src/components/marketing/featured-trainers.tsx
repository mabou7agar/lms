"use client";

import Link from "next/link";
import { ArrowRight } from "lucide-react";
import { useTrainers } from "@/lib/catalog/hooks";
import { useI18n } from "@/lib/i18n/i18n-context";
import { pickLocale } from "@/config/theme";
import * as V2 from "@/config/home-v2";
import { Section, SectionHeading } from "@/components/landing/section";
import { Reveal } from "@/components/landing/reveal";
import { Button } from "@/components/ui/button";
import { TrainerCard } from "@/components/catalog/trainer-card";

/**
 * Real homepage faculty rail. Uploaded trainer photos win inside <TrainerCard>; when the DB has no
 * photo, the same faculty-medallion fallback used across the catalogue keeps the section intentional.
 */
export function FeaturedTrainers() {
  const { locale } = useI18n();
  const query = useTrainers();
  // Four, not five: at five across a 1440px row every card is narrow enough that the name and the
  // headline both truncate, which reads as broken data rather than a designed rail.
  const trainers = (query.data ?? []).slice(0, 4);

  if (trainers.length === 0) return null;

  return (
    <Section id="instructors" className="bg-background">
      <SectionHeading
        eyebrow={pickLocale(V2.instructorsHeading.eyebrow, locale)}
        title1={pickLocale(V2.instructorsHeading.title1, locale)}
        title2={pickLocale(V2.instructorsHeading.title2, locale)}
        subtitle={pickLocale(V2.instructorsHeading.subtitle, locale)}
      />
      <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
        {trainers.map((trainer, index) => (
          <Reveal as="div" key={trainer.id} delay={index * 60}>
            <TrainerCard trainer={trainer} />
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
