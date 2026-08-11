import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";

const { useSeatSummary, useSeatHistory, assignMutate, releaseMutate, resizeMutate } = vi.hoisted(() => ({
  useSeatSummary: vi.fn(),
  useSeatHistory: vi.fn(),
  assignMutate: vi.fn(),
  releaseMutate: vi.fn(),
  resizeMutate: vi.fn(),
}));
vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }) }));
vi.mock("@/lib/enterprise/manager-hooks", () => ({
  useSeatSummary,
  useSeatHistory,
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
  });

  it("blocks a resize below the number of assigned seats (client-side guard)", async () => {
    renderWithI18n(<ManagerSeatsPage />);
    await userEvent.type(screen.getByRole("spinbutton"), "2"); // below used = 3
    await userEvent.click(screen.getByRole("button", { name: /^Resize$/i }));
    expect(screen.getByText(/cannot be below/i)).toBeInTheDocument();
    expect(resizeMutate).not.toHaveBeenCalled();
  });

  it("assigns a seat to a member", async () => {
    renderWithI18n(<ManagerSeatsPage />);
    await userEvent.type(screen.getByLabelText("Member ID"), "mem_123");
    await userEvent.click(screen.getByRole("button", { name: /Assign seat/i }));
    expect(assignMutate).toHaveBeenCalledWith("mem_123", expect.anything());
  });
});
