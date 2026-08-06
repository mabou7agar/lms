"use client";

import { useForm } from "react-hook-form";
import { useMemo, useState } from "react";
import { Bell, Check } from "lucide-react";
import { errorMessage } from "@/lib/api/errors";
import { useI18n } from "@/lib/i18n/i18n-context";
import { useAuth } from "@/lib/auth/auth-context";
import { useMarkNotificationRead, useNotifications, useUpdatePreferences } from "@/lib/student/hooks";
import { PageHeader } from "@/components/student/page-header";
import { QueryState } from "@/components/student/query-state";
import { Field } from "@/components/auth/field";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Pagination } from "@/components/ui/pagination";
import { EmptyState } from "@/components/states/empty-state";
import { toast } from "@/components/ui/toast";
import { cn } from "@/lib/utils";

const controlClass =
  "flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2";

/**
 * Small, MENA-first curated IANA fallback used only when the runtime lacks
 * `Intl.supportedValuesOf` (pre-2022 engines). Kept deterministic so the rendered option list is
 * identical on the server and client (no hydration mismatch).
 */
const FALLBACK_TIMEZONES = [
  "UTC",
  "Africa/Cairo",
  "Asia/Riyadh",
  "Asia/Dubai",
  "Europe/London",
  "Europe/Paris",
  "America/New_York",
] as const;

/** All IANA timezone identifiers, falling back to a curated list when the API is unavailable. */
function timezoneOptions(): readonly string[] {
  try {
    return Intl.supportedValuesOf("timeZone");
  } catch {
    return FALLBACK_TIMEZONES;
  }
}

type PrefValues = { locale: "en" | "ar"; digest_frequency: "none" | "daily" | "weekly"; timezone: string };

function PreferencesForm() {
  const { t } = useI18n();
  const { user } = useAuth();
  const update = useUpdatePreferences();
  const tz = typeof Intl !== "undefined" ? Intl.DateTimeFormat().resolvedOptions().timeZone : "UTC";
  const timezones = useMemo(() => timezoneOptions(), []);

  const { register, handleSubmit } = useForm<PrefValues>({
    defaultValues: { locale: user?.locale ?? "en", digest_frequency: "daily", timezone: tz },
  });

  const onSubmit = handleSubmit((v) =>
    update.mutate(v, {
      onSuccess: () => toast.success(t("student.notifications.prefsSaved")),
      onError: (e) => toast.error(errorMessage(e, t("common.error"))),
    }),
  );

  return (
    <Card className="border-border/70">
      <CardHeader>
        <CardTitle className="font-serif text-lg">{t("student.notifications.preferences")}</CardTitle>
      </CardHeader>
      <CardContent>
        <form onSubmit={onSubmit} className="grid gap-4 sm:grid-cols-3">
          <Field id="pref-locale" label={t("student.profile.language")}>
            <select id="pref-locale" className={controlClass} {...register("locale")}>
              <option value="en">English</option>
              <option value="ar">العربية</option>
            </select>
          </Field>
          <Field id="digest" label={t("student.notifications.digest")}>
            <select id="digest" className={controlClass} {...register("digest_frequency")}>
              <option value="daily">{t("student.notifications.digestDaily")}</option>
              <option value="weekly">{t("student.notifications.digestWeekly")}</option>
              <option value="none">{t("student.notifications.digestNever")}</option>
            </select>
          </Field>
          <Field id="timezone" label={t("student.notifications.timezone")}>
            <select id="timezone" className={controlClass} {...register("timezone")}>
              {timezones.map((zone) => (
                <option key={zone} value={zone}>
                  {zone}
                </option>
              ))}
            </select>
          </Field>
          <div className="sm:col-span-3">
            <Button type="submit" loading={update.isPending}>
              {t("student.notifications.savePrefs")}
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  );
}

export default function NotificationsPage() {
  const { t } = useI18n();
  const [page, setPage] = useState(1);
  const query = useNotifications(page);
  const markRead = useMarkNotificationRead();

  const onMarkRead = (id: string) =>
    markRead.mutate(id, {
      onSuccess: () => toast.success(t("student.notifications.marked")),
      onError: (e) => toast.error(errorMessage(e, t("common.error"))),
    });

  return (
    <div className="space-y-6">
      <PageHeader eyebrow="INBOX" icon="Bell" title={t("student.notifications.title")} subtitle={t("student.notifications.subtitle")} />

      <QueryState
        query={query}
        isEmpty={(d) => d.data.length === 0}
        empty={<EmptyState icon={<Bell className="size-8" />} title={t("student.notifications.empty")} />}
      >
        {(data) => (
          <div className="space-y-3">
            {data.data.map((n) => (
              <Card key={n.id} className={cn("border-border/70 transition-colors hover:border-copper/30", !n.read && "border-copper/30 bg-copper/[0.03]")}>
                <CardContent className="flex items-start gap-3 p-4">
                  <span className={cn("mt-1.5 size-2 shrink-0 rounded-full", !n.read ? "bg-copper" : "bg-border")} aria-hidden />
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                      <p className="truncate font-medium">{n.title}</p>
                      <Badge variant="outline" className="shrink-0">{n.category}</Badge>
                    </div>
                    <p className="text-sm text-muted-foreground">{n.body}</p>
                    {n.created_at ? (
                      <p className="mt-1 text-xs text-muted-foreground">{new Date(n.created_at).toLocaleString()}</p>
                    ) : null}
                  </div>
                  {!n.read ? (
                    <Button
                      size="sm"
                      variant="ghost"
                      loading={markRead.isPending && markRead.variables === n.id}
                      onClick={() => onMarkRead(n.id)}
                    >
                      <Check className="size-4" aria-hidden /> {t("student.notifications.markRead")}
                    </Button>
                  ) : null}
                </CardContent>
              </Card>
            ))}
            <Pagination page={data.meta.current_page} lastPage={data.meta.last_page} onPageChange={setPage} />
          </div>
        )}
      </QueryState>

      <PreferencesForm />
    </div>
  );
}
