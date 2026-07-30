"use client";

import { useQuery } from "@tanstack/react-query";
import { getCommerceAnalytics, type CommerceAnalyticsRange } from "./commerce-analytics";

/**
 * Aggregated commerce KPIs for a reporting window. The date bounds are part of the query key so a
 * range change refetches (and results for each window stay independently cached). `placeholderData`
 * is intentionally omitted — a stale range would misreport totals — so switching ranges shows the
 * shared loading state rather than the previous window's numbers.
 */
export const useCommerceAnalytics = (range: CommerceAnalyticsRange) =>
  useQuery({
    queryKey: ["admin", "analytics", range.from, range.to],
    queryFn: () => getCommerceAnalytics(range),
  });
