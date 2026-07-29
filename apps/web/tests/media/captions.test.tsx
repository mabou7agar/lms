import { beforeEach, describe, expect, it, vi } from "vitest";
import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";
import { makeCaption } from "./fixtures";

/**
 * The caption manager validates before calling the API (language + label are required, mirroring
 * StoreCaptionRequest), lists existing tracks, and confirms before removing one.
 */
const hooks = vi.hoisted(() => ({
  useCaptions: vi.fn(),
  useAddCaption: vi.fn(),
  useDeleteCaption: vi.fn(),
}));
vi.mock("@/lib/media/media-hooks", () => hooks);

import { CaptionManager } from "@/components/media/caption-manager";

const addMutate = vi.fn();
const removeMutate = vi.fn();

beforeEach(() => {
  vi.clearAllMocks();
  hooks.useAddCaption.mockReturnValue({ mutate: addMutate, isPending: false });
  hooks.useDeleteCaption.mockReturnValue({ mutate: removeMutate, isPending: false });
  hooks.useCaptions.mockReturnValue({ data: [], isPending: false, isError: false, refetch: vi.fn() });
});

describe("CaptionManager validation", () => {
  it("blocks submission without a language", async () => {
    const user = userEvent.setup();
    renderWithI18n(<CaptionManager mediaId="m1" canManage />);

    await user.click(screen.getByRole("button", { name: "Add caption" }));
    expect(screen.getByRole("alert")).toHaveTextContent("A BCP-47 language tag is required.");
    expect(addMutate).not.toHaveBeenCalled();
  });

  it("blocks submission without a label", async () => {
    const user = userEvent.setup();
    renderWithI18n(<CaptionManager mediaId="m1" canManage />);

    await user.type(screen.getByLabelText("Language"), "en");
    await user.click(screen.getByRole("button", { name: "Add caption" }));
    expect(screen.getByRole("alert")).toHaveTextContent("A label is required.");
    expect(addMutate).not.toHaveBeenCalled();
  });

  it("submits a valid caption", async () => {
    const user = userEvent.setup();
    renderWithI18n(<CaptionManager mediaId="m1" canManage />);

    await user.type(screen.getByLabelText("Language"), "en-US");
    await user.type(screen.getByLabelText("Label"), "English");
    await user.click(screen.getByRole("button", { name: "Add caption" }));

    expect(addMutate).toHaveBeenCalledWith(
      { language: "en-US", label: "English", format: "vtt" },
      expect.anything(),
    );
  });
});

describe("CaptionManager list", () => {
  it("lists existing tracks", () => {
    hooks.useCaptions.mockReturnValue({
      data: [makeCaption({ label: "English", language: "en" })],
      isPending: false,
      isError: false,
      refetch: vi.fn(),
    });
    renderWithI18n(<CaptionManager mediaId="m1" canManage />);
    expect(screen.getByText("English")).toBeInTheDocument();
  });

  it("confirms before removing a track", async () => {
    hooks.useCaptions.mockReturnValue({
      data: [makeCaption({ id: "cap9", label: "English" })],
      isPending: false,
      isError: false,
      refetch: vi.fn(),
    });
    const user = userEvent.setup();
    renderWithI18n(<CaptionManager mediaId="m1" canManage />);

    await user.click(screen.getByRole("button", { name: "Remove this caption?" }));
    const dialog = await screen.findByRole("dialog", { name: "Remove this caption?" });
    await user.click(within(dialog).getByRole("button", { name: "Remove" }));

    expect(removeMutate).toHaveBeenCalledWith("cap9", expect.anything());
  });

  it("surfaces a load error with retry", async () => {
    const refetch = vi.fn();
    hooks.useCaptions.mockReturnValue({ data: undefined, isPending: false, isError: true, refetch });
    const user = userEvent.setup();
    renderWithI18n(<CaptionManager mediaId="m1" canManage />);

    expect(screen.getByRole("alert")).toHaveTextContent("Couldn't load captions.");
    await user.click(screen.getByRole("button", { name: "Retry" }));
    expect(refetch).toHaveBeenCalled();
  });
});

describe("CaptionManager permissions", () => {
  it("hides the add form and delete actions from a non-manager", () => {
    hooks.useCaptions.mockReturnValue({
      data: [makeCaption({ label: "English" })],
      isPending: false,
      isError: false,
      refetch: vi.fn(),
    });
    renderWithI18n(<CaptionManager mediaId="m1" canManage={false} />);

    expect(screen.queryByRole("button", { name: "Add caption" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Remove this caption?" })).not.toBeInTheDocument();
  });
});
