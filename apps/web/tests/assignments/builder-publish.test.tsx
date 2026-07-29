import { beforeEach, describe, expect, it, vi } from "vitest";
import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";

/**
 * Publish controls take the server's `publish_state` as the source of truth: they offer Publish for
 * anything unpublished and Unpublish only for a published assignment, and both actions confirm
 * before firing the mutation.
 */

const { usePublishAssignment, useUnpublishAssignment } = vi.hoisted(() => ({
  usePublishAssignment: vi.fn(),
  useUnpublishAssignment: vi.fn(),
}));

vi.mock("@/lib/assignments/assignments-hooks", () => ({
  usePublishAssignment,
  useUnpublishAssignment,
}));

const { toastError } = vi.hoisted(() => ({ toastError: vi.fn() }));
vi.mock("@/components/ui/toast", () => ({ toast: { success: vi.fn(), error: toastError } }));

import { PublishControls } from "@/components/assignments/builder/publish-controls";

function mutation(overrides: Record<string, unknown> = {}) {
  return { mutateAsync: vi.fn().mockResolvedValue({}), isPending: false, ...overrides };
}

beforeEach(() => {
  vi.clearAllMocks();
  usePublishAssignment.mockReturnValue(mutation());
  useUnpublishAssignment.mockReturnValue(mutation());
});

describe("PublishControls", () => {
  it("offers Publish and shows the draft state badge for a draft", () => {
    renderWithI18n(<PublishControls assignmentId="a1" publishState="draft" />);
    expect(screen.getByText("Draft")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Publish" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Unpublish" })).not.toBeInTheDocument();
  });

  it("offers Unpublish for a published assignment", () => {
    renderWithI18n(<PublishControls assignmentId="a1" publishState="published" />);
    expect(screen.getByText("Published")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Unpublish" })).toBeInTheDocument();
  });

  it("confirms then fires the publish mutation", async () => {
    const user = userEvent.setup();
    const publish = mutation();
    usePublishAssignment.mockReturnValue(publish);
    renderWithI18n(<PublishControls assignmentId="a1" publishState="draft" />);

    await user.click(screen.getByRole("button", { name: "Publish" }));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText("Publish this assignment?")).toBeInTheDocument();

    await user.click(within(dialog).getByRole("button", { name: "Publish" }));
    await waitFor(() => expect(publish.mutateAsync).toHaveBeenCalledTimes(1));
  });

  it("confirms then fires the unpublish mutation", async () => {
    const user = userEvent.setup();
    const unpublish = mutation();
    useUnpublishAssignment.mockReturnValue(unpublish);
    renderWithI18n(<PublishControls assignmentId="a1" publishState="published" />);

    await user.click(screen.getByRole("button", { name: "Unpublish" }));
    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: "Unpublish" }));
    await waitFor(() => expect(unpublish.mutateAsync).toHaveBeenCalledTimes(1));
  });

  it("reports a publish failure via toast", async () => {
    const user = userEvent.setup();
    usePublishAssignment.mockReturnValue(mutation({ mutateAsync: vi.fn().mockRejectedValue(new Error("x")) }));
    renderWithI18n(<PublishControls assignmentId="a1" publishState="draft" />);

    await user.click(screen.getByRole("button", { name: "Publish" }));
    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: "Publish" }));
    await waitFor(() => expect(toastError).toHaveBeenCalledWith("Couldn't change the publish state."));
  });
});
