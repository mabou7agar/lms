import { api } from "@/lib/api/client";
import type { ApiSuccess } from "@/types/api";

export type EnterpriseRequestType = "demo" | "pricing" | "contact" | "partnership";

export type EnterpriseLeadUtm = {
  source?: string;
  medium?: string;
  campaign?: string;
  term?: string;
  content?: string;
};

export type EnterpriseLeadInput = {
  name: string;
  work_email: string;
  company: string;
  phone?: string;
  company_size?: string;
  country?: string;
  request_type: EnterpriseRequestType;
  message?: string;
  source_page: string;
  utm?: EnterpriseLeadUtm;
  gclid?: string;
  referrer?: string;
  locale: string;
  marketing_consent?: boolean;
  /**
   * Honeypot. Real users never see or fill this; bots that do are rejected server-side. Always
   * sent (empty for humans) so the backend can score the submission.
   */
  website?: string;
};

export type EnterpriseLeadResult = { id: string; status: string };

/**
 * POST /api/v1/public/leads — the public (guest) enterprise-lead funnel. The API base already
 * carries the `/api/v1` prefix, so the path here is just `public/leads` (no leading `v1`).
 */
export const submitEnterpriseLead = (body: EnterpriseLeadInput) =>
  api.post<ApiSuccess<EnterpriseLeadResult>>("public/leads", body);

/** The company-size buckets offered in the form (mirror of the backend scoring config keys). */
export const COMPANY_SIZES = ["1-10", "11-50", "51-200", "201-500", "501-1000", "1000+"] as const;

export const REQUEST_TYPES: readonly EnterpriseRequestType[] = ["demo", "pricing", "contact", "partnership"];
