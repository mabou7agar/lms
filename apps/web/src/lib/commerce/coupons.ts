import { api } from "@/lib/api/client";
import type { Paginated } from "@/types/api";

/**
 * Admin coupons read/write client. Kept in its own module so the coupons console never shares
 * query keys or endpoints with the learner-facing cart surface (`api.ts`, which owns the public
 * `validateCoupon`). Every money field is an integer in minor units and is server-authoritative:
 * the form only *proposes* values, and the domain action validates them before persisting.
 */

/** Discount mechanic. `percentage` stores an integer percent; `fixed` stores integer minor units. */
export type CouponType = "percentage" | "fixed";

/** What a coupon may be redeemed against. Mirrors the backend `CouponScope` enum (all | products). */
export type CouponScope = "all" | "products";

/**
 * Admin read model for a single coupon. `value` is an integer percent (0–100) when
 * `type === "percentage"`, or integer minor units when `type === "fixed"` (paired with
 * `currency`). The validity window (`starts_at` / `ends_at`) and redemption caps are optional.
 */
export type Coupon = {
  id: string;
  code: string;
  type: CouponType;
  /** Integer percent for `percentage`, integer minor units for `fixed`. */
  value: number;
  /** ISO currency for `fixed` coupons; `null` for `percentage`. */
  currency: string | null;
  scope: CouponScope;
  starts_at: string | null;
  ends_at: string | null;
  /** Max redemptions per user; `null` means unlimited. */
  per_user_limit: number | null;
  first_order_only: boolean;
  is_active: boolean;
  /** Server-computed count of settled redemptions. Read-only. */
  redeemed_count: number;
};

/**
 * The coupon an admin proposes on create/update. `value` follows the same encoding as
 * {@link Coupon.value}: integer percent for `percentage`, integer minor units for `fixed`.
 */
export type CouponInput = {
  code: string;
  type: CouponType;
  value: number;
  currency?: string | null;
  scope: CouponScope;
  starts_at?: string | null;
  ends_at?: string | null;
  per_user_limit?: number | null;
  first_order_only: boolean;
  is_active: boolean;
};

/** Page of every coupon in the system, newest first. Envelope: `{ data, meta, links }`. */
export const getCoupons = (page = 1) => api.get<Paginated<Coupon>>(`admin/coupons?page=${page}`);

/** Create a coupon. Returns the persisted read model (server fills `id` / `redeemed_count`). */
export const createCoupon = (input: CouponInput) =>
  api.data<Coupon>("admin/coupons", { method: "POST", body: input });

/** Update a coupon by public id. Only the provided fields are changed. */
export const updateCoupon = (id: string, input: Partial<CouponInput>) =>
  api.data<Coupon>(`admin/coupons/${id}`, { method: "PATCH", body: input });
