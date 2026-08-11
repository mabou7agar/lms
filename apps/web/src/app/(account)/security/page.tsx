"use client";

import { useEffect, useRef, useState } from "react";
import { Link2Off, ShieldCheck } from "lucide-react";
import { errorMessage } from "@/lib/api/errors";
import { useI18n } from "@/lib/i18n/i18n-context";
import { track } from "@/lib/analytics/track";
import type { LinkedAccount } from "@/lib/sso/api";
import { useLinkedAccounts, useUnlinkAccount } from "@/lib/sso/hooks";
import { PageHeader } from "@/components/student/page-header";
import { QueryState } from "@/components/student/query-state";
import { EmptyState } from "@/components/states/empty-state";
import { SectionCard } from "@/components/org/section-card";
import { FormAlert } from "@/components/auth/form-alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

function providerLabel(t: (k: string) => string, provider: string): string {
  const key = `sso.providers.${provider}`;
  const label = t(key);
  return label === key ? provider : label;
}

export default function AccountSecurityPage() {
  const { t, locale } = useI18n();

  // Non-PII page-view (locale is the i18n Locale type). Fired once.
  const fired = useRef(false);
  useEffect(() => {
    if (fired.current) return;
    fired.current = true;
    track("page_view", { locale, path: "/security" });
  }, [locale]);

  const query = useLinkedAccounts();
  const unlink = useUnlinkAccount();

  const [pending, setPending] = useState<LinkedAccount | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const onConfirm = () => {
    if (!pending) return;
    setError(null);
    setNotice(null);
    unlink.mutate(pending.id, {
      onSuccess: () => {
        setNotice(t("sso.linked.unlinked"));
        setPending(null);
      },
      onError: (err) => {
        setError(errorMessage(err, t("sso.error")));
        setPending(null);
      },
    });
  };

  return (
    <div className="max-w-2xl space-y-6">
      <PageHeader
        eyebrow={t("sso.linked.eyebrow")}
        icon="User"
        title={t("sso.linked.title")}
        subtitle={t("sso.linked.subtitle")}
      />

      {notice ? <FormAlert variant="success">{notice}</FormAlert> : null}
      {error ? <FormAlert>{error}</FormAlert> : null}

      <SectionCard title={t("sso.linked.providers")}>
        <QueryState
          query={query}
          isEmpty={(d) => d.accounts.length === 0}
          empty={<EmptyState title={t("sso.linked.empty")} description={t("sso.linked.emptyHint")} />}
        >
          {(data) => {
            // The last remaining method may not be removed when there is no password to fall back on.
            const lastMethodLocked = !data.has_password && data.accounts.length <= 1;

            return (
              <ul className="space-y-2">
                {data.accounts.map((acc) => (
                  <li
                    key={acc.id}
                    className="flex flex-wrap items-center justify-between gap-3 rounded-md border p-3"
                  >
                    <div className="flex min-w-0 items-center gap-3">
                      <ShieldCheck className="size-5 text-muted-foreground" aria-hidden />
                      <div className="min-w-0">
                        <p className="flex items-center gap-2 text-sm font-medium">
                          {providerLabel(t, acc.provider)}
                          {acc.email ? (
                            <span className="truncate text-xs text-muted-foreground">{acc.email}</span>
                          ) : null}
                        </p>
                        {lastMethodLocked ? (
                          <p className="text-xs text-muted-foreground">{t("sso.linked.lastMethodHint")}</p>
                        ) : null}
                      </div>
                    </div>

                    <div className="flex items-center gap-2">
                      {lastMethodLocked ? <Badge variant="outline">{t("sso.linked.onlyMethod")}</Badge> : null}
                      <Button
                        variant="outline"
                        size="sm"
                        disabled={lastMethodLocked}
                        aria-label={`${t("sso.linked.unlink")} — ${providerLabel(t, acc.provider)}`}
                        onClick={() => setPending(acc)}
                      >
                        <Link2Off className="size-4" aria-hidden /> {t("sso.linked.unlink")}
                      </Button>
                    </div>
                  </li>
                ))}
              </ul>
            );
          }}
        </QueryState>
      </SectionCard>

      <ConfirmDialog
        open={pending !== null}
        onOpenChange={(open) => {
          if (!open) setPending(null);
        }}
        title={t("sso.linked.unlinkTitle")}
        description={
          pending
            ? `${t("sso.linked.unlinkBody")} (${providerLabel(t, pending.provider)})`
            : t("sso.linked.unlinkBody")
        }
        confirmLabel={t("sso.linked.unlink")}
        loading={unlink.isPending}
        onConfirm={onConfirm}
      />
    </div>
  );
}
