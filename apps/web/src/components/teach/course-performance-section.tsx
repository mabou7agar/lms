"use client";

import { ArrowDown, ArrowUp, ArrowUpDown, Search } from "lucide-react";
import Link from "next/link";
import { useEffect, useState } from "react";
import { EmptyState } from "@/components/states/empty-state";
import { ErrorState } from "@/components/states/error-state";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Pagination } from "@/components/ui/pagination";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Table,
  TableBody,
  TableCaption,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { errorMessage } from "@/lib/api/errors";
import { useI18n } from "@/lib/i18n/i18n-context";
import type {
  CoursePerformanceRow,
  CourseStatus,
  PerformanceSortField,
  SortDirection,
} from "@/lib/teach/api";
import { useCoursePerformance } from "@/lib/teach/hooks";
import { formatDate, formatMetric } from "@/lib/teach/format";
import { CourseActionsMenu } from "./course-actions-menu";

const STATUS_VARIANT: Record<CourseStatus, "success" | "secondary" | "outline"> = {
  published: "success",
  draft: "secondary",
  archived: "outline",
};

const PER_PAGE_OPTIONS = [10, 15, 25, 50] as const;

/** Only these are offered, and only these are sent. An unknown column is a 422 from the backend. */
const SORTABLE_COLUMNS: ReadonlyArray<{ field: PerformanceSortField; labelKey: string }> = [
  { field: "title", labelKey: "teach.performance.course" },
  { field: "status", labelKey: "teach.performance.status" },
  { field: "updated_at", labelKey: "teach.performance.lastUpdated" },
  { field: "published_at", labelKey: "teach.performance.lastPublished" },
];

export interface CoursePerformanceSectionProps {
  onReviewReadiness: (courseId: string) => void;
  onViewChanges: (courseId: string) => void;
}

/** A metric cell that says "Unavailable" rather than printing a zero the backend never sent. */
function MetricCell({
  metric,
  format,
}: {
  metric: CoursePerformanceRow["completion_rate"];
  format: "number" | "percent";
}) {
  const { t, locale } = useI18n();
  const formatted = formatMetric(metric, format, locale);

  return formatted === null ? (
    <span className="text-xs text-muted-foreground" title={metric?.reason ?? undefined}>
      {t("teach.metric.unavailable")}
    </span>
  ) : (
    <span className="tabular-nums">{formatted}</span>
  );
}

