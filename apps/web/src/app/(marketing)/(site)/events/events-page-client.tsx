"use client";

import Link from "next/link";
import { Suspense, useEffect, useState } from "react";
import { CalendarDays, Search, Users } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { useEvents } from "@/lib/events/hooks";
import type { EventFilter, EventListItem, EventStatus } from "@/lib/events/api";
import { formatEventDateTime } from "@/lib/events/format";
import { PageHero } from "@/components/marketing/page-hero";
import { QueryState } from "@/components/student/query-state";
import { Badge } from "@/components/ui/badge";
import { Input } from "@/components/ui/input";
import { Card, CardContent } from "@/components/ui/card";
import { Pagination } from "@/components/ui/pagination";
import { EmptyState } from "@/components/states/empty-state";
import { cn } from "@/lib/utils";

function statusVariant(status: EventStatus): "default" | "secondary" | "outline" | "destructive" {
  switch (status) {
    case "live":
      return "destructive";
    case "completed":
      return "secondary";
    case "cancelled":
      return "outline";
    default:
      return "default";
  }
}

function EventCard({ event }: { event: EventListItem }) {
  const { t, locale } = useI18n();
  const when = formatEventDateTime(event.starts_at, event.timezone, locale);

  return (
    <Link href={`/events/${event.id}`} className="group block h-full focus:outline-none">
      <Card className="card-hover h-full overflow-hidden group-hover:border-primary/30 group-hover:elevation-4 group-focus-visible:ring-2 group-focus-visible:ring-ring">
        <CardContent className="flex h-full flex-col gap-3 p-5">
          <div className="flex items-center justify-between gap-2">
            <Badge variant={statusVariant(event.status)}>{t(`events.status.${event.status}`)}</Badge>
            {event.capacity != null ? (
              <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                <Users className="size-3.5" aria-hidden />
                {event.registered_count}/{event.capacity}
              </span>
            ) : null}
          </div>

          <h2 className="font-serif text-lg font-semibold leading-snug tracking-tight">{event.title}</h2>

          {when ? (
            <p className="inline-flex items-center gap-1.5 text-sm text-muted-foreground">
              <CalendarDays className="size-4" aria-hidden />
              <span>
                {when} <span className="text-xs">({event.timezone})</span>
              </span>
            </p>
          ) : null}

          {event.description ? (
            <p className="line-clamp-2 text-sm text-muted-foreground">{event.description}</p>
          ) : null}

          {event.speakers.length > 0 ? (
            <p className="mt-auto text-xs text-muted-foreground">
              {t("events.speakersLabel")}: {event.speakers.map((s) => s.name).join(", ")}
            </p>
          ) : null}
        </CardContent>
      </Card>
    </Link>
  );
}

function EventsCatalog() {
  const { t } = useI18n();
  const [filter, setFilter] = useState<EventFilter>("upcoming");
  const [q, setQ] = useState("");
  const [debouncedQ, setDebouncedQ] = useState("");
  const [page, setPage] = useState(1);

  useEffect(() => {
    const id = setTimeout(() => setDebouncedQ(q), 300);
    return () => clearTimeout(id);
  }, [q]);

  // Reset to page 1 when the search/filter changes — during render (React's "adjust state while
  // rendering" pattern) rather than in an effect, avoiding the extra cascading render.
  const filterKey = `${debouncedQ}|${filter}`;
  const [prevFilterKey, setPrevFilterKey] = useState(filterKey);
  if (filterKey !== prevFilterKey) {
    setPrevFilterKey(filterKey);
    setPage(1);
  }

  const query = useEvents({ filter, q: debouncedQ || undefined, page, per_page: 12 });

  const tabs: { key: EventFilter; label: string }[] = [
    { key: "upcoming", label: t("events.tabs.upcoming") },
    { key: "past", label: t("events.tabs.past") },
  ];

  return (
    <div>
      <PageHero page="events" />

      <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div role="tablist" aria-label={t("events.tabs.aria")} className="inline-flex rounded-lg border bg-card p-1">
          {tabs.map((tab) => (
            <button
              key={tab.key}
              role="tab"
              aria-selected={filter === tab.key}
              onClick={() => setFilter(tab.key)}
              className={cn(
                "rounded-md px-4 py-1.5 text-sm font-medium transition-colors",
                filter === tab.key ? "bg-primary text-primary-foreground" : "text-muted-foreground hover:text-foreground",
              )}
            >
              {tab.label}
            </button>
          ))}
        </div>

        <div className="relative w-full sm:max-w-xs">
          <Search className="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-muted-foreground" aria-hidden />
          <Input
            className="ps-9"
            placeholder={t("events.searchPlaceholder")}
            value={q}
            onChange={(e) => setQ(e.target.value)}
            aria-label={t("events.searchPlaceholder")}
          />
        </div>
      </div>

      <QueryState
        query={query}
        isEmpty={(d) => d.data.length === 0}
        empty={<EmptyState title={t("events.empty")} />}
      >
        {(data) => (
          <div className="space-y-6">
            <div className="stagger-in grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {data.data.map((event) => (
                <EventCard key={event.id} event={event} />
              ))}
            </div>
            <Pagination page={data.meta.current_page} lastPage={data.meta.last_page} onPageChange={setPage} />
          </div>
        )}
      </QueryState>
    </div>
  );
}

export function EventsPageClient() {
  return (
    <Suspense>
      <EventsCatalog />
    </Suspense>
  );
}
