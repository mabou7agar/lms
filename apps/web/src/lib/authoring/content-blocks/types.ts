/**
 * C5 — Nested lesson CONTENT BLOCKS (the ordered block layer INSIDE a lesson).
 *
 * Distinct from the curriculum `Block` in `../types.ts` (which models a backend *lesson*). A
 * ContentBlock is a first-class, typed unit that belongs to a lesson and mirrors the backend
 * `content_blocks` table / `BlockResource`:
 *   { id (public_id), type, family, position, content_i18n:{en,ar}, config, publish_state, lock_version }
 *
 * The whole surface is gated behind the `authoring.blocks_enabled` backend flag (endpoints 404 while
 * off), so it is additive and invisible in production until enabled.
 */
import type { BlockKind, LocaleCode, PublishState } from "../types";

/** A single locale's typed payload for a block (the shape is per-type; see BlockPayloadRules). */
export type BlockLocalePayload = Record<string, unknown>;

/**
 * The bilingual content map the builder edits. Each locale carries the SAME per-type payload shape.
 * A locale may be omitted (Arabic falls back to English for learners), so both keys are optional.
 */
export type BlockContentI18n = Partial<Record<LocaleCode, BlockLocalePayload>>;

/** A content block as returned by `BlockResource`. */
export interface ContentBlock {
  id: string; // public_id
  type: BlockKind;
  family: string;
  position: number;
  publish_state: PublishState;
  /** Optimistic-concurrency token. Echoed back as `expected_version`; bumped by the server on write. */
  lock_version: number;
  /** Locale-resolved payload (honours `?locale=`), for read-only rendering/preview. */
  content: BlockLocalePayload;
  /** Full bilingual map the builder edits. */
  content_i18n: BlockContentI18n;
  /** Free-form per-block configuration (opaque here; preserved on write). */
  config: Record<string, unknown> | null;
  learning_object_id: string | null;
}

// ── Mutation input payloads (match the backend CreateBlockRequest / UpdateBlockRequest) ──────────

export interface CreateContentBlockInput {
  type: BlockKind;
  content_i18n?: BlockContentI18n;
  config?: Record<string, unknown> | null;
}

export interface UpdateContentBlockInput {
  type?: BlockKind;
  content_i18n?: BlockContentI18n;
  config?: Record<string, unknown> | null;
  /** Optimistic-concurrency token (last known `lock_version`); a stale value yields HTTP 409. */
  expected_version?: number;
}

/** Reorder response — the parent LESSON's new lock_version (blocks are ordered under the lesson). */
export interface ReorderContentBlocksResult {
  lock_version: number;
}
