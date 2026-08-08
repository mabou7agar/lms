"use client";

import { useState, type FormEvent } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ArrowLeft, BookOpen, CheckCircle2, Layers, ListChecks, Megaphone, TrendingUp, Users } from "lucide-react";
import { errorMessage } from "@/lib/api/errors";
import { useI18n } from "@/lib/i18n/i18n-context";
import {
  useArchiveCourse,
  useCreateAnnouncement,
  usePublishCourse,
  useTeachAnnouncements,
  useTeachCourse,
  useTeachStudents,
  useUnpublishCourse,
} from "@/lib/teach/hooks";
import { PageHeader } from "@/components/student/page-header";
import { QueryState } from "@/components/student/query-state";
import { StatCard } from "@/components/student/stat-card";
import { ProgressBar } from "@/components/student/progress-bar";
import { EmptyState } from "@/components/states/empty-state";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Label } from "@/components/ui/label";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { toast } from "@/components/ui/toast";

const STATUS_VARIANT: Record<string, "success" | "secondary" | "outline"> = {
  published: "success",
  draft: "secondary",
  archived: "outline",
};

export default function TeachCourseDetailPage() {
  const { t } = useI18n();
  const params = useParams<{ public_id: string }>();
  const id = params.public_id;

  const courseQuery = useTeachCourse(id);
  const publish = usePublishCourse();
  const unpublish = useUnpublishCourse();
  const archive = useArchiveCourse();
  const [archiveOpen, setArchiveOpen] = useState(false);

  const run = (mutation: ReturnType<typeof usePublishCourse>, key: string) =>
    mutation.mutate(id, {
      onSuccess: () => toast.success(t(key)),
      onError: (e) => toast.error(errorMessage(e, t("common.error"))),
    });

  return (
    <div className="space-y-6">
      <div>
        <Button asChild variant="ghost" size="sm" className="mb-2">
          <Link href="/teach/courses">
            <ArrowLeft className="size-4 rtl:rotate-180" aria-hidden /> {t("teach.course.back")}
          </Link>
        </Button>
      </div>

      <QueryState
        query={courseQuery}
        empty={<EmptyState title={t("teach.course.notFound")} />}
        isEmpty={(d) => !d}
      >
        {(course) => (
          <div className="space-y-6">
            <PageHeader
              eyebrow="INSTRUCTOR"
              icon="GraduationCap"
              title={course.title}
              subtitle={course.subtitle ?? undefined}
              action={
                <div className="flex flex-wrap items-center gap-2">
                  <Badge variant={STATUS_VARIANT[course.status] ?? "secondary"}>
                    {t(`teach.courses.${course.status}`)}
                  </Badge>
                  {course.status === "draft" ? (
                    <Button size="sm" loading={publish.isPending} onClick={() => run(publish, "teach.courses.publishedToast")}>
                      {t("teach.courses.publish")}
                    </Button>
                  ) : null}
                  {course.status === "published" ? (
                    <Button size="sm" variant="secondary" loading={unpublish.isPending} onClick={() => run(unpublish, "teach.courses.unpublishedToast")}>
                      {t("teach.courses.unpublish")}
                    </Button>
                  ) : null}
                  {course.status !== "archived" ? (
                    <Button size="sm" variant="outline" loading={archive.isPending} onClick={() => setArchiveOpen(true)}>
                      {t("teach.courses.archive")}
                    </Button>
                  ) : null}
                </div>
              }
            />

            <ConfirmDialog
              open={archiveOpen}
              onOpenChange={setArchiveOpen}
              title={t("teach.courses.archiveConfirmTitle")}
              description={t("teach.courses.archiveConfirmBody")}
              confirmLabel={t("teach.courses.archive")}
              loading={archive.isPending}
              onConfirm={() =>
                archive.mutate(id, {
                  onSuccess: () => {
                    toast.success(t("teach.courses.archivedToast"));
                    setArchiveOpen(false);
                  },
                  onError: (e) => {
                    toast.error(errorMessage(e, t("common.error")));
                    setArchiveOpen(false);
                  },
                })
              }
            />

            <section aria-label={t("teach.course.analytics")}>
              <div className="mb-3 flex items-center justify-between gap-2">
                <h2 className="font-serif text-lg font-semibold">{t("teach.course.analytics")}</h2>
                <Button asChild size="sm" variant="outline">
                  <Link href={`/teach/courses/${id}/analytics`}>{t("teach.analytics.view")}</Link>
                </Button>
              </div>
              <div className="stagger-in grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                <StatCard label={t("teach.courses.enrollments")} value={course.stats?.enrollments ?? 0} icon={Users} />
                <StatCard label={t("teach.courses.completions")} value={course.stats?.completions ?? 0} icon={CheckCircle2} />
                <StatCard label={t("teach.courses.avgProgress")} value={`${course.stats?.avg_progress ?? 0}%`} icon={TrendingUp} />
                <StatCard label={t("teach.courses.sections")} value={course.stats?.sections ?? 0} icon={Layers} />
                <StatCard label={t("teach.courses.lessons")} value={course.stats?.lessons ?? 0} icon={ListChecks} />
              </div>
            </section>

            <StudentsPanel id={id} />
            <AnnouncementsPanel id={id} />
          </div>
        )}
      </QueryState>
    </div>
  );
}

