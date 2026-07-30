import type { ReactNode } from "react";
import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";

const { useCoupons, createMutate, updateMutate } = vi.hoisted(() => ({
  useCoupons: vi.fn(),
  createMutate: vi.fn(),
  updateMutate: vi.fn(),
}));

// AdminGuard reads the session from `@/lib/auth/guards`; stub that boundary so the coupons console
// renders for a privileged user.
vi.mock("@/lib/auth/guards", () => ({
  RequireAuth: ({ children }: { children: ReactNode }) => <>{children}</>,
  useAuth: () => ({ status: "authenticated", user: { roles: ["commerce_manager"] } }),
}));
vi.mock("@/lib/auth/auth-context", () => ({
  useAuth: () => ({ status: "authenticated", user: { roles: ["commerce_manager"] } }),
}));
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  usePathname: () => "/admin/coupons",
  useSearchParams: () => new URLSearchParams(),
  useParams: () => ({}),
}));
vi.mock("@/lib/commerce/coupons-hooks", () => ({
  useCoupons,
  useCreateCoupon: () => ({ mutate: createMutate, isPending: false }),
  useUpdateCoupon: () => ({ mutate: updateMutate, isPending: false }),
}));

import CouponsPage from "@/app/(commerce)/admin/coupons/page";

const couponsResult = {
  isPending: false,
  isError: false,
  refetch: vi.fn(),
  data: {
    data: [
      {
        id: "cpn_1",
        code: "WELCOME10",
        type: "percentage",
        value: 10,
        currency: null,
        scope: "all",
        starts_at: null,
        ends_at: null,
        per_user_limit: null,
        first_order_only: false,
        is_active: true,
        redeemed_count: 3,
      },
    ],
    meta: { current_page: 1, per_page: 10, total: 1, last_page: 1 },
    links: { first: null, last: null, prev: null, next: null },
  },
};

describe("CouponsPage", () => {
  beforeEach(() => vi.clearAllMocks());

  it("lists coupons for an admin", () => {
    useCoupons.mockReturnValue(couponsResult);
    renderWithI18n(<CouponsPage />);

    expect(screen.getByText("Coupons")).toBeInTheDocument();
    expect(screen.getByText("WELCOME10")).toBeInTheDocument();
    expect(screen.getByText("10%")).toBeInTheDocument();
  });

  it("creates a coupon from the form", async () => {
    useCoupons.mockReturnValue(couponsResult);
    renderWithI18n(<CouponsPage />);

    // Open the blank create form from the header action.
    await userEvent.click(screen.getByRole("button", { name: "Create coupon" }));

    await userEvent.type(screen.getByLabelText("Code"), "SUMMER25");
    await userEvent.type(screen.getByLabelText("Value"), "25");
    // Once the form is open the only remaining "Create coupon" button is the form submit.
    await userEvent.click(screen.getByRole("button", { name: "Create coupon" }));

    expect(createMutate).toHaveBeenCalled();
    const [input] = createMutate.mock.calls[0];
    expect(input.code).toBe("SUMMER25");
    expect(input.value).toBe(25);
    expect(input.type).toBe("percentage");
  });
});
