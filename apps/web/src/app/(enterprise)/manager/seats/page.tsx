"use client";

import Link from "next/link";
import { useState } from "react";
import { Armchair, ArrowDownUp, UserPlus, UserMinus } from "lucide-react";
import { ApiRequestError } from "@/lib/api/client";
import { errorMessage } from "@/lib/api/errors";
import { useI18n } from "@/lib/i18n/i18n-context";
import { SEAT_DOWNGRADE_CODE, type CompanyEntitlement, type SeatSummary } from "@/lib/enterprise/manager-api";
import {
  useAssignSeat,
  useEntitlements,
  useReleaseSeat,
  useResizeSeats,
  useSeatHistory,
  useSeatSummary,
} from "@/lib/enterprise/manager-hooks";
import { PageHeader } from "@/components/student/page-header";
import { QueryState } from "@/components/student/query-state";
import { LoadingState } from "@/components/states/loading-state";
import { SectionCard } from "@/components/org/section-card";
import { Field } from "@/components/auth/field";
import { FormAlert } from "@/components/auth/form-alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Badge } from "@/components/ui/badge";
import { Progress } from "@/components/ui/progress";
import { Pagination } from "@/components/ui/pagination";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";

function AssignReleaseForm() {
  const { t } = useI18n();
  const assign = useAssignSeat();
  const release = useReleaseSeat();
  const [memberId, setMemberId] = useState("");
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const run = (kind: "assign" | "release") => {
    if (memberId.trim() === "") return;
    setNotice(null);
    setError(null);
    const mutation = kind === "assign" ? assign : release;
    mutation.mutate(memberId.trim(), {
      onSuccess: () => setNotice(t(kind === "assign" ? "manager.seats.assigned" : "manager.seats.released")),
      onError: (err) => setError(errorMessage(err, t("manager.error"))),
    });
  };

  return (
    <SectionCard title={t("manager.seats.assignTitle")}>
      <div className="space-y-3">
        {notice ? <FormAlert variant="success">{notice}</FormAlert> : null}
        {error ? <FormAlert>{error}</FormAlert> : null}
        <Field id="seat-member" label={t("manager.seats.memberId")}>
          <Input
            id="seat-member"
            placeholder={t("manager.seats.memberIdPlaceholder")}
            value={memberId}
            onChange={(e) => setMemberId(e.target.value)}
          />
        </Field>
        <div className="flex gap-2">
          <Button onClick={() => run("assign")} disabled={assign.isPending} className="flex-1">
            <UserPlus className="size-4" aria-hidden /> {t("manager.seats.assign")}
          </Button>
          <Button variant="outline" onClick={() => run("release")} disabled={release.isPending} className="flex-1">
            <UserMinus className="size-4" aria-hidden /> {t("manager.seats.release")}
          </Button>
        </div>
      </div>
    </SectionCard>
  );
}

function ResizeForm({ used }: { used: number }) {
  const { t } = useI18n();
  const resize = useResizeSeats();
  const [value, setValue] = useState("");
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const onResize = () => {
    setNotice(null);
    setError(null);
    const seats = Number.parseInt(value, 10);
    if (!Number.isFinite(seats) || seats < 1) {
      setError(t("manager.error"));
      return;
    }
    // Client-side guard mirroring the backend downgrade-below-assigned rule.
    if (seats < used) {
      setError(t("manager.seats.resizeBelowError").replace("{used}", String(used)));
      return;
    }
    resize.mutate(seats, {
      onSuccess: () => {
        setNotice(t("manager.seats.resized"));
        setValue("");
      },
      onError: (err) => {
        if (err instanceof ApiRequestError && err.code === SEAT_DOWNGRADE_CODE) {
          setError(t("manager.seats.resizeBelowError").replace("{used}", String(used)));
          return;
        }
        setError(errorMessage(err, t("manager.error")));
      },
    });
  };

  return (
    <SectionCard title={t("manager.seats.resizeTitle")}>
      <div className="space-y-3">
        {notice ? <FormAlert variant="success">{notice}</FormAlert> : null}
        {error ? <FormAlert>{error}</FormAlert> : null}
        <Field id="seat-resize" label={t("manager.seats.resizeLabel")} hint={t("manager.seats.resizeHelp")}>
          <Input
            id="seat-resize"
            type="number"
            min={1}
            inputMode="numeric"
            value={value}
            onChange={(e) => setValue(e.target.value)}
          />
        </Field>
        <Button onClick={onResize} disabled={resize.isPending} className="w-full">
          <ArrowDownUp className="size-4" aria-hidden /> {t("manager.seats.resize")}
        </Button>
      </div>
    </SectionCard>
  );
}

