"use client";

import { useQuery } from "@tanstack/react-query";
import { getInvoice, getInvoices } from "./billing";

/** Page of the current user's invoices for the billing portal list. */
export const useInvoices = (page: number) =>
  useQuery({ queryKey: ["invoices", page], queryFn: () => getInvoices(page) });

/** A single invoice for the billing portal detail view; skipped until an id is known. */
export const useInvoice = (id: string) =>
  useQuery({ queryKey: ["invoice", id], queryFn: () => getInvoice(id), enabled: id.length > 0 });
