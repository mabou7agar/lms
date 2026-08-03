import { describe, expect, it } from "vitest";
import { comparisons, competitorSlugs, getCompetitor, type Localized } from "@/config/comparison";

function pairs(node: unknown, out: Localized[] = []): Localized[] {
  if (node && typeof node === "object") {
    const obj = node as Record<string, unknown>;
    if (typeof obj.en === "string" && typeof obj.ar === "string") {
      out.push(obj as unknown as Localized);
      return out;
    }
    for (const v of Object.values(obj)) pairs(v, out);
  }
  return out;
}

describe("competitor comparison data", () => {
  it("includes Moodle and Thinkific", () => {
    expect(competitorSlugs.sort()).toEqual(["moodle", "thinkific"]);
    expect(getCompetitor("moodle")?.name).toBe("Moodle");
    expect(getCompetitor("unknown")).toBeNull();
  });

  it("carries honest guidance and a review date for every competitor", () => {
    for (const c of Object.values(comparisons)) {
      expect(c.bestFor.en.length).toBeGreaterThan(0);
      expect(c.helbaronBestFor.en.length).toBeGreaterThan(0);
      expect(c.bestFor.ar.length).toBeGreaterThan(0);
      expect(/^\d{4}-\d{2}-\d{2}$/.test(c.lastReviewed), `bad date: ${c.lastReviewed}`).toBe(true);
      expect(c.rows.length).toBeGreaterThan(2);
    }
  });

  it("requires a note whenever a competitor cell is `varies`", () => {
    for (const c of Object.values(comparisons)) {
      for (const row of c.rows) {
        for (const cell of [row.helbaron, row.competitor]) {
          if (cell.support === "varies") {
            expect(cell.note, `varies without note in ${c.slug}/${row.id}`).toBeDefined();
            expect(cell.note?.en.length ?? 0).toBeGreaterThan(0);
            expect(cell.note?.ar.length ?? 0).toBeGreaterThan(0);
          }
        }
      }
    }
  });

  it("has EN/AR parity and no prices or defamatory/absolute-superiority language", () => {
    const forbidden: RegExp[] = [
      /\$|€|£|﷼|ر\.?س|\bUSD\b|\bEUR\b/i, // no prices
      /\/\s?mo\b/i,
      /\bworst\b/i,
      /\boutdated\b/i,
      /\bterrible\b/i,
      /\bclunky\b/i,
      /\bthe\s+best\b/i,
      /#\s?1\b/,
      /\bnumber\s+one\b/i,
    ];
    for (const c of Object.values(comparisons)) {
      for (const pair of pairs(c)) {
        expect(pair.en.trim().length).toBeGreaterThan(0);
        expect(pair.ar.trim().length).toBeGreaterThan(0);
        expect(/[؀-ۿ]/.test(pair.ar), `AR not Arabic: ${pair.ar}`).toBe(true);
        for (const re of [...forbidden]) {
          expect(re.test(pair.en), `disallowed in EN: "${pair.en}" (${re})`).toBe(false);
          expect(re.test(pair.ar), `disallowed in AR: "${pair.ar}" (${re})`).toBe(false);
        }
      }
    }
  });
});
