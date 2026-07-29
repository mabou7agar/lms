import { describe, expect, it } from "vitest";
import {
  canManageMedia,
  formatBytes,
  formatDuration,
  mediaPhase,
  typeFromMime,
} from "@/lib/media/media-format";

describe("mediaPhase", () => {
  it("prefers is_ready over the status string", () => {
    expect(mediaPhase({ status: "processing", is_ready: true })).toBe("ready");
  });
  it("maps the lifecycle to UI phases", () => {
    expect(mediaPhase({ status: "created", is_ready: false })).toBe("awaiting");
    expect(mediaPhase({ status: "waiting_for_upload", is_ready: false })).toBe("awaiting");
    expect(mediaPhase({ status: "processing", is_ready: false })).toBe("processing");
    expect(mediaPhase({ status: "uploaded", is_ready: false })).toBe("processing");
    expect(mediaPhase({ status: "failed", is_ready: false })).toBe("failed");
  });
});

describe("formatting", () => {
  it("formats bytes", () => {
    expect(formatBytes(null)).toBe("—");
    expect(formatBytes(0)).toBe("0 B");
    expect(formatBytes(1024)).toBe("1 KB");
    expect(formatBytes(10 * 1024 * 1024)).toBe("10 MB");
  });
  it("formats duration", () => {
    expect(formatDuration(null)).toBe("—");
    expect(formatDuration(65)).toBe("1:05");
    expect(formatDuration(3661)).toBe("1:01:01");
  });
  it("infers type from mime", () => {
    expect(typeFromMime("video/mp4")).toBe("video");
    expect(typeFromMime("audio/mpeg")).toBe("audio");
    expect(typeFromMime("image/png")).toBe("image");
    expect(typeFromMime("application/pdf")).toBe("document");
  });
});

describe("canManageMedia", () => {
  it("permits managers and denies everyone else", () => {
    expect(canManageMedia({ roles: ["instructor"] })).toBe(true);
    expect(canManageMedia({ roles: ["admin"] })).toBe(true);
    expect(canManageMedia({ roles: ["student"] })).toBe(false);
    expect(canManageMedia(null)).toBe(false);
  });
});
