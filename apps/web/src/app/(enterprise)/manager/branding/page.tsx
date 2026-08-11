"use client";

import { useEffect, useRef, useState } from "react";
import { Plus, Trash2, BadgeCheck, ShieldCheck } from "lucide-react";
import { errorMessage } from "@/lib/api/errors";
import { useI18n } from "@/lib/i18n/i18n-context";
import { useAuth } from "@/lib/auth/auth-context";
import { track } from "@/lib/analytics/track";
import type { Branding } from "@/lib/branding/api";
import { isValidHex, type CustomDomain, type OrgBrandInput } from "@/lib/branding/org-api";
import {
  useCreateOrgDomain,
  useDeleteOrgDomain,
  useOrgBranding,
  useOrgDomains,
  useUpdateOrgBranding,
  useVerifyOrgDomain,
} from "@/lib/branding/org-hooks";
import { PageHeader } from "@/components/student/page-header";
import { QueryState } from "@/components/student/query-state";
import { SectionCard } from "@/components/org/section-card";
import { Field } from "@/components/auth/field";
import { FormField } from "@/components/ui/form-field";
import { FormAlert } from "@/components/auth/form-alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

/** A colour override control: a native colour picker + a hex text field kept in sync (text is truth). */
function ColorField({
  id,
  label,
  value,
  onChange,
  error,
}: {
  id: string;
  label: string;
  value: string;
  onChange: (next: string) => void;
  error?: string;
}) {
  // The native <input type=color> only accepts #RRGGBB; feed it a safe value and never let it throw.
  const swatch = isValidHex(value) && value.length === 7 ? value : "#000000";
  return (
    <FormField id={id} label={label} error={error}>
      {(control) => (
        <div className="flex items-center gap-2">
          <input
            type="color"
            aria-label={`${label} — picker`}
            value={swatch}
            onChange={(e) => onChange(e.target.value)}
            className="size-10 shrink-0 cursor-pointer rounded-md border border-input bg-background p-1"
          />
          <Input {...control} dir="ltr" value={value} placeholder="#0F766E" onChange={(e) => onChange(e.target.value)} />
        </div>
      )}
    </FormField>
  );
}

function DomainRow({
  domain,
  canVerify,
  onError,
  onNotice,
}: {
  domain: CustomDomain;
  canVerify: boolean;
  onError: (msg: string) => void;
  onNotice: (msg: string) => void;
}) {
  const { t } = useI18n();
  const remove = useDeleteOrgDomain();
  const verify = useVerifyOrgDomain();
  const [confirmDelete, setConfirmDelete] = useState(false);

  return (
    <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border p-3">
      <div className="flex min-w-0 items-center gap-2">
        <p dir="ltr" className="truncate text-sm font-medium">
          {domain.host}
        </p>
        {domain.is_primary ? <Badge variant="outline">{t("branding.domains.primary")}</Badge> : null}
        {domain.verified ? (
          <Badge variant="success">
            <BadgeCheck className="size-3.5" aria-hidden /> {t("branding.domains.verified")}
          </Badge>
        ) : (
          <Badge variant="outline">{t("branding.domains.unverified")}</Badge>
        )}
      </div>

      <div className="flex items-center gap-2">
        {canVerify && !domain.verified ? (
          <Button
            variant="outline"
            size="sm"
            disabled={verify.isPending}
            aria-label={`${t("branding.domains.verify")} — ${domain.host}`}
            onClick={() =>
              verify.mutate(
                { id: domain.id, verified: true },
                {
                  onSuccess: () => onNotice(t("branding.domains.verifiedNotice")),
                  onError: (err) => onError(errorMessage(err, t("branding.error"))),
                },
              )
            }
          >
            <ShieldCheck className="size-4" aria-hidden /> {t("branding.domains.verify")}
          </Button>
        ) : null}
        <Button
          variant="destructive"
          size="sm"
          aria-label={`${t("branding.domains.remove")} — ${domain.host}`}
          onClick={() => setConfirmDelete(true)}
        >
          <Trash2 className="size-4" aria-hidden /> {t("branding.domains.remove")}
        </Button>
      </div>

      <ConfirmDialog
        open={confirmDelete}
        onOpenChange={setConfirmDelete}
        title={t("branding.domains.removeTitle")}
        description={`${t("branding.domains.removeBody")} (${domain.host})`}
        confirmLabel={t("branding.domains.remove")}
        loading={remove.isPending}
        onConfirm={() =>
          remove.mutate(domain.id, {
            onSuccess: () => {
              setConfirmDelete(false);
              onNotice(t("branding.domains.removed"));
            },
            onError: (err) => {
              setConfirmDelete(false);
              onError(errorMessage(err, t("branding.error")));
            },
          })
        }
      />
    </div>
  );
}

