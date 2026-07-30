import { api } from "@/lib/api/client";

/**
 * Commerce analytics read layer. Kept separate from the learner-facing `api.ts` and the write-heavy
 * `admin.ts` so the reporting surface never shares query keys or endpoints with either. Every money
 * field is an integer in minor units and is server-authoritative: the browser only ever *reads*
 * pre-aggregated figures, it never re-derives revenue, MRR or averages on the client.
 */

/**
 * Inclusive reporting window, both bounds as `YYYY-MM-DD` date strings. The server interprets the
 * range in the store's reporting timezone; the client only ever passes the two calendar dates.
 */
export type CommerceAnalyticsRange = {
  from: string;
  to: string;
};

/**
 * Pre-aggregated commerce KPIs for a reporting window. All money fields are integer minor units in
 * `currency`; all counts are whole numbers. Server-computed and treated as read-only:
 *
 * - `revenue_minor`        gross settled revenue in the window (before refunds).
 * - `net_revenue_minor`    revenue net of refunds (`revenue_minor − refunds_minor`).
 * - `refunds_minor`        total refunded amount, stored as a positive magnitude.
 * - `orders`               count of paid orders in the window.
 * - `aov_minor`            average order value (net revenue ÷ orders), server-rounded.
 * - `mrr_minor`            monthly recurring revenue across active subscriptions, normalized.
 * - `active_subscribers`   distinct subscriptions currently granting access.
 */
export type CommerceAnalytics = {
  currency: string;
  range: CommerceAnalyticsRange;
  revenue_minor: number;
  net_revenue_minor: number;
  refunds_minor: number;
  orders: number;
  aov_minor: number;
  mrr_minor: number;
  active_subscribers: number;
};

/**
 * Fetch the aggregated commerce KPIs for a date window. Returns the bare data object (no pagination
 * envelope) — the report is a single server-computed snapshot, not a list.
 */
export const getCommerceAnalytics = ({ from, to }: CommerceAnalyticsRange) =>
  api.data<CommerceAnalytics>(
    `admin/analytics?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`,
  );
