"use client";

import { useMemo, useState } from "react";
import { CalendarClock, Package, ShieldAlert, Users } from "lucide-react";
import { errorMessage } from "@/lib/api/errors";
import { useI18n } from "@/lib/i18n/i18n-context";
import { formatExpiry, isExpiringSoon } from "@/lib/commerce/expiry";
import { ExpiryBanner } from "@/components/commerce/expiry-banner";
import type { CompanyEntitlement, CourseAssignmentTargetType } from "@/lib/enterprise/manager-api";
import {
  useAssignEntitlement,
  useDepartments,
  useEntitlement,
  useEntitlements,
  useMembers,
  useRevokeEntitlement,
  useTeams,
} from "@/lib/enterprise/manager-hooks";
import { QueryState } from "@/components/student/query-state";
import { SectionCard } from "@/components/org/section-card";
import { Field } from "@/components/auth/field";
import { FormAlert } from "@/components/auth/form-alert";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";

const TARGET_TYPES: CourseAssignmentTargetType[] = ["organization", "member", "department", "team"];

/**
 * The company's purchased training: what was bought, how much of it is in use, and the controls to
 * hand it out. Deliberately operational rather than promotional — a manager comes here to see how
 * many seats are left and to move them, so the counts and the policy that governs them lead.
 *
 * Every refusal the server can raise (out of seats, purchase expired, licence forbids a recall) is
 * surfaced verbatim, because "why can't I do this?" is the question this screen exists to answer.
 */
export function PurchasedTraining() {
  const { t, locale } = useI18n();
  const entitlements = useEntitlements();
  const [openId, setOpenId] = useState<string | null>(null);

  return (
    <SectionCard title={t("manager.training.purchased.title")}>
      <p className="mb-4 text-sm text-muted-foreground">{t("manager.training.purchased.subtitle")}</p>

      <QueryState
        query={entitlements}
        isEmpty={(d) => d.length === 0}
        empty={<p className="text-sm text-muted-foreground">{t("manager.training.purchased.empty")}</p>}
      >
        {(data) => {
          // A purchase running out is the manager's problem before it is the employees': they are
          // the only ones who can renew it, and the seat count tells them how many people it costs.
          const ending = data.filter((e) => e.status === "active" && isExpiringSoon(e.access_ends_at));
          const seatsAtRisk = ending.reduce((sum, e) => sum + e.seats.used, 0);

          return (
          <>
          {ending.length > 0 ? (
            <ExpiryBanner
              className="mb-4"
              title={t("manager.training.purchased.expiringBanner")
                .replace("{count}", String(ending.length))
                .replace("{seats}", String(seatsAtRisk))}
              detail={ending
                .map((e) => `${e.product_title} · ${formatExpiry(e.access_ends_at, locale)}`)
                .join(" · ")}
            />
          ) : null}

          <ul className="space-y-3">
            {data.map((entitlement) => (
              <li key={entitlement.id}>
                <EntitlementRow
                  entitlement={entitlement}
                  open={openId === entitlement.id}
                  onToggle={() => setOpenId(openId === entitlement.id ? null : entitlement.id)}
                  locale={locale}
                />
              </li>
            ))}
          </ul>
          </>
          );
        }}
      </QueryState>
    </SectionCard>
  );
}

