/**
 * Assignments — pure formatting + derivation helpers (no React, no I/O).
 *
 * The rubric total here is the CLIENT MIRROR of the server's computation (BuildRubricRequest →
 * AssignmentService::buildRubric): a criterion's points is the MAX of its levels' points, and the
 * rubric total is the sum of those criterion maxima. It is deterministic and side-effect free so
 * the builder can show a live total that matches what the server will persist.
 */
import type {
  Assignment,
  AssignmentInput,
  InstructionsDoc,
  RubricCriterionInput,
  RubricLevelInput,
} from "./assignments-api";

// ─────────────────────────────────────────────────────────────────────────────
// Rubric points (deterministic; mirrors the server)
// ─────────────────────────────────────────────────────────────────────────────

/** Max points among a criterion's levels (0 when it has none). Deterministic. */
export function criterionMaxPoints(levels: ReadonlyArray<{ points: number }>): number {
  return levels.reduce((max, l) => (Number.isFinite(l.points) && l.points > max ? l.points : max), 0);
}

/** Rubric total = sum of each criterion's max level points. Deterministic; mirrors the server. */
export function rubricTotalPoints(
  criteria: ReadonlyArray<{ levels: ReadonlyArray<{ points: number }> }>,
): number {
  return criteria.reduce((sum, c) => sum + criterionMaxPoints(c.levels), 0);
}

/** Trim to a fixed number of decimals without trailing-zero noise (points may be fractional). */
export function formatPoints(points: number): string {
  if (!Number.isFinite(points)) return "0";
  return Number(points.toFixed(2)).toString();
}

// ─────────────────────────────────────────────────────────────────────────────
// Instructions (plain text <-> opaque JSON doc)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Serialize the textarea's plain text into the stored doc shape (one paragraph node per line,
 * blank lines dropped). Empty input serializes to `null` so the field can be cleared.
 */
export function textToInstructions(text: string): InstructionsDoc | null {
  const lines = text
    .split("\n")
    .map((l) => l.trimEnd())
    .filter((l) => l.trim() !== "");
  if (lines.length === 0) return null;
  return lines.map((line) => ({ type: "paragraph", text: line }));
}

/** Best-effort inverse of {@link textToInstructions} for populating the textarea from stored data. */
export function instructionsToText(doc: InstructionsDoc | null | undefined): string {
  if (!doc || !Array.isArray(doc)) return "";
  return doc
    .map((node) => {
      if (node && typeof node === "object" && "text" in node) {
        const t = (node as { text?: unknown }).text;
        return typeof t === "string" ? t : "";
      }
      return typeof node === "string" ? node : "";
    })
    .filter((t) => t !== "")
    .join("\n");
}

// ─────────────────────────────────────────────────────────────────────────────
// Assignment <-> flat editable draft
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Flatten an {@link Assignment} (nested `settings`) into the flat {@link AssignmentInput} the save
 * endpoint accepts and the builder form edits. Round-trips with the resource shape.
 */
export function assignmentToDraft(a: Assignment): Required<AssignmentInput> {
  return {
    title: a.title,
    lesson_id: a.lesson_id,
    instructions: a.instructions,
    submission_type: a.submission_type,
    allowed_file_types: a.settings.allowed_file_types,
    max_file_size: a.settings.max_file_size,
    max_files: a.settings.max_files ?? 1,
    attempt_limit: a.settings.attempt_limit,
    due_at: a.settings.due_at,
    late_policy: a.settings.late_policy,
    late_penalty_percent: a.settings.late_penalty_percent,
    max_grade: a.settings.max_grade,
    passing_grade: a.settings.passing_grade,
    required_for_completion: a.required_for_completion,
  };
}

/** True when the submission type requires at least one file (file / text_and_file). */
export function requiresFile(type: AssignmentInput["submission_type"]): boolean {
  return type === "file" || type === "text_and_file";
}

/** True when the submission type requires written text (text / text_and_file). */
export function requiresText(type: AssignmentInput["submission_type"]): boolean {
  return type === "text" || type === "text_and_file";
}

/** True when the submission type requires an external URL. */
export function requiresUrl(type: AssignmentInput["submission_type"]): boolean {
  return type === "external_url";
}

// ─────────────────────────────────────────────────────────────────────────────
// File-type token parsing (comma/space separated extensions)
// ─────────────────────────────────────────────────────────────────────────────

/** Parse a free-text "pdf, docx png" string into a normalized, de-duplicated extension list. */
export function parseFileTypes(raw: string): string[] {
  const seen = new Set<string>();
  const out: string[] = [];
  for (const token of raw.split(/[\s,]+/)) {
    const ext = token.replace(/^\./, "").trim().toLowerCase();
    if (ext !== "" && ext.length <= 16 && !seen.has(ext)) {
      seen.add(ext);
      out.push(ext);
    }
  }
  return out;
}

/** Render an extension list back into the comma-separated string the input shows. */
export function formatFileTypes(types: string[] | null | undefined): string {
  return (types ?? []).join(", ");
}

// ─────────────────────────────────────────────────────────────────────────────
// Rubric draft helpers (for the builder's local editing state)
// ─────────────────────────────────────────────────────────────────────────────

export function emptyLevel(): RubricLevelInput {
  return { title: "", description: null, points: 0 };
}

export function emptyCriterion(): RubricCriterionInput {
  return { title: "", description: null, levels: [emptyLevel()] };
}

/** Move an item within an array (immutably); out-of-range moves are no-ops. */
export function moveItem<T>(items: readonly T[], from: number, to: number): T[] {
  if (from === to || from < 0 || to < 0 || from >= items.length || to >= items.length) {
    return items.slice();
  }
  const next = items.slice();
  const [moved] = next.splice(from, 1);
  next.splice(to, 0, moved);
  return next;
}
