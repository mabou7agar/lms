"use client";

import { AlertTriangle } from "lucide-react";
import type { Subscription } from "@/lib/commerce/subscriptions";
import { useI18n } from "@/lib/i18n/i18n-context";

/**
 * Dunning notice shown when a subscription is in `grace` or `past_due`: payment has failed and
 * access lapses when the grace clock runs out. Renders nothing for any other status, so the page
 * can mount it unconditionally. The date shown is the grace deadline, falling back to the current
 * period end when no explicit grace clock is set.
 */
export function GraceBanner({ subscription }: { subscription: Subscription }) {
  const { t } = useI18n();

  if (subscription.status !== "grace" && subscription.status !== "past_due") {
    return null;
  }

  const deadline = subscription.grace_ends_at ?? subscription.current_period_end;

  return (
    <div
      role="alert"
      className="flex items-start gap-3 rounded-lg border border-warning/30 bg-warning/10 p-4 text-sm"
    >
      <AlertTriangle className="mt-0.5 size-5 shrink-0 text-warning" aria-hidden />
      <div className="space-y-0.5">
        <p className="font-medium">{t("commerce.subscriptions.grace")}</p>
        {deadline ? (
          <p className="text-muted-foreground">
            {t("commerce.subscriptions.graceUntil")}: {new Date(deadline).toLocaleDateString()}
          </p>
        ) : null}
      </div>
    </div>
  );
}
