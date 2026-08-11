"use client";

import { useMemo } from "react";
import Link from "next/link";
import { CalendarClock, ArrowRight, Radio } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { useEvents } from "@/lib/events/hooks";
import { formatEventDateTime } from "@/lib/events/format";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";

/**
 * Learner UPCOMING live-sessions widget. Lists the next few upcoming live sessions with the start
 * time rendered in the user's own timezone (defaulting to the runtime zone; overridable for tests),
 * plus a join/details affordance. The raw meeting URL is never in the payload, so the affordance
 * links to the session page where join/registration is handled. Loading + empty states included.
 */
export function UpcomingSessions({ timeZone }: { timeZone?: string }) {
  const { t, locale } = useI18n();
  const query = useEvents({ filter: "upcoming", per_page: 5 });

  // The user's timezone: an explicit prop wins (deterministic in tests), else the runtime zone.
  const tz = useMemo(
    () => timeZone ?? (typeof Intl !== "undefined" ? Intl.DateTimeFormat().resolvedOptions().timeZone : "UTC"),
    [timeZone],
  );

  const sessions = query.data?.data ?? [];

  return (
    <Card className="h-full border-border/70">
      <CardHeader className="flex-row items-center justify-between">
        <CardTitle className="flex items-center gap-2 font-serif text-xl">
          <CalendarClock className="size-4 text-copper" aria-hidden /> {t("student.upcoming.title")}
        </CardTitle>
        <Button asChild variant="ghost" size="sm" className="text-copper">
          <Link href="/events">
            {t("student.viewAll")} <ArrowRight className="size-4 rtl:rotate-180" aria-hidden />
          </Link>
        </Button>
      </CardHeader>
      <CardContent className="space-y-2">
        {query.isPending ? (
          <>
            <Skeleton className="h-14" />
            <Skeleton className="h-14" />
          </>
        ) : sessions.length === 0 ? (
          <p className="py-6 text-center text-sm text-muted-foreground">{t("student.upcoming.empty")}</p>
        ) : (
          <ul className="space-y-2">
            {sessions.map((s) => {
              const isLive = s.status === "live";
              return (
                <li
                  key={s.id}
                  className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border/60 p-3"
                >
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                      <p className="truncate text-sm font-medium">{s.title}</p>
                      {isLive ? (
                        <Badge variant="success">
                          <Radio className="size-3.5" aria-hidden /> {t("events.status.live")}
                        </Badge>
                      ) : null}
                    </div>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                      {formatEventDateTime(s.starts_at, tz, locale)}
                    </p>
                  </div>
                  <Button asChild size="sm" variant={isLive ? "default" : "outline"}>
                    <Link href={`/events/${s.id}`}>
                      {isLive ? t("student.upcoming.join") : t("student.upcoming.details")}
                    </Link>
                  </Button>
                </li>
              );
            })}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}
