import { api, apiFetch, ApiRequestError } from "@/lib/api/client";
import type { ApiError, ApiSuccess, Paginated } from "@/types/api";

/**
 * Enterprise MANAGER-PORTAL data layer. App-side (authenticated, role-gated to org manager/admin).
 * Every path resolves to the backend `/api/v1/enterprise/*` routes: the browser hits the same-origin
 * BFF proxy at `/api/backend/<path>`, which is rooted at `/api/v1`, so the client path is
 * `enterprise/*` (NOT `v1/enterprise/*`). Kept separate from the marketing enterprise-lead form.
 */

export type MemberRole = "owner" | "admin" | "manager" | "member";
export type MemberStatus = "invited" | "active" | "inactive" | "removed";

/** Org subscription seat snapshot (OrganizationSeatSummary). `null` = no active subscription. */
export type SeatSummary = {
  subscription_id: string;
  status: string;
  seats: { purchased: number; used: number; available: number };
};

/** Manager learning report (ManagerReport::toArray). `seats` is null with no active subscription. */
export type ManagerReport = {
  organization_id: number;
  learners: number;
  enrollments: number;
  started: number;
  completions: number;
  avg_progress: number;
  watch_time_seconds: number;
  avg_watch_time_seconds_per_learner: number;
  inactive_learners: number;
  assessments_passed: number;
  assessments_failed: number;
  certificates_issued: number;
  seats: { purchased: number; used: number; available: number } | null;
};

/** OrganizationMemberResource. */
export type EnterpriseMember = {
  id: string;
  email: string;
  role: MemberRole;
  status: MemberStatus;
  invited_at: string | null;
};

/** DepartmentResource. `manager_id` is the assigned manager's user id (int) or null. */
export type Department = {
  id: string;
  name: string;
  manager_id: number | null;
  members_count?: number;
  created_at: string | null;
};

/** TeamResource. */
export type Team = {
  id: string;
  name: string;
  department_id: number | null;
  manager_id: number | null;
  created_at: string | null;
};

export type CourseAssignmentTargetType = "organization" | "member" | "department" | "team";

export type CourseAssignmentResult = {
  course: { id: string; title: string };
  target: { type: CourseAssignmentTargetType; id: string | null };
  summary: {
    matched_members: number;
    eligible_members: number;
    assigned: number;
    already_assigned: number;
    skipped_without_account: number;
  };
};

/** SeatAssignmentResource. */
export type SeatAssignment = {
  id: string;
  member_id: number;
  assigned_at: string | null;
  revoked_at: string | null;
  active: boolean;
};

/** Report scope + range filters. A department/team filter is honoured only within the caller's scope. */
export type ReportScope = {
  department_id?: string;
  team_id?: string;
  inactive_days?: number;
  from?: string;
  to?: string;
};

export type ImportRowStatus = "valid" | "error" | "duplicate";

/** One row of the EmployeeCsvImporter dry-run report. */
export type ImportRow = {
  line: number;
  email: string;
  name: string;
  role: string;
  department_id: number | null;
  status: ImportRowStatus;
  errors: string[];
};

export type ImportSummary = { total: number; valid: number; errors: number; duplicates: number };

/** DRY-RUN validation report (writes nothing). */
export type ImportDryRun = { summary: ImportSummary; rows: ImportRow[] };

/** Commit result. */
export type ImportCommit = {
  summary: ImportSummary;
  created: number;
  invited: number;
  skipped: number;
  errors: Array<{ line: number; errors: string[] }>;
};

/** Machine-readable error code the backend returns when a resize would drop below assigned seats. */
export const SEAT_DOWNGRADE_CODE = "CRM_SEATS_DOWNGRADE_BELOW_ASSIGNED";

// ── Seats ────────────────────────────────────────────────────────────────────────────────────────

/** GET /enterprise/seats — purchased/used/available (or null with no active subscription). */
export const getSeatSummary = () => api.data<SeatSummary | null>("enterprise/seats");

