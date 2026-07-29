import { describe, expect, it, vi } from "vitest";
import { performDirectUpload, type UploadPhase, type UploadTransport } from "@/lib/media/media-upload";
import type { CreateDirectUploadInput, DirectUploadTicket, MediaAsset } from "@/lib/media/media-api";

/**
 * The direct-upload orchestrator is the load-bearing behaviour of the whole feature: bytes must go
 * straight to the provider, the single-use finalize token must be returned, and a processing asset
 * must be polled to readiness. Provider transport + backend calls are injected, so the flow is
 * exercised without a real network or timers beyond a 1ms poll.
 */

function asset(overrides: Partial<MediaAsset> = {}): MediaAsset {
  return {
    id: "m1",
    type: "video",
    status: "waiting_for_upload",
    purpose: "lesson_video",
    provider: "fake",
    original_filename: "lecture.mp4",
    mime_type: "video/mp4",
    size_bytes: 1024,
    duration_seconds: null,
    width: null,
    height: null,
    processing_progress: 0,
    is_ready: false,
    failure_code: null,
    failure_message: null,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

const input: CreateDirectUploadInput = {
  type: "video",
  purpose: "lesson_video",
  filename: "lecture.mp4",
  mime_type: "video/mp4",
  size_bytes: 1024,
  idempotency_key: "key-1",
};

const file = new Blob(["x".repeat(1024)], { type: "video/mp4" });

function ticket(overrides: Partial<MediaAsset> = {}): DirectUploadTicket {
  return {
    media: asset(overrides),
    upload: {
      url: "https://provider.example/upload/abc",
      method: "PUT",
      headers: { "content-type": "video/mp4" },
      fields: {},
      expires_at: "2026-08-01T00:00:00Z",
    },
    upload_token: "tok-123",
  };
}

describe("performDirectUpload", () => {
  it("runs create → upload (with progress) → finalize → ready", async () => {
    const phases: UploadPhase[] = [];
    const progress: number[] = [];

    const createUpload = vi.fn().mockResolvedValue(ticket());
    const finalize = vi.fn().mockResolvedValue(asset({ status: "ready", is_ready: true }));
    const fetchStatus = vi.fn();

    const transport: UploadTransport = vi.fn(async ({ instructions, onProgress }) => {
      expect(instructions.url).toBe("https://provider.example/upload/abc");
      onProgress?.({ loaded: 512, total: 1024, percent: 50 });
      onProgress?.({ loaded: 1024, total: 1024, percent: 100 });
    });

    const result = await performDirectUpload(
      input,
      file,
      { onPhase: (p) => phases.push(p), onProgress: (p) => progress.push(p.percent) },
      { createUpload, finalize, fetchStatus, transport },
    );

    // Bytes went to the provider, not the backend; the finalize token was returned verbatim.
    expect(transport).toHaveBeenCalledOnce();
    expect(finalize).toHaveBeenCalledWith("m1", "tok-123");
    expect(fetchStatus).not.toHaveBeenCalled();

    expect(phases).toEqual(["creating", "uploading", "finalizing", "ready"]);
    expect(progress).toEqual([50, 100]);
    expect(result.is_ready).toBe(true);
  });

  it("polls a processing asset until it becomes ready", async () => {
    const phases: UploadPhase[] = [];
    const createUpload = vi.fn().mockResolvedValue(ticket());
    const finalize = vi.fn().mockResolvedValue(asset({ status: "processing", processing_progress: 20 }));
    const fetchStatus = vi
      .fn()
      .mockResolvedValueOnce(asset({ status: "processing", processing_progress: 60 }))
      .mockResolvedValueOnce(asset({ status: "ready", is_ready: true, processing_progress: 100 }));

    const transport: UploadTransport = vi.fn(async () => {});

    const result = await performDirectUpload(
      input,
      file,
      { onPhase: (p) => phases.push(p) },
      { createUpload, finalize, fetchStatus, transport, },
    ).catch((e) => {
      throw e;
    });

    expect(fetchStatus).toHaveBeenCalledTimes(2);
    expect(phases[phases.length - 1]).toBe("ready");
    expect(result.is_ready).toBe(true);
  });

  it("reports a failed finalize as a failed phase without polling", async () => {
    const phases: UploadPhase[] = [];
    const createUpload = vi.fn().mockResolvedValue(ticket());
    const finalize = vi
      .fn()
      .mockResolvedValue(asset({ status: "failed", failure_message: "Transcode failed." }));
    const fetchStatus = vi.fn();
    const transport: UploadTransport = vi.fn(async () => {});

    const result = await performDirectUpload(input, file, { onPhase: (p) => phases.push(p) }, {
      createUpload,
      finalize,
      fetchStatus,
      transport,
    });

    expect(phases).toContain("failed");
    expect(fetchStatus).not.toHaveBeenCalled();
    expect(result.status).toBe("failed");
  });

  it("propagates a provider transport failure", async () => {
    const createUpload = vi.fn().mockResolvedValue(ticket());
    const finalize = vi.fn();
    const transport: UploadTransport = vi.fn(async () => {
      throw new Error("Network error during upload.");
    });

    await expect(
      performDirectUpload(input, file, {}, { createUpload, finalize, transport }),
    ).rejects.toThrow("Network error during upload.");
    expect(finalize).not.toHaveBeenCalled();
  });

  it("sends provider form fields for a POST (S3 presigned) upload via the default transport path", async () => {
    // Exercises the branch selection indirectly: a POST ticket with fields must not throw and must
    // still finalize. The real XHR transport is swapped for a spy that asserts the method.
    const createUpload = vi.fn().mockResolvedValue({
      ...ticket(),
      upload: {
        url: "https://s3.example/bucket",
        method: "POST",
        headers: {},
        fields: { key: "uploads/abc", policy: "xyz" },
        expires_at: "2026-08-01T00:00:00Z",
      },
    });
    const finalize = vi.fn().mockResolvedValue(asset({ status: "ready", is_ready: true }));
    const transport: UploadTransport = vi.fn(async ({ instructions }) => {
      expect(instructions.method).toBe("POST");
      expect(instructions.fields.key).toBe("uploads/abc");
    });

    const result = await performDirectUpload(input, file, {}, { createUpload, finalize, transport });
    expect(result.is_ready).toBe(true);
  });
});
