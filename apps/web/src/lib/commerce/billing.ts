import { api } from "@/lib/api/client";
import type { Paginated } from "@/types/api";

/** One immutable snapshot line of an invoice. All money fields are integer minor units. */
export type InvoiceLine = {
  id: string;
  description: string;
  quantity: number;
  unit_amount_minor: number;
  tax_minor: number;
  total_minor: number;
};

/**
 * Learner billing-portal read model for an invoice. Money fields are integer minor units.
 * `lines` is only present on the detail endpoint (the list omits the line snapshot).
 */
export type Invoice = {
  id: string;
  number: string;
  status: string;
  currency: string;
  subtotal_minor: number;
  tax_minor: number;
  total_minor: number;
  issued_at: string | null;
  paid_at: string | null;
  lines?: InvoiceLine[];
};

/** Page of the authenticated user's invoices, newest first. Envelope: `{ data, meta, links }`. */
export const getInvoices = (page = 1) => api.get<Paginated<Invoice>>(`invoices?page=${page}`);

/** A single invoice (with its line snapshot) owned by the authenticated user. */
export const getInvoice = (id: string) => api.data<Invoice>(`invoices/${id}`);

/**
 * Same-origin BFF proxy URL for the invoice PDF download. The proxy attaches the Sanctum token
 * server-side, so a plain anchor href is authenticated without exposing the credential to JS.
 */
export const invoicePdfUrl = (id: string) => `/api/backend/invoices/${id}/pdf`;
