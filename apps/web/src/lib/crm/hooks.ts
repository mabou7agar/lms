"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { convertLead, createLead, getLeads, type CreateLeadInput, type LeadFilters } from "./api";

export const useLeads = (filters: LeadFilters) =>
  useQuery({ queryKey: ["leads", filters], queryFn: () => getLeads(filters) });

export function useCreateLead() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: CreateLeadInput) => createLead(body),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["leads"] }),
  });
}

export function useConvertLead() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (publicId: string) => convertLead(publicId),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["leads"] }),
  });
}
