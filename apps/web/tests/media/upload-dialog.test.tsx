import { beforeEach, describe, expect, it, vi } from "vitest";
import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";
import type { UploadItem } from "@/lib/media/media-hooks";

/**
 * The upload dialog renders the uploader's per-file state machine. It owns no upload logic itself
 * (that is the injectable transport + orchestrator), so here we drive it with a controlled uploader
 * and assert it surfaces progress, the ready state, and a retry on failure.
 */
const hooks = vi.hoisted(() => ({ useMediaUploader: vi.fn() }));
vi.mock("@/lib/media/media-hooks", () => hooks);

import { MediaUploadDialog } from "@/components/media/media-upload-dialog";

function item(overrides: Partial<UploadItem> = {}): UploadItem {
  return {
    id: "u1",
    file: new File(["x"], "lecture.mp4", { type: "video/mp4" }),
    phase: "uploading",
    progress: 50,
    error: null,
    asset: null,
    ...overrides,
  };
}

const enqueue = vi.fn();
const retry = vi.fn();
const remove = vi.fn();

function setup(items: UploadItem[]) {
  hooks.useMediaUploader.mockReturnValue({ items, enqueue, retry, remove, clear: vi.fn() });
}

beforeEach(() => vi.clearAllMocks());

describe("MediaUploadDialog", () => {
  it("shows byte progress while a file is uploading", () => {
    setup([item({ phase: "uploading", progress: 50 })]);
    renderWithI18n(<MediaUploadDialog open onOpenChange={vi.fn()} purpose="lesson_video" />);

    // The percentage is the progressbar's accessible name (label is aria-only, not a text node).
    expect(screen.getByRole("progressbar", { name: "Uploading 50%" })).toBeInTheDocument();
  });

  it("shows a ready state once processing resolves", () => {
    setup([item({ phase: "ready", progress: 100 })]);
    renderWithI18n(<MediaUploadDialog open onOpenChange={vi.fn()} purpose="lesson_video" />);

    expect(screen.getByText("Ready")).toBeInTheDocument();
  });

  it("shows a processing state", () => {
    setup([item({ phase: "processing" })]);
    renderWithI18n(<MediaUploadDialog open onOpenChange={vi.fn()} purpose="lesson_video" />);

    expect(screen.getByText("Processing…")).toBeInTheDocument();
  });

  it("surfaces a failure and retries on demand", async () => {
    setup([item({ phase: "failed", error: "Network error during upload." })]);
    const user = userEvent.setup();
    renderWithI18n(<MediaUploadDialog open onOpenChange={vi.fn()} purpose="lesson_video" />);

    expect(screen.getByRole("alert")).toHaveTextContent("Network error during upload.");
    await user.click(screen.getByRole("button", { name: "Retry" }));
    expect(retry).toHaveBeenCalledWith("u1");
  });

  it("enqueues files chosen through the file input", async () => {
    setup([]);
    const user = userEvent.setup();
    renderWithI18n(<MediaUploadDialog open onOpenChange={vi.fn()} purpose="lesson_video" />);

    const file = new File(["data"], "clip.mp4", { type: "video/mp4" });
    await user.upload(screen.getByLabelText("browse"), file);

    expect(enqueue).toHaveBeenCalledTimes(1);
    expect(enqueue.mock.calls[0][0][0]).toBe(file);
  });

  it("scopes the empty list message when nothing is queued", () => {
    setup([]);
    renderWithI18n(<MediaUploadDialog open onOpenChange={vi.fn()} purpose="lesson_video" />);
    expect(screen.getByText("No files selected yet.")).toBeInTheDocument();
  });

  it("keeps the dialog closed when open is false", () => {
    setup([item()]);
    renderWithI18n(<MediaUploadDialog open={false} onOpenChange={vi.fn()} purpose="lesson_video" />);
    // Dialog content is portalled only when open; the row must not be in the document.
    expect(screen.queryByText("lecture.mp4")).not.toBeInTheDocument();
  });
});
