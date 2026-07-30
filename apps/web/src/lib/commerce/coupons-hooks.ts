"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { createCoupon, getCoupons, updateCoupon, type CouponInput } from "./coupons";

/** Page of every coupon for the admin console. */
export const useCoupons = (page: number) =>
  useQuery({ queryKey: ["admin", "coupons", page], queryFn: () => getCoupons(page) });

/** Create a coupon, then refetch every coupons page so the list reflects the new row. */
export function useCreateCoupon() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: CouponInput) => createCoupon(input),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["admin", "coupons"] }),
  });
}

/** Update a coupon, then refetch every coupons page so the edited row reflects the change. */
export function useUpdateCoupon() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, input }: { id: string; input: Partial<CouponInput> }) => updateCoupon(id, input),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["admin", "coupons"] }),
  });
}
