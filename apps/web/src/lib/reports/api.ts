import { api } from "@/lib/api/client";

/**
 * Typed client for the operational reports API (/api/v1/reports/insights/*). Every report returns a
 * real, server-computed payload; the shapes vary per report so payloads are kept as loose records
 * and rendered generically. The catalog drives the reports hub.
 */

export type ReportCatalogItem = { key: string; label: string; description: string };
export type ReportMeta = { from: string; to: string };
export type ReportPayload = Record<string, unknown>;
export type ReportEnvelope = { data: ReportPayload; meta: ReportMeta };

export const REPORT_KEYS = [
  "revenue",
  "commerce",
  "course_performance",
  "instructor_performance",
  "organization_performance",
  "certificates",
  "live_sessions",
  "learner_activity",
  "completion_funnel",
  "retention",
  "crm",
  "admin_summary",
  "marketing_funnel",
  "accounting",
] as const;

export type ReportKey = (typeof REPORT_KEYS)[number];

/** Report key -> hyphenated endpoint path segment. */
const PATHS: Record<string, string> = {
  revenue: "revenue",
  commerce: "commerce",
  course_performance: "course-performance",
  instructor_performance: "instructor-performance",
  organization_performance: "organization-performance",
  certificates: "certificates",
  live_sessions: "live-sessions",
  learner_activity: "learner-activity",
  completion_funnel: "completion-funnel",
  retention: "retention",
  crm: "crm",
  admin_summary: "admin-summary",
  marketing_funnel: "marketing-funnel",
  accounting: "accounting",
};

export type ReportParams = { from?: string; to?: string; page?: number; perPage?: number };

/** GET /reports/insights/catalog — the list of available operational reports. */
export const getReportCatalog = () => api.data<ReportCatalogItem[]>("reports/insights/catalog");

/** GET /reports/insights/{report} — a single report's computed payload + range meta. */
export const getReportInsight = (key: string, params: ReportParams = {}): Promise<ReportEnvelope> => {
  const seg = PATHS[key] ?? key;
  const q = new URLSearchParams();
  if (params.from) q.set("from", params.from);
  if (params.to) q.set("to", params.to);
  if (params.page) q.set("page", String(params.page));
  if (params.perPage) q.set("per_page", String(params.perPage));
  const qs = q.toString();
  return api.get<ReportEnvelope>(`reports/insights/${seg}${qs ? `?${qs}` : ""}`);
};
