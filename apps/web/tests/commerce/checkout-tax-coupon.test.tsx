import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { renderWithI18n } from "../render";

const { useCart, checkoutMutate, validateMutate } = vi.hoisted(() => ({
  useCart: vi.fn(),
  checkoutMutate: vi.fn(),
  validateMutate: vi.fn(),
}));

vi.mock("@/lib/auth/auth-context", () => ({
  useAuth: () => ({ status: "authenticated", user: { roles: ["student"] } }),
}));
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  usePathname: () => "/checkout",
  useSearchParams: () => new URLSearchParams(),
  useParams: () => ({}),
}));
vi.mock("@/lib/commerce/hooks", () => ({
  useCart,
  useCheckout: () => ({ mutate: checkoutMutate, isPending: false }),
  useContracts: () => ({ data: [] }),
  useAcceptContract: () => ({ mutate: vi.fn(), isPending: false, variables: undefined }),
  useValidateCoupon: () => ({ mutate: validateMutate, isPending: false }),
}));

import CheckoutPage from "@/app/(commerce)/checkout/page";

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return renderWithI18n(
    <QueryClientProvider client={qc}>
      <CheckoutPage />
    </QueryClientProvider>,
  );
}

describe("CheckoutPage (tax + coupon)", () => {
  beforeEach(() => vi.clearAllMocks());

  const cart = {
    isPending: false,
    isError: false,
    refetch: vi.fn(),
    data: {
      id: "cart_1",
      currency: "USD",
      coupon: null,
      items: [{ id: "ci_1", product_id: "p_1", title: "Pro Plan", unit_amount_minor: 5000 }],
      subtotal_minor: 5000,
      discount_minor: 0,
      tax_minor: 750,
      total_minor: 5750,
    },
  };

  it("renders the tax line and totals in the order summary", () => {
    useCart.mockReturnValue(cart);
    renderPage();

    // i18n summary labels for the tax-aware checkout.
    expect(screen.getByText("Subtotal")).toBeInTheDocument();
    expect(screen.getByText("Tax")).toBeInTheDocument();
    expect(screen.getByText("Total")).toBeInTheDocument();
    // Server-computed tax amount, formatted from minor units.
    expect(screen.getByText("$7.50")).toBeInTheDocument();
    expect(screen.getByText("$57.50")).toBeInTheDocument();
    // The line item title is data-derived and must render.
    expect(screen.getByText("Pro Plan")).toBeInTheDocument();
  });

  it("validates a coupon when the code is applied", async () => {
    useCart.mockReturnValue(cart);
    renderPage();

    expect(screen.getByText("Coupon code")).toBeInTheDocument();
    await userEvent.type(screen.getByLabelText("Coupon code"), "SAVE10");
    await userEvent.click(screen.getByRole("button", { name: "Apply" }));
    expect(validateMutate).toHaveBeenCalled();
    expect(validateMutate.mock.calls[0][0]).toBe("SAVE10");
  });

  it("places the order when the primary action is clicked", async () => {
    useCart.mockReturnValue(cart);
    renderPage();

    await userEvent.click(screen.getByRole("button", { name: "Place order" }));
    expect(checkoutMutate).toHaveBeenCalled();
  });
});