/** GET /enterprise/seats/history — paginated assignment history. */
export const getSeatHistory = (page = 1, perPage = 25) =>
  api.get<Paginated<SeatAssignment>>(`enterprise/seats/history?page=${page}&per_page=${perPage}`);

/** POST /enterprise/seats/assign — assign a seat to a member (by public id). */
export const assignSeat = (memberId: string) =>
  api.post<ApiSuccess<SeatSummary | null>>("enterprise/seats/assign", { member_id: memberId });

/** POST /enterprise/seats/release — release a member's seat (idempotent). */
export const releaseSeat = (memberId: string) =>
  api.post<ApiSuccess<SeatSummary | null>>("enterprise/seats/release", { member_id: memberId });

/** POST /enterprise/seats/resize — set the purchased seat count (downgrade-below-assigned rejected). */
export const resizeSeats = (seats: number) =>
  api.post<ApiSuccess<SeatSummary | null>>("enterprise/seats/resize", { seats });

// ── Manager report ───────────────────────────────────────────────────────────────────────────────

function reportQuery(scope: ReportScope): string {
  const params = new URLSearchParams();
  if (scope.department_id) params.set("department_id", scope.department_id);
  if (scope.team_id) params.set("team_id", scope.team_id);
  if (typeof scope.inactive_days === "number") params.set("inactive_days", String(scope.inactive_days));
  if (scope.from) params.set("from", scope.from);
  if (scope.to) params.set("to", scope.to);
  const qs = params.toString();
  return qs ? `?${qs}` : "";
}

/** GET /enterprise/report — the manager learning report for the given scope. */
export const getManagerReport = (scope: ReportScope = {}) =>
  api.data<ManagerReport>(`enterprise/report${reportQuery(scope)}`);

/** Same-origin CSV export URL for the current scope (opened/downloaded by the browser). */
export const reportExportUrl = (scope: ReportScope = {}) =>
  `/api/backend/enterprise/report/export${reportQuery(scope)}`;

// ── Members ──────────────────────────────────────────────────────────────────────────────────────

/** GET /enterprise/members — paginated, scoped to the caller's authority. */
export const getMembers = (page = 1, perPage = 25) =>
  api.get<Paginated<EnterpriseMember>>(`enterprise/members?page=${page}&per_page=${perPage}`);

/** DELETE /enterprise/members/{member} — remove a member (releases their seats server-side). */
export const removeMember = (id: string) => api.del<ApiSuccess<null>>(`enterprise/members/${id}`);

/** PATCH /enterprise/members/{member}/role — change a member's role. */
export const changeMemberRole = (id: string, role: MemberRole) =>
  apiFetch<ApiSuccess<EnterpriseMember>>(`enterprise/members/${id}/role`, { method: "PATCH", body: { role } });

/** POST /enterprise/members/{member}/deactivate — deactivate a member (releases their seats). */
export const deactivateMember = (id: string) =>
  api.post<ApiSuccess<EnterpriseMember>>(`enterprise/members/${id}/deactivate`);

// ── Departments ──────────────────────────────────────────────────────────────────────────────────

/** GET /enterprise/departments — paginated (members_count included). */
export const getDepartments = (page = 1, perPage = 100) =>
  api.get<Paginated<Department>>(`enterprise/departments?page=${page}&per_page=${perPage}`);

/** POST /enterprise/departments — create. */
export const createDepartment = (name: string) =>
  api.post<ApiSuccess<Department>>("enterprise/departments", { name });

/** PATCH /enterprise/departments/{department} — rename. */
export const updateDepartment = (id: string, name: string) =>
  apiFetch<ApiSuccess<Department>>(`enterprise/departments/${id}`, { method: "PATCH", body: { name } });

/** DELETE /enterprise/departments/{department}. */
export const deleteDepartment = (id: string) => api.del<ApiSuccess<null>>(`enterprise/departments/${id}`);