/** The brand-override form. Mounted only once `branding` is loaded, so state is seeded lazily (no effect). */
function BrandForm({ branding }: { branding: Branding }) {
  const { t } = useI18n();
  const update = useUpdateOrgBranding();

  const [nameEn, setNameEn] = useState(branding.identity.brand_name.en);
  const [nameAr, setNameAr] = useState(branding.identity.brand_name.ar);
  const [logo, setLogo] = useState(branding.logos.logo_light);
  const [favicon, setFavicon] = useState(branding.logos.favicon);
  // Colours are stored as hex overrides; a non-hex global default (oklch) surfaces as an empty field.
  const [primary, setPrimary] = useState(isValidHex(branding.theme.colors.primary) ? branding.theme.colors.primary : "");
  const [secondary, setSecondary] = useState(
    isValidHex(branding.theme.colors.secondary) ? branding.theme.colors.secondary : "",
  );
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const primaryInvalid = primary.trim() !== "" && !isValidHex(primary);
  const secondaryInvalid = secondary.trim() !== "" && !isValidHex(secondary);
  const canSave = !primaryInvalid && !secondaryInvalid && !update.isPending;

  const onSave = () => {
    if (!canSave) return;
    setError(null);
    setNotice(null);
    const body: OrgBrandInput = {
      brand_name_en: nameEn.trim(),
      brand_name_ar: nameAr.trim(),
      logo: logo.trim(),
      favicon: favicon.trim(),
      primary_color: primary.trim() === "" ? null : primary.trim(),
      secondary_color: secondary.trim() === "" ? null : secondary.trim(),
    };
    update.mutate(body, {
      onSuccess: () => setNotice(t("branding.saved")),
      onError: (err) => setError(errorMessage(err, t("branding.error"))),
    });
  };

  const previewPrimary = isValidHex(primary) ? primary : "var(--primary)";
  const previewSecondary = isValidHex(secondary) ? secondary : "var(--secondary)";

  return (
    <div className="space-y-6">
      {notice ? <FormAlert variant="success">{notice}</FormAlert> : null}
      {error ? <FormAlert>{error}</FormAlert> : null}

      <SectionCard title={t("branding.identity.title")}>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field id="brand-name-en" label={t("branding.identity.nameEn")}>
            <Input id="brand-name-en" value={nameEn} onChange={(e) => setNameEn(e.target.value)} />
          </Field>
          <Field id="brand-name-ar" label={t("branding.identity.nameAr")}>
            <Input id="brand-name-ar" dir="rtl" value={nameAr} onChange={(e) => setNameAr(e.target.value)} />
          </Field>
        </div>
      </SectionCard>

      <SectionCard title={t("branding.assets.title")}>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field id="brand-logo" label={t("branding.assets.logo")} hint={t("branding.assets.assetHint")}>
            <Input id="brand-logo" dir="ltr" value={logo} placeholder="https://…" onChange={(e) => setLogo(e.target.value)} />
          </Field>
          <Field id="brand-favicon" label={t("branding.assets.favicon")} hint={t("branding.assets.assetHint")}>
            <Input id="brand-favicon" dir="ltr" value={favicon} placeholder="https://…" onChange={(e) => setFavicon(e.target.value)} />
          </Field>
        </div>
      </SectionCard>

      <SectionCard title={t("branding.colors.title")}>
        <div className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <ColorField
              id="brand-primary"
              label={t("branding.colors.primary")}
              value={primary}
              onChange={setPrimary}
              error={primaryInvalid ? t("branding.colors.hexInvalid") : undefined}
            />
            <ColorField
              id="brand-secondary"
              label={t("branding.colors.secondary")}
              value={secondary}
              onChange={setSecondary}
              error={secondaryInvalid ? t("branding.colors.hexInvalid") : undefined}
            />
          </div>
          <div className="flex items-center gap-3" aria-label={t("branding.colors.preview")}>
            <span className="text-sm text-muted-foreground">{t("branding.colors.preview")}</span>
            <span className="size-8 rounded-full border" style={{ background: previewPrimary }} aria-hidden />
            <span className="size-8 rounded-full border" style={{ background: previewSecondary }} aria-hidden />
          </div>
          <p className="text-xs text-muted-foreground">{t("branding.colors.hint")}</p>
        </div>
      </SectionCard>

      <div>
        <Button onClick={onSave} disabled={!canSave}>
          {update.isPending ? t("branding.saving") : t("branding.save")}
        </Button>
      </div>
    </div>
  );
}

