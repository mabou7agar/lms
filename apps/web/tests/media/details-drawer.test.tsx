import { beforeEach, describe, expect, it, vi } from "vitest";
import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";
import { ApiRequestError } from "@/lib/api/client";
import { makeAsset } from "./fixtures";

/**
 * The details drawer confirms before deleting, escalates to a force delete when the asset is still
 * in use (MediaInUseException / MEDIA_IN_USE), and surfaces a load error with retry.
 */
const hooks = vi.hoisted(() => ({
  useMediaAsset: vi.fn(),
  useDeleteMedia: vi.fn(),
  useRetryMedia: vi.fn(),
  useCaptions: vi.fn(),
  useAddCaption: vi.fn(),
  useDeleteCaption: vi.fn(),
}));
vi.mock("@/lib/media/media-hooks", () => hooks);

import { MediaDetailsDrawer } from "@/components/media/media-details-drawer";

const delMutate = vi.fn();

beforeEach(() => {
  vi.clearAllMocks();
  hooks.useDeleteMedia.mockReturnValue({ mutate: delMutate, isPending: false });
  hooks.useRetryMedia.mockReturnValue({ mutate: vi.fn(), isPending: false });
  hooks.useCaptions.mockReturnValue({ data: [], isPending: false, isError: false, refetch: vi.fn() });
  hooks.useAddCaption.mockReturnValue({ mutate: vi.fn(), isPending: false });
  hooks.useDeleteCaption.mockReturnValue({ mutate: vi.fn(), isPending: false });
});

function readyAsset() {
  hooks.useMediaAsset.mockReturnValue({
    data: makeAsset(),
    isPending: false,
    isError: false,
    refetch: vi.fn(),
  });
}

describe("MediaDetailsDrawer", () => {
  it("shows an error with retry when the asset fails to load", async () => {
    const refetch = vi.fn();
    hooks.useMediaAsset.mockReturnValue({ data: undefined, isPending: false, isError: true, refetch });
    const user = userEvent.setup();
    renderWithI18n(<MediaDetailsDrawer mediaId="m1" canManage onOpenChange={vi.fn()} />);

    expect(screen.getByRole("alert")).toHaveTextContent("Couldn't load your media library.");
    await user.click(screen.getByRole("button", { name: "Retry" }));
    expect(refetch).toHaveBeenCalled();
  });

  it("confirms before deleting and fires the mutation on confirm", async () => {
    readyAsset();
    const user = userEvent.setup();
    renderWithI18n(<MediaDetailsDrawer mediaId="m1" canManage onOpenChange={vi.fn()} />);

    await user.click(screen.getByRole("button", { name: "Delete media" }));
    const dialog = await screen.findByRole("dialog", { name: "Delete this media?" });
    await user.click(within(dialog).getByRole("button", { name: "Delete" }));

    expect(delMutate).toHaveBeenCalledWith({ id: "m1", force: false }, expect.anything());
  });

  it("escalates to a force delete when the asset is still in use", async () => {
    readyAsset();
    // First (non-force) delete reports MEDIA_IN_USE; the drawer must offer a force delete.
    delMutate.mockImplementation((_args, opts) => {
      opts?.onError?.(new ApiRequestError(409, "MEDIA_IN_USE", "Media is still attached."));
    });
    const user = userEvent.setup();
    renderWithI18n(<MediaDetailsDrawer mediaId="m1" canManage onOpenChange={vi.fn()} />);

    await user.click(screen.getByRole("button", { name: "Delete media" }));
    const dialog = await screen.findByRole("dialog", { name: "Delete this media?" });
    await user.click(within(dialog).getByRole("button", { name: "Delete" }));

    await waitFor(() => expect(screen.getByText("This media is still in use")).toBeInTheDocument());
    expect(screen.getByRole("button", { name: "Force delete" })).toBeInTheDocument();
  });

  it("hides destructive actions from a non-manager", () => {
    readyAsset();
    renderWithI18n(<MediaDetailsDrawer mediaId="m1" canManage={false} onOpenChange={vi.fn()} />);
    expect(screen.queryByRole("button", { name: "Delete media" })).not.toBeInTheDocument();
  });
});
