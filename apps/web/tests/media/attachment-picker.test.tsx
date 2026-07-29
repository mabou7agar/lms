import { beforeEach, describe, expect, it, vi } from "vitest";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";
import { makeAsset, makePage } from "./fixtures";

/**
 * The picker is the authoring-side seam for attaching an existing asset. Only ready assets are
 * offered; selection returns the asset (and, when given an attach context, attaches it first).
 */
const hooks = vi.hoisted(() => ({ useMediaLibrary: vi.fn(), useAttachMedia: vi.fn() }));
vi.mock("@/lib/media/media-hooks", () => hooks);

import { MediaAttachmentPicker } from "@/components/media/media-attachment-picker";

const attachMutate = vi.fn();

beforeEach(() => {
  vi.clearAllMocks();
  hooks.useAttachMedia.mockReturnValue({ mutate: attachMutate, isPending: false, variables: undefined });
});

describe("MediaAttachmentPicker", () => {
  it("requests only ready assets", () => {
    hooks.useMediaLibrary.mockReturnValue({ isPending: false, isError: false, data: makePage([]) });
    renderWithI18n(<MediaAttachmentPicker open onOpenChange={vi.fn()} onSelect={vi.fn()} type="video" />);

    expect(hooks.useMediaLibrary).toHaveBeenCalledWith(
      expect.objectContaining({ status: "ready", type: "video" }),
      true,
    );
  });

  it("returns the chosen asset and closes", async () => {
    const asset = makeAsset({ id: "m9", original_filename: "clip.mp4" });
    hooks.useMediaLibrary.mockReturnValue({ isPending: false, isError: false, data: makePage([asset]) });
    const onSelect = vi.fn();
    const onOpenChange = vi.fn();
    const user = userEvent.setup();

    renderWithI18n(<MediaAttachmentPicker open onOpenChange={onOpenChange} onSelect={onSelect} />);

    await user.click(screen.getByRole("button", { name: "Select" }));
    expect(onSelect).toHaveBeenCalledWith(asset);
    expect(onOpenChange).toHaveBeenCalledWith(false);
    expect(attachMutate).not.toHaveBeenCalled();
  });

  it("attaches through the backend when an attach context is provided", async () => {
    const asset = makeAsset({ id: "m9" });
    hooks.useMediaLibrary.mockReturnValue({ isPending: false, isError: false, data: makePage([asset]) });
    const user = userEvent.setup();

    renderWithI18n(
      <MediaAttachmentPicker
        open
        onOpenChange={vi.fn()}
        onSelect={vi.fn()}
        attachTo={{ attachable_type: "Lesson", attachable_id: 42, role: "primary" }}
      />,
    );

    await user.click(screen.getByRole("button", { name: "Select" }));
    expect(attachMutate).toHaveBeenCalledWith(
      expect.objectContaining({
        id: "m9",
        input: expect.objectContaining({ attachable_type: "Lesson", attachable_id: 42, role: "primary" }),
      }),
      expect.anything(),
    );
  });

  it("shows an empty state when no ready media exists", () => {
    hooks.useMediaLibrary.mockReturnValue({ isPending: false, isError: false, data: makePage([]) });
    renderWithI18n(<MediaAttachmentPicker open onOpenChange={vi.fn()} onSelect={vi.fn()} />);
    expect(screen.getByText("No ready media available.")).toBeInTheDocument();
  });

  it("shows an error with retry", async () => {
    const refetch = vi.fn();
    hooks.useMediaLibrary.mockReturnValue({ isPending: false, isError: true, refetch });
    const user = userEvent.setup();
    renderWithI18n(<MediaAttachmentPicker open onOpenChange={vi.fn()} onSelect={vi.fn()} />);

    expect(screen.getByRole("alert")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Retry" }));
    expect(refetch).toHaveBeenCalled();
  });
});
