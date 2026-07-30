"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  cancelSubscription,
  changePlan,
  getMySubscriptions,
  getPlans,
  reactivateSubscription,
  subscribe,
} from "./subscriptions";

/** Public catalogue of active subscription plans. */
export const usePlans = () => useQuery({ queryKey: ["subscription-plans"], queryFn: getPlans });

/** Page of the current user's subscriptions. */
export const useMySubscriptions = (page: number) =>
  useQuery({ queryKey: ["subscriptions", page], queryFn: () => getMySubscriptions(page) });

/** Invalidate every subscription list/page after a mutation so the current view refetches. */
function invalidateSubscriptions(qc: ReturnType<typeof useQueryClient>) {
  return qc.invalidateQueries({ queryKey: ["subscriptions"] });
}

export function useSubscribe() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (planId: string) => subscribe(planId),
    onSuccess: () => invalidateSubscriptions(qc),
  });
}

export function useCancelSubscription() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => cancelSubscription(id),
    onSuccess: () => invalidateSubscriptions(qc),
  });
}

export function useReactivateSubscription() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => reactivateSubscription(id),
    onSuccess: () => invalidateSubscriptions(qc),
  });
}

export function useChangePlan() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, planId }: { id: string; planId: string }) => changePlan(id, planId),
    onSuccess: () => invalidateSubscriptions(qc),
  });
}
