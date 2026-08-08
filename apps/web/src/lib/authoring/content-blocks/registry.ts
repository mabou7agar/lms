/**
 * C5 — Content-block authoring registry.
 *
 * Single source of truth for which block TYPES the nested-blocks UI exposes (the runtime-supported
 * set, read from the shared `block-registry`), and the typed FIELDS each type's editor renders.
 *
 * The field schema mirrors the backend `BlockPayloadRules` one-for-one so the builder can never
 * author a key the server would reject:
 *   article        → html (rich text, localized, required)
 *   pdf / download → url, s3_key (shared refs) + filename (localized)
 *   external_link  → url (shared, required) + label (localized)
 *   video / audio  → mux_playback_id, s3_key, url (shared media refs)
 *   quiz_placeholder → note (localized)
 *   quiz           → assessment_public_id (shared reference)
 *
 * Editors edit `content_i18n` through the shared BilingualField (EN + AR); there is no raw-JSON path.
 * "Localized" fields expose both languages; "shared" fields (URLs, storage keys, ids — not natural
 * language) expose one control whose value is mirrored into every present locale so the payload stays
 * renderable in either language.
 */
import { BLOCK_DEFS, isBackendSupported } from "../block-registry";
import type { BlockKind, LocaleCode } from "../types";
import type { BlockContentI18n, BlockLocalePayload } from "./types";

/** The runtime-supported block kinds, in registry order — the ONLY kinds the add menu offers. */
export const SUPPORTED_BLOCK_KINDS: readonly BlockKind[] = BLOCK_DEFS.filter((d) => d.supported).map((d) => d.kind);

export function isSupportedBlockKind(kind: BlockKind): boolean {
  return isBackendSupported(kind);
}

export type FieldControl = "richtext" | "text" | "textarea" | "url";

export interface BlockFieldSpec {
  key: string;
  control: FieldControl;
  /** True → a bilingual EN/AR field; false → one shared control mirrored across locales. */
  localized: boolean;
  required?: boolean;
  /** authoring-i18n label / hint keys. */
  labelKey: string;
  hintKey?: string;
}

const FIELDS: Partial<Record<BlockKind, BlockFieldSpec[]>> = {
  article: [{ key: "html", control: "richtext", localized: true, required: true, labelKey: "cblock.field.html" }],
  pdf: [
    { key: "url", control: "url", localized: false, labelKey: "cblock.field.url", hintKey: "cblock.field.urlHint" },
    { key: "s3_key", control: "text", localized: false, labelKey: "cblock.field.s3Key", hintKey: "cblock.field.s3KeyHint" },
    { key: "filename", control: "text", localized: true, labelKey: "cblock.field.filename" },
  ],
  download: [
    { key: "url", control: "url", localized: false, labelKey: "cblock.field.url", hintKey: "cblock.field.urlHint" },
    { key: "s3_key", control: "text", localized: false, labelKey: "cblock.field.s3Key", hintKey: "cblock.field.s3KeyHint" },
    { key: "filename", control: "text", localized: true, labelKey: "cblock.field.filename" },
  ],
  external_link: [
    { key: "url", control: "url", localized: false, required: true, labelKey: "cblock.field.url" },
    { key: "label", control: "text", localized: true, labelKey: "cblock.field.label" },
  ],
  video: [
    { key: "mux_playback_id", control: "text", localized: false, labelKey: "cblock.field.muxPlaybackId" },
    { key: "s3_key", control: "text", localized: false, labelKey: "cblock.field.s3Key", hintKey: "cblock.field.s3KeyHint" },
    { key: "url", control: "url", localized: false, labelKey: "cblock.field.url", hintKey: "cblock.field.urlHint" },
  ],
  audio: [
    { key: "mux_playback_id", control: "text", localized: false, labelKey: "cblock.field.muxPlaybackId" },
    { key: "s3_key", control: "text", localized: false, labelKey: "cblock.field.s3Key", hintKey: "cblock.field.s3KeyHint" },
    { key: "url", control: "url", localized: false, labelKey: "cblock.field.url", hintKey: "cblock.field.urlHint" },
  ],
  quiz_placeholder: [{ key: "note", control: "textarea", localized: true, labelKey: "cblock.field.note" }],
  quiz: [{ key: "assessment_public_id", control: "text", localized: false, labelKey: "cblock.field.assessmentId", hintKey: "cblock.field.assessmentIdHint" }],
};

/** The editor field schema for a supported block kind (empty for unsupported/unknown kinds). */
export function fieldsFor(kind: BlockKind): BlockFieldSpec[] {
  return FIELDS[kind] ?? [];
}

const LOCALES: readonly LocaleCode[] = ["en", "ar"];

/** A per-field bilingual editing value. Shared fields use `.en` and mirror it to `.ar` on assemble. */
export type FieldValue = { en: string; ar: string };
export type BlockFormValues = Record<string, FieldValue>;

/** Read the initial editor values for a block's fields out of its `content_i18n` map. */
export function parseFormValues(kind: BlockKind, content: BlockContentI18n): BlockFormValues {
  const out: BlockFormValues = {};
  for (const f of fieldsFor(kind)) {
    const en = readField(content.en, f.key);
    const ar = readField(content.ar, f.key);
    // A shared reference is stored in every locale; surface whichever is present as the single value.
    out[f.key] = f.localized ? { en, ar } : { en: en || ar, ar: en || ar };
  }
  return out;
}

/**
 * Build the `content_i18n` map from editor values. A locale is included only when it carries at least
 * one non-empty field, so an untranslated Arabic side is omitted (learner fallback) rather than sent
 * as an empty object that would fail the backend's per-locale `required` rules.
 */
export function assembleContentI18n(kind: BlockKind, values: BlockFormValues): BlockContentI18n {
  const fields = fieldsFor(kind);
  const out: BlockContentI18n = {};
  for (const locale of LOCALES) {
    const payload: BlockLocalePayload = {};
    for (const f of fields) {
      const raw = f.localized ? values[f.key]?.[locale] : values[f.key]?.en;
      const value = (raw ?? "").trim();
      if (value !== "") payload[f.key] = value;
    }
    if (Object.keys(payload).length > 0) out[locale] = payload;
  }
  return out;
}

/** True when every required field has an English value (Arabic is optional; it falls back to English). */
export function isFormValid(kind: BlockKind, values: BlockFormValues): boolean {
  return fieldsFor(kind)
    .filter((f) => f.required)
    .every((f) => (values[f.key]?.en ?? "").trim() !== "");
}

function readField(payload: BlockLocalePayload | undefined, key: string): string {
  const value = payload?.[key];
  return typeof value === "string" ? value : "";
}
