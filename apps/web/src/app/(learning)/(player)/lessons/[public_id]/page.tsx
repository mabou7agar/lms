"use client";

import Link from "next/link";
import { useParams, useSearchParams } from "next/navigation";
import { Suspense, useEffect, useRef, useState } from "react";
import { Bookmark, Check, ChevronLeft, ChevronRight } from "lucide-react";
import { errorMessage } from "@/lib/api/errors";
import { useI18n } from "@/lib/i18n/i18n-context";
import { useLesson, useRecordProgress, useToggleBookmark, useUpsertNote } from "@/lib/learning/hooks";
import { RequireAuth } from "@/lib/auth/guards";
import { AskQuestionDialog } from "@/components/community/ask-question-dialog";
import { LessonContent } from "@/components/learning/lesson-content";
import { PageHeader } from "@/components/student/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Textarea } from "@/components/ui/textarea";
import { LoadingState } from "@/components/states/loading-state";
import { ErrorState } from "@/components/states/error-state";
import { toast } from "@/components/ui/toast";
import { cn } from "@/lib/utils";

function LessonInner() {
  const { t, dir } = useI18n();
  const params = useParams<{ public_id: string }>();
  const lessonId = params.public_id;
  // Course context threaded from the curriculum (`?course=`); enables the lesson-scoped "Ask the
  // instructor" affordance. Absent on deep links, where the affordance is simply not shown.
  const searchParams = useSearchParams();
  const courseId = searchParams.get("course");

  const query = useLesson(lessonId);
  const progress = useRecordProgress(lessonId);
  const bookmark = useToggleBookmark(lessonId);
  const note = useUpsertNote(lessonId);

  const videoRef = useRef<HTMLVideoElement | null>(null);
  const startedRef = useRef(false);
  const [noteText, setNoteText] = useState("");
  const [seededFrom, setSeededFrom] = useState<unknown>(null);

  const data = query.data;

  // Seed the note editor from the loaded note — during render (React's adjust-state-while-rendering
  // pattern) instead of an effect. Tracking the seeded payload in STATE (not a ref, which cannot be
  // touched during render) runs this once per distinct lesson payload, matching the prior [data] effect.
  if (data && seededFrom !== data) {
    setSeededFrom(data);
    setNoteText(data.note ?? "");
  }

  // Mark as started once, when a not-started lesson loads.
  useEffect(() => {
    if (data && !startedRef.current && data.progress.status === "not_started") {
      startedRef.current = true;
      progress.mutate({ status: "in_progress" });
    }
  }, [data, progress]);

  if (query.isPending) return <LoadingState />;
  if (query.isError) return <ErrorState message={errorMessage(query.error, t("common.error"))} onRetry={() => query.refetch()} />;

  const lesson = data!;
  const done = lesson.progress.status === "completed";
  const Prev = dir === "rtl" ? ChevronRight : ChevronLeft;
  const Next = dir === "rtl" ? ChevronLeft : ChevronRight;

  const markComplete = () =>
    progress.mutate(
      { status: "completed" },
      {
        onSuccess: (res) => toast.success(`${t("learn.lesson.progressSaved")} (${Math.round(res.data.course_progress_percentage)}%)`),
        onError: (e) => toast.error(errorMessage(e, t("common.error"))),
      },
    );

  return (
    <div className="space-y-6">
      <PageHeader
        title={lesson.title}
        action={
          <div className="flex items-center gap-2">
            <Badge variant={done ? "success" : "secondary"}>{done ? t("learn.lesson.completed") : t("learn.lesson.started")}</Badge>
            {courseId ? (
              <AskQuestionDialog
                courseId={courseId}
                lessonId={lessonId}
                getTimestamp={() => videoRef.current?.currentTime ?? null}
              />
            ) : null}
            <Button
              variant="ghost"
              size="icon"
              aria-label={t("learn.lesson.bookmark")}
              loading={bookmark.isPending}
              onClick={() => bookmark.mutate()}
            >
              <Bookmark className={cn("size-5", lesson.bookmarked && "fill-current text-primary")} aria-hidden />
            </Button>
          </div>
        }
      />

      <LessonContent
        lesson={lesson}
        videoRef={videoRef}
        onVideoLoaded={(el) => {
          if (lesson.progress.position_seconds) el.currentTime = lesson.progress.position_seconds;
        }}
        onVideoPause={(seconds) => progress.mutate({ status: "in_progress", position_seconds: seconds })}
      />

      <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-border/70 bg-card p-4">
        <div className="flex gap-2">
          {lesson.navigation.previous ? (
            <Button asChild variant="outline">
              <Link href={`/lessons/${lesson.navigation.previous}`}>
                <Prev className="size-4" aria-hidden /> {t("learn.lesson.previous")}
              </Link>
            </Button>
          ) : null}
          {lesson.navigation.next ? (
            <Button asChild variant="outline">
              <Link href={`/lessons/${lesson.navigation.next}`}>
                {t("learn.lesson.next")} <Next className="size-4" aria-hidden />
              </Link>
            </Button>
          ) : null}
        </div>
        <Button onClick={markComplete} loading={progress.isPending} disabled={done} variant={done ? "outline" : "default"} className={done ? undefined : "shine relative overflow-hidden"}>
          <Check className="size-4" aria-hidden /> {done ? t("learn.lesson.completed") : t("learn.lesson.markComplete")}
        </Button>
      </div>

      <Card className="border-border/70">
        <CardHeader>
          <CardTitle className="flex items-center gap-2 font-serif text-lg">
            <Bookmark className="size-4 text-copper" aria-hidden /> {t("learn.lesson.notes")}
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-3">
          <Textarea
            rows={4}
            value={noteText}
            onChange={(e) => setNoteText(e.target.value)}
            placeholder={t("learn.lesson.notePlaceholder")}
          />
          <Button
            size="sm"
            loading={note.isPending}
            disabled={!noteText.trim()}
            onClick={() =>
              note.mutate(noteText.trim(), {
                onSuccess: () => toast.success(t("learn.lesson.noteSaved")),
                onError: (e) => toast.error(errorMessage(e, t("common.error"))),
              })
            }
          >
            {t("learn.lesson.saveNote")}
          </Button>
        </CardContent>
      </Card>
    </div>
  );
}

export default function LessonPage() {
  return (
    <RequireAuth>
      {/* useSearchParams (course context for the ask-instructor affordance) needs a Suspense boundary. */}
      <Suspense fallback={<LoadingState />}>
        <LessonInner />
      </Suspense>
    </RequireAuth>
  );
}
