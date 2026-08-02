"use client";

import { useEffect, useId, useState } from "react";
import { X } from "lucide-react";
import type { AdminOrder, RefundReason } from "@/lib/commerce/admin";
import { useIssueRefund } from "@/lib/commerce/admin-hooks";
import { errorMessage } from "@/lib/api/errors";
import { formatMoney } from "@/lib/format";
import { useI18n } from "@/lib/i18n/i18n-context";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { FormAlert } from "@/components/auth/form-alert";

/** Minor units per major unit for the currencies this store transacts in (all 2-decimal). */
const MINOR_PER_MAJOR = 100;

const REFUND_REASONS: RefundReason[] = ["requested_by_customer", "duplicate", "fraudulent"];

type RefundDialogProps = {
  order: AdminOrder;
  open: boolean;
  onClose: () => void;
};

/**
 * Modal for proposing a refund against an order. The admin enters a major-unit amount and a reason;
 * on submit the value is converted to integer minor units and sent to the server, which is the sole
 * authority on whether the amount is permitted (it validates the refundable cap and immutability).
 * The client-side max is a UX affordance only, never a security boundary.
 */
export function RefundDialog({ order, open, onClose }: RefundDialogProps) {
  const { t, locale } = useI18n();
  const refund = useIssueRefund();
  const fieldId = useId();

  const [amount, setAmount] = useState("");
  const [reason, setReason] = useState<RefundReason>("requested_by_customer");
  const [error, setError] = useState<string | null>(null);

  const refundableMinor = Math.max(0, order.total_minor - (order.refunded_minor ?? 0));

  // Reset the form whenever the dialog opens for a (possibly different) order. Tracked during
  // render via an open-key sentinel rather than a post-paint effect (no cascading render).
  const openKey = open ? order.id : null;
  const [lastOpenKey, setLastOpenKey] = useState<string | null>(null);
  if (openKey !== lastOpenKey) {
    setLastOpenKey(openKey);
    if (open) {
      setAmount("");
      setReason("requested_by_customer");
      setError(null);
    }
  }

  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") onClose();
    };
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [open, onClose]);

  if (!open) return null;

  const parsed = Number.parseFloat(amount);
  const hasAmount = amount.trim().length > 0 && Number.isFinite(parsed);
  const amountMinor = hasAmount ? Math.round(parsed * MINOR_PER_MAJOR) : undefined;
  const canSubmit = hasAmount && amountMinor != null && amountMinor > 0;

  const onSubmit = () => {
    if (!canSubmit || amountMinor == null) return;
    setError(null);
    refund.mutate(
      { orderId: order.id, input: { amount: amountMinor, reason } },
      {
        onSuccess: () => onClose(),
        onError: (e) => setError(errorMessage(e, t("common.error"))),
      },
    );
  };

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
      role="presentation"
      onClick={onClose}
    >
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby={`${fieldId}-title`}
        className="w-full max-w-md rounded-lg border bg-background p-6 shadow-lg"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="mb-4 flex items-start justify-between gap-3">
          <div className="space-y-1">
            <h2 id={`${fieldId}-title`} className="font-serif text-xl font-semibold">
              {t("commerce.admin.issueRefund")}
            </h2>
            <p className="text-xs text-muted-foreground">
              {t("commerce.admin.order")}: <span className="font-medium">{order.id}</span> ·{" "}
              {formatMoney(refundableMinor, order.currency, locale)}
            </p>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="rounded-md p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
            aria-label={t("commerce.admin.cancel")}
          >
            <X className="size-4" aria-hidden />
          </button>
        </div>

        <div className="space-y-4">
          {error ? <FormAlert>{error}</FormAlert> : null}

          <div className="space-y-2">
            <label className="text-sm font-medium" htmlFor={`${fieldId}-amount`}>
              {t("commerce.admin.refundAmount")}
            </label>
            <Input
              id={`${fieldId}-amount`}
              type="number"
              inputMode="decimal"
              min="0"
              step="0.01"
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
              placeholder="0.00"
              autoComplete="off"
              aria-invalid={amount.trim().length > 0 && !canSubmit}
            />
          </div>

          <div className="space-y-2">
            <label className="text-sm font-medium" htmlFor={`${fieldId}-reason`}>
              {t("commerce.admin.reason")}
            </label>
            <select
              id={`${fieldId}-reason`}
              value={reason}
              onChange={(e) => setReason(e.target.value as RefundReason)}
              className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
              {REFUND_REASONS.map((value) => (
                <option key={value} value={value}>
                  {t(`commerce.admin.reasons.${value}`)}
                </option>
              ))}
            </select>
          </div>

          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="outline" onClick={onClose} disabled={refund.isPending}>
              {t("commerce.admin.cancel")}
            </Button>
            <Button
              type="button"
              variant="destructive"
              onClick={onSubmit}
              disabled={!canSubmit}
              loading={refund.isPending}
            >
              {t("commerce.admin.issueRefund")}
            </Button>
          </div>
        </div>
      </div>
    </div>
  );
}
