/**
 * Assignments — typed API client (W04, HELBARON LMS).
 *
 * Wraps the frozen Assessment/assignment endpoints reached through the same-origin BFF proxy
 * (`@/lib/api/client`). Mirrors `lib/authoring/versioning-api.ts`: every call hits the real backend
 * and surfaces `ApiRequestError` on failure. This module + `assignments-hooks.ts` are the SHARED
 * data layer that the submission/grading UI (D4) imports — the exported TYPES here and the hook
 * surface in `assignments-hooks.ts` form the contract those callers depend on.
 *
 * Endpoint paths, payload keys and resource fields are matched VERBATIM against:
 *   app/Domains/Assessment/routes/assignments.php
 *   app/Domains/Assessment/Http/Resources/**  (AssignmentResource, RubricResource, …)
 *   app/Domains/Assessment/Http/Requests/**   (SaveAssignmentRequest, BuildRubricRequest, …)
 *   app/Domains/Assessment/Enums/**           (SubmissionType, LatePolicy, SubmissionStatus, …)
 *
 * Instructor/authoring + grading routes live under `v1/admin`; learner routes under `v1`.
 * Single-resource endpoints use the `{ data }` success envelope (unwrapped via `api.data`);
 * index/gradebook endpoints use the paginated `{ data, meta, links }` envelope (via `api.get`).
 */
import { api } from "@/lib/api/client";
import type { Paginated } from "@/types/api";

// ─────────────────────────────────────────────────────────────────────────────
// Enums (verbatim from app/Domains/Assessment/Enums)
// ─────────────────────────────────────────────────────────────────────────────

/** SubmissionType.php — what a learner hands in. */
export type SubmissionType = "text" | "file" | "text_and_file" | "external_url";
export const SUBMISSION_TYPES: readonly SubmissionType[] = [
  "text",
  "file",
  "text_and_file",
  "external_url",
] as const;

/** LatePolicy.php — how a post-due submission is handled. */
export type LatePolicy = "blocked" | "allowed" | "penalised";
export const LATE_POLICIES: readonly LatePolicy[] = ["blocked", "allowed", "penalised"] as const;

/** SubmissionStatus.php — lifecycle of one submission attempt. */
export type SubmissionStatus =
  | "draft"
  | "submitted"
  | "late"
  | "under_review"
  | "changes_requested"
  | "graded"
  | "returned"
  | "cancelled";

/** AssignmentState.php — publish lifecycle of an assignment. */
export type AssignmentPublishState = "draft" | "published" | "unpublished";

// ─────────────────────────────────────────────────────────────────────────────
// Read models (mirror the Resources exactly)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Rich-text instructions. Stored server-side as a nullable JSON array (`instructions` is validated
 * as `array` in SaveAssignmentRequest), so the client treats it opaquely. `assignments-format`
 * offers plain-text <-> doc helpers for the textarea-based editor.
 */
export type InstructionsDoc = unknown[];

/** RubricResource → one level within a criterion. */
export interface RubricLevel {
  id: string;
  title: string;
  description: string | null;
  points: number;
  position: number;
}

/** RubricResource → one criterion (its `max_points` is the max of its levels, computed server-side). */
export interface RubricCriterion {
  id: string;
  title: string;
  description: string | null;
  position: number;
  max_points: number;
  levels: RubricLevel[];
}

/** RubricResource — the grading standard (safe for instructor AND learner views). */
export interface Rubric {
  id: string;
  title: string | null;
  total_points: number;
  criteria: RubricCriterion[];
}

/** AssignmentResource → nested `settings` block. */
export interface AssignmentSettings {
  allowed_file_types: string[] | null;
  max_file_size: number | null;
  max_files: number | null;
  attempt_limit: number | null;
  due_at: string | null;
  late_policy: LatePolicy;
  late_penalty_percent: number | null;
  max_grade: number;
  passing_grade: number | null;
}

