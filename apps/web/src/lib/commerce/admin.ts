import { api } from "@/lib/api/client";
import type { ApiSuccess, Paginated } from "@/types/api";
import type { Order } from "@/lib/commerce/api";

/**
 * Admin commerce read/write client. Kept separate from the learner-facing `api.ts` so the two
 * surfaces never share query keys or endpoints. Every money field is an integer in minor units and
 * is treated as server-authoritative: the browser only ever *proposes* a refund amount, and the
 * domain action on the server validates the cap, currency and immutability rules before persisting.
 */

/**
 * A single row in the admin orders ledger. Extends the learner {@link Order} read model with the
 * running refunded total so the console can present the remaining refundable balance. The customer
 * block is optional because the backing resource only includes it for staff scopes.
 */
export type AdminOrder = Order & {
  /** Sum of all settled refunds against this order, integer minor units. */
  refunded_minor?: number;
  customer?: { id: string; name: string | null; email: string | null } | null;
};

/** Refund reasons accepted by the server's `RefundReason` enum. Mirrors the domain enum values. */
export type RefundReason = "requested_by_customer" | "duplicate" | "fraudulent";

/** The refund the admin proposes. `amount` is integer minor units; omit for a full refund. */
export type RefundInput = {
  amount?: number;
  reason?: RefundReason;
};

/** Server response after a refund is issued. All money is integer minor units, server-computed. */
export type RefundResult = {
  id: string;
  order_id: string;
  status: string;
  amount_minor: number;
  currency: string;
  reason: string | null;
  provider_reference: string | null;
  processed_at: string | null;
};

/** One immutable credited line of a credit note. Money fields are integer minor units. */
export type CreditNoteLine = {
  id: string | number;
  description: string;
  amount_minor: number;
  tax_minor: number;
};

/**
 * Admin read model for a credit note. Money fields are positive integer minor units — the credit
 * note document itself represents the negation. `lines` is only present on eager-loaded reads.
 */
export type CreditNote = {
  id: string;
  number: string;
  status: string;
  currency: string;
  total_minor: number;
  issued_at: string | null;
  order_id?: string | null;
  lines?: CreditNoteLine[];
};

/** Admin read model for a subscription. Recurring price is integer minor units. */
export type AdminSubscription = {
  id: string;
  status: string;
  currency: string;
  amount_minor: number;
  current_period_start: string | null;
  current_period_end: string | null;
  trial_ends_at: string | null;
  grace_ends_at: string | null;
  canceled_at: string | null;
  cancel_at_period_end: boolean;
  is_active_now: boolean;
  plan?: { id: string; name: string; interval: string } | null;
};

/** Page of every order in the system, newest first. Envelope: `{ data, meta, links }`. */
export const getAdminOrders = (page = 1) => api.get<Paginated<AdminOrder>>(`admin/orders?page=${page}`);

/**
 * Propose a refund against an order. The `amount` (minor units) is optional — omitting it asks the
 * server for a full refund. The server validates the amount against the refundable balance; the
 * client never decides the final figure.
 */
export const issueRefund = (orderId: string, input: RefundInput) =>
  api.post<ApiSuccess<RefundResult>>(`admin/orders/${orderId}/refund`, {
    ...(input.amount != null ? { amount_minor: input.amount } : {}),
    ...(input.reason != null ? { reason: input.reason } : {}),
  });

/** Page of the credit notes ledger, newest first. Envelope: `{ data, meta, links }`. */
export const getCreditNotes = (page = 1) => api.get<Paginated<CreditNote>>(`admin/credit-notes?page=${page}`);

/** Page of every subscription in the system. Envelope: `{ data, meta, links }`. */
export const getAdminSubscriptions = (page = 1) =>
  api.get<Paginated<AdminSubscription>>(`admin/subscriptions?page=${page}`);
