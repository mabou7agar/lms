/**
 * Course Builder — domain types.
 *
 * These mirror the Authoring backend (`/api/v1/admin/courses/{course}/curriculum` and the
 * section/lesson admin endpoints) and extend it with the richer block taxonomy the Course Builder
 * surfaces. Block kinds that the backend does not yet accept are marked here and gated at the API
 * layer with `TODO(backend)` — the UI can render/select them, but saving is disabled until the
 * backend supports them (no fake persistence). See `block-registry.ts` for per-kind capability.
 */

/** Publish lifecycle shared by course, section and lesson (mirrors backend PublishState). */
export type PublishState = "draft" | "published";

/** The two authoring languages the builder edits. */
export type LocaleCode = "en" | "ar";

/**
 * A translatable string, one entry per authoring language. Mirrors the backend `*_i18n` jsonb
 * columns (`title_i18n`, `summary_i18n` → `{ en, ar }`). Arabic falls back to English when empty;
 * the map is authoring-only and is NEVER placed inside a lesson's learner-facing `content`.
 */
export interface LocalizedText {
  en: string;
  ar: string;
}

/**
 * Every content block kind the builder understands.
 * The first group is accepted by the backend today (LessonType enum); the second group is
 * design-complete on the frontend but awaits backend support (see REMAINING_BACKEND in api.ts).
 */
export type BlockKind =
  // ── Backend-supported (LessonType enum) ────────────────────────────────
  | "video"
  | "audio"
  | "article"
  | "pdf"
  | "download"
  | "external_link"
  | "quiz_placeholder"
  // ── Extended (frontend-ready, backend TODO) ────────────────────────────
  | "scorm"
  | "xapi"
  | "cmi5"
  | "quiz"
  | "assignment"
  | "discussion"
  | "live_session"
  | "certificate"
  | "survey";

/** Node kinds in the curriculum tree. Sub-sections are frontend-modelled (backend is flat today). */
export type NodeKind = "course" | "section" | "subsection" | "lesson";

/** Free-form, per-kind content payload. Persisted by the backend as JSON; typed loosely here and
 *  narrowed by each editor. Concrete shapes for the kinds we edit live in `block-content.ts`. */
export type BlockContent = Record<string, unknown>;

/** Media metadata attached to a lesson (Mux for video/audio, S3 for files). */
export interface LessonMedia {
  mux_asset_id: string | null;
  mux_playback_id: string | null;
  s3_key: string | null;
  mime_type: string | null;
  duration: number | null; // seconds
  filesize: number | null; // bytes
}

/** A lesson prerequisite reference (as returned by the backend). */
export interface PrerequisiteRef {
  id: string;
  title: string;
}

/** A single content block (backend "lesson"). */
export interface Block {
  id: string; // public_id
  title: string;
  /** Bilingual title (authoring-only). `title` above is the locale-resolved display string. */
  title_i18n: LocalizedText;
  kind: BlockKind; // maps to backend `type`
  content: BlockContent;
  position: number;
  publish_state: PublishState;
  /** Optimistic-concurrency token. Sent back as `expected_version`; bumped by the server on write. */
  lock_version: number;
  is_preview: boolean;
  media: LessonMedia | null;
  prerequisites: PrerequisiteRef[];
  /** Estimated duration in minutes, when the backend can derive it (media duration / reading time). */
  estimated_minutes?: number | null;
  /**
   * Compact reference to the attached Assessment, for `quiz` lessons. Null when nothing is
   * attached — and also when a previously-attached assessment has been deleted, so a stale
   * reference degrades to "no quiz" instead of breaking the tree.
   */
  assessment?: LessonAssessmentRef | null;
}

/** Mirrors the `assessment` block emitted by the backend LessonResource. */
export interface LessonAssessmentRef {
  id: string;
  title: string;
  status: "draft" | "published" | "archived";
  question_count: number;
  version: number;
}

/** A curriculum section containing blocks (and, on the frontend, optional sub-sections). */
export interface Section {
  id: string; // public_id
  title: string;
  /** Bilingual title (authoring-only). `title` above is the locale-resolved display string. */
  title_i18n: LocalizedText;
  summary: string | null;
  /** Bilingual summary (authoring-only). `summary` above is the locale-resolved display string. */
  summary_i18n: LocalizedText;
  position: number;
  publish_state: PublishState;
  /** Optimistic-concurrency token. Sent back as `expected_version`; bumped by the server on write. */
  lock_version: number;
  blocks: Block[];
  /** Frontend-only nesting; not persisted until backend adds sub-section support. */
  subsections?: Section[];
}

/** The full curriculum tree for a course. */
export interface Curriculum {
  course_id: string;
  sections: Section[];
}

/** Course-level authoring metadata surfaced in the builder header / course editor. */
export interface BuilderCourseMeta {
  id: string;
  title: string;
  status: PublishState | "archived";
  updated_at?: string | null;
}

// ── Selection & builder view state ────────────────────────────────────────

/** What the center editor + inspector are focused on. */
export type Selection =
  | { kind: "course" }
  | { kind: "section"; sectionId: string }
  | { kind: "subsection"; sectionId: string; subsectionId: string }
  | { kind: "lesson"; sectionId: string; blockId: string };

/** Autosave lifecycle for the sticky toolbar indicator. */
export type SaveStatus = "idle" | "dirty" | "saving" | "saved" | "error";

// ── Validation ────────────────────────────────────────────────────────────

export type ValidationLevel = "error" | "warning";

export interface ValidationIssue {
  level: ValidationLevel;
  /** Dot-path target, e.g. "section:<id>", "lesson:<id>", "course". */
  target: string;
  message: string;
}

// ── Mutation input payloads (match backend request bodies) ─────────────────

export interface CreateSectionInput {
  title: string;
  summary?: string;
  title_i18n?: LocalizedText;
  summary_i18n?: LocalizedText;
}
export interface UpdateSectionInput {
  title?: string;
  summary?: string | null;
  /** Bilingual edits. When present the client dual-writes `title`/`summary` = the English value. */
  title_i18n?: LocalizedText;
  summary_i18n?: LocalizedText;
  /** Optimistic-concurrency token (last known `lock_version`); a stale value yields HTTP 409. */
  expected_version?: number;
}
export interface CreateBlockInput {
  title: string;
  kind: BlockKind;
  content?: BlockContent;
  is_preview?: boolean;
  title_i18n?: LocalizedText;
}
export interface UpdateBlockInput {
  title?: string;
  kind?: BlockKind;
  content?: BlockContent;
  /** Bilingual title edit. When present the client dual-writes `title` = the English value. */
  title_i18n?: LocalizedText;
  /** Optimistic-concurrency token (last known `lock_version`); a stale value yields HTTP 409. */
  expected_version?: number;
}
/**
 * Payload for `PUT /admin/lessons/{lesson}/media`. Every field is `nullable` in
 * `UpsertLessonMediaRequest`, so an explicit `null` clears that column — which is how the
 * media editor detaches an asset (there is no DELETE endpoint).
 */
export interface UpsertMediaInput {
  mux_asset_id?: string | null;
  mux_playback_id?: string | null;
  s3_key?: string | null;
  mime_type?: string | null;
  duration?: number | null;
  filesize?: number | null;
}

/** Whole-tree reorder payload (backend PUT .../curriculum/order). */
export interface ReorderTreeInput {
  tree: { id: string; lessons: string[] }[];
}
