"use client";

import { useEffect, useRef, useState } from "react";
import { Plus, Trash2, ShieldAlert, BadgeCheck } from "lucide-react";
import { errorMessage } from "@/lib/api/errors";
import { useI18n } from "@/lib/i18n/i18n-context";
import { track } from "@/lib/analytics/track";
import type { SsoDomainMapping, SsoDomainMode } from "@/lib/sso/api";
import {
  useCreateSsoDomain,
  useDeleteSsoDomain,
  useSsoCapabilities,
  useSsoDomains,
  useUpdateSsoDomainMode,
} from "@/lib/sso/hooks";
import { PageHeader } from "@/components/student/page-header";
import { QueryState } from "@/components/student/query-state";
import { SectionCard } from "@/components/org/section-card";
import { Field } from "@/components/auth/field";
import { FormAlert } from "@/components/auth/form-alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

const MODES: SsoDomainMode[] = ["auto_join", "restrict"];

/** Honest, data-driven SSO capability notice. SAML is surfaced as unsupported from the API. */
function CapabilitiesNotice() {
  const { t } = useI18n();
  const query = useSsoCapabilities();
  const caps = query.data;
  if (!caps) return null;

  return (
    <div
      role="note"
      aria-label={t("sso.domains.capabilitiesLabel")}
      className="rounded-lg border border-amber-300/60 bg-amber-50 p-4 text-sm dark:border-amber-400/30 dark:bg-amber-950/30"
    >
      <div className="flex items-start gap-3">
        <ShieldAlert className="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-400" aria-hidden />
        <div className="space-y-1">
          <p className="font-semibold">{t("sso.domains.samlUnsupportedTitle")}</p>
          {/* The reason string is the single source of truth from config('sso.capabilities'). */}
          <p className="text-muted-foreground">{caps.saml.reason}</p>
          {caps.oidc.supported ? (
            <p className="text-muted-foreground">
              {t("sso.domains.oidcSupported")}
              {caps.oidc.providers.length > 0 ? `: ${caps.oidc.providers.join(", ")}` : ""}
            </p>
          ) : null}
        </div>
      </div>
    </div>
  );
}

function DomainRow({
  mapping,
  onError,
  onNotice,
}: {
  mapping: SsoDomainMapping;
  onError: (msg: string) => void;
  onNotice: (msg: string) => void;
}) {
  const { t } = useI18n();
  const updateMode = useUpdateSsoDomainMode();
  const remove = useDeleteSsoDomain();
  const [confirmDelete, setConfirmDelete] = useState(false);

  return (
    <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border p-3">
      <div className="flex min-w-0 items-center gap-2">
        <p className="truncate text-sm font-medium">{mapping.domain}</p>
        {mapping.verified ? (
          <Badge variant="success">
            <BadgeCheck className="size-3.5" aria-hidden /> {t("sso.domains.verified")}
          </Badge>
        ) : (
          <Badge variant="outline">{t("sso.domains.unverified")}</Badge>
        )}
      </div>

      <div className="flex items-center gap-2">
        <Select
          value={mapping.mode}
          onValueChange={(val) =>
            updateMode.mutate(
              { id: mapping.id, mode: val as SsoDomainMode },
              {
                onSuccess: () => onNotice(t("sso.domains.updated")),
                onError: (err) => onError(errorMessage(err, t("sso.error"))),
              },
            )
          }
        >
          <SelectTrigger className="h-8 w-40" aria-label={`${t("sso.domains.mode")} — ${mapping.domain}`}>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {MODES.map((m) => (
              <SelectItem key={m} value={m}>
                {t(`sso.domains.modes.${m}`)}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        <Button
          variant="destructive"
          size="sm"
          aria-label={`${t("sso.domains.remove")} — ${mapping.domain}`}
          onClick={() => setConfirmDelete(true)}
        >
          <Trash2 className="size-4" aria-hidden /> {t("sso.domains.remove")}
        </Button>
      </div>

      <ConfirmDialog
        open={confirmDelete}
        onOpenChange={setConfirmDelete}
        title={t("sso.domains.removeTitle")}
        description={`${t("sso.domains.removeBody")} (${mapping.domain})`}
        confirmLabel={t("sso.domains.remove")}
        loading={remove.isPending}
        onConfirm={() =>
          remove.mutate(mapping.id, {
            onSuccess: () => {
              setConfirmDelete(false);
              onNotice(t("sso.domains.removed"));
            },
            onError: (err) => {
              setConfirmDelete(false);
              onError(errorMessage(err, t("sso.error")));
            },
          })
        }
      />
    </div>
  );
}

export default function ManagerSsoPage() {
  const { t, locale } = useI18n();

  const fired = useRef(false);
  useEffect(() => {
    if (fired.current) return;
    fired.current = true;
    track("page_view", { locale, path: "/manager/sso" });
  }, [locale]);

  const domains = useSsoDomains();
  const create = useCreateSsoDomain();

  const [domain, setDomain] = useState("");
  const [mode, setMode] = useState<SsoDomainMode>("auto_join");
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const onCreate = () => {
    if (domain.trim() === "") return;
    setError(null);
    create.mutate(
      { domain: domain.trim(), mode },
      {
        onSuccess: () => {
          setDomain("");
          setMode("auto_join");
          setNotice(t("sso.domains.added"));
        },
        onError: (err) => setError(errorMessage(err, t("sso.error"))),
      },
    );
  };

  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow={t("sso.domains.eyebrow")}
        icon="Building"
        title={t("sso.domains.title")}
        subtitle={t("sso.domains.subtitle")}
      />

      <CapabilitiesNotice />

      {notice ? <FormAlert variant="success">{notice}</FormAlert> : null}
      {error ? <FormAlert>{error}</FormAlert> : null}

      <SectionCard title={t("sso.domains.addTitle")}>
        <div className="space-y-4">
          <div className="grid gap-3 sm:grid-cols-[1fr_auto_auto] sm:items-end">
            <Field id="new-domain" label={t("sso.domains.domain")}>
              <Input
                id="new-domain"
                placeholder={t("sso.domains.domainPlaceholder")}
                value={domain}
                onChange={(e) => setDomain(e.target.value)}
              />
            </Field>
            <Field id="new-domain-mode" label={t("sso.domains.mode")}>
              <Select value={mode} onValueChange={(v) => setMode(v as SsoDomainMode)}>
                <SelectTrigger id="new-domain-mode" className="w-44">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {MODES.map((m) => (
                    <SelectItem key={m} value={m}>
                      {t(`sso.domains.modes.${m}`)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </Field>
            <Button onClick={onCreate} disabled={create.isPending}>
              <Plus className="size-4" aria-hidden />
              {create.isPending ? t("sso.domains.adding") : t("sso.domains.add")}
            </Button>
          </div>
          <p className="text-xs text-muted-foreground">{t("sso.domains.modeHint")}</p>
        </div>
      </SectionCard>

      <SectionCard title={t("sso.domains.listTitle")}>
        <QueryState
          query={domains}
          isEmpty={(d) => d.length === 0}
          empty={<p className="text-sm text-muted-foreground">{t("sso.domains.empty")}</p>}
        >
          {(data) => (
            <div className="space-y-2">
              {data.map((m) => (
                <DomainRow key={m.id} mapping={m} onError={setError} onNotice={setNotice} />
              ))}
            </div>
          )}
        </QueryState>
      </SectionCard>
    </div>
  );
}
