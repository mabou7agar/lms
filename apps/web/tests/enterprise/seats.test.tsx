import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";

const { useSeatSummary, useSeatHistory, useEntitlements, assignMutate, releaseMutate, resizeMutate } = vi.hoisted(() => ({
  useSeatSummary: vi.fn(),
  useSeatHistory: vi.fn(),
  useEntitlements: vi.fn(),
  assignMutate: vi.fn(),
  releaseMutate: vi.fn(),
  resizeMutate: vi.fn(),
}));
vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }) }));
vi.mock("@/lib/enterprise/manager-hooks", () => ({
  useSeatSummary,
  useSeatHistory,
  // The seat panel falls back to company entitlements when there is no org subscription, so the page
  // reads this too.
  useEntitlements,
  useAssignSeat: () => ({ mutate: assignMutate, isPending: false }),
  useReleaseSeat: () => ({ mutate: releaseMutate, isPending: false }),
  useResizeSeats: () => ({ mutate: resizeMutate, isPending: false }),
}));

import ManagerSeatsPage from "@/app/(enterprise)/manager/seats/page";

const ok = (data: unknown) => ({ isPending: false, isError: false, refetch: vi.fn(), data });
const emptyPage = ok({ data: [], meta: { current_page: 1, per_page: 25, total: 0, last_page: 1 }, links: {} });

describe("ManagerSeatsPage", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    useSeatSummary.mockReturnValue(ok({ subscription_id: "sub_1", status: "active", seats: { purchased: 10, used: 3, available: 7 } }));
    useSeatHistory.mockReturnValue(emptyPage);
    useEntitlements.mockReturnValue(ok([]));
  });

  it("blocks a resize below the number of assigned seats (client-side guard)", async () => {
    renderWithI18n(<ManagerSeatsPage />);
    await userEvent.type(screen.getByRole("spinbutton"), "2"); // below used = 3
    await userEvent.click(screen.getByRole("button", { name: /^Resize$/i }));
    expect(screen.getByText(/cannot be below/i)).toBeInTheDocument();
    expect(resizeMutate).not.toHaveBeenCalled();
  });

  /*
   * /manager/seats reads the ORG SUBSCRIPTION; /manager/training reads company ENTITLEMENTS. A company
   * that bought a seat-bearing product has the latter but not the former, and this page used to tell
   * that manager "No active subscription" while the very next screen showed their seats.
   */
  it("shows entitlement seats instead of denying a subscription the company never bought", () => {
    useSeatSummary.mockReturnValue(ok(null));
    useEntitlements.mockReturnValue(
      ok([
        {
          id: "ent_1",
          product_title: "Company Bundle",
          order_id: "ord_1",
          courses: [],
          seats: { purchased: 5, used: 1, available: 4, unlimited: false },
          status: "active",
          assignable: true,
          access_starts_at: null,
          access_ends_at: null,
          policy: { seat_mode: "fixed", reassignment: "allowed", reassignment_progress_threshold: null, certificate_branding: null },
        },
      ]),
    );

    renderWithI18n(<ManagerSeatsPage />);

    expect(screen.queryByText(/No active subscription/i)).not.toBeInTheDocument();
    expect(screen.getByText("5")).toBeInTheDocument();
    expect(screen.getByText("4")).toBeInTheDocument();
  });

  it("still reports no subscription when the company holds no seats at all", () => {
    useSeatSummary.mockReturnValue(ok(null));
    useEntitlements.mockReturnValue(ok([]));

    renderWithI18n(<ManagerSeatsPage />);

    expect(screen.getByText(/No active subscription/i)).toBeInTheDocument();
  });

  it("does not flash an empty state while the entitlements query is still loading", () => {
    useSeatSummary.mockReturnValue(ok(null));
    useEntitlements.mockReturnValue({ isPending: true, isError: false, refetch: vi.fn(), data: undefined });

    renderWithI18n(<ManagerSeatsPage />);

    expect(screen.queryByText(/No active subscription/i)).not.toBeInTheDocument();
  });

  it("assigns a seat to a member", async () => {
    renderWithI18n(<ManagerSeatsPage />);
    await userEvent.type(screen.getByLabelText("Member ID"), "mem_123");
    await userEvent.click(screen.getByRole("button", { name: /Assign seat/i }));
    expect(assignMutate).toHaveBeenCalledWith("mem_123", expect.anything());
  });
});
