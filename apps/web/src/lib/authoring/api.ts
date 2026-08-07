/**
 * Course Builder — API client.
 *
 * Wraps the existing Authoring admin endpoints (`/api/v1/admin/*`, reached via the same-origin BFF
 * proxy through `@/lib/api/client`). Only backend-supported block kinds are ever sent; unsupported
 * kinds are guarded here (never faked) and enumerated in `REMAINING_BACKEND` for the backend team.
 *
 * NOTE (scope): these endpoints are authorized by `authoring.curriculum.manage` / super_admin, not
 * the instructor `teach` scope. Wiring instructor access is a backend task — see REMAINING_BACKEND.
 */
import { api, ApiRequestError } from "@/lib/api/client";
import { isBackendSupported } from "./block-registry";
import type {
  Block,
  BlockContent,
  BlockKind,
  CreateBlockInput,
  CreateSectionInput,
  Curriculum,
  LessonAssessmentRef,
  LessonMedia,
  LocalizedText,
  PublishState,
  ReorderTreeInput,
  Section,
  UpdateBlockInput,
  UpdateSectionInput,
  UpsertMediaInput,
} from "./types";

/** Thrown when a caller tries to persist a block kind the backend does not accept yet. */
export class UnsupportedBlockError extends globalThis.Error {
  constructor(public readonly kind: BlockKind) {
    super(`Block kind "${kind}" is not yet supported by the backend.`);
    this.name = "UnsupportedBlockError";
  }
}

/**
 * Thrown when a mutation is rejected with HTTP 409 because the node changed since it was loaded
 * (optimistic-concurrency). Carries the server's authoritative `current_version` when present so the
 * conflict UX can reconcile without a full reload. The builder surfaces this as a non-destructive
 * banner rather than overwriting the newer server state.
 */
export class StaleWriteError extends globalThis.Error {
  constructor(public readonly currentVersion?: number) {
    super("This item was changed elsewhere since you opened it.");
    this.name = "StaleWriteError";
  }
}

/**
 * Run a mutating call, translating the agreed optimistic-concurrency 409
 * (`{ error: "stale_write", current_version: <int> }`) into a typed {@link StaleWriteError}.
 * Every other failure propagates unchanged.
 */
async function withConflict<T>(fn: () => Promise<T>): Promise<T> {
  try {
    return await fn();
  } catch (e) {
    if (e instanceof ApiRequestError && e.status === 409) {
      const body = e.payload as { current_version?: number } | null | undefined;
      const current = typeof body?.current_version === "number" ? body.current_version : undefined;
      throw new StaleWriteError(current);
    }
    throw e;
  }
}

/**
 * Build the request body for a translatable field: dual-writes the legacy scalar (English, the
 * learner default) alongside the `*_i18n` map the backend persists. Returns `undefined` when there
 * is nothing to write so callers can spread it conditionally.
 */
function localizedBody(scalarKey: string, i18nKey: string, value: LocalizedText | undefined): Record<string, unknown> {
  if (!value) return {};
  const en = value.en ?? "";
  const ar = value.ar ?? "";
  return { [scalarKey]: en, [i18nKey]: { en, ar } };
}

// ── Raw backend shapes ─────────────────────────────────────────────────────
interface RawMedia {
  mux_asset_id: string | null;
  mux_playback_id: string | null;
  s3_key: string | null;
  mime_type: string | null;
  duration: number | null;
  filesize: number | null;
}
interface RawLesson {
  id: string;
  title: string;
  title_i18n?: Partial<LocalizedText> | null;
  type: string;
  content: unknown;
  position: number;
  publish_state: PublishState;
  lock_version?: number | null;
  is_preview: boolean;
  media: RawMedia | null;
  prerequisites?: { id: string; title: string }[];
  estimated_minutes?: number | null;
  assessment?: LessonAssessmentRef | null;
}
interface RawSection {
  id: string;
  title: string;
  title_i18n?: Partial<LocalizedText> | null;
  summary: string | null;
  summary_i18n?: Partial<LocalizedText> | null;
  position: number;
  publish_state: PublishState;
  lock_version?: number | null;
  lessons: RawLesson[];
}

// ── Mappers (backend → builder domain) ─────────────────────────────────────
function asContent(raw: unknown): BlockContent {
  return raw && typeof raw === "object" && !Array.isArray(raw) ? (raw as BlockContent) : {};
}
function toMedia(raw: RawMedia | null): LessonMedia | null {
  return raw ? { ...raw } : null;
}
/**
 * Normalise a translatable field to a full `{ en, ar }` map. Prefers the server's `*_i18n` map when
 * present; otherwise seeds English from the locale-resolved scalar so a backend that only emits the
 * scalar still populates the English authoring field (Arabic stays empty until authored).
 */
