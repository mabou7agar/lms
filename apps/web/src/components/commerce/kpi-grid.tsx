"use client";

import { Receipt, Repeat, ShoppingBag, TrendingUp, Undo2, Users, Wallet } from "lucide-react";
import type { CommerceAnalytics } from "@/lib/commerce/commerce-analytics";
import { formatMoney } from "@/lib/format";
import { useI18n } from "@/lib/i18n/i18n-context";
import { RevenueTile } from "./revenue-tile";

const iconClass = "size-4";

/**
 * The KPI tile grid for the commerce analytics dashboard. Owns i18n and money/number formatting so
 * each {@link RevenueTile} stays purely presentational. Money is server-computed integer minor units
 * rendered via {@link formatMoney}; plain counts go through the locale's number formatter. Refunds
 * carry a negative tone (they reduce revenue); net revenue reads positive.
 */
export function KpiGrid({ analytics }: { analytics: CommerceAnalytics }) {
  const { t, locale } = useI18n();
  const money = (minor: number) => formatMoney(minor, analytics.currency, locale);
  const count = (value: number) => new Intl.NumberFormat(locale).format(value);

  return (
    <div className="stagger-in grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <RevenueTile
        label={t("commerce.analytics.revenue")}
        value={money(analytics.revenue_minor)}
        icon={<Wallet className={iconClass} />}
      />
      <RevenueTile
        label={t("commerce.analytics.netRevenue")}
        value={money(analytics.net_revenue_minor)}
        tone="positive"
        icon={<TrendingUp className={iconClass} />}
      />
      <RevenueTile
        label={t("commerce.analytics.refunds")}
        value={money(analytics.refunds_minor)}
        tone="negative"
        icon={<Undo2 className={iconClass} />}
      />
      <RevenueTile
        label={t("commerce.analytics.orders")}
        value={count(analytics.orders)}
        icon={<ShoppingBag className={iconClass} />}
      />
      <RevenueTile
        label={t("commerce.analytics.aov")}
        value={money(analytics.aov_minor)}
        icon={<Receipt className={iconClass} />}
      />
      <RevenueTile
        label={t("commerce.analytics.mrr")}
        value={money(analytics.mrr_minor)}
        icon={<Repeat className={iconClass} />}
      />
      <RevenueTile
        label={t("commerce.analytics.activeSubscribers")}
        value={count(analytics.active_subscribers)}
        icon={<Users className={iconClass} />}
      />
    </div>
  );
}