function EntitlementRow({
  entitlement,
  open,
  onToggle,
  locale,
}: {
  entitlement: CompanyEntitlement;
  open: boolean;
  onToggle: () => void;
  locale: string;
}) {
  const { t } = useI18n();
  const seats = entitlement.seats;

  return (
    <div className="rounded-lg border">
      {/* On a phone the seat counts drop to their own row: sharing one line squeezes the product
          title into a one-word-per-line column that nobody can read. */}
      <div className="flex flex-wrap items-start justify-between gap-3 p-4">
        <div className="min-w-0 flex-1 basis-full sm:basis-0">
          <div className="flex flex-wrap items-center gap-2">
            <Package className="size-4 shrink-0 text-primary" aria-hidden />
            <span className="font-medium">{entitlement.product_title}</span>
            <StatusBadge status={entitlement.status} />
          </div>

          <p className="mt-1 text-xs text-muted-foreground">
            {t("manager.training.purchased.includedCourses")}:{" "}
            {entitlement.courses.map((c) => c.title).join(" · ") || "—"}
          </p>

          <p className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
            <span className="inline-flex items-center gap-1">
              <CalendarClock className="size-3.5" aria-hidden />
              {t("manager.training.purchased.expires")}:{" "}
              {entitlement.access_ends_at
                ? new Date(entitlement.access_ends_at).toLocaleDateString(locale === "ar" ? "ar" : "en")
                : t("manager.training.purchased.noExpiry")}
            </span>
            <span>
              {t("manager.training.purchased.order")}: {entitlement.order_id}
            </span>
          </p>
        </div>

        <div className="flex w-full items-center justify-between gap-4 sm:w-auto sm:justify-end">
          <SeatCount label={t("manager.training.purchased.seatsPurchased")} value={seats.unlimited ? t("manager.training.purchased.unlimited") : String(seats.purchased ?? 0)} />
          <SeatCount label={t("manager.training.purchased.seatsUsed")} value={String(seats.used)} />
          <SeatCount
            label={t("manager.training.purchased.seatsAvailable")}
            value={seats.unlimited ? "∞" : String(seats.available ?? 0)}
          />
          <Button variant="outline" size="sm" onClick={onToggle}>
            {open ? t("manager.training.purchased.close") : t("manager.training.purchased.manage")}
          </Button>
        </div>
      </div>

      <PolicyNotes entitlement={entitlement} />

      {open ? <SeatManager entitlement={entitlement} /> : null}
    </div>
  );
}

function SeatCount({ label, value }: { label: string; value: string }) {
  return (
    <div className="text-center">
      <p className="text-xs text-muted-foreground">{label}</p>
      <p className="text-lg font-semibold tabular-nums">{value}</p>
    </div>
  );
}

function StatusBadge({ status }: { status: CompanyEntitlement["status"] }) {
  const { t } = useI18n();
  const variant = status === "active" ? "default" : status === "expired" ? "secondary" : "destructive";

  return <Badge variant={variant}>{t(`manager.training.purchased.status.${status}`)}</Badge>;
}

/**
 * The rules the manager is operating under, spelled out rather than left to be discovered when an
 * action is refused.
 */
function PolicyNotes({ entitlement }: { entitlement: CompanyEntitlement }) {
  const { t } = useI18n();
  const policy = entitlement.policy;

  const reassignment = (() => {
    switch (policy.reassignment) {
      case "never":
        return t("manager.training.purchased.policy.reassignNever");
      case "before_start":
        return t("manager.training.purchased.policy.reassignBeforeStart");
      case "before_progress_threshold":
        return t("manager.training.purchased.policy.reassignThreshold").replace(
          "{threshold}",
          String(policy.reassignment_progress_threshold ?? 0),
        );
      default:
        return t("manager.training.purchased.policy.reassignAlways");
    }
  })();

  const notes = [
    reassignment,
    policy.employee_access_expires_with_purchase
      ? t("manager.training.purchased.policy.expiresWithPurchase")
      : t("manager.training.purchased.policy.outlivesPurchase"),
    policy.certificate_branding === "company"
      ? t("manager.training.purchased.policy.brandingCompany")
      : t("manager.training.purchased.policy.brandingPlatform"),
  ];

  return (
    <div className="border-t bg-muted/30 px-4 py-2">
      <p className="mb-1 flex items-center gap-1.5 text-xs font-medium">
        <ShieldAlert className="size-3.5" aria-hidden />
        {t("manager.training.purchased.policy.title")}
      </p>
      <ul className="space-y-0.5 text-xs text-muted-foreground">
        {notes.map((note) => (
          <li key={note}>· {note}</li>
        ))}
      </ul>
    </div>
  );
}

