import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";

const { useEntitlements, useEntitlement, assignMutate, revokeMutate } = vi.hoisted(() => ({
  useEntitlements: vi.fn(),
  useEntitlement: vi.fn(),
  assignMutate: vi.fn(),
  revokeMutate: vi.fn(),
}));

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }) }));
vi.mock("@/lib/enterprise/manager-hooks", () => ({
  useEntitlements,
  useEntitlement,
  useAssignEntitlement: () => ({ mutate: assignMutate, isPending: false }),
  useRevokeEntitlement: () => ({ mutate: revokeMutate, isPending: false }),
  useMembers: () => ({
    isPending: false,
    isError: false,
    data: { data: [{ id: "mem_1", email: "staff@corp.com" }], meta: { total: 1 } },
  }),
  useDepartments: () => ({ isPending: false, isError: false, data: { data: [], meta: { total: 0 } } }),
  useTeams: () => ({ isPending: false, isError: false, data: { data: [], meta: { total: 0 } } }),
}));

import { PurchasedTraining } from "@/components/enterprise/purchased-training";

const ok = (data: unknown) => ({ isPending: false, isError: false, refetch: vi.fn(), data });

function entitlement(overrides: Record<string, unknown> = {}) {
  return {
    id: "ent_1",
    product_title: "Leadership Bundle",
    order_id: "ord_1",
    courses: [
      { id: "c1", title: "Leading Teams" },
      { id: "c2", title: "Difficult Conversations" },
    ],
    seats: { purchased: 5, used: 2, available: 3, unlimited: false },
    status: "active",
    assignable: true,
    access_starts_at: null,
    access_ends_at: null,
    policy: {
      seat_mode: "fixed",
      reassignment: "always",
      reassignment_progress_threshold: null,
      certificate_branding: "company",
      employee_access_expires_with_purchase: true,
    },
    ...overrides,
  };
}

describe("PurchasedTraining", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    useEntitlements.mockReturnValue(ok([entitlement()]));
    useEntitlement.mockReturnValue(ok({ ...entitlement(), seat_holders: [] }));
  });

  it("shows the purchase with its seat counts and included courses", () => {
    renderWithI18n(<PurchasedTraining />);

    expect(screen.getByText("Leadership Bundle")).toBeInTheDocument();
    expect(screen.getByText(/Leading Teams/)).toBeInTheDocument();
    // purchased 5 / used 2 / available 3
    expect(screen.getByText("5")).toBeInTheDocument();
    expect(screen.getByText("2")).toBeInTheDocument();
    expect(screen.getByText("3")).toBeInTheDocument();
  });

  it("spells out the reassignment and expiry policy the manager is bound by", () => {
    useEntitlements.mockReturnValue(
      ok([
        entitlement({
          policy: {
            seat_mode: "fixed",
            reassignment: "never",
            reassignment_progress_threshold: null,
            certificate_branding: "company",
            employee_access_expires_with_purchase: true,
          },
        }),
      ]),
    );

    renderWithI18n(<PurchasedTraining />);

    expect(screen.getByText(/stay with their first holder/i)).toBeInTheDocument();
    expect(screen.getByText(/lose access when this purchase ends/i)).toBeInTheDocument();
  });

  it("renders an unlimited licence without inventing a seat number", () => {
    useEntitlements.mockReturnValue(
      ok([entitlement({ seats: { purchased: null, used: 4, available: null, unlimited: true } })]),
    );

    renderWithI18n(<PurchasedTraining />);

    expect(screen.getByText("Unlimited")).toBeInTheDocument();
    expect(screen.getByText("∞")).toBeInTheDocument();
  });

  it("assigns seats to a chosen member", async () => {
    renderWithI18n(<PurchasedTraining />);

    await userEvent.click(screen.getByRole("button", { name: /Manage seats/i }));
    await userEvent.click(screen.getByRole("combobox", { name: /Target/i }));
    await userEvent.click(await screen.findByRole("option", { name: "staff@corp.com" }));
    await userEvent.click(screen.getByRole("button", { name: /^Assign seats$/i }));

    expect(assignMutate).toHaveBeenCalledWith(
      { id: "ent_1", target_type: "member", target_id: "mem_1" },
      expect.anything(),
    );
  });

  it("revokes the seat of a current holder", async () => {
    useEntitlement.mockReturnValue(
      ok({
        ...entitlement(),
        seat_holders: [
          {
            id: "sa_1",
            member_id: "mem_1",
            email: "staff@corp.com",
            assigned_at: null,
            revoked_at: null,
            active: true,
          },
        ],
      }),
    );

    renderWithI18n(<PurchasedTraining />);
    await userEvent.click(screen.getByRole("button", { name: /Manage seats/i }));

    const holder = screen.getByText("staff@corp.com").closest("li") as HTMLElement;
    await userEvent.click(within(holder).getByRole("button", { name: /^Revoke$/i }));

    expect(revokeMutate).toHaveBeenCalledWith({ id: "ent_1", memberId: "mem_1" }, expect.anything());
  });

  it("will not let a manager assign from an expired purchase", async () => {
    useEntitlements.mockReturnValue(
      ok([entitlement({ status: "expired", assignable: false, access_ends_at: "2020-01-01T00:00:00+00:00" })]),
    );

    renderWithI18n(<PurchasedTraining />);
    await userEvent.click(screen.getByRole("button", { name: /Manage seats/i }));

    expect(screen.getByText("Expired")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /^Assign seats$/i })).toBeDisabled();
  });
});