export function CoursePerformanceSection({
  onReviewReadiness,
  onViewChanges,
}: CoursePerformanceSectionProps) {
  const { t, locale } = useI18n();

  const [search, setSearch] = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");
  const [status, setStatus] = useState<CourseStatus | "">("");
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [sort, setSort] = useState<PerformanceSortField>("updated_at");
  const [direction, setDirection] = useState<SortDirection>("desc");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState<number>(15);

  // 300ms, matching the existing convention in the leads and catalog pages.
  useEffect(() => {
    const id = setTimeout(() => {
      setDebouncedSearch(search);
      setPage(1);
    }, 300);
    return () => clearTimeout(id);
  }, [search]);

  // Every filter handler below also resets to page 1, in the SAME event rather than in an effect.
  // Page 4 of an unfiltered list is usually empty once a filter is applied, which an author reads
  // as "no results" when there are plenty. Doing it in an effect would cascade a second render on
  // every filter change — what `react-hooks/set-state-in-effect` warns about.

  const query = useCoursePerformance({
    search: debouncedSearch || undefined,
    status: status || undefined,
    sort,
    direction,
    page,
    per_page: perPage,
    date_from: dateFrom || undefined,
    date_to: dateTo || undefined,
  });

  const toggleSort = (field: PerformanceSortField) => {
    setPage(1);

    if (sort === field) {
      setDirection((current) => (current === "asc" ? "desc" : "asc"));
      return;
    }
    setSort(field);
    setDirection("asc");
  };

  const sortIndicator = (field: PerformanceSortField) => {
    if (sort !== field) return <ArrowUpDown className="size-3.5 opacity-50" aria-hidden />;
    return direction === "asc" ? (
      <ArrowUp className="size-3.5" aria-hidden />
    ) : (
      <ArrowDown className="size-3.5" aria-hidden />
    );
  };

  const rows = query.data?.data ?? [];
  const meta = query.data?.meta;

  return (
    <Card>
      <CardContent className="space-y-4 p-4 sm:p-5">
        {/* ---- filters ---- */}
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div className="space-y-1.5">
            <Label htmlFor="perf-search">{t("teach.performance.search")}</Label>
            <div className="relative">
              <Search
                className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                aria-hidden
              />
              <Input
                id="perf-search"
                type="search"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder={t("teach.performance.searchPlaceholder")}
                className="ps-9"
              />
            </div>
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="perf-status">{t("teach.performance.status")}</Label>
            <Select
              value={status || "all"}
              onValueChange={(value) => {
                setStatus(value === "all" ? "" : (value as CourseStatus));
                setPage(1);
              }}
            >
              <SelectTrigger id="perf-status">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">{t("teach.courses.all")}</SelectItem>
                <SelectItem value="draft">{t("teach.courses.draft")}</SelectItem>
                <SelectItem value="published">{t("teach.courses.published")}</SelectItem>
                <SelectItem value="archived">{t("teach.courses.archived")}</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="perf-from">{t("teach.performance.from")}</Label>
            <Input
              id="perf-from"
              type="date"
              value={dateFrom}
              onChange={(event) => {
                setDateFrom(event.target.value);
                setPage(1);
              }}
            />
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="perf-to">{t("teach.performance.to")}</Label>
            <Input
              id="perf-to"
              type="date"
              value={dateTo}
              onChange={(event) => {
                setDateTo(event.target.value);
                setPage(1);
              }}
            />
          </div>
        </div>

        {/* ---- table ---- */}
        {query.isError ? (
          <ErrorState
            message={errorMessage(query.error, t("common.error"))}
            onRetry={() => query.refetch()}
          />
        ) : query.isPending ? (
          <div className="space-y-2" aria-busy="true">
            {Array.from({ length: 5 }).map((_, index) => (
              <Skeleton key={index} variant="table-row" />
            ))}
          </div>
        ) : rows.length === 0 ? (
          <EmptyState
            title={t("teach.performance.empty")}
            description={t("teach.performance.emptyHint")}
          />
        ) : (
          <>
            {/* overflow-x-auto keeps the table usable on mobile without clipping any column */}
            <div className="overflow-x-auto">
              <Table density="compact">
                <TableCaption className="sr-only">{t("teach.performance.caption")}</TableCaption>
                <TableHeader sticky>
                  <TableRow>
                    {SORTABLE_COLUMNS.map(({ field, labelKey }) => (
                      <TableHead
                        key={field}
                        aria-sort={
                          sort === field
                            ? direction === "asc"
                              ? "ascending"
                              : "descending"
                            : "none"
                        }
                      >
                        <button
                          type="button"
                          onClick={() => toggleSort(field)}
                          className="inline-flex items-center gap-1 rounded outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                          {t(labelKey)}
                          {sortIndicator(field)}
                        </button>
                      </TableHead>
                    ))}
                    <TableHead>{t("teach.performance.enrollments")}</TableHead>
                    <TableHead>{t("teach.performance.uniqueLearners")}</TableHead>
                    <TableHead>{t("teach.performance.activeLearners")}</TableHead>
                    <TableHead>{t("teach.performance.completionRate")}</TableHead>
                    <TableHead>{t("teach.performance.averageProgress")}</TableHead>
                    <TableHead>{t("teach.performance.passRate")}</TableHead>
                    <TableHead>{t("teach.performance.readiness")}</TableHead>
                    <TableHead>
                      <span className="sr-only">{t("teach.performance.actions")}</span>
                    </TableHead>
                  </TableRow>
                </TableHeader>

                <TableBody>
                  {rows.map((row) => (
                    <TableRow key={row.id}>
                      <TableCell className="max-w-[16rem]">
                        <Link
                          href={`/teach/courses/${row.id}`}
                          className="font-medium hover:underline"
                        >
                          {row.title}
                        </Link>
                        <p className="text-xs text-muted-foreground">
                          {row.sections} · {row.lessons}
                        </p>
                      </TableCell>

                      <TableCell>
                        <Badge variant={STATUS_VARIANT[row.status]}>
                          {t(`teach.courses.${row.status}`)}
                        </Badge>
                      </TableCell>

                      <TableCell className="whitespace-nowrap text-xs text-muted-foreground">
                        {formatDate(row.last_updated_at, locale) ?? "—"}
                      </TableCell>
                      <TableCell className="whitespace-nowrap text-xs text-muted-foreground">
                        {formatDate(row.last_published_at, locale) ?? "—"}
                      </TableCell>

                      <TableCell><MetricCell metric={row.enrollment_count} format="number" /></TableCell>
                      <TableCell><MetricCell metric={row.unique_learners} format="number" /></TableCell>
                      <TableCell><MetricCell metric={row.active_learners} format="number" /></TableCell>
                      <TableCell><MetricCell metric={row.completion_rate} format="percent" /></TableCell>
                      <TableCell><MetricCell metric={row.average_progress} format="percent" /></TableCell>
                      <TableCell><MetricCell metric={row.assessment_pass_rate} format="percent" /></TableCell>

                      <TableCell>
                        <button
                          type="button"
                          onClick={() => onReviewReadiness(row.id)}
                          className="inline-flex items-center gap-1.5 rounded outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                          {row.publish_blocker_count > 0 ? (
                            <Badge variant="destructive">
                              {row.publish_blocker_count} {t("teach.readiness.blockersShort")}
                            </Badge>
                          ) : null}
                          {row.warning_count > 0 ? (
                            <Badge variant="warning">
                              {row.warning_count} {t("teach.readiness.warningsShort")}
                            </Badge>
                          ) : null}
                          {row.publish_blocker_count === 0 && row.warning_count === 0 ? (
                            <Badge variant="success">{t("teach.readiness.ready")}</Badge>
                          ) : null}
                        </button>
                      </TableCell>

                      <TableCell className="text-end">
                        <CourseActionsMenu
                          courseId={row.id}
                          title={row.title}
                          status={row.status}
                          onReviewReadiness={onReviewReadiness}
                          onViewChanges={onViewChanges}
                        />
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>

            {/* ---- pagination ---- */}
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div className="flex items-center gap-2">
                <Label htmlFor="perf-per-page" className="text-xs text-muted-foreground">
                  {t("teach.performance.perPage")}
                </Label>
                <Select
                  value={String(perPage)}
                  onValueChange={(value) => {
                    setPerPage(Number(value));
                    setPage(1);
                  }}
                >
                  <SelectTrigger id="perf-per-page" className="h-8 w-20">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {PER_PAGE_OPTIONS.map((option) => (
                      <SelectItem key={option} value={String(option)}>
                        {option}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              {meta && meta.last_page > 1 ? (
                <Pagination page={meta.current_page} lastPage={meta.last_page} onPageChange={setPage} />
              ) : null}
            </div>
          </>
        )}
      </CardContent>
    </Card>
  );
}

