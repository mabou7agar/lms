"use client";

import Link from "next/link";
import { CheckCircle2, Circle, Lock, PlayCircle } from "lucide-react";
import type { LearnSection } from "@/lib/learning/api";
import { useI18n } from "@/lib/i18n/i18n-context";
import { cn } from "@/lib/utils";

export function CurriculumSidebar({
  sections,
  activeLessonId,
  coursePublicId,
}: {
  sections: LearnSection[];
  activeLessonId?: string;
  /** When set, lesson links carry `?course=` so the lesson page can scope course-level community actions. */
  coursePublicId?: string;
}) {
  const { t } = useI18n();
  const lessonHref = (lessonId: string) => (coursePublicId ? `/lessons/${lessonId}?course=${coursePublicId}` : `/lessons/${lessonId}`);
  return (
    <nav className="space-y-4" aria-label={t("learn.curriculum")}>
      {sections.map((section) => (
        <div key={section.id}>
          <h3 className="mb-1 px-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{section.title}</h3>
          <ul className="space-y-0.5">
            {section.lessons.map((lesson) => {
              const active = lesson.id === activeLessonId;
              const Icon = lesson.completed ? CheckCircle2 : lesson.locked ? Lock : active ? PlayCircle : Circle;
              const body = (
                <span
                  className={cn(
                    "relative flex items-center gap-2.5 rounded-xl px-2.5 py-2 text-sm transition-colors",
                    active ? "bg-copper/[0.08] font-medium text-foreground" : "text-muted-foreground",
                    lesson.locked ? "" : "hover:bg-accent/60 hover:text-foreground",
                  )}
                >
                  {active ? <span className="absolute inset-y-2 start-0 w-[3px] rounded-full bg-copper" aria-hidden /> : null}
                  <Icon className={cn("size-4 shrink-0", lesson.completed ? "text-primary" : active ? "text-copper" : "text-muted-foreground")} aria-hidden />
                  <span className="line-clamp-1 flex-1">{lesson.title}</span>
                  {lesson.is_preview && lesson.locked ? (
                    <span className="text-[10px] uppercase text-muted-foreground">{t("learn.preview")}</span>
                  ) : null}
                </span>
              );
              return (
                <li key={lesson.id}>
                  {lesson.locked ? (
                    <div title={t("learn.locked")} aria-disabled className="cursor-not-allowed opacity-70">{body}</div>
                  ) : (
                    <Link href={lessonHref(lesson.id)}>{body}</Link>
                  )}
                </li>
              );
            })}
          </ul>
        </div>
      ))}
    </nav>
  );
}
