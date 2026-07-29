"use client";

import { FileClock } from "lucide-react";
import { QueryState } from "@/components/student/query-state";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Skeleton } from "@/components/ui/skeleton";
import { useI18n } from "@/lib/i18n/i18n-context";
import type { ChangeSummary } from "@/lib/teach/api";
import { useCourseChanges } from "@/lib/teach/hooks";

export interface ChangeSummaryDialogProps {
  courseId: string | null;
  onOpenChange: (open: boolean) => void;
}

/**
 * Read-only draft-vs-published change summary.
 *
 * Today this always renders the unavailable branch: no publication snapshot is persisted, so there
 * is genuinely nothing to compare a draft against. That is stated plainly rather than dressed up.
 *
 * What this component must never do — and the reason the unavailable branch has no list at all:
 * rendering an EMPTY change list would read as "nothing has changed since you published", which is
 * a reassurance the backend never gave and cannot give. "No changes" and "we cannot tell" are
 * different statements. The `available: true` branch is written now so the eventual snapshot work
 * changes only the producer, not this page's contract.
 */
export function ChangeSummaryDialog({ courseId, onOpenChange }: ChangeSummaryDialogProps) {
  const { t } = useI18n();
  const query = useCourseChanges(courseId ?? "", Boolean(courseId));

  return (
    <Dialog open={Boolean(courseId)} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>{t("teach.changes.title")}</DialogTitle>
          <DialogDescription>{t("teach.changes.subtitle")}</DialogDescription>
        </DialogHeader>

        <QueryState<ChangeSummary> query={query} loading={<Skeleton className="h-28" />}>
          {(summary) =>
            summary.available ? (
              <div className="space-y-3">
                <dl className="space-y-2">
                  {Object.entries(summary.changes).map(([category, value]) => (
                    <div key={category} className="flex items-baseline justify-between gap-3">
                      <dt className="text-sm text-muted-foreground">
                        {t(`teach.changes.category.${category}`)}
                      </dt>
                      <dd className="text-sm font-medium tabular-nums">{String(value)}</dd>
                    </div>
                  ))}
                </dl>
              </div>
            ) : (
              <div className="flex flex-col items-center gap-3 rounded-lg border border-dashed p-6 text-center">
                <FileClock className="size-8 text-muted-foreground" aria-hidden />
                <p className="font-medium">{t("teach.changes.unavailableTitle")}</p>
                {/* The server's own words, verbatim. */}
                <p className="text-sm text-muted-foreground">{summary.reason}</p>
                <p className="text-xs text-muted-foreground">{t("teach.changes.notImplemented")}</p>
              </div>
            )
          }
        </QueryState>
      </DialogContent>
    </Dialog>
  );
}
