"use client";

import Link from "next/link";
import { useState } from "react";
import { ArrowRight } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { pickLocale } from "@/config/theme";
import { DEMO_ENABLED, demoCourses, featuredHeading } from "@/config/demo";
import { Section, SectionHeading } from "@/components/landing/section";
import { Reveal } from "@/components/landing/reveal";
import { Button } from "@/components/ui/button";
import dynamic from "next/dynamic";
import { CoursePreviewCard } from "./course-preview-card";

// Below-the-fold, interaction-gated modal (YouTube iframe embed + focus trap) — code-split so its
// JS loads only when a preview is opened rather than shipping in the homepage's initial chunk.
const VideoModal = dynamic(() => import("./video-modal").then((m) => m.VideoModal), { ssr: false });

export function FeaturedCourses() {
  const { locale } = useI18n();
  const [video, setVideo] = useState<string | null>(null);

  if (!DEMO_ENABLED || demoCourses.length === 0) return null;

  return (
    <Section className="bg-card/40">
      <SectionHeading
        eyebrow={pickLocale(featuredHeading.eyebrow, locale)}
        title1={pickLocale(featuredHeading.title1, locale)}
        title2={pickLocale(featuredHeading.title2, locale)}
        subtitle={pickLocale(featuredHeading.subtitle, locale)}
      />
      <div className="stagger-in grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
        {demoCourses.map((c) => (
          <CoursePreviewCard key={c.id} course={c} onPlay={setVideo} />
        ))}
      </div>
      <Reveal className="mt-10 text-center">
        <Button asChild size="lg" variant="outline">
          <Link href="/courses">
            {pickLocale(featuredHeading.cta, locale)}
            <ArrowRight className="size-4 rtl:rotate-180" aria-hidden />
          </Link>
        </Button>
      </Reveal>
      <VideoModal videoId={video} onClose={() => setVideo(null)} />
    </Section>
  );
}
