"use client";

import type { Subscription, SubscriptionStatus } from "@/lib/commerce/subscriptions";
import { formatMoney } from "@/lib/format";
import { useI18n } from "@/lib/i18n/i18n-context";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";

const statusVariant: Record<SubscriptionStatus, "success" | "warning" | "destructive" | "secondary"> = {
  trialing: "success",
  active: "success",
  past_due: "warning",
  grace: "warning",
  expired: "destructive",
  canceled: "secondary",
  paused: "secondary",
};

/** Statuses whose subscription still grants access and can be actively canceled. */
const cancellable: SubscriptionStatus[] = ["trialing", "active", "past_due", "grace"];

type SubscriptionCardProps = {
  subscription: Subscription;
  /** Cancel the subscription (at period end). */
  onCancel: () => void;
  /** Revive a soft-canceled / scheduled-to-cancel subscription. */
  onReactivate: () => void;
  cancelPending?: boolean;
  reactivatePending?: boolean;
};

/**
 * Current-subscription summary: status badge, plan name, recurring price, and the next clock
 * (renewal date, or cancellation date when a soft cancel is scheduled). Presentational — the
 * cancel / reactivate actions are delegated to the parent through callbacks.
 */
export function SubscriptionCard({
  subscription,
  onCancel,
  onReactivate,
  cancelPending,
  reactivatePending,
}: SubscriptionCardProps) {
  const { t, locale } = useI18n();

  const statusLabel: Record<SubscriptionStatus, string> = {
    trialing: t("commerce.subscriptions.status.trialing"),
    active: t("commerce.subscriptions.status.active"),
    past_due: t("commerce.subscriptions.status.past_due"),
    grace: t("commerce.subscriptions.status.grace"),
    expired: t("commerce.subscriptions.status.expired"),
    canceled: t("commerce.subscriptions.status.canceled"),
    paused: t("commerce.subscriptions.status.paused"),
  };

  const scheduledToCancel = subscription.cancel_at_period_end || subscription.status === "canceled";
  const canCancel = !scheduledToCancel && cancellable.includes(subscription.status);
  const canReactivate =
    subscription.cancel_at_period_end || subscription.status === "canceled";

  const interval = subscription.plan?.interval ?? "";
  const isYearly = /year|annual/i.test(interval);
  const periodLabel = isYearly ? t("commerce.subscriptions.perYear") : t("commerce.subscriptions.perMonth");
  const price = formatMoney(subscription.amount_minor, subscription.currency, locale);

  const periodEnd = subscription.current_period_end;

  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-3 space-y-0">
        <div className="space-y-1">
          <CardTitle className="font-serif text-xl">
            {subscription.plan?.name ?? t("commerce.subscriptions.current")}
          </CardTitle>
          <p className="text-sm text-muted-foreground tabular-nums">
            {price} {periodLabel}
          </p>
        </div>
        <Badge variant={statusVariant[subscription.status]}>{statusLabel[subscription.status]}</Badge>
      </CardHeader>

      <CardContent className="space-y-4">
        {periodEnd ? (
          <p className="text-sm text-muted-foreground">
            {scheduledToCancel ? t("commerce.subscriptions.endsOn") : t("commerce.subscriptions.renews")}:{" "}
            <span className="text-foreground">{new Date(periodEnd).toLocaleDateString()}</span>
          </p>
        ) : null}

        {canCancel || canReactivate ? (
          <div className="flex flex-wrap gap-2">
            {canReactivate ? (
              <Button variant="outline" loading={reactivatePending} onClick={onReactivate}>
                {t("commerce.subscriptions.reactivate")}
              </Button>
            ) : null}
            {canCancel ? (
              <Button variant="outline" loading={cancelPending} onClick={onCancel}>
                {t("commerce.subscriptions.cancel")}
              </Button>
            ) : null}
          </div>
        ) : null}
      </CardContent>
    </Card>
  );
}
