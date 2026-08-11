import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import { renderWithI18n } from "../render";

const { useEvents } = vi.hoisted(() => ({ useEvents: vi.fn() }));
vi.mock("@/lib/events/hooks", () => ({ useEvents }));

import { UpcomingSessions } from "@/components/learning/upcoming-sessions";

const ok = (data: unknown) => ({ isPending: false, isError: false, refetch: vi.fn(), data });

const session = (over: Record<string, unknown> = {}) => ({
  id: "e_1",
  title: "Intro to TypeScript",
  description: null,
  status: "scheduled",
  timezone: "UTC",
  starts_at: "2026-09-01T12:00:00Z",
  ends_at: null,
  capacity: null,
  registered_count: 0,
  speakers: [],
  ...over,
});

const page = (items: unknown[]) => ({ data: items, meta: {}, links: {} });

describe("UpcomingSessions widget", () => {
  beforeEach(() => vi.clearAllMocks());

  it("lists the learner's upcoming live sessions", () => {
    useEvents.mockReturnValue(ok(page([session(), session({ id: "e_2", title: "Advanced React" })])));
    renderWithI18n(<UpcomingSessions timeZone="UTC" />);
    expect(screen.getByText("Intro to TypeScript")).toBeInTheDocument();
    expect(screen.getByText("Advanced React")).toBeInTheDocument();
  });

  it("shows the empty state when there are no upcoming sessions", () => {
    useEvents.mockReturnValue(ok(page([])));
    renderWithI18n(<UpcomingSessions timeZone="UTC" />);
    expect(screen.getByText("No upcoming live sessions.")).toBeInTheDocument();
  });

  it("renders the start time in the user's timezone", () => {
    useEvents.mockReturnValue(ok(page([session()])));

    // 12:00 UTC is 15:00 in Riyadh (UTC+3)…
    const { unmount } = renderWithI18n(<UpcomingSessions timeZone="Asia/Riyadh" />);
    expect(screen.getByText(/3:00/)).toBeInTheDocument();
    unmount();

    // …and 08:00 in New York (UTC-4 during September DST). Same instant, different wall-clock time.
    renderWithI18n(<UpcomingSessions timeZone="America/New_York" />);
    expect(screen.getByText(/8:00/)).toBeInTheDocument();
  });
});