/** POST /enterprise/departments/{department}/manager — assign (or clear with null) a manager. */
export const assignDepartmentManager = (id: string, memberId: string | null) =>
  api.post<ApiSuccess<Department>>(`enterprise/departments/${id}/manager`, { member_id: memberId });

// ── Teams ────────────────────────────────────────────────────────────────────────────────────────

/** GET /enterprise/teams — paginated. */
export const getTeams = (page = 1, perPage = 100) =>
  api.get<Paginated<Team>>(`enterprise/teams?page=${page}&per_page=${perPage}`);

/** POST /enterprise/teams — create (optionally in a department, by public id). */
export const createTeam = (body: { name: string; department_id?: string | null }) =>
  api.post<ApiSuccess<Team>>("enterprise/teams", body);

/** PATCH /enterprise/teams/{team} — update. */
export const updateTeam = (id: string, body: { name: string; department_id?: string | null }) =>
  apiFetch<ApiSuccess<Team>>(`enterprise/teams/${id}`, { method: "PATCH", body });

/** DELETE /enterprise/teams/{team}. */
export const deleteTeam = (id: string) => api.del<ApiSuccess<null>>(`enterprise/teams/${id}`);

/** POST /enterprise/teams/{team}/manager — assign (or clear) a manager. */
export const assignTeamManager = (id: string, memberId: string | null) =>
  api.post<ApiSuccess<Team>>(`enterprise/teams/${id}/manager`, { member_id: memberId });

export const assignCourse = (body: {
  course_id: string;
  target_type: CourseAssignmentTargetType;
  target_id?: string | null;
}) => api.data<CourseAssignmentResult>("enterprise/course-assignments", { method: "POST", body });

// ── Invitations (token-authorized) ────────────────────────────────────────────────────────────────

/** POST /enterprise/invitations/{token}/accept — link the caller's account to the membership. */
export const acceptInvitation = (token: string) =>
  api.post<ApiSuccess<EnterpriseMember>>(`enterprise/invitations/${token}/accept`);

/** POST /enterprise/invitations/{token}/decline. */
export const declineInvitation = (token: string) =>
  api.post<ApiSuccess<null>>(`enterprise/invitations/${token}/decline`);

// ── Employee CSV import (multipart) ────────────────────────────────────────────────────────────────

/**
 * Multipart POST through the BFF proxy. The shared JSON client serializes bodies as JSON, so file
 * uploads use a dedicated poster that lets the browser set the multipart boundary. Client-only (the
 * import UI runs in the browser); the standard error envelope is still surfaced as ApiRequestError.
 */
async function postForm<T>(path: string, form: FormData): Promise<T> {
  const res = await fetch(`/api/backend/${path.replace(/^\//, "")}`, {
    method: "POST",
    credentials: "same-origin",
    headers: { Accept: "application/json" },
    body: form,
  });

  const json = res.status === 204 ? null : await res.json().catch(() => null);

  if (!res.ok) {
    const err = (json as ApiError | null)?.error;
    throw new ApiRequestError(
      res.status,
      err?.code ?? "HTTP_ERROR",
      err?.message ?? res.statusText,
      err?.details,
      err?.correlation_id,
      json,
    );
  }

  return json as T;
}

/** POST /enterprise/employees/import (no commit) — DRY-RUN validation report. */
export const analyzeImport = async (file: File): Promise<ImportDryRun> => {
  const form = new FormData();
  form.append("file", file);
  return (await postForm<ApiSuccess<ImportDryRun>>("enterprise/employees/import", form)).data;
};

/** POST /enterprise/employees/import (commit=true) — write members (optionally invite). */
export const commitImport = async (file: File, invite: boolean): Promise<ImportCommit> => {
  const form = new FormData();
  form.append("file", file);
  form.append("commit", "true");
  form.append("invite", invite ? "true" : "false");
  return (await postForm<ApiSuccess<ImportCommit>>("enterprise/employees/import", form)).data;
};
