/**
 * Course Builder — content versioning API client (P2/W03).
 *
 * Wraps the Authoring versioning admin endpoints (`/api/v1/admin/...`) reached through the same
 * same-origin BFF proxy as the rest of the app (`@/lib/api/client`). No mock endpoints: every call
 * hits the real backend and surfaces `ApiRequestError` on failure.
 */
import { api } from "@/lib/api/client";

export type VersionReason = "manual" | "safety" | "rollback" | "clone" | "fork";

export interface VersionSummary {
  modules: number;
  sections: number;
  lessons: number;
  blocks: number;
}

export interface VersionSource {
  id: string;
  version_number: number;
  from_other_course: boolean;
}

export interface ContentVersion {
  id: string;
  version_number: number;
  label: string | null;
  reason: VersionReason;
  checksum: string;
  schema_version: number;
  created_by: number | null;
  created_at: string | null;
  source: VersionSource | null;
  summary: VersionSummary;
}

export interface PageMeta {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
  from: number | null;
  to: number | null;
}

export interface VersionHistoryPage {
  data: ContentVersion[];
  meta: PageMeta;
}

export interface RestoreResult {
  restored: ContentVersion;
  safety_snapshot: ContentVersion;
}

export interface CreateSnapshotInput {
  label?: string | null;
  force?: boolean;
}

export interface ForkInput {
  destination_course_id: string;
  label?: string | null;
}

/** Paginated version history, newest first. `course` is the course public id. */
export function listVersions(course: string, page = 1, perPage = 20): Promise<VersionHistoryPage> {
  const query = `page=${encodeURIComponent(page)}&per_page=${encodeURIComponent(perPage)}`;
  return api.get<VersionHistoryPage>(`admin/courses/${course}/versions?${query}`);
}

export function getVersion(version: string): Promise<ContentVersion> {
  return api.data<ContentVersion>(`admin/versions/${version}`);
}

export function createSnapshot(course: string, input: CreateSnapshotInput = {}): Promise<ContentVersion> {
  return api.data<ContentVersion>(`admin/courses/${course}/versions`, {
    method: "POST",
    body: { label: input.label ?? null, force: input.force ?? false },
  });
}

export function restoreVersion(version: string): Promise<RestoreResult> {
  return api.data<RestoreResult>(`admin/versions/${version}/restore`, { method: "POST" });
}

export function rollbackVersion(version: string): Promise<ContentVersion> {
  return api.data<ContentVersion>(`admin/versions/${version}/rollback`, { method: "POST" });
}

export function cloneVersion(version: string, label?: string | null): Promise<ContentVersion> {
  return api.data<ContentVersion>(`admin/versions/${version}/clone`, {
    method: "POST",
    body: { label: label ?? null },
  });
}

export function forkVersion(version: string, input: ForkInput): Promise<ContentVersion> {
  return api.data<ContentVersion>(`admin/versions/${version}/fork`, {
    method: "POST",
    body: { destination_course_id: input.destination_course_id, label: input.label ?? null },
  });
}
