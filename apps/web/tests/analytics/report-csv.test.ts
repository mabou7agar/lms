import { describe, expect, it } from "vitest";
import { reportToCsv } from "@/lib/reports/csv";

describe("reportToCsv", () => {
  it("exports the summary as metric/value rows", () => {
    const csv = reportToCsv({ summary: { orders: 3, gross_revenue_minor: 287500 } });

    expect(csv).toContain("summary\nmetric,value\norders,3\ngross_revenue_minor,287500");
  });

  it("keeps money in minor units so the file can be summed", () => {
    const csv = reportToCsv({ summary: { gross_revenue_minor: 287500 } });

    // Not "SAR 2,875.00" — the screen formats, the export does not.
    expect(csv).toContain("gross_revenue_minor,287500");
    expect(csv).not.toContain("SAR");
  });

  it("gives every object array its own table", () => {
    const csv = reportToCsv({
      top_courses: [{ course: "Leading Teams", units: 12 }],
      top_bundles: [{ bundle: "Manager Pack", units: 2 }],
    });

    expect(csv).toContain("top_courses\ncourse,units\nLeading Teams,12");
    expect(csv).toContain("top_bundles\nbundle,units\nManager Pack,2");
  });

  it("lines up rows that do not all carry the same keys", () => {
    const csv = reportToCsv({ rows: [{ a: 1 }, { a: 2, b: 3 }] });

    expect(csv).toContain("rows\na,b\n1,\n2,3");
  });

  it("escapes commas, quotes and newlines", () => {
    const csv = reportToCsv({ rows: [{ name: 'Ops, "Core"', note: "line\nbreak" }] });

    expect(csv).toContain('"Ops, ""Core""","line\nbreak"');
  });

  it("defuses a value a spreadsheet would run as a formula", () => {
    const csv = reportToCsv({ rows: [{ term: "=1+1" }] });

    expect(csv).toContain("'=1+1");
  });

  it("records the range the figures were computed over", () => {
    const csv = reportToCsv({ summary: { orders: 1 } }, { from: "2026-01-01", to: "2026-06-30" });

    expect(csv.startsWith("range_from,2026-01-01\nrange_to,2026-06-30")).toBe(true);
  });

  it("skips sections the report did not return", () => {
    expect(reportToCsv({ tracking_since: null })).toBe("");
  });
});