function SeatHistory() {
  const { t } = useI18n();
  const [page, setPage] = useState(1);
  const query = useSeatHistory(page);

  return (
    <SectionCard title={t("manager.seats.history")}>
      <QueryState
        query={query}
        isEmpty={(d) => d.data.length === 0}
        empty={<p className="text-sm text-muted-foreground">{t("manager.seats.historyEmpty")}</p>}
      >
        {(data) => (
          <div className="space-y-4">
            <div className="overflow-x-auto rounded-lg border">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t("manager.seats.memberId")}</TableHead>
                    <TableHead>{t("manager.seats.assignedAt")}</TableHead>
                    <TableHead>{t("manager.seats.revokedAt")}</TableHead>
                    <TableHead>{t("manager.members.status")}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {data.data.map((a) => (
                    <TableRow key={a.id}>
                      <TableCell className="font-medium tabular-nums">{a.member_id}</TableCell>
                      <TableCell>{a.assigned_at ? new Date(a.assigned_at).toLocaleDateString() : "—"}</TableCell>
                      <TableCell>{a.revoked_at ? new Date(a.revoked_at).toLocaleDateString() : "—"}</TableCell>
                      <TableCell>
                        <Badge variant={a.active ? "success" : "outline"}>
                          {t(a.active ? "manager.seats.statusActive" : "manager.seats.statusRevoked")}
                        </Badge>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
            {data.meta.last_page > 1 ? (
              <Pagination page={page} lastPage={data.meta.last_page} onPageChange={setPage} />
            ) : null}
          </div>
        )}
      </QueryState>
    </SectionCard>
  );
}

/**
 * Seats can come from two different purchases, and this screen used to know about only one of them.
 *
 * `enterprise/seats` describes an ORG SUBSCRIPTION and is null when the company does not have one —
 * but a company that bought a seat-bearing product/bundle instead holds its seats as ENTITLEMENTS,
 * which is what /manager/training renders. Reading only the subscription made this page announce
 * "No active subscription" to a manager who was, on the very next screen, looking at 5 seats with 1
 * used. Both are consulted here now, so the two screens can no longer disagree.
 */
function SeatSummaryPanel({
  summary,
  entitlements,
}: {
  summary: SeatSummary | null;
  entitlements: CompanyEntitlement[];
}) {
  const { t } = useI18n();

  if (!summary) {
    const active = entitlements.filter((e) => e.status === "active");
    const unlimited = active.some((e) => e.seats.unlimited);
    const purchased = active.reduce((sum, e) => sum + (e.seats.purchased ?? 0), 0);
    const used = active.reduce((sum, e) => sum + e.seats.used, 0);

    // Seats exist, just not as a subscription — show the real numbers rather than denying them.
    if (unlimited || purchased > 0) {
      const infinity = "∞";

      return (
        <SectionCard title={t("manager.seats.title")}>
          <div className="space-y-4">
            <div className="grid grid-cols-3 gap-3 text-center">
              <div>
                <div className="font-serif text-2xl font-bold tabular-nums">{unlimited ? infinity : purchased}</div>
                <div className="text-xs text-muted-foreground">{t("manager.seats.purchased")}</div>
              </div>
              <div>
                <div className="font-serif text-2xl font-bold tabular-nums">{used}</div>
                <div className="text-xs text-muted-foreground">{t("manager.seats.used")}</div>
              </div>
              <div>
                <div className="font-serif text-2xl font-bold tabular-nums">
                  {unlimited ? infinity : Math.max(purchased - used, 0)}
                </div>
                <div className="text-xs text-muted-foreground">{t("manager.seats.available")}</div>
              </div>
            </div>
            {unlimited ? null : (
              <Progress value={purchased > 0 ? (used / purchased) * 100 : 0} label={t("manager.seats.utilization")} />
            )}
            <p className="text-sm text-muted-foreground">
              {t("manager.seats.fromPurchasedTraining")}{" "}
              <Link href="/manager/training" className="font-medium underline underline-offset-4">
                {t("manager.training.purchased.title")}
              </Link>
            </p>
          </div>
        </SectionCard>
      );
    }

    return (
      <SectionCard title={t("manager.seats.title")}>
        <p className="text-sm text-muted-foreground">{t("manager.seats.noSubscription")}</p>
      </SectionCard>
    );
  }
  return (
    <SectionCard title={t("manager.seats.title")}>
      <div className="space-y-4">
        <div className="grid grid-cols-3 gap-3 text-center">
          <div>
            <div className="font-serif text-2xl font-bold tabular-nums">{summary.seats.purchased}</div>
            <div className="text-xs text-muted-foreground">{t("manager.seats.purchased")}</div>
          </div>
          <div>
            <div className="font-serif text-2xl font-bold tabular-nums">{summary.seats.used}</div>
            <div className="text-xs text-muted-foreground">{t("manager.seats.used")}</div>
          </div>
          <div>
            <div className="font-serif text-2xl font-bold tabular-nums">{summary.seats.available}</div>
            <div className="text-xs text-muted-foreground">{t("manager.seats.available")}</div>
          </div>
        </div>
        <Progress
          value={summary.seats.purchased > 0 ? (summary.seats.used / summary.seats.purchased) * 100 : 0}
          label={t("manager.seats.utilization")}
        />
      </div>
    </SectionCard>
  );
}

export default function ManagerSeatsPage() {
  const { t } = useI18n();
  const summary = useSeatSummary();
  const entitlements = useEntitlements();
  const used = summary.data?.seats.used ?? 0;

  // The panel's "no subscription" branch reads entitlements, so it must not render until BOTH have
  // resolved — otherwise the empty state appears for a moment before the seats it denies arrive.
  const summaryPending = summary.isPending || entitlements.isPending;

  return (
    <div className="space-y-6">
      <PageHeader eyebrow="SEATS" icon="Building" title={t("manager.seats.title")} subtitle={t("manager.seats.subtitle")} />

      <div className="grid gap-6 lg:grid-cols-3">
        <div className="lg:col-span-1">
          {summaryPending ? (
            <SectionCard title={t("manager.seats.title")}>
              <LoadingState />
            </SectionCard>
          ) : (
            <QueryState query={summary}>
              {(data) => <SeatSummaryPanel summary={data} entitlements={entitlements.data ?? []} />}
            </QueryState>
          )}
        </div>
        <div className="lg:col-span-1">
          <AssignReleaseForm />
        </div>
        <div className="lg:col-span-1">
          <ResizeForm used={used} />
        </div>
      </div>

      <SeatHistory />
    </div>
  );
}
