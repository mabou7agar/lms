import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { renderWithI18n } from "../render";

const hooks = vi.hoisted(() => ({
  usePublishCourse: vi.fn(),
  useUnpublishCourse: vi.fn(),
  useArchiveCourse: vi.fn(),
}));
vi.mock("@/lib/teach/hooks", () => hooks);

const toastMock = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }));
vi.mock("@/components/ui/toast", () => ({ toast: toastMock }));

import { CourseActionsMenu } from "@/components/teach/course-actions-menu";
import { ApiRequestError } from "@/lib/api/client";

const settled = (impl?: () => Promise<unknown>) => ({
  mutateAsync: vi.fn(impl ?? (() => Promise.resolve({}))),
  isPending: false,
});

function render(status: "draft" | "published" | "archived" = "draft", onReview = vi.fn()) {
  renderWithI18n(
    <CourseActionsMenu
      courseId="crs-1"
      title="Advanced Laravel"
      status={status}
      onReviewReadiness={onReview}
      onViewChanges={vi.fn()}
    />,
  );
  return onReview;
}

async function openMenu(user: ReturnType<typeof userEvent.setup>) {
  await user.click(screen.getByRole("button", { name: /Course actions/ }));
}

describe("CourseActionsMenu", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    hooks.usePublishCourse.mockReturnValue(settled());
    hooks.useUnpublishCourse.mockReturnValue(settled());
    hooks.useArchiveCourse.mockReturnValue(settled());
  });

  it("offers publish for a draft and hides unpublish", async () => {
    const user = userEvent.setup();
    render("draft");
    await openMenu(user);

    expect(await screen.findByRole("menuitem", { name: /Publish/ })).toBeInTheDocument();
    expect(screen.queryByRole("menuitem", { name: /Unpublish/ })).not.toBeInTheDocument();
  });

  it("offers unpublish for a published course and hides publish", async () => {
    const user = userEvent.setup();
    render("published");
    await openMenu(user);

    expect(await screen.findByRole("menuitem", { name: /Unpublish/ })).toBeInTheDocument();
    expect(screen.queryByRole("menuitem", { name: /^Publish$/ })).not.toBeInTheDocument();
  });

  it("hides archive for an already archived course", async () => {
    const user = userEvent.setup();
    render("archived");
    await openMenu(user);

    expect(screen.queryByRole("menuitem", { name: /Archive/ })).not.toBeInTheDocument();
  });

  it("requires confirmation before publishing", async () => {
    const user = userEvent.setup();
    const publish = settled();
    hooks.usePublishCourse.mockReturnValue(publish);
    render("draft");

    await openMenu(user);
    await user.click(await screen.findByRole("menuitem", { name: /Publish/ }));

    // Dialog is open; nothing has been sent yet.
    expect(await screen.findByRole("dialog")).toBeInTheDocument();
    expect(publish.mutateAsync).not.toHaveBeenCalled();
  });

  it("publishes and reports success only after the server confirms", async () => {
    const user = userEvent.setup();
    const publish = settled();
    hooks.usePublishCourse.mockReturnValue(publish);
    render("draft");

    await openMenu(user);
    await user.click(await screen.findByRole("menuitem", { name: /Publish/ }));
    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: /^Publish$/ }));

    await waitFor(() => expect(publish.mutateAsync).toHaveBeenCalledWith("crs-1"));
    await waitFor(() => expect(toastMock.success).toHaveBeenCalledWith("Course published."));
  });

  it("surfaces the backend message and a readiness shortcut when publishing is refused", async () => {
    const user = userEvent.setup();
    const onReview = vi.fn();
    hooks.usePublishCourse.mockReturnValue(
      settled(() =>
        Promise.reject(
          new ApiRequestError(422, "COURSE_PUBLISH_BLOCKED", "The course has no sections.", {
            blockers: [{ code: "course.no_sections" }],
          }),
        ),
      ),
    );
    render("draft", onReview);

    await openMenu(user);
    await user.click(await screen.findByRole("menuitem", { name: /Publish/ }));
    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: /^Publish$/ }));

    await waitFor(() =>
      expect(toastMock.error).toHaveBeenCalledWith(
        "The course has no sections.",
        expect.objectContaining({ action: expect.objectContaining({ label: "Review readiness" }) }),
      ),
    );
    expect(toastMock.success).not.toHaveBeenCalled();
  });

  it("does not report success when the action fails", async () => {
    const user = userEvent.setup();
    hooks.useArchiveCourse.mockReturnValue(settled(() => Promise.reject(new Error("nope"))));
    render("draft");

    await openMenu(user);
    await user.click(await screen.findByRole("menuitem", { name: /Archive/ }));
    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: /^Archive$/ }));

    await waitFor(() => expect(toastMock.error).toHaveBeenCalled());
    expect(toastMock.success).not.toHaveBeenCalled();
  });
});
