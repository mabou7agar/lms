import { api } from "@/lib/api/client";

/**
 * Public certificate verification result (GET /api/v1/certificates/verify/{code}).
 * Issuance facts only — no ids or storage paths are exposed by the backend.
 */
export type CertificateVerification = {
  /** Issued, untampered AND still inside its validity window. A lapsed credential is genuine but not current. */
  valid: boolean;
  /** Effective status: "issued" | "revoked" | "expired". Expiry is derived from the date, never stored. */
  status: string;
  number: string;
  holder_name: string | null;
  course_title: string | null;
  issued_at: string | null;
  expires_at: string | null;
  revoked_at: string | null;
  /** Present only when the branding the certificate was issued under actually names the company. */
  company_name: string | null;
  company_logo_url: string | null;
};

/** Public, unauthenticated verification lookup (throttled server-side per IP). */
export const verifyCertificate = (code: string) =>
  api.data<CertificateVerification>(`certificates/verify/${encodeURIComponent(code)}`, { auth: false });
