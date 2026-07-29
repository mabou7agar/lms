import { beforeEach, describe, expect, it, vi } from "vitest";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";
import { makeAsset, makePage } from "./fixtures";

/**
 * The library surface: it must render every lifecycle state deterministically and must hide manage
 * actions from a viewer without permission (mirroring MediaAssetPolicy server-side).
 */
const hooks = vi.hoisted(() => ({
  useMediaLibrary: vi.fn(),
  useRetryMedia: vi.fn(),
  useMediaUploader: vi.fn(),
  useMediaAsset: vi.fn(),
  useDeleteMedia: vi.fn(),
  useCaptions: vi.fn(),
  useAddCaption: vi.fn(),
  useDeleteCaption: vi.fn(),
}));
vi.mock("@/lib/media/media-hooks", () => hooks);

const authMock = vi.hoisted(() => ({ useAuth: vi.fn() }));
vi.mock("@/lib/auth/auth-context", () => authMock);

import { MediaLibraryPanel } from "@/components/media/media-library-panel";

function setUser(roles: string[]) {
  authMock.useAuth.mockReturnValue({ user: { id: "u1", roles }, status: "authenticated" });
}

beforeEach(() => {
  vi.clearAllMocks();
  setUser(["instructor"]);
  hooks.useRetryMedia.mockReturnValue({ mutate: vi.fn(), isPending: false, variables: undefined });
  hooks.useMediaUploader.mockReturnValue({ items: [], enqueue: vi.fn(), retry: vi.fn(), remove: vi.fn(), clear: vi.fn() });
  hooks.useMediaAsset.mockReturnValue({ data: undefined, isPending: false, isError: false, refetch: vi.fn() });
  hooks.useDeleteMedia.mockReturnValue({ mutate: vi.fn(), isPending: false });
  hooks.useCaptions.mockReturnValue({ data: [], isPending: false, isError: false, refetch: vi.fn() });
  hooks.useAddCaption.mockReturnValue({ mutate: vi.fn(), isPending: false });
  hooks.useDeleteCaption.mockReturnValue({ mutate: vi.fn(), isPending: false });
});

describe("MediaLibraryPanel states", () => {
  it("shows a single loading status while the first page loads", () => {
    hooks.useMediaLibrary.mockReturnValue({ isPending: true, isError: false });
    renderWithI18n(<MediaLibraryPanel />);
    expect(screen.getByRole("status")).toBeInTheDocument();
  });

  it("shows an error with a retry that refetches", async () => {
    const refetch = vi.fn();
    hooks.useMediaLibrary.mockReturnValue({ isPending: false, isError: true, refetch });
    const user = userEvent.setup();
    renderWithI18n(<MediaLibraryPanel />);

    expect(screen.getByRole("alert")).toHaveTextContent("Couldn't load your media library.");
    await user.click(screen.getByRole("button", { name: "Retry" }));
    expect(refetch).toHaveBeenCalled();
  });

  it("shows an empty state when there is no media", () => {
    hooks.useMediaLibrary.mockReturnValue({ isPending: false, isError: false, data: makePage([]) });
    renderWithI18n(<MediaLibraryPanel />);
    expect(screen.getByText(/No media yet/)).toBeInTheDocument();
  });

  it("renders a ready asset with a Ready badge", () => {
    hooks.useMediaLibrary.mockReturnValue({
      isPending: false,
      isError: false,
      data: makePage([makeAsset({ original_filename: "intro.mp4" })]),
    });
    renderWithI18n(<MediaLibraryPanel />);
    expect(screen.getByText("intro.mp4")).toBeInTheDocument();
    // The status badge is a div; the same word also labels a filter button, so select the badge.
    expect(screen.getByText("Ready", { selector: "div" })).toBeInTheDocument();
  });

  it("renders a processing asset with a progress bar", () => {
    hooks.useMediaLibrary.mockReturnValue({
      isPending: false,
      isError: false,
      data: makePage([makeAsset({ status: "processing", is_ready: false, processing_progress: 40 })]),
    });
    renderWithI18n(<MediaLibraryPanel />);
    expect(screen.getByText("Processing", { selector: "div" })).toBeInTheDocument();
    expect(screen.getByRole("progressbar")).toBeInTheDocument();
  });
});

describe("MediaLibraryPanel permissions", () => {
  it("offers upload to a manager", () => {
    hooks.useMediaLibrary.mockReturnValue({ isPending: false, isError: false, data: makePage([]) });
    setUser(["instructor"]);
    renderWithI18n(<MediaLibraryPanel />);
    expect(screen.getByRole("button", { name: "Upload media" })).toBeInTheDocument();
  });

  it("hides upload from a viewer without a manage role", () => {
    hooks.useMediaLibrary.mockReturnValue({ isPending: false, isError: false, data: makePage([]) });
    setUser(["student"]);
    renderWithI18n(<MediaLibraryPanel />);
    expect(screen.queryByRole("button", { name: "Upload media" })).not.toBeInTheDocument();
  });
});