function toLocalized(map: Partial<LocalizedText> | null | undefined, scalar: string | null): LocalizedText {
  return {
    en: map?.en ?? scalar ?? "",
    ar: map?.ar ?? "",
  };
}
function toBlock(raw: RawLesson): Block {
  return {
    id: raw.id,
    title: raw.title,
    title_i18n: toLocalized(raw.title_i18n, raw.title),
    kind: raw.type as BlockKind,
    content: asContent(raw.content),
    position: raw.position,
    publish_state: raw.publish_state,
    lock_version: raw.lock_version ?? 0,
    is_preview: raw.is_preview,
    media: toMedia(raw.media),
    prerequisites: raw.prerequisites ?? [],
    estimated_minutes: raw.estimated_minutes ?? null,
    assessment: raw.assessment ?? null,
  };
}
function toSection(raw: RawSection): Section {
  return {
    id: raw.id,
    title: raw.title,
    title_i18n: toLocalized(raw.title_i18n, raw.title),
    summary: raw.summary,
    summary_i18n: toLocalized(raw.summary_i18n, raw.summary),
    position: raw.position,
    publish_state: raw.publish_state,
    lock_version: raw.lock_version ?? 0,
    blocks: (raw.lessons ?? []).map(toBlock),
  };
}

// ── Reads ──────────────────────────────────────────────────────────────────
export async function getCurriculum(courseId: string): Promise<Curriculum> {
  const data = await api.data<{ sections: RawSection[] }>(`admin/courses/${courseId}/curriculum`);
  const sections = Array.isArray(data?.sections) ? data.sections.map(toSection) : [];
  return { course_id: courseId, sections };
}

// ── Sections ────────────────────────────────────────────────────────────────
export async function createSection(courseId: string, input: CreateSectionInput): Promise<Section> {
  const body: Record<string, unknown> = {
    title: input.title,
    ...(input.summary !== undefined ? { summary: input.summary } : {}),
    ...localizedBody("title", "title_i18n", input.title_i18n),
    ...localizedBody("summary", "summary_i18n", input.summary_i18n),
  };
  const data = await api.data<RawSection>(`admin/courses/${courseId}/sections`, { method: "POST", body });
  return toSection(data);
}
export async function updateSection(sectionId: string, input: UpdateSectionInput): Promise<Section> {
  const body: Record<string, unknown> = {
    ...(input.title !== undefined ? { title: input.title } : {}),
    ...(input.summary !== undefined ? { summary: input.summary } : {}),
    ...localizedBody("title", "title_i18n", input.title_i18n),
    ...localizedBody("summary", "summary_i18n", input.summary_i18n),
    ...(input.expected_version !== undefined ? { expected_version: input.expected_version } : {}),
  };
  const data = await withConflict(() =>
    api.data<RawSection>(`admin/sections/${sectionId}`, { method: "PUT", body }),
  );
  return toSection(data);
}

/**
 * Deep-copy a section (with all its lessons: media, prerequisites, i18n maps, draft state) into the
 * same course. The backend clones server-side and returns the new section; the builder refetches the
 * tree afterwards so ordering and positions come from the server, not a client re-creation.
 */
export async function duplicateSection(courseId: string, sectionId: string): Promise<Section> {
  const data = await api.data<RawSection>(`admin/courses/${courseId}/sections/${sectionId}/duplicate`, {
    method: "POST",
  });
  return toSection(data);
}
export async function deleteSection(sectionId: string): Promise<void> {
  await api.del(`admin/sections/${sectionId}`);
}
export async function setSectionPublish(sectionId: string, state: PublishState): Promise<Section> {
  const data = await api.data<RawSection>(`admin/sections/${sectionId}/publish`, { method: "POST", body: { state } });
  return toSection(data);
}
export async function reorderSections(courseId: string, order: string[]): Promise<void> {
  await withConflict(() => api.put(`admin/courses/${courseId}/sections/order`, { order }));
}

