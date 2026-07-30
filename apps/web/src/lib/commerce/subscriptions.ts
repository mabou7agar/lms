import { api } from "@/lib/api/client";
import type { ApiSuccess, Paginated } from "@/types/api";

/**
 * Lifecycle state of a subscription, mirroring the backend SubscriptionStatus enum
 * (trialing → active → past_due → grace → expired, plus canceled / paused). Kept as a
 * string-literal union so status labels and badge variants stay exhaustive at compile time.
 */
export type SubscriptionStatus =
  | "trialing"
  | "active"
  | "past_due"
  | "grace"
  | "expired"
  | "canceled"
  | "paused";

/** A per-currency price for a plan. Money is integer minor units only. */
export type PlanPrice = {
  currency: string;
  amount_minor: number;
  is_default: boolean;
};

/**
 * Catalogue read model for a subscription plan. `interval` is the billing cadence
 * (e.g. "monthly" / "yearly"); `prices` carries one row per published currency.
 */
export type Plan = {
  id: string;
  name: string;
  interval: string;
  trial_days: number;
  is_active: boolean;
  prices: PlanPrice[];
};

/** The plan summary embedded on a subscription (present when the relation is loaded). */
export type SubscriptionPlanRef = {
  id: string;
  name: string;
  interval: string;
};

/**
 * Learner-facing read model for one subscription: its status, recurring price (integer minor
 * units), the period / trial / grace / cancellation clocks (ISO-8601 strings), and the computed
 * `is_active_now` flag. `plan` is present when the backend eager-loads the relation.
 */
export type Subscription = {
  id: string;
  status: SubscriptionStatus;
  currency: string;
  amount_minor: number;
  current_period_start: string | null;
  current_period_end: string | null;
  trial_ends_at: string | null;
  grace_ends_at: string | null;
  canceled_at: string | null;
  cancel_at_period_end: boolean;
  is_active_now: boolean;
  plan?: SubscriptionPlanRef | null;
};

/** Catalogue of active plans with their per-currency prices. */
export const getPlans = () => api.data<Plan[]>("subscription-plans");

/** Page of the authenticated user's subscriptions, newest first. Envelope: `{ data, meta, links }`. */
export const getMySubscriptions = (page = 1) => api.get<Paginated<Subscription>>(`subscriptions?page=${page}`);

/** Start a new subscription on the given plan (by public id). */
export const subscribe = (planId: string) =>
  api.post<ApiSuccess<Subscription>>("subscriptions", { plan: planId });

/** Cancel a subscription. Defaults to cancel-at-period-end (soft cancellation). */
export const cancelSubscription = (id: string) =>
  api.post<ApiSuccess<Subscription>>(`subscriptions/${id}/cancel`, { at_period_end: true });

/** Revive a soft-canceled subscription whose period is still open. */
export const reactivateSubscription = (id: string) =>
  api.post<ApiSuccess<Subscription>>(`subscriptions/${id}/reactivate`);

/** Move an existing subscription to a different plan (upgrade / downgrade). */
export const changePlan = (id: string, planId: string) =>
  api.post<ApiSuccess<Subscription>>(`subscriptions/${id}/change`, { plan: planId });