function DomainsSection({ canVerify }: { canVerify: boolean }) {
  const { t } = useI18n();
  const domains = useOrgDomains();
  const create = useCreateOrgDomain();

  const [host, setHost] = useState("");
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const onCreate = () => {
    if (host.trim() === "") return;
    setError(null);
    create.mutate(
      { host: host.trim() },
      {
        onSuccess: () => {
          setHost("");
          setNotice(t("branding.domains.added"));
        },
        onError: (err) => setError(errorMessage(err, t("branding.error"))),
      },
    );
  };

  return (
    <div className="space-y-4">
      {notice ? <FormAlert variant="success">{notice}</FormAlert> : null}
      {error ? <FormAlert>{error}</FormAlert> : null}

      <SectionCard title={t("branding.domains.addTitle")}>
        <div className="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
          <Field id="new-brand-domain" label={t("branding.domains.host")}>
            <Input
              id="new-brand-domain"
              dir="ltr"
              placeholder={t("branding.domains.hostPlaceholder")}
              value={host}
              onChange={(e) => setHost(e.target.value)}
            />
          </Field>
          <Button onClick={onCreate} disabled={create.isPending}>
            <Plus className="size-4" aria-hidden />
            {create.isPending ? t("branding.domains.adding") : t("branding.domains.add")}
          </Button>
        </div>
        <p className="mt-3 text-xs text-muted-foreground">{t("branding.domains.hint")}</p>
      </SectionCard>

      <SectionCard title={t("branding.domains.listTitle")}>
        <QueryState
          query={domains}
          isEmpty={(d) => d.length === 0}
          empty={<p className="text-sm text-muted-foreground">{t("branding.domains.empty")}</p>}
        >
          {(data) => (
            <div className="space-y-2">
              {data.map((d) => (
                <DomainRow key={d.id} domain={d} canVerify={canVerify} onError={setError} onNotice={setNotice} />
              ))}
            </div>
          )}
        </QueryState>
      </SectionCard>
    </div>
  );
}

export default function ManagerBrandingPage() {
  const { t, locale } = useI18n();
  const { user } = useAuth();
  const branding = useOrgBranding();

  const canVerify = Boolean(user?.roles?.includes("super_admin"));

  // Non-PII page-view (locale is the i18n Locale type). Fired once.
  const fired = useRef(false);
  useEffect(() => {
    if (fired.current) return;
    fired.current = true;
    track("page_view", { locale, path: "/manager/branding" });
  }, [locale]);

  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow={t("branding.eyebrow")}
        icon="Building2"
        title={t("branding.title")}
        subtitle={t("branding.subtitle")}
      />

      <QueryState query={branding}>{(data) => <BrandForm branding={data} />}</QueryState>

      <DomainsSection canVerify={canVerify} />
    </div>
  );
}
