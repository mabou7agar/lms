import { describe, expect, it } from "vitest";
import { formatVersionDate, shortChecksum } from "@/lib/authoring/versioning-format";

describe("shortChecksum", () => {
  it("takes the first 8 characters", () => {
    expect(shortChecksum("abcd1234ef567890")).toBe("abcd1234");
  });

  it("is safe for an empty checksum", () => {
    expect(shortChecksum("")).toBe("");
  });
});

describe("formatVersionDate", () => {
  it("formats a UTC date deterministically", () => {
    expect(formatVersionDate("2026-07-20T10:00:00Z")).toBe("2026-07-20 10:00 UTC");
  });

  it("returns an em dash for a missing or invalid date", () => {
    expect(formatVersionDate(null)).toBe("—");
    expect(formatVersionDate("not-a-date")).toBe("—");
  });
});
