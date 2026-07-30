"use client";

import { useState } from "react";
import { Sparkles } from "lucide-react";
import { errorMessage } from "@/lib/api/errors";
import { useI18n } from "@/lib/i18n/i18n-context";
import type { Subscription } from "@/lib/commerce/subscriptions";
import {
  useCancelSubscription,
  useChangePlan,
  useMySubscriptions,
  usePlans,
  useReactivateSubscription,
  useSubscribe,
} from "@/lib/commerce/subscriptions-hooks";
import { RequireAuth } from "@/lib/auth/guards";
import { QueryState } from "@/components/student/query-state";
import { GraceBanner } from "@/components/commerce/grace-banner";
import { SubscriptionCard } from "@/components/commerce/subscription-card";
import { PlanSelector } from "@/components/commerce/plan-selector";
import { FormAlert } from "@/components/auth/form-alert";
import { EmptyState } from "@/components/states/empty-state";

/** The subscription to surface as "current": the access-granting one, else the newest row. */
function pickCurrent(subscriptions: Subscription[]): Subscription | null {
  return subscriptions.find((s) => s.is_active_now) ?? subscriptions[0] ?? null;
}

function SubscriptionsView() {
  const { t } = useI18n();
  const subsQuery = useMySubscriptions(1);
  const plansQuery = usePlans();

  const subscribe = useSubscribe();
  const changePlan = useChangePlan();
  const cancel = useCancelSubscription();
  const reactivate = useReactivateSubscription();

  const [error, setError] = useState<string | null>(null);

  const current = pickCurrent(subsQuery.data?.data ?? []);
  const hasLive = current != null && current.is_active_now;

  const pendingPlanId = subscribe.isPending
    ? (subscribe.variables ?? null)
    : changePlan.isPending
      ? (changePlan.variables?.planId ?? null)
      : null;

  const onSelectPlan = (planId: string) => {
    setError(null);
    const onError = (e: unknown) => setError(errorMessage(e, t("common.error")));
    if (hasLive && current) {
      changePlan.mutate({ id: current.id, planId }, { onError });
    } else {
      subscribe.mutate(planId, { onError });
    }
  };

  const onCancel = () => {
    if (!current) return;
    setError(null);
    cancel.mutate(current.id, { onError: (e) => setError(errorMessage(e, t("common.error"))) });
  };

  const onReactivate = () => {
    if (!current) return;
    setError(null);
    reactivate.mutate(current.id, { onError: (e) => setError(errorMessage(e, t("common.error"))) });
  };

  return (
    <div className="space-y-8">
      {error ? <FormAlert>{error}</FormAlert> : null}

      {current ? (
        <section className="space-y-4">
          <GraceBanner subscription={current} />
          <SubscriptionCard
            subscription={current}
            onCancel={onCancel}
            onReactivate={onReactivate}
            cancelPending={cancel.isPending}
            reactivatePending={reactivate.isPending}
          />
        </section>
      ) : null}

      <QueryState
        query={plansQuery}
        isEmpty={(plans) => plans.length === 0}
        empty={<EmptyState icon={<Sparkles className="size-8" />} title={t("commerce.subscriptions.noPlans")} />}
      >
        {(plans) => (
          <PlanSelector plans={plans} current={current} onSelect={onSelectPlan} pendingPlanId={pendingPlanId} />
        )}
      </QueryState>
    </div>
  );
}

export default function SubscriptionsPage() {
  const { t } = useI18n();
  return (
    <RequireAuth>
      <header className="mb-8">
        <h1 className="font-serif text-3xl font-semibold tracking-tight sm:text-4xl">
          {t("commerce.subscriptions.title")}
        </h1>
      </header>
      <SubscriptionsView />
    </RequireAuth>
  );
}
