"use client";

import { Download, FileText, Lock } from "lucide-react";
import { errorMessage } from "@/lib/api/errors";
import { useI18n } from "@/lib/i18n/i18n-context";
import type { CourseResource } from "@/lib/courseware/api";
import { useCourseResources, useDownloadResource, useLessonResources } from "@/lib/courseware/hooks";
import { QueryState } from "@/components/student/query-state";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { toast } from "@/components/ui/toast";

/** Bytes as something a person can judge a download by. */
function formatSize(bytes: number | null): string | null {
  if (bytes === null || bytes <= 0) return null;
  const units = ["B", "KB", "MB", "GB"];
  let value = bytes;
  let unit = 0;
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit += 1;
  }
  return `${value < 10 && unit > 0 ? value.toFixed(1) : Math.round(value)} ${units[unit]}`;
}

/** A crude but readable file kind from the mime type — "PDF", "XLSX", "ZIP". */
function formatKind(mime: string | null): string | null {
  if (!mime) return null;
  const subtype = mime.split("/")[1] ?? "";
  const map: Record<string, string> = {
    pdf: "PDF",
    zip: "ZIP",
    "vnd.openxmlformats-officedocument.spreadsheetml.sheet": "XLSX",
    "vnd.openxmlformats-officedocument.presentationml.presentation": "PPTX",
    "vnd.openxmlformats-officedocument.wordprocessingml.document": "DOCX",
    csv: "CSV",
    plain: "TXT",
  };
  return map[subtype] ?? (subtype.slice(0, 5).toUpperCase() || null);
}

/**
 * The files attached to a course or a lesson.
 *
 * A file the viewer may not take is still LISTED when the course chose to advertise it, because
 * "here is what you get" is part of what sells a course — but the download button is replaced by a
 * lock rather than by a button that fails. Nothing here holds a URL: pressing download asks the
 * server, which re-checks entitlement and issues a link valid for minutes.
 */
export function ResourceList({
  courseId,
  lessonId,
  scope = "course",
  title,
}: {
  courseId?: string;
  lessonId?: string;
  scope?: "course" | "all";
  title?: string;
}) {
  const { t } = useI18n();
  const courseQuery = useCourseResources(lessonId ? null : (courseId ?? null), scope);
  const lessonQuery = useLessonResources(lessonId ?? null);
  const query = lessonId ? lessonQuery : courseQuery;
  const download = useDownloadResource();

  const onDownload = (resource: CourseResource) =>
    download.mutate(resource.id, {
      onSuccess: (res) => window.open(res.url, "_blank", "noopener,noreferrer"),
      onError: (e) => toast.error(errorMessage(e, t("courseware.resources.downloadFailed"))),
    });

  return (
    <section className="space-y-3">
      <h3 className="flex items-center gap-2 font-serif text-lg font-semibold">
        <FileText className="size-5 text-copper" aria-hidden />
        {title ?? t("courseware.resources.title")}
      </h3>

      <QueryState
        query={query}
        isEmpty={(d) => d.items.length === 0}
        empty={<p className="text-sm text-muted-foreground">{t("courseware.resources.empty")}</p>}
      >
        {(data) => (
          <ul className="divide-y rounded-lg border">
            {data.items.map((resource) => {
              const locked = !resource.downloadable || (!resource.is_preview && !data.entitled);
              const meta = [formatKind(resource.file.mime_type), formatSize(resource.file.size_bytes)]
                .filter(Boolean)
                .join(" · ");

              return (
                <li key={resource.id} className="flex flex-wrap items-center justify-between gap-3 p-3">
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2 text-sm font-medium">
                      {resource.title}
                      {resource.is_preview ? (
                        <Badge variant="outline">{t("courseware.resources.preview")}</Badge>
                      ) : null}
                    </div>
                    {resource.description ? (
                      <p className="mt-0.5 text-xs text-muted-foreground">{resource.description}</p>
                    ) : null}
                    {meta ? <p className="mt-0.5 text-xs text-muted-foreground">{meta}</p> : null}
                  </div>

                  {locked ? (
                    <span className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                      <Lock className="size-3.5" aria-hidden />
                      {resource.downloadable
                        ? t("courseware.resources.enrolOnly")
                        : t("courseware.resources.notDownloadable")}
                    </span>
                  ) : (
                    <Button
                      size="sm"
                      variant="outline"
                      loading={download.isPending && download.variables === resource.id}
                      onClick={() => onDownload(resource)}
                    >
                      <Download className="size-4" aria-hidden />
                      {t("courseware.resources.download")}
                    </Button>
                  )}
                </li>
              );
            })}
          </ul>
        )}
      </QueryState>
    </section>
  );
}
