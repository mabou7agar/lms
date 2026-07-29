import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { renderWithI18n } from "../render";
import { failed, ok, page, pending, performanceRow } from "./fixtures";

const hooks = vi.hoisted(() => ({
  useCoursePerformance: vi.fn(),
  usePublishCourse: vi.fn(),
  useUnpublishCourse: vi.fn(),
  useArchiveCourse: vi.fn(),
}));
vi.mock("@/lib/teach/hooks", () => hooks);

import { CoursePerformanceSection } from "@/components/teach/course-performance-section";

const noop = vi.fn();
const mutation = () => ({ mutateAsync: vi.fn().mockResolvedValue({}), isPending: false });

function render(props: Partial<{ onReviewReadiness: (id: string) => void }> = {}) {
  return renderWithI18n(
    <CoursePerformanceSection
      onReviewReadiness={props.onReviewReadiness ?? noop}
      onViewChanges={noop}
    />,
  );
}

describe("CoursePerformanceSection", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    hooks.usePublishCourse.mockReturnValue(mutation());
    hooks.useUnpublishCourse.mockReturnValue(mutation());
    hooks.useArchiveCourse.mockReturnValue(mutation());
  });

  it("renders a row per course with its metrics", () => {
    hooks.useCoursePerformance.mockReturnValue(ok(page([performanceRow()])));
    render();

    expect(screen.getByRole("link", { name: "Advanced Laravel" })).toBeInTheDocument();
    expect(screen.getByText("50")).toBeInTheDocument();
    expect(screen.getByText("30%")).toBeInTheDocument();
  });

  it("labels the table for screen readers", () => {
    hooks.useCoursePerformance.mockReturnValue(ok(page([performanceRow()])));
    render();

    expect(screen.getByText("Performance for each course you teach.")).toBeInTheDocument();
  });

  it("shows Unavailable in a metric cell rather than a zero", () => {
    hooks.useCoursePerformance.mockReturnValue(ok(page([performanceRow()])));
    render();

    expect(screen.getByText("Unavailable")).toBeInTheDocument();
  });

  it("sends only whitelisted sort fields", async () => {
    const user = userEvent.setup();
    hooks.useCoursePerformance.mockReturnValue(ok(page([performanceRow()])));
    render();

    await user.click(screen.getByRole("button", { name: /^Course$/ }));

    await waitFor(() => {
      const lastCall = hooks.useCoursePerformance.mock.calls.at(-1)?.[0];
      expect(lastCall.sort).toBe("title");
      expect(["asc", "desc"]).toContain(lastCall.direction);
    });
  });

  it("exposes sort direction to assistive technology", async () => {
    const user = userEvent.setup();
    hooks.useCoursePerformance.mockReturnValue(ok(page([performanceRow()])));
    render();

    await user.click(screen.getByRole("button", { name: /^Course$/ }));

    await waitFor(() => {
      const header = screen.getByRole("columnheader", { name: /Course/ });
      expect(header).toHaveAttribute("aria-sort", "ascending");
    });
  });

  it("debounces the search before querying", async () => {
    const user = userEvent.setup();
    hooks.useCoursePerformance.mockReturnValue(ok(page([performanceRow()])));
    render();

    await user.type(screen.getByLabelText("Search"), "Laravel");

    // Typed immediately, but the filter only reaches the query after the debounce window.
    expect(hooks.useCoursePerformance.mock.calls.at(-1)?.[0].search).toBeUndefined();

    await waitFor(
      () => expect(hooks.useCoursePerformance.mock.calls.at(-1)?.[0].search).toBe("Laravel"),
      { timeout: 2000 },
    );
  });

  it("passes the status filter through", async () => {
    const user = userEvent.setup();
    hooks.useCoursePerformance.mockReturnValue(ok(page([performanceRow()])));
    render();

    await user.click(screen.getByLabelText("Status"));
    await user.click(await screen.findByRole("option", { name: "Draft" }));

    await waitFor(() =>
      expect(hooks.useCoursePerformance.mock.calls.at(-1)?.[0].status).toBe("draft"),
    );
  });

  it("resets to page one when a filter changes", async () => {
    const user = userEvent.setup();
    hooks.useCoursePerformance.mockReturnValue(
      ok(page([performanceRow()], { current_page: 3, last_page: 5, total: 60 })),
    );
    render();

    await user.click(screen.getByRole("button", { name: /next/i }));
    await user.click(screen.getByLabelText("Status"));
    await user.click(await screen.findByRole("option", { name: "Draft" }));

    // Page 4 of a filtered list is usually empty, which reads as "no results" when there are plenty.
    await waitFor(() => expect(hooks.useCoursePerformance.mock.calls.at(-1)?.[0].page).toBe(1));
  });

  it("shows pagination only when there is more than one page", () => {
    hooks.useCoursePerformance.mockReturnValue(ok(page([performanceRow()])));
    const { unmount } = render();
    expect(screen.queryByRole("navigation", { name: /pagination/i })).not.toBeInTheDocument();
    unmount();

    hooks.useCoursePerformance.mockReturnValue(
      ok(page([performanceRow()], { last_page: 4, total: 60 })),
    );
    render();
    expect(screen.getByRole("navigation", { name: /pagination/i })).toBeInTheDocument();
  });

  it("sends the chosen per-page value", async () => {
    const user = userEvent.setup();
    hooks.useCoursePerformance.mockReturnValue(ok(page([performanceRow()])));
    render();

    await user.click(screen.getByLabelText("Per page"));
    await user.click(await screen.findByRole("option", { name: "25" }));

    await waitFor(() =>
      expect(hooks.useCoursePerformance.mock.calls.at(-1)?.[0].per_page).toBe(25),
    );
  });

  it("renders an empty state rather than a bare table", () => {
    hooks.useCoursePerformance.mockReturnValue(ok(page([])));
    render();

    expect(screen.getByText("No courses match these filters.")).toBeInTheDocument();
  });

  it("keeps the filters usable when the table itself fails", () => {
    hooks.useCoursePerformance.mockReturnValue(failed("Table exploded"));
    render();

    expect(screen.getByRole("button", { name: /try again/i })).toBeInTheDocument();
    expect(screen.getByLabelText("Search")).toBeInTheDocument();
  });

  it("shows skeleton rows while loading", () => {
    hooks.useCoursePerformance.mockReturnValue(pending());
    const { container } = render();

    expect(container.querySelector("[aria-busy='true']")).toBeInTheDocument();
  });

  it("opens readiness for the row that was clicked", async () => {
    const user = userEvent.setup();
    const onReviewReadiness = vi.fn();
    hooks.useCoursePerformance.mockReturnValue(
      ok(page([performanceRow({ publish_blocker_count: 2, is_publishable: false })])),
    );
    render({ onReviewReadiness });

    await user.click(screen.getByText(/2 blockers/));

    expect(onReviewReadiness).toHaveBeenCalledWith("crs-1");
  });

  it("distinguishes blockers from warnings by text, not colour alone", () => {
    hooks.useCoursePerformance.mockReturnValue(
      ok(page([performanceRow({ publish_blocker_count: 1, warning_count: 3 })])),
    );
    render();

    expect(screen.getByText(/1 blockers/)).toBeInTheDocument();
    expect(screen.getByText(/3 warnings/)).toBeInTheDocument();
  });
});