/** AssignmentResource — INSTRUCTOR view (authoring settings + rubric). */
export interface Assignment {
  id: string;
  title: string;
  lesson_id: number | null;
  instructions: InstructionsDoc | null;
  submission_type: SubmissionType;
  publish_state: AssignmentPublishState;
  required_for_completion: boolean;
  settings: AssignmentSettings;
  rubric: Rubric | null;
}

/** LearnerAssignmentResource — LEARNER view (flat settings, no authoring-only knobs). */
export interface LearnerAssignment {
  id: string;
  title: string;
  instructions: InstructionsDoc | null;
  submission_type: SubmissionType;
  allowed_file_types: string[] | null;
  max_file_size: number | null;
  max_files: number | null;
  attempt_limit: number | null;
  due_at: string | null;
  late_policy: LatePolicy;
  max_grade: number;
  passing_grade: number | null;
  rubric: Rubric | null;
}

/** A file reference on a submission (SubmissionResource/LearnerSubmissionResource `files[]`). */
export interface SubmissionFileRef {
  id: string;
  /** Public media id — the client requests a signed URL from Media with it. */
  media_id: string;
  filename: string;
}

/** One `{ criterion, level }` selection carried in a grade's `rubric_result`. */
export interface RubricResultEntry {
  criterion_public_id: string;
  level_public_id: string;
  points?: number;
}

/** Immutable rubric snapshot captured on the submission (opaque to the client). */
export type RubricSnapshot = Record<string, unknown>;

/** SubmissionResource → grade block (grader view; carries private_notes + unreleased score). */
export interface Grade {
  score: number | null;
  passed: boolean | null;
  feedback: string | null;
  private_notes: string | null;
  rubric_result: RubricResultEntry[] | null;
  version: number;
  released_at: string | null;
}

/** LearnerSubmissionResource → grade block (only present once released; no private_notes/version). */
export interface LearnerGrade {
  score: number | null;
  passed: boolean | null;
  feedback: string | null;
  rubric_result: RubricResultEntry[] | null;
  released_at: string | null;
}

/** SubmissionResource — INSTRUCTOR/grader view of a submission. */
export interface Submission {
  id: string;
  assignment_id: string | null;
  learner_id: number;
  attempt_no: number;
  status: SubmissionStatus;
  submitted_at: string | null;
  is_late: boolean;
  text_response: string | null;
  external_url: string | null;
  files: SubmissionFileRef[];
  rubric_snapshot: RubricSnapshot | null;
  grade: Grade | null;
}

/** LearnerSubmissionResource — LEARNER view of their own submission. */
export interface LearnerSubmission {
  id: string;
  attempt_no: number;
  status: SubmissionStatus;
  submitted_at: string | null;
  is_late: boolean;
  text_response: string | null;
  external_url: string | null;
  files: SubmissionFileRef[];
  rubric_snapshot: RubricSnapshot | null;
  grade: LearnerGrade | null;
}

/** SubmissionListResource — compact grading-queue row. */
export interface SubmissionListRow {
  id: string;
  learner_id: number;
  attempt_no: number;
  status: SubmissionStatus;
  submitted_at: string | null;
  is_late: boolean;
  has_grade: boolean;
  released: boolean;
  score: number | null;
}

// ─────────────────────────────────────────────────────────────────────────────
// Write models (mirror the Requests exactly — payloads are FLAT, not nested)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * SaveAssignmentRequest — shared create/update payload. All fields optional here; `title` and
 * `submission_type` are required by the server ONLY on create (POST). The wire payload is flat:
 * `settings.*` in the resource map back onto top-level keys here.
 */
export interface AssignmentInput {
  title?: string;
  lesson_id?: number | null;
  instructions?: InstructionsDoc | null;
  submission_type?: SubmissionType;
  allowed_file_types?: string[] | null;
  max_file_size?: number | null;
  max_files?: number;
  attempt_limit?: number | null;
  due_at?: string | null;
  late_policy?: LatePolicy;
  late_penalty_percent?: number | null;
  max_grade?: number;
  passing_grade?: number | null;
  required_for_completion?: boolean;
}