/** Assign form + the current seat holders, with a revoke on each. */
function SeatManager({ entitlement }: { entitlement: CompanyEntitlement }) {
  const { t } = useI18n();
  const [showRevoked, setShowRevoked] = useState(false);
  const detail = useEntitlement(entitlement.id, showRevoked);
  const members = useMembers(1);
  const departments = useDepartments();
  const teams = useTeams();
  const assign = useAssignEntitlement();
  const revoke = useRevokeEntitlement();

  const [targetType, setTargetType] = useState<CourseAssignmentTargetType>("member");
  const [targetId, setTargetId] = useState("");
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const targets = useMemo(() => {
    if (targetType === "member") return (members.data?.data ?? []).map((m) => ({ id: m.id, label: m.email }));
    if (targetType === "department") return (departments.data?.data ?? []).map((d) => ({ id: d.id, label: d.name }));
    if (targetType === "team") return (teams.data?.data ?? []).map((tm) => ({ id: tm.id, label: tm.name }));
    return [];
  }, [departments.data?.data, members.data?.data, targetType, teams.data?.data]);

  const requiresTarget = targetType !== "organization";
  const canSubmit = (!requiresTarget || targetId !== "") && entitlement.assignable;

  const onAssign = () => {
    if (!canSubmit) return;
    setNotice(null);
    setError(null);
    assign.mutate(
      { id: entitlement.id, target_type: targetType, target_id: requiresTarget ? targetId : null },
      {
        onSuccess: (result) =>
          setNotice(
            t("manager.training.purchased.result")
              .replace("{assigned}", String(result.summary.assigned))
              .replace("{already}", String(result.summary.already_assigned))
              .replace("{skipped}", String(result.summary.skipped_without_account)),
          ),
        onError: (err) => setError(errorMessage(err, t("manager.error"))),
      },
    );
  };

  const onRevoke = (memberId: string) => {
    setNotice(null);
    setError(null);
    revoke.mutate(
      { id: entitlement.id, memberId },
      {
        onSuccess: () => setNotice(t("manager.training.purchased.revoked_result")),
        onError: (err) => setError(errorMessage(err, t("manager.error"))),
      },
    );
  };

  const holders = detail.data?.seat_holders ?? [];

  return (
    <div className="space-y-4 border-t p-4">
      {notice ? <FormAlert variant="success">{notice}</FormAlert> : null}
      {error ? <FormAlert>{error}</FormAlert> : null}

      <div className="grid gap-3 md:grid-cols-[12rem_minmax(0,1fr)_auto] md:items-end">
        <Field id={`seat-target-type-${entitlement.id}`} label={t("manager.training.targetType")}>
          <Select
            value={targetType}
            onValueChange={(value) => {
              setTargetType(value as CourseAssignmentTargetType);
              setTargetId("");
            }}
          >
            <SelectTrigger id={`seat-target-type-${entitlement.id}`}>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {TARGET_TYPES.map((type) => (
                <SelectItem key={type} value={type}>
                  {t(`manager.training.targets.${type}`)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </Field>

        {requiresTarget ? (
          <Field id={`seat-target-${entitlement.id}`} label={t("manager.training.target")}>
            <Select value={targetId} onValueChange={setTargetId}>
              <SelectTrigger id={`seat-target-${entitlement.id}`}>
                <SelectValue placeholder={t("manager.training.targetPlaceholder")} />
              </SelectTrigger>
              <SelectContent>
                {targets.map((target) => (
                  <SelectItem key={target.id} value={target.id}>
                    {target.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </Field>
        ) : (
          <p className="text-sm text-muted-foreground">{t("manager.training.organizationScope")}</p>
        )}

        <Button onClick={onAssign} disabled={!canSubmit || assign.isPending}>
          {assign.isPending
            ? t("manager.training.purchased.assigning")
            : t("manager.training.purchased.assignSeats")}
        </Button>
      </div>

      <div>
        <div className="mb-2 flex items-center justify-between gap-3">
          <p className="flex items-center gap-1.5 text-sm font-medium">
            <Users className="size-4" aria-hidden />
            {t("manager.training.purchased.seatHolders")}
          </p>
          <Button variant="ghost" size="sm" onClick={() => setShowRevoked(!showRevoked)}>
            {t("manager.training.purchased.showRevoked")}
          </Button>
        </div>

        {holders.length === 0 ? (
          <p className="text-sm text-muted-foreground">{t("manager.training.purchased.noHolders")}</p>
        ) : (
          <ul className="divide-y rounded-md border">
            {holders.map((holder) => (
              <li key={holder.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm">
                <span className="min-w-0 truncate">{holder.email ?? "—"}</span>
                <span className="flex items-center gap-2">
                  <Badge variant={holder.active ? "outline" : "secondary"}>
                    {holder.active
                      ? t("manager.training.purchased.assigned")
                      : t("manager.training.purchased.revoked")}
                  </Badge>
                  {holder.active && holder.member_id ? (
                    <Button
                      variant="ghost"
                      size="sm"
                      disabled={revoke.isPending}
                      onClick={() => onRevoke(holder.member_id as string)}
                    >
                      {revoke.isPending
                        ? t("manager.training.purchased.revoking")
                        : t("manager.training.purchased.revoke")}
                    </Button>
                  ) : null}
                </span>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}
