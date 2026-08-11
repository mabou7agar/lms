import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";

const { useDepartments, useTeams, useMembers, createDeptMutate } = vi.hoisted(() => ({
  useDepartments: vi.fn(),
  useTeams: vi.fn(),
  useMembers: vi.fn(),
  createDeptMutate: vi.fn(),
}));
vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }) }));
vi.mock("@/lib/enterprise/manager-hooks", () => ({
  useDepartments,
  useTeams,
  useMembers,
  useCreateDepartment: () => ({ mutate: createDeptMutate, isPending: false }),
  useCreateTeam: () => ({ mutate: vi.fn(), isPending: false }),
  useUpdateDepartment: () => ({ mutate: vi.fn(), isPending: false }),
  useDeleteDepartment: () => ({ mutate: vi.fn(), isPending: false }),
  useDeleteTeam: () => ({ mutate: vi.fn(), isPending: false }),
  useAssignDepartmentManager: () => ({ mutate: vi.fn(), isPending: false }),
  useAssignTeamManager: () => ({ mutate: vi.fn(), isPending: false }),
}));

import ManagerDepartmentsPage from "@/app/(enterprise)/manager/departments/page";

const paginated = (items: unknown[]) => ({
  isPending: false,
  isError: false,
  refetch: vi.fn(),
  data: { data: items, meta: { current_page: 1, per_page: 100, total: items.length, last_page: 1 }, links: {} },
});

describe("ManagerDepartmentsPage", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    useTeams.mockReturnValue(paginated([]));
    useMembers.mockReturnValue(paginated([]));
  });

  it("lists departments", () => {
    useDepartments.mockReturnValue(paginated([{ id: "d_1", name: "Engineering", manager_id: null, members_count: 4, created_at: null }]));
    renderWithI18n(<ManagerDepartmentsPage />);
    expect(screen.getByText("Engineering")).toBeInTheDocument();
  });

  it("creates a department", async () => {
    useDepartments.mockReturnValue(paginated([]));
    renderWithI18n(<ManagerDepartmentsPage />);
    await userEvent.type(screen.getByLabelText("Name", { selector: "#new-dept" }), "Sales");
    // The first "Create" button belongs to the departments card.
    await userEvent.click(screen.getAllByRole("button", { name: /Create/i })[0]);
    expect(createDeptMutate).toHaveBeenCalledWith("Sales", expect.anything());
  });
});