/** Create requires title + submission_type (SaveAssignmentRequest, POST branch). */
export interface CreateAssignmentInput extends AssignmentInput {
  title: string;
  submission_type: SubmissionType;
}

/** BuildRubricRequest — a rubric build REPLACES the assignment's rubric wholesale. */
export interface RubricLevelInput {
  title: string;
  description?: string | null;
  points: number;
}
export interface RubricCriterionInput {
  title: string;
  description?: string | null;
  levels: RubricLevelInput[];
}
export interface RubricInput {
  title?: string | null;
  criteria: RubricCriterionInput[];
}

/** SaveDraftRequest — learner draft content (files attach via their own endpoint). */
export interface SaveDraftInput {
  text_response?: string | null;
  external_url?: string | null;
}

/** AttachFileRequest — reference an already-uploaded media asset by its public id. */
export interface AttachFileInput {
  media_id: string;
}

/** GradeSubmissionRequest — numeric score OR rubric selection; optimistic concurrency via version. */
export interface GradeInput {
  score?: number | null;
  rubric_result?: RubricResultEntry[] | null;
  feedback?: string | null;
  private_notes?: string | null;
  expected_version?: number | null;
}

/** RequestChangesRequest — optional revision note. */
export interface RequestChangesInput {
  note?: string | null;
}

// ─────────────────────────────────────────────────────────────────────────────
// Instructor / authoring API (v1/admin)
// ─────────────────────────────────────────────────────────────────────────────

/** GET v1/admin/courses/{course}/assignments — paginated, newest first. */
export function listAssignments(course: string, page = 1, perPage = 25): Promise<Paginated<Assignment>> {
  const query = `page=${encodeURIComponent(page)}&per_page=${encodeURIComponent(perPage)}`;
  return api.get<Paginated<Assignment>>(`v1/admin/courses/${course}/assignments?${query}`);
}

/** GET v1/admin/assignments/{assignment}. */
export function getAssignment(assignment: string): Promise<Assignment> {
  return api.data<Assignment>(`v1/admin/assignments/${assignment}`);
}

/** POST v1/admin/courses/{course}/assignments. */
export function createAssignment(course: string, input: CreateAssignmentInput): Promise<Assignment> {
  return api.data<Assignment>(`v1/admin/courses/${course}/assignments`, { method: "POST", body: input });
}

/** PUT v1/admin/assignments/{assignment}. */
export function updateAssignment(assignment: string, input: AssignmentInput): Promise<Assignment> {
  return api.data<Assignment>(`v1/admin/assignments/${assignment}`, { method: "PUT", body: input });
}

/** DELETE v1/admin/assignments/{assignment}. */
export function deleteAssignment(assignment: string): Promise<void> {
  return api.del<void>(`v1/admin/assignments/${assignment}`);
}

/** POST v1/admin/assignments/{assignment}/publish. */
export function publishAssignment(assignment: string): Promise<Assignment> {
  return api.data<Assignment>(`v1/admin/assignments/${assignment}/publish`, { method: "POST" });
}

/** POST v1/admin/assignments/{assignment}/unpublish. */
export function unpublishAssignment(assignment: string): Promise<Assignment> {
  return api.data<Assignment>(`v1/admin/assignments/${assignment}/unpublish`, { method: "POST" });
}

/** PUT v1/admin/assignments/{assignment}/rubric — replaces the rubric wholesale, returns it. */
export function buildRubric(assignment: string, input: RubricInput): Promise<Rubric> {
  return api.data<Rubric>(`v1/admin/assignments/${assignment}/rubric`, { method: "PUT", body: input });
}

// ─────────────────────────────────────────────────────────────────────────────
// Instructor / grading API (v1/admin) — imported by D4
// ─────────────────────────────────────────────────────────────────────────────

/** GET v1/admin/assignments/{assignment}/submissions — the grading queue. */
export function listSubmissions(
  assignment: string,
  page = 1,
  perPage = 25,
): Promise<Paginated<SubmissionListRow>> {
  const query = `page=${encodeURIComponent(page)}&per_page=${encodeURIComponent(perPage)}`;
  return api.get<Paginated<SubmissionListRow>>(`v1/admin/assignments/${assignment}/submissions?${query}`);
}

