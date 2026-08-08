import { describe, expect, it } from "vitest";
import {
  SUPPORTED_BLOCK_KINDS,
  assembleContentI18n,
  fieldsFor,
  isFormValid,
  isSupportedBlockKind,
  parseFormValues,
} from "@/lib/authoring/content-blocks/registry";

/**
 * The content-block field registry mirrors the backend `BlockPayloadRules`: it exposes only
 * supported kinds, edits EN/AR per localized field, mirrors shared reference fields across locales,
 * and assembles a `content_i18n` map that omits an untranslated Arabic side (learner fallback).
 */

describe("supported kinds", () => {
  it("exposes only runtime-supported kinds and excludes the rest", () => {
    expect(SUPPORTED_BLOCK_KINDS).toContain("article");
    expect(SUPPORTED_BLOCK_KINDS).not.toContain("scorm");
    expect(isSupportedBlockKind("article")).toBe(true);
    expect(isSupportedBlockKind("scorm")).toBe(false);
  });
});

describe("parse ↔ assemble round-trip", () => {
  it("reads both languages for a localized field", () => {
    const values = parseFormValues("article", { en: { html: "<p>hi</p>" }, ar: { html: "<p>مرحبا</p>" } });
    expect(values.html).toEqual({ en: "<p>hi</p>", ar: "<p>مرحبا</p>" });
  });

  it("assembles the {en,ar} map and OMITS an empty Arabic side (fallback to English)", () => {
    const values = parseFormValues("article", { en: { html: "<p>hi</p>" } });
    values.html = { en: "<p>hi</p>", ar: "" };
    const assembled = assembleContentI18n("article", values);
    expect(assembled.en).toEqual({ html: "<p>hi</p>" });
    expect(assembled.ar).toBeUndefined();
  });

  it("mirrors a shared (non-localized) reference field into every present locale", () => {
    const values = parseFormValues("external_link", {});
    values.url = { en: "https://example.com", ar: "https://example.com" };
    values.label = { en: "Docs", ar: "" };
    const assembled = assembleContentI18n("external_link", values);
    // English carries the shared URL and its authored label.
    expect(assembled.en).toMatchObject({ url: "https://example.com", label: "Docs" });
    // The shared URL is mirrored into Arabic too (so the block renders in either language), but the
    // untranslated label is omitted — Arabic falls back to English for the label text.
    expect(assembled.ar).toEqual({ url: "https://example.com" });
  });
});

describe("validation", () => {
  it("requires an English value for a required field", () => {
    const empty = parseFormValues("article", {});
    expect(isFormValid("article", empty)).toBe(false);

    empty.html = { en: "<p>x</p>", ar: "" };
    expect(isFormValid("article", empty)).toBe(true);
  });

  it("treats a type with no required fields as always valid", () => {
    expect(fieldsFor("quiz_placeholder").some((f) => f.required)).toBe(false);
    expect(isFormValid("quiz_placeholder", parseFormValues("quiz_placeholder", {}))).toBe(true);
  });
});