function StudentsPanel({ id }: { id: string }) {
  const { t } = useI18n();
  const [page, setPage] = useState(1);
  const query = useTeachStudents(id, page);

  return (
    <section aria-label={t("teach.course.students")}>
      <div className="mb-3 flex items-center gap-2">
        <Users className="size-5 text-primary" aria-hidden />
        <h2 className="font-serif text-lg font-semibold">{t("teach.course.students")}</h2>
      </div>
      <QueryState
        query={query}
        isEmpty={(d) => d.data.length === 0}
        empty={<EmptyState title={t("teach.course.studentsEmpty")} />}
      >
        {(res) => (
          <Card>
            <CardContent className="p-0">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t("teach.course.name")}</TableHead>
                    <TableHead>{t("teach.course.status")}</TableHead>
                    <TableHead className="w-40">{t("teach.course.progress")}</TableHead>
                    <TableHead>{t("teach.course.enrolledAt")}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {res.data.map((row) => (
                    <TableRow key={row.enrollment_id}>
                      <TableCell className="font-medium">
                        {row.student.id ? (
                          <Link
                            href={`/teach/courses/${id}/students/${row.student.id}`}
                            className="text-primary underline-offset-4 hover:underline"
                          >
                            {row.student.name ?? row.student.id}
                          </Link>
                        ) : (
                          (row.student.name ?? "—")
                        )}
                      </TableCell>
                      <TableCell>
                        <Badge variant={row.status === "completed" ? "success" : "secondary"}>{row.status}</Badge>
                      </TableCell>
                      <TableCell>
                        <div className="flex items-center gap-2">
                          <ProgressBar value={row.progress_percentage} className="flex-1" />
                          <span className="w-10 text-end text-xs tabular-nums text-muted-foreground">
                            {row.progress_percentage}%
                          </span>
                        </div>
                      </TableCell>
                      <TableCell className="text-sm text-muted-foreground">
                        {row.enrolled_at ? new Date(row.enrolled_at).toLocaleDateString() : "—"}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
              {res.meta.last_page > 1 ? (
                <div className="flex items-center justify-between gap-2 border-t p-3">
                  <span className="text-xs text-muted-foreground tabular-nums">
                    {res.meta.current_page} / {res.meta.last_page}
                  </span>
                  <div className="flex gap-2">
                    <Button
                      size="sm"
                      variant="outline"
                      aria-label={t("common.previous")}
                      disabled={page <= 1}
                      onClick={() => setPage((p) => p - 1)}
                    >
                      <span aria-hidden>‹</span>
                    </Button>
                    <Button
                      size="sm"
                      variant="outline"
                      aria-label={t("common.next")}
                      disabled={page >= res.meta.last_page}
                      onClick={() => setPage((p) => p + 1)}
                    >
                      <span aria-hidden>›</span>
                    </Button>
                  </div>
                </div>
              ) : null}
            </CardContent>
          </Card>
        )}
      </QueryState>
    </section>
  );
}

function AnnouncementsPanel({ id }: { id: string }) {
  const { t } = useI18n();
  const query = useTeachAnnouncements(id);
  const create = useCreateAnnouncement(id);
  const [title, setTitle] = useState("");
  const [body, setBody] = useState("");

  const onSubmit = (e: FormEvent) => {
    e.preventDefault();
    if (!title.trim() || !body.trim()) return;
    create.mutate(
      { title: title.trim(), body: body.trim() },
      {
        onSuccess: () => {
          toast.success(t("teach.course.posted"));
          setTitle("");
          setBody("");
        },
        onError: (err) => toast.error(errorMessage(err, t("common.error"))),
      },
    );
  };

  return (
    <section aria-label={t("teach.course.announcements")}>
      <div className="mb-3 flex items-center gap-2">
        <Megaphone className="size-5 text-primary" aria-hidden />
        <h2 className="font-serif text-lg font-semibold">{t("teach.course.announcements")}</h2>
      </div>

      <div className="grid gap-4 lg:grid-cols-[1fr_20rem]">
        <QueryState
          query={query}
          isEmpty={(d) => d.length === 0}
          empty={<EmptyState icon={<BookOpen className="size-8" />} title={t("teach.course.announcementsEmpty")} />}
        >
          {(items) => (
            <ul className="space-y-3">
              {items.map((a) => (
                <li key={a.id}>
                  <Card>
                    <CardContent className="space-y-1 p-4">
                      <div className="flex items-center justify-between gap-2">
                        <h3 className="font-semibold">{a.title}</h3>
                        <span className="text-xs text-muted-foreground tabular-nums">
                          {a.published_at ? new Date(a.published_at).toLocaleDateString() : ""}
                        </span>
                      </div>
                      <p className="whitespace-pre-line text-sm text-muted-foreground">{a.body}</p>
                    </CardContent>
                  </Card>
                </li>
              ))}
            </ul>
          )}
        </QueryState>

        <Card className="h-fit">
          <CardContent className="p-4">
            <h3 className="mb-3 font-semibold">{t("teach.course.newAnnouncement")}</h3>
            <form onSubmit={onSubmit} className="space-y-3">
              <div className="space-y-1.5">
                <Label htmlFor="ann-title">{t("teach.course.annTitle")}</Label>
                <Input
                  id="ann-title"
                  value={title}
                  onChange={(e) => setTitle(e.target.value)}
                  maxLength={160}
                  required
                />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="ann-body">{t("teach.course.annBody")}</Label>
                <Textarea
                  id="ann-body"
                  value={body}
                  onChange={(e) => setBody(e.target.value)}
                  maxLength={5000}
                  required
                  rows={5}
                />
              </div>
              <Button type="submit" className="w-full" loading={create.isPending} disabled={!title.trim() || !body.trim()}>
                {t("teach.course.post")}
              </Button>
            </form>
          </CardContent>
        </Card>
      </div>
    </section>
  );
}