/** GET v1/admin/submissions/{submission} — full grader view (private notes + unreleased score). */
export function getSubmission(submission: string): Promise<Submission> {
  return api.data<Submission>(`v1/admin/submissions/${submission}`);
}

/** POST v1/admin/submissions/{submission}/grade. */
export function gradeSubmission(submission: string, input: GradeInput): Promise<Submission> {
  return api.data<Submission>(`v1/admin/submissions/${submission}/grade`, { method: "POST", body: input });
}

/** POST v1/admin/submissions/{submission}/request-changes. */
export function requestChanges(submission: string, input: RequestChangesInput = {}): Promise<Submission> {
  return api.data<Submission>(`v1/admin/submissions/${submission}/request-changes`, {
    method: "POST",
    body: input,
  });
}

/** POST v1/admin/submissions/{submission}/release. */
export function releaseGrade(submission: string): Promise<Submission> {
  return api.data<Submission>(`v1/admin/submissions/${submission}/release`, { method: "POST" });
}

/** POST v1/admin/submissions/{submission}/unrelease. */
export function unreleaseGrade(submission: string): Promise<Submission> {
  return api.data<Submission>(`v1/admin/submissions/${submission}/unrelease`, { method: "POST" });
}

// ── Gradebook (D3): REMOVED — the canonical gradebook data layer is `@/lib/gradebook` (D5), which
// matches the backend `user_id` field. The former duplicate here drifted to `learner_id`. ──

// ─────────────────────────────────────────────────────────────────────────────
// Learner submission API (v1) — imported by D4
// ─────────────────────────────────────────────────────────────────────────────

/** GET v1/assignments/{assignment} — LEARNER view. */
export function getLearnerAssignment(assignment: string): Promise<LearnerAssignment> {
  return api.data<LearnerAssignment>(`v1/assignments/${assignment}`);
}

/** GET v1/assignments/{assignment}/submissions — the learner's own attempt history. */
export function getSubmissionHistory(assignment: string): Promise<LearnerSubmission[]> {
  return api.data<LearnerSubmission[]>(`v1/assignments/${assignment}/submissions`);
}

/** GET v1/submissions/{submission} — LEARNER view of one submission. */
export function getLearnerSubmission(submission: string): Promise<LearnerSubmission> {
  return api.data<LearnerSubmission>(`v1/submissions/${submission}`);
}

/** POST v1/assignments/{assignment}/draft — create/update the learner's draft. */
export function saveDraft(assignment: string, input: SaveDraftInput): Promise<LearnerSubmission> {
  return api.data<LearnerSubmission>(`v1/assignments/${assignment}/draft`, { method: "POST", body: input });
}

/** POST v1/assignments/{assignment}/draft/files — attach an uploaded media asset to the draft. */
export function attachFile(assignment: string, input: AttachFileInput): Promise<LearnerSubmission> {
  return api.data<LearnerSubmission>(`v1/assignments/${assignment}/draft/files`, {
    method: "POST",
    body: input,
  });
}

/** DELETE v1/submissions/{submission}/files/{file} — detach a file from the draft. */
export function detachFile(submission: string, file: string): Promise<LearnerSubmission> {
  return api.data<LearnerSubmission>(`v1/submissions/${submission}/files/${file}`, { method: "DELETE" });
}

/** POST v1/assignments/{assignment}/submit — hand in the current draft. */
export function submitAssignment(assignment: string): Promise<LearnerSubmission> {
  return api.data<LearnerSubmission>(`v1/assignments/${assignment}/submit`, { method: "POST" });
}

/** POST v1/assignments/{assignment}/resubmit — open a fresh attempt after changes requested/returned. */
export function resubmitAssignment(assignment: string): Promise<LearnerSubmission> {
  return api.data<LearnerSubmission>(`v1/assignments/${assignment}/resubmit`, { method: "POST" });
}
