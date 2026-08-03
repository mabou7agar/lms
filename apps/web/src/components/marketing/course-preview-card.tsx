"use client";

import Link from "next/link";
import { Play, Star, Clock, BookOpen } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { pickLocale } from "@/config/theme";
import type { DemoCourse } from "@/config/demo";
import { Card } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { CourseThumb } from "./course-thumb";

export function CoursePreviewCard({ course, onPlay }: { course: DemoCourse; onPlay: (id: string) => void }) {
  const { locale } = useI18n();
  return (
    <Card className="card-hover group overflow-hidden rounded-xl p-0 hover:border-primary/30 hover:elevation-4">
      <div className="relative">
        <CourseThumb code={course.code} color={course.color} className="aspect-video w-full" />
        <button
          onClick={() => onPlay(course.youtubeId)}
          aria-label={locale === "ar" ? `تشغيل معاينة ${pickLocale(course.title, locale)}` : `Play preview of ${pickLocale(course.title, locale)}`}
          className="absolute inset-0 flex items-center justify-center bg-primary/0 transition-colors group-hover:bg-primary/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
        >
          <span className="flex size-14 items-center justify-center rounded-full bg-background/95 text-primary elevation-3 transition-transform group-hover:scale-110">
            <Play className="size-6 translate-x-0.5 fill-current" aria-hidden />
          </span>
        </button>
        <Badge className="absolute end-3 top-3 bg-background/90 text-foreground">{course.price}</Badge>
      </div>
      <div className="space-y-2 p-5">
        <div className="flex items-center gap-2 text-xs text-muted-foreground">
          <Badge variant="secondary">{pickLocale(course.category, locale)}</Badge>
          <Badge variant="outline">{pickLocale(course.level, locale)}</Badge>
        </div>
        <Link href="/courses" className="block">
          <h3 className="line-clamp-2 font-serif text-lg font-semibold leading-tight hover:text-primary">
            {pickLocale(course.title, locale)}
          </h3>
        </Link>
        <p className="text-sm text-muted-foreground">{course.trainer}</p>
        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 pt-1 text-xs text-muted-foreground">
          <span className="inline-flex items-center gap-1 font-semibold text-foreground">
            <Star className="size-3.5 fill-gold text-gold" aria-hidden /> {course.rating}
          </span>
          <span className="inline-flex items-center gap-1"><BookOpen className="size-3.5" aria-hidden /> {course.lessons}</span>
          <span className="inline-flex items-center gap-1"><Clock className="size-3.5" aria-hidden /> {course.hours}h</span>
        </div>
      </div>
    </Card>
  );
}
