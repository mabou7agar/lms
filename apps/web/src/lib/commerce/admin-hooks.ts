"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  getAdminOrders,
  getAdminSubscriptions,
  getCreditNotes,
  issueRefund,
  type RefundInput,
} from "./admin";

/** Page of every order for the admin console. */
export const useAdminOrders = (page: number) =>
  useQuery({ queryKey: ["admin", "orders", page], queryFn: () => getAdminOrders(page) });

/** Page of the credit notes ledger for the admin console. */
export const useCreditNotes = (page: number) =>
  useQuery({ queryKey: ["admin", "credit-notes", page], queryFn: () => getCreditNotes(page) });

/** Page of every subscription for the admin console. */
export const useAdminSubscriptions = (page: number) =>
  useQuery({ queryKey: ["admin", "subscriptions", page], queryFn: () => getAdminSubscriptions(page) });

/**
 * Issue a refund against an order. On success it invalidates the orders and credit-notes lists —
 * a refund can mint a credit note server-side, so both surfaces must refetch.
 */
export function useIssueRefund() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ orderId, input }: { orderId: string; input: RefundInput }) => issueRefund(orderId, input),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["admin", "orders"] });
      qc.invalidateQueries({ queryKey: ["admin", "credit-notes"] });
    },
  });
}
