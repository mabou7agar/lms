"use client";

import { PencilLine, Send } from "lucide-react";
import Link from "next/link";
import { EmptyState } from "@/components/states/empty-state";
import { QueryState } from "@/components/student/query-state";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useI18n } from "@/lib/i18n/i18n-context";
import type { ActivityEntry, AuthoringActivity, CourseStatus } from "@/lib/teach/api";
import { useAuthoringActivity } from "@/lib/teach/hooks";
import { formatDateTime } from "@/lib/teach/format";

const STATUS_VARIANT: Record<CourseStatus, "success" | "secondary" | "outline"> = {
  published: "success",
  draft: "secondary",
  archived: "outline",
};

/**
 * Absolute formatted timestamps, not relative ones.
 *
 * The project has no relative-time formatter and no Arabic plural rules wired up; hand-rolling
 * "3 days ago" would produce broken Arabic. A medium date-time reads correctly in both locales.
 */
function ActivityList({
  entries,
  emptyLabel,
  icon: Icon,
}: {
  entries: ActivityEntry[];
  emptyLabel: string;
  icon: typeof PencilLine;
}) {
  const { t, locale } = useI18n();

  if (entries.length === 0) {
    return <EmptyState title={emptyLabel} className="p-6" />;
  }

  return (
    <ul className="divide-y">
      {entries.map((entry) => (
        <li key={`${entry.id}-${entry.occurred_at}`} className="flex items-start gap-3 py-3">
          <Icon className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden />

          <div className="min-w-0 flex-1">
            <Link
              href={`/teach/courses/${entry.id}`}
              className="font-medium hover:underline"
            >
              {entry.title}
            </Link>
            <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
              <Badge variant={STATUS_VARIANT[entry.status]}>
                {t(`teach.courses.${entry.status}`)}
              </Badge>
              <time dateTime={entry.occurred_at ?? undefined}>
                {formatDateTime(entry.occurred_at, locale) ?? t("teach.activity.unknownTime")}
              </time>
            </div>
          </div>
        </li>
      ))}
    </ul>
  );
}

/**
 * Authoring activity, built from persisted timestamps only.
 *
 * The backend returns exactly two streams — edits and publishes — because those are the only two
 * facts a column genuinely holds. There is no actor attribution: no `published_by` column exists,
 * so no author name is displayed rather than a plausible-looking guess.
 */
export function ActivitySection() {
  const { t } = useI18n();
  const query = useAuthoringActivity();

  return (
    <QueryState<AuthoringActivity>
      query={query}
      loading={<Skeleton variant="card" className="h-64" />}
    >
      {(activity) => (
        <Card>
          <CardContent className="space-y-5 p-4 sm:p-5">
            <div className="space-y-1">
              <h3 className="text-sm font-semibold">{t("teach.activity.recentlyEdited")}</h3>
              <ActivityList
                entries={activity.recently_edited}
                emptyLabel={t("teach.activity.noEdits")}
                icon={PencilLine}
              />
            </div>

            <div className="space-y-1">
              <h3 className="text-sm font-semibold">{t("teach.activity.recentlyPublished")}</h3>
              <ActivityList
                entries={activity.recently_published}
                emptyLabel={t("teach.activity.noPublishes")}
                icon={Send}
              />
            </div>
          </CardContent>
        </Card>
      )}
    </QueryState>
  );
}
