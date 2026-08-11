import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";

const { useMembers, removeMutate, roleMutate, deactivateMutate } = vi.hoisted(() => ({
  useMembers: vi.fn(),
  removeMutate: vi.fn(),
  roleMutate: vi.fn(),
  deactivateMutate: vi.fn(),
}));
vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }) }));
vi.mock("@/lib/enterprise/manager-hooks", () => ({
  useMembers,
  useRemoveMember: () => ({ mutate: removeMutate, isPending: false }),
  useChangeMemberRole: () => ({ mutate: roleMutate, isPending: false }),
  useDeactivateMember: () => ({ mutate: deactivateMutate, isPending: false }),
}));

import ManagerMembersPage from "@/app/(enterprise)/manager/members/page";

const paginated = (items: unknown[]) => ({
  isPending: false,
  isError: false,
  refetch: vi.fn(),
  data: { data: items, meta: { current_page: 1, per_page: 25, total: items.length, last_page: 1 }, links: {} },
});

const MEMBER = { id: "m_1", email: "lead@acme.test", role: "member", status: "active", invited_at: null };

describe("ManagerMembersPage", () => {
  beforeEach(() => vi.clearAllMocks());

  it("renders the members table", () => {
    useMembers.mockReturnValue(paginated([MEMBER]));
    renderWithI18n(<ManagerMembersPage />);
    expect(screen.getByRole("table")).toBeInTheDocument();
    expect(screen.getByText("lead@acme.test")).toBeInTheDocument();
  });

  it("removes a member and surfaces the seat-release note", async () => {
    removeMutate.mockImplementation((_id: string, opts: { onSuccess?: () => void }) => opts?.onSuccess?.());
    useMembers.mockReturnValue(paginated([MEMBER]));
    renderWithI18n(<ManagerMembersPage />);

    // Open the confirm dialog from the row action, then confirm inside the dialog.
    await userEvent.click(screen.getByRole("button", { name: /Remove/i }));
    const dialog = screen.getByRole("dialog");
    await userEvent.click(within(dialog).getByRole("button", { name: /Remove/i }));

    expect(removeMutate).toHaveBeenCalledWith("m_1", expect.anything());
    expect(screen.getByText(/seats have been released/i)).toBeInTheDocument();
  });
});
