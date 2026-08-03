import { describe, expect, it } from "vitest";
import { messaging, personaById, localized, type Localized } from "@/config/messaging";

/** Every real public route a messaging CTA is allowed to point at. */
const REAL_ROUTES = new Set([
  "/courses",
  "/pricing",
  "/enterprise",
  "/contact",
  "/register",
  "/login",
  "/cohorts",
  "/workshops",
  "/teach/apply",
]);

/** Recursively collect every {en, ar} pair reachable from the messaging tree. */
function collectLocalized(node: unknown, out: Localized[] = []): Localized[] {
  if (node && typeof node === "object") {
    const obj = node as Record<string, unknown>;
    if (typeof obj.en === "string" && typeof obj.ar === "string") {
      out.push(obj as unknown as Localized);
      return out;
    }
    for (const v of Object.values(obj)) collectLocalized(v, out);
  }
  return out;
}

const allPairs = collectLocalized(messaging);
const allStrings = allPairs.flatMap((p) => [p.en, p.ar]);

describe("messaging system", () => {
  it("has full English/Arabic parity (both non-empty for every localized string)", () => {
    expect(allPairs.length).toBeGreaterThan(10);
    for (const pair of allPairs) {
      expect(pair.en.trim().length, `EN empty: ${JSON.stringify(pair)}`).toBeGreaterThan(0);
      expect(pair.ar.trim().length, `AR empty: ${JSON.stringify(pair)}`).toBeGreaterThan(0);
      // Arabic must actually contain Arabic script.
      expect(/[؀-ۿ]/.test(pair.ar), `AR not Arabic: ${pair.ar}`).toBe(true);
    }
  });

  it("contains no fabricated proof (counts, ratings, ROI %, compliance certs, superiority)", () => {
    const forbidden: RegExp[] = [
      /ISO\s?\d{4,5}/i,
      /SOC\s?2/i,
      /\bGDPR\b/i,
      /\bHIPAA\b/i,
      /\bguarantee(d|s)?\b/i,
      /\d[\d,]*\+?\s*(customers|companies|students|learners|organizations|users)\b/i,
      /\b\d+(\.\d+)?\s?%/,
      /\b[45](\.\d)?\s*(stars?|\/\s?5|out of 5)/i,
      /#\s?1\b/,
      /\bworld'?s\s+best\b/i,
      /\bnumber\s+one\b/i,
    ];
    for (const s of allStrings) {
      for (const re of forbidden) {
        expect(re.test(s), `fabricated/unsupported claim in: "${s}" (${re})`).toBe(false);
      }
    }
  });

  it("routes every CTA to a real application route", () => {
    const ctas = [
      messaging.cta.primary,
      messaging.cta.secondary,
      ...messaging.personas.map((p) => p.primaryCta),
    ];
    for (const cta of ctas) {
      expect(cta.href.startsWith("/"), `not absolute path: ${cta.href}`).toBe(true);
      expect(REAL_ROUTES.has(cta.href), `unknown route: ${cta.href}`).toBe(true);
    }
  });

  it("marks all external-evidence proof slots as awaiting real content", () => {
    expect(messaging.proofSlots.length).toBeGreaterThan(0);
    for (const slot of messaging.proofSlots) {
      expect(slot.status).toBe("awaiting_real_content");
    }
  });

  it("exposes distinct personas (no duplicated problem/outcome copy)", () => {
    const problems = messaging.personas.map((p) => p.problem.en);
    const outcomes = messaging.personas.map((p) => p.outcome.en);
    expect(new Set(problems).size).toBe(problems.length);
    expect(new Set(outcomes).size).toBe(outcomes.length);
    expect(Object.keys(personaById).sort()).toEqual(
      ["academies", "companies", "instructors", "public_sector"],
    );
  });

  it("localized() resolves per locale with English fallback", () => {
    expect(localized(messaging.category, "en")).toBe(messaging.category.en);
    expect(localized(messaging.category, "ar")).toBe(messaging.category.ar);
  });
});
