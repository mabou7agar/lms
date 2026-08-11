"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  acceptInvitation,
  analyzeImport,
  assignDepartmentManager,
  assignSeat,
  assignTeamManager,
  changeMemberRole,
  commitImport,
  createDepartment,
  createTeam,
  deactivateMember,
  declineInvitation,
  deleteDepartment,
  deleteTeam,
  getDepartments,
  getManagerReport,
  getMembers,
  getSeatHistory,
  getSeatSummary,
  getTeams,
  releaseSeat,
  removeMember,
  resizeSeats,
  updateDepartment,
  updateTeam,
  type MemberRole,
  type ReportScope,
} from "./manager-api";

/**
 * React-Query hooks for the enterprise manager portal. Mirrors `@/lib/org/hooks`: thin `useQuery`
 * wrappers with stable keys and `useMutation`s that invalidate the affected caches on success.
 */

const KEYS = {
  seats: ["enterprise", "seats"] as const,
  seatHistory: (page: number) => ["enterprise", "seats", "history", page] as const,
  report: (scope: ReportScope) => ["enterprise", "report", scope] as const,
  members: (page: number) => ["enterprise", "members", page] as const,
  departments: ["enterprise", "departments"] as const,
  teams: ["enterprise", "teams"] as const,
};

// ── Seats ──────────────────────────────────────────────────────────────────────

export const useSeatSummary = () => useQuery({ queryKey: KEYS.seats, queryFn: getSeatSummary });

export const useSeatHistory = (page: number) =>
  useQuery({ queryKey: KEYS.seatHistory(page), queryFn: () => getSeatHistory(page) });

function useSeatInvalidation() {
  const qc = useQueryClient();
  return () => {
    qc.invalidateQueries({ queryKey: KEYS.seats });
    qc.invalidateQueries({ queryKey: ["enterprise", "report"] });
  };
}

export function useAssignSeat() {
  const invalidate = useSeatInvalidation();
  return useMutation({ mutationFn: (memberId: string) => assignSeat(memberId), onSuccess: invalidate });
}

export function useReleaseSeat() {
  const invalidate = useSeatInvalidation();
  return useMutation({ mutationFn: (memberId: string) => releaseSeat(memberId), onSuccess: invalidate });
}

export function useResizeSeats() {
  const invalidate = useSeatInvalidation();
  return useMutation({ mutationFn: (seats: number) => resizeSeats(seats), onSuccess: invalidate });
}

// ── Report ─────────────────────────────────────────────────────────────────────

export const useManagerReport = (scope: ReportScope = {}) =>
  useQuery({ queryKey: KEYS.report(scope), queryFn: () => getManagerReport(scope) });

// ── Members ────────────────────────────────────────────────────────────────────

export const useMembers = (page: number) =>
  useQuery({ queryKey: KEYS.members(page), queryFn: () => getMembers(page) });

function useMemberInvalidation() {
  const qc = useQueryClient();
  return () => {
    qc.invalidateQueries({ queryKey: ["enterprise", "members"] });
    qc.invalidateQueries({ queryKey: KEYS.seats });
  };
}

export function useRemoveMember() {
  const invalidate = useMemberInvalidation();
  return useMutation({ mutationFn: (id: string) => removeMember(id), onSuccess: invalidate });
}

export function useChangeMemberRole() {
  const invalidate = useMemberInvalidation();
  return useMutation({
    mutationFn: ({ id, role }: { id: string; role: MemberRole }) => changeMemberRole(id, role),
    onSuccess: invalidate,
  });
}

export function useDeactivateMember() {
  const invalidate = useMemberInvalidation();
  return useMutation({ mutationFn: (id: string) => deactivateMember(id), onSuccess: invalidate });
}

// ── Departments ────────────────────────────────────────────────────────────────

export const useDepartments = () => useQuery({ queryKey: KEYS.departments, queryFn: () => getDepartments() });

function useDepartmentInvalidation() {
  const qc = useQueryClient();
  return () => qc.invalidateQueries({ queryKey: KEYS.departments });
}

export function useCreateDepartment() {
  const invalidate = useDepartmentInvalidation();
  return useMutation({ mutationFn: (name: string) => createDepartment(name), onSuccess: invalidate });
}

export function useUpdateDepartment() {
  const invalidate = useDepartmentInvalidation();
  return useMutation({
    mutationFn: ({ id, name }: { id: string; name: string }) => updateDepartment(id, name),
    onSuccess: invalidate,
  });
}

export function useDeleteDepartment() {
  const invalidate = useDepartmentInvalidation();
  return useMutation({ mutationFn: (id: string) => deleteDepartment(id), onSuccess: invalidate });
}

export function useAssignDepartmentManager() {
  const invalidate = useDepartmentInvalidation();
  return useMutation({
    mutationFn: ({ id, memberId }: { id: string; memberId: string | null }) => assignDepartmentManager(id, memberId),
    onSuccess: invalidate,
  });
}

// ── Teams ──────────────────────────────────────────────────────────────────────

export const useTeams = () => useQuery({ queryKey: KEYS.teams, queryFn: () => getTeams() });

function useTeamInvalidation() {
  const qc = useQueryClient();
  return () => qc.invalidateQueries({ queryKey: KEYS.teams });
}

export function useCreateTeam() {
  const invalidate = useTeamInvalidation();
  return useMutation({
    mutationFn: (body: { name: string; department_id?: string | null }) => createTeam(body),
    onSuccess: invalidate,
  });
}

export function useUpdateTeam() {
  const invalidate = useTeamInvalidation();
  return useMutation({
    mutationFn: ({ id, body }: { id: string; body: { name: string; department_id?: string | null } }) =>
      updateTeam(id, body),
    onSuccess: invalidate,
  });
}

export function useDeleteTeam() {
  const invalidate = useTeamInvalidation();
  return useMutation({ mutationFn: (id: string) => deleteTeam(id), onSuccess: invalidate });
}

export function useAssignTeamManager() {
  const invalidate = useTeamInvalidation();
  return useMutation({
    mutationFn: ({ id, memberId }: { id: string; memberId: string | null }) => assignTeamManager(id, memberId),
    onSuccess: invalidate,
  });
}

// ── CSV import ─────────────────────────────────────────────────────────────────

export function useAnalyzeImport() {
  return useMutation({ mutationFn: (file: File) => analyzeImport(file) });
}

export function useCommitImport() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ file, invite }: { file: File; invite: boolean }) => commitImport(file, invite),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["enterprise", "members"] });
      qc.invalidateQueries({ queryKey: KEYS.seats });
    },
  });
}

// ── Invitations ────────────────────────────────────────────────────────────────

export function useAcceptInvitation() {
  return useMutation({ mutationFn: (token: string) => acceptInvitation(token) });
}

export function useDeclineInvitation() {
  return useMutation({ mutationFn: (token: string) => declineInvitation(token) });
}
