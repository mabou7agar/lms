import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";

const { useMySubscriptions, usePlans, subscribeMutate, cancelMutate } = vi.hoisted(() => ({
  useMySubscriptions: vi.fn(),
  usePlans: vi.fn(),
  subscribeMutate: vi.fn(),
  cancelMutate: vi.fn(),
}));

vi.mock("@/lib/auth/auth-context", () => ({
  useAuth: () => ({ status: "authenticated", user: { roles: ["student"] } }),
}));
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  usePathname: () => "/subscriptions",
  useSearchParams: () => new URLSearchParams(),
  useParams: () => ({}),
}));
vi.mock("@/lib/commerce/subscriptions-hooks", () => ({
  useMySubscriptions,
  usePlans,
  useSubscribe: () => ({ mutate: subscribeMutate, isPending: false, variables: undefined }),
  useChangePlan: () => ({ mutate: vi.fn(), isPending: false, variables: undefined }),
  useCancelSubscription: () => ({ mutate: cancelMutate, isPending: false }),
  useReactivateSubscription: () => ({ mutate: vi.fn(), isPending: false }),
}));

import SubscriptionsPage from "@/app/(commerce)/subscriptions/page";

const emptySubs = {
  isPending: false,
  isError: false,
  refetch: vi.fn(),
  data: {
    data: [],
    meta: { current_page: 1, per_page: 10, total: 0, last_page: 1 },
    links: { first: null, last: null, prev: null, next: null },
  },
};

const plans = {
  isPending: false,
  isError: false,
  refetch: vi.fn(),
  data: [
    {
      id: "plan_basic",
      name: "Basic Monthly",
      interval: "monthly",
      trial_days: 0,
      is_active: true,
      prices: [{ currency: "USD", amount_minor: 1500, is_default: true }],
    },
    {
      id: "plan_pro",
      name: "Pro Yearly",
      interval: "yearly",
      trial_days: 14,
      is_active: true,
      prices: [{ currency: "USD", amount_minor: 12000, is_default: true }],
    },
  ],
};

describe("SubscriptionsPage", () => {
  beforeEach(() => vi.clearAllMocks());

  it("renders the plan catalogue when the user has no subscription", () => {
    useMySubscriptions.mockReturnValue(emptySubs);
    usePlans.mockReturnValue(plans);

    renderWithI18n(<SubscriptionsPage />);

    expect(screen.getByText("Subscriptions")).toBeInTheDocument();
    expect(screen.getByText("Choose a plan")).toBeInTheDocument();
    expect(screen.getByText("Basic Monthly")).toBeInTheDocument();
    expect(screen.getByText("Pro Yearly")).toBeInTheDocument();
  });

  it("subscribes to a plan when its action is clicked", async () => {
    useMySubscriptions.mockReturnValue(emptySubs);
    usePlans.mockReturnValue(plans);

    renderWithI18n(<SubscriptionsPage />);

    const subscribeButtons = screen.getAllByRole("button", { name: "Subscribe" });
    await userEvent.click(subscribeButtons[0]);
    expect(subscribeMutate).toHaveBeenCalled();
    expect(subscribeMutate.mock.calls[0][0]).toBe("plan_basic");
  });

  it("cancels the current subscription for an active subscriber", async () => {
    useMySubscriptions.mockReturnValue({
      isPending: false,
      isError: false,
      refetch: vi.fn(),
      data: {
        data: [
          {
            id: "sub_1",
            status: "active",
            currency: "USD",
            amount_minor: 1500,
            current_period_start: "2026-01-01T00:00:00Z",
            current_period_end: "2026-02-01T00:00:00Z",
            trial_ends_at: null,
            grace_ends_at: null,
            canceled_at: null,
            cancel_at_period_end: false,
            is_active_now: true,
            plan: { id: "plan_basic", name: "Basic Monthly", interval: "monthly" },
          },
        ],
        meta: { current_page: 1, per_page: 10, total: 1, last_page: 1 },
        links: { first: null, last: null, prev: null, next: null },
      },
    });
    usePlans.mockReturnValue(plans);

    renderWithI18n(<SubscriptionsPage />);

    expect(screen.getByText("Active")).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "Cancel subscription" }));
    expect(cancelMutate).toHaveBeenCalled();
    expect(cancelMutate.mock.calls[0][0]).toBe("sub_1");
  });
});