// ── Blocks (backend "lessons") ───────────────────────────────────────────────
export async function createBlock(sectionId: string, input: CreateBlockInput): Promise<Block> {
  if (!isBackendSupported(input.kind)) throw new UnsupportedBlockError(input.kind);
  const body: Record<string, unknown> = {
    title: input.title,
    type: input.kind,
    content: input.content ?? {},
    is_preview: input.is_preview ?? false,
    ...localizedBody("title", "title_i18n", input.title_i18n),
  };
  const data = await api.data<RawLesson>(`admin/sections/${sectionId}/lessons`, { method: "POST", body });
  return toBlock(data);
}
export async function updateBlock(blockId: string, input: UpdateBlockInput): Promise<Block> {
  if (input.kind && !isBackendSupported(input.kind)) throw new UnsupportedBlockError(input.kind);
  const body: Record<string, unknown> = {};
  if (input.title !== undefined) body.title = input.title;
  if (input.kind !== undefined) body.type = input.kind;
  if (input.content !== undefined) body.content = input.content;
  Object.assign(body, localizedBody("title", "title_i18n", input.title_i18n));
  if (input.expected_version !== undefined) body.expected_version = input.expected_version;
  const data = await withConflict(() => api.data<RawLesson>(`admin/lessons/${blockId}`, { method: "PUT", body }));
  return toBlock(data);
}

/**
 * Deep-copy a lesson within its section — media, prerequisites, i18n title and draft state are all
 * cloned server-side. The lesson is bound under its section so a foreign lesson id 404s. The builder
 * refetches the tree afterwards so positions come from the server.
 */
export async function duplicateBlock(sectionId: string, blockId: string): Promise<Block> {
  const data = await api.data<RawLesson>(`admin/sections/${sectionId}/lessons/${blockId}/duplicate`, {
    method: "POST",
  });
  return toBlock(data);
}
export async function deleteBlock(blockId: string): Promise<void> {
  await api.del(`admin/lessons/${blockId}`);
}
export async function setBlockPublish(blockId: string, state: PublishState): Promise<Block> {
  const data = await api.data<RawLesson>(`admin/lessons/${blockId}/publish`, { method: "POST", body: { state } });
  return toBlock(data);
}
export async function toggleBlockPreview(blockId: string): Promise<Block> {
  const data = await api.data<RawLesson>(`admin/lessons/${blockId}/preview`, { method: "POST" });
  return toBlock(data);
}
export async function reorderBlocks(sectionId: string, order: string[], expectedVersion?: number): Promise<void> {
  const body: Record<string, unknown> = { order };
  if (expectedVersion !== undefined) body.expected_version = expectedVersion;
  await withConflict(() => api.put(`admin/sections/${sectionId}/lessons/order`, body));
}
export async function setPrerequisites(blockId: string, prerequisites: string[]): Promise<Block> {
  const data = await api.data<RawLesson>(`admin/lessons/${blockId}/prerequisites`, { method: "PUT", body: { prerequisites } });
  return toBlock(data);
}
export async function upsertMedia(blockId: string, input: UpsertMediaInput): Promise<Block> {
  const data = await api.data<RawLesson>(`admin/lessons/${blockId}/media`, { method: "PUT", body: input });
  return toBlock(data);
}

// ── Whole-tree reorder (DnD across sections) ─────────────────────────────────
export async function reorderTree(courseId: string, input: ReorderTreeInput): Promise<void> {
  await withConflict(() => api.put(`admin/courses/${courseId}/curriculum/order`, input));
}

/**
 * Endpoints the Course Builder needs but the backend does not expose yet. The UI models these
 * cleanly; persistence is disabled until they exist. (No fake implementations.)
 */
export const REMAINING_BACKEND: readonly string[] = [
  "Direct media upload — there is no endpoint that returns an upload target, so the builder can only reference assets that already exist. Needed: POST /admin/lessons/{lesson}/media/upload returning either a Mux direct-upload URL ({ upload_id, upload_url }) or an S3 presigned POST ({ url, fields, key }), plus a way to learn when Mux finishes ingesting (webhook → lesson_media.mux_playback_id, or GET /admin/lessons/{lesson}/media/status returning { state: 'waiting'|'ready'|'errored' }). Until both exist the builder will NOT display upload progress or claim an asset is ready.",
  "Media delete — DELETE /admin/lessons/{lesson}/media. Detaching currently works by PUTting explicit nulls, which relies on every column being nullable.",
  "Instructor-scoped curriculum access — expose the /admin curriculum endpoints under the `teach` scope (or grant `authoring.curriculum.manage`) so instructors, not just admins, can author.",
  "Block kinds not in LessonType enum: scorm, xapi, cmi5, quiz (full), assignment, discussion, live_session, certificate, survey — need create/update support + content schemas.",
  "Sub-section nesting — curriculum is a flat Section→Lesson tree; nested sub-sections need a schema + endpoints.",
  "Course-level authoring meta (title/description/access/schedule/completion rules) editable via a course PUT for the builder header + inspector.",
  // Duplicate endpoints now exist and are used (POST .../sections/{section}/duplicate and
  // .../lessons/{lesson}/duplicate) — the builder no longer re-creates nodes client-side.
];
