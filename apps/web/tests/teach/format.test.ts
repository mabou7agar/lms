import { describe, expect, it } from "vitest";
import { formatDate, formatMetric, formatNumber, formatPercent } from "@/lib/teach/format";
import { available, unavailable } from "./fixtures";

describe("teach formatters", () => {
  it("groups numbers per locale", () => {
    expect(formatNumber(1234567, "en")).toBe("1,234,567");
    expect(formatNumber(1234567, "ar")).not.toBe("");
  });

  it("treats a backend percentage as whole units", () => {
    // The backend sends 42 meaning 42%. Intl's percent style multiplies by 100, so the value must
    // be divided first — otherwise 42 renders as 4,200%.
    expect(formatPercent(42, "en")).toBe("42%");
    expect(formatPercent(100, "en")).toBe("100%");
    expect(formatPercent(0, "en")).toBe("0%");
  });

  it("formats dates and tolerates a missing or malformed value", () => {
    expect(formatDate("2026-07-19T10:00:00+00:00", "en")).toMatch(/2026/);
    expect(formatDate(null, "en")).toBeNull();
    expect(formatDate("not-a-date", "en")).toBeNull();
  });

  it("returns null for an unavailable metric instead of a zero", () => {
    // Returning null forces every call site to decide what unavailable looks like, rather than
    // letting a 0 leak into the UI as if the backend had sent it.
    expect(formatMetric(unavailable("no data"), "number", "en")).toBeNull();
    expect(formatMetric(undefined, "number", "en")).toBeNull();
    expect(formatMetric({ value: null, available: true }, "number", "en")).toBeNull();
  });

  it("formats an available metric", () => {
    expect(formatMetric(available(1234), "number", "en")).toBe("1,234");
    expect(formatMetric(available(72), "percent", "en")).toBe("72%");
  });
});
