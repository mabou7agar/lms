"use client";

import { AlertTriangle, CheckCircle2, OctagonAlert } from "lucide-react";
import Link from "next/link";
import { QueryState } from "@/components/student/query-state";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Progress } from "@/components/ui/progress";
import { Skeleton } from "@/components/ui/skeleton";
import { useI18n } from "@/lib/i18n/i18n-context";
import type { ReadinessIssue, ReadinessReport } from "@/lib/teach/api";
import { useCourseReadiness } from "@/lib/teach/hooks";

export interface ReadinessDialogProps {
  courseId: string | null;
  onOpenChange: (open: boolean) => void;
}

/**
 * Where an issue should send the author.
 *
 * The backend supplies `entity_type` + `entity_id` rather than a URL, because routes are a frontend
 * concern. Returning null for anything unrecognised is deliberate: a link that guesses is worse
 * than no link, since it lands the author somewhere unrelated to the problem.
 */
function deepLink(courseId: string, issue: ReadinessIssue): string | null {
  switch (issue.entity_type) {
    case "course":
      return `/teach/courses/${courseId}/edit`;
    case "section":
      return issue.entity_id ? `/teach/courses/${courseId}/edit#section-${issue.entity_id}` : null;
    case "lesson":
      return issue.entity_id ? `/teach/courses/${courseId}/edit#lesson-${issue.entity_id}` : null;
    default:
      return null;
  }
}

function IssueRow({ courseId, issue }: { courseId: string; issue: ReadinessIssue }) {
  const { t } = useI18n();
  const isBlocker = issue.severity === "blocker";
  const href = deepLink(courseId, issue);
  const Icon = isBlocker ? OctagonAlert : AlertTriangle;

  return (
    <li className="flex gap-3 rounded-lg border p-3">
      <Icon
        className={isBlocker ? "size-4 shrink-0 text-destructive" : "size-4 shrink-0 text-warning"}
        aria-hidden
      />

      <div className="min-w-0 flex-1 space-y-1">
        <div className="flex flex-wrap items-center gap-2">
          <p className="font-medium">{issue.title}</p>
          {/* Text, not just colour — the severity has to survive a greyscale screenshot. */}
          <Badge variant={isBlocker ? "destructive" : "warning"}>
            {isBlocker ? t("teach.readiness.blocker") : t("teach.readiness.warning")}
          </Badge>
        </div>

        <p className="text-sm text-muted-foreground">{issue.explanation}</p>
        <p className="text-sm">{issue.recommended_action}</p>

        {href ? (
          <Button variant="link" size="sm" className="h-auto p-0" asChild>
            <Link href={href}>{t("teach.readiness.goFix")}</Link>
          </Button>
        ) : null}
      </div>
    </li>
  );
}

/**
 * The publish-readiness panel.
 *
 * `is_publishable` is read straight from the payload and never recomputed from the issue lists.
 * The backend derives its own publish guard from this same report, so recomputing here would
 * create a second rule set that can drift — and a panel that says "ready" while the publish is
 * refused teaches authors to ignore it.
 *
 * Warnings never affect the verdict. A course with a `not_publicly_visible` warning is publishable,
 * and this panel says so.
 */
export function ReadinessDialog({ courseId, onOpenChange }: ReadinessDialogProps) {
  const { t } = useI18n();
  const query = useCourseReadiness(courseId ?? "", Boolean(courseId));

  return (
    <Dialog open={Boolean(courseId)} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[85vh] max-w-2xl overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{t("teach.readiness.title")}</DialogTitle>
          <DialogDescription>{t("teach.readiness.subtitle")}</DialogDescription>
        </DialogHeader>

        <QueryState<ReadinessReport>
          query={query}
          loading={
            <div className="space-y-3">
              <Skeleton className="h-16" />
              <Skeleton variant="card" className="h-24" />
            </div>
          }
        >
          {(report) => {
            const totalChecks =
              report.passed_checks.length + report.blockers.length + report.warnings.length;

            return (
              <div className="space-y-5">
                <div className="space-y-2">
                  <div className="flex items-center justify-between gap-3">
                    <span className="text-sm text-muted-foreground">
                      {t("teach.readiness.score")}
                    </span>
                    <span className="text-2xl font-bold tabular-nums">{report.score}%</span>
                  </div>

                  <Progress value={report.score} />

                  <div className="flex flex-wrap items-center gap-2">
                    {report.is_publishable ? (
                      <Badge variant="success">
                        <CheckCircle2 className="size-3.5" aria-hidden />{" "}
                        {t("teach.readiness.ready")}
                      </Badge>
                    ) : (
                      <Badge variant="destructive">{t("teach.readiness.notReady")}</Badge>
                    )}
                    <span className="text-xs text-muted-foreground">
                      {report.passed_checks.length} / {totalChecks}{" "}
                      {t("teach.readiness.checksPassed")}
                    </span>
                  </div>
                </div>

                {report.blockers.length > 0 ? (
                  <section className="space-y-2">
                    <h3 className="text-sm font-semibold">
                      {t("teach.readiness.blockers")} ({report.blockers.length})
                    </h3>
                    <ul className="space-y-2">
                      {report.blockers.map((issue, index) => (
                        <IssueRow key={`${issue.code}-${index}`} courseId={courseId!} issue={issue} />
                      ))}
                    </ul>
                  </section>
                ) : null}

                {report.warnings.length > 0 ? (
                  <section className="space-y-2">
                    <h3 className="text-sm font-semibold">
                      {t("teach.readiness.warnings")} ({report.warnings.length})
                    </h3>
                    {/* Said explicitly so an author does not read a warning as a refusal. */}
                    <p className="text-xs text-muted-foreground">
                      {t("teach.readiness.warningsDoNotBlock")}
                    </p>
                    <ul className="space-y-2">
                      {report.warnings.map((issue, index) => (
                        <IssueRow key={`${issue.code}-${index}`} courseId={courseId!} issue={issue} />
                      ))}
                    </ul>
                  </section>
                ) : null}

                {report.passed_checks.length > 0 ? (
                  <section className="space-y-2">
                    <h3 className="text-sm font-semibold">
                      {t("teach.readiness.passed")} ({report.passed_checks.length})
                    </h3>
                    <ul className="flex flex-wrap gap-1.5">
                      {report.passed_checks.map((code) => (
                        <li key={code}>
                          <Badge variant="outline">
                            <CheckCircle2 className="size-3 text-success" aria-hidden /> {code}
                          </Badge>
                        </li>
                      ))}
                    </ul>
                  </section>
                ) : null}
              </div>
            );
          }}
        </QueryState>
      </DialogContent>
    </Dialog>
  );
}
