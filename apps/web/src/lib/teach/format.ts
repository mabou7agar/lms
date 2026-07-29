import type { Locale } from "@/lib/i18n/config";
import type { MetricValue } from "./api";

/**
 * Locale-aware formatting for the instructor dashboard.
 *
 * Follows the codebase's existing `locale === "ar" ? "ar" : "en"` narrowing (see lib/format.ts)
 * and wraps every Intl call in a try/catch, because a runtime without full ICU data throws rather
 * than degrading — and a dashboard that crashes on a number is worse than one showing a plain one.
 */

function intlLocale(locale: Locale): string {
  return locale === "ar" ? "ar" : "en";
}

export function formatNumber(value: number, locale: Locale): string {
  try {
    return new Intl.NumberFormat(intlLocale(locale)).format(value);
  } catch {
    return String(value);
  }
}

/**
 * The backend sends percentages as whole numbers (72 means 72%), so the value is divided by 100
 * before Intl's percent style multiplies it back. Getting this wrong renders 72% as 7,200%.
 */
export function formatPercent(value: number, locale: Locale): string {
  try {
    return new Intl.NumberFormat(intlLocale(locale), {
      style: "percent",
      maximumFractionDigits: 1,
    }).format(value / 100);
  } catch {
    return `${value}%`;
  }
}

export function formatDate(iso: string | null | undefined, locale: Locale): string | null {
  if (!iso) return null;

  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return null;

  try {
    return new Intl.DateTimeFormat(intlLocale(locale), { dateStyle: "medium" }).format(date);
  } catch {
    return date.toISOString().slice(0, 10);
  }
}

export function formatDateTime(iso: string | null | undefined, locale: Locale): string | null {
  if (!iso) return null;

  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return null;

  try {
    return new Intl.DateTimeFormat(intlLocale(locale), {
      dateStyle: "medium",
      timeStyle: "short",
    }).format(date);
  } catch {
    return date.toISOString();
  }
}

export type MetricFormat = "number" | "percent";

/**
 * Render a metric, or null when it cannot be rendered.
 *
 * Returning null rather than "0" or "—" is the point: the caller is forced to decide what an
 * unavailable metric looks like, and cannot accidentally print a number the backend never sent.
 * A metric that is `available` but somehow carries a null value is treated as unavailable too,
 * rather than coerced.
 */
export function formatMetric(
  metric: MetricValue | undefined,
  format: MetricFormat,
  locale: Locale,
): string | null {
  if (!metric?.available || metric.value === null || metric.value === undefined) return null;

  return format === "percent"
    ? formatPercent(metric.value, locale)
    : formatNumber(metric.value, locale);
}
