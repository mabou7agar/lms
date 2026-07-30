"use client";

import type { Plan, Subscription } from "@/lib/commerce/subscriptions";
import { formatMoney } from "@/lib/format";
import { useI18n } from "@/lib/i18n/i18n-context";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";

/** Pick the price row for a currency, falling back to the plan's default, then the first price. */
function priceFor(plan: Plan, currency: string | null) {
  const byCurrency = currency ? plan.prices.find((p) => p.currency === currency) : undefined;
  return byCurrency ?? plan.prices.find((p) => p.is_default) ?? plan.prices[0] ?? null;
}

type PlanSelectorProps = {
  plans: Plan[];
  /** The user's current subscription, if any — drives current / upgrade / downgrade labelling. */
  current?: Subscription | null;
  /** Called with the plan public id when a plan action is triggered. */
  onSelect: (planId: string) => void;
  /** The plan id whose mutation is in flight (shows a spinner and disables the others). */
  pendingPlanId?: string | null;
};

/**
 * Grid of active plans. When the user has no live subscription each plan offers `subscribe`;
 * otherwise the current plan is marked and the rest are labelled `upgrade` / `downgrade` by
 * comparing their price (in the subscription's currency) against the current recurring amount.
 */
export function PlanSelector({ plans, current, onSelect, pendingPlanId }: PlanSelectorProps) {
  const { t, locale } = useI18n();

  const hasSubscription = current != null && current.is_active_now;
  const currency = current?.currency ?? null;
  const currentPlanId = current?.plan?.id ?? null;
  const currentAmount = current?.amount_minor ?? 0;
  const anyPending = pendingPlanId != null;

  return (
    <div>
      <h2 className="mb-3 text-lg font-semibold">{t("commerce.subscriptions.choosePlan")}</h2>
      <div className="grid gap-4 sm:grid-cols-2">
        {plans.map((plan) => {
          const price = priceFor(plan, currency);
          const isYearly = /year|annual/i.test(plan.interval);
          const periodLabel = isYearly
            ? t("commerce.subscriptions.perYear")
            : t("commerce.subscriptions.perMonth");

          const isCurrent = hasSubscription && plan.id === currentPlanId;
          let action: "subscribe" | "upgrade" | "downgrade" = "subscribe";
          if (hasSubscription && !isCurrent) {
            action = (price?.amount_minor ?? 0) >= currentAmount ? "upgrade" : "downgrade";
          }
          const actionLabel =
            action === "upgrade"
              ? t("commerce.subscriptions.upgrade")
              : action === "downgrade"
                ? t("commerce.subscriptions.downgrade")
                : t("commerce.subscriptions.subscribe");

          return (
            <Card key={plan.id} className={isCurrent ? "border-primary/50" : undefined}>
              <CardContent className="flex h-full flex-col gap-3 p-5">
                <div className="flex items-start justify-between gap-2">
                  <p className="font-semibold">{plan.name}</p>
                  {isCurrent ? <Badge variant="success">{t("commerce.subscriptions.current")}</Badge> : null}
                </div>
                <p className="tabular-nums">
                  {price ? (
                    <>
                      <span className="text-lg font-semibold">
                        {formatMoney(price.amount_minor, price.currency, locale)}
                      </span>{" "}
                      <span className="text-sm text-muted-foreground">{periodLabel}</span>
                    </>
                  ) : null}
                </p>
                {plan.trial_days > 0 ? (
                  <p className="text-xs text-muted-foreground">
                    {t("commerce.subscriptions.trialDays").replace("{days}", String(plan.trial_days))}
                  </p>
                ) : null}
                <div className="mt-auto">
                  <Button
                    className="w-full"
                    variant={action === "downgrade" ? "outline" : "default"}
                    disabled={isCurrent || (anyPending && pendingPlanId !== plan.id)}
                    loading={pendingPlanId === plan.id}
                    onClick={() => onSelect(plan.id)}
                  >
                    {actionLabel}
                  </Button>
                </div>
              </CardContent>
            </Card>
          );
        })}
      </div>
    </div>
  );
}
