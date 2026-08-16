import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";

const { useCart, applyMutate, clearMutate, removeMutate } = vi.hoisted(() => ({ useCart: vi.fn(), applyMutate: vi.fn(), clearMutate: vi.fn(), removeMutate: vi.fn() }));
vi.mock("@/lib/auth/auth-context", () => ({ useAuth: () => ({ status: "authenticated" }) }));
vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn(), replace: vi.fn() }), usePathname: () => "/cart", useSearchParams: () => new URLSearchParams() }));
vi.mock("@/lib/commerce/hooks", () => ({
  useCart,
  useAddToCart: () => ({ mutate: applyMutate, isPending: false }),
  useRemoveCartItem: () => ({ mutate: removeMutate, isPending: false, variables: undefined }),
  useClearCart: () => ({ mutate: clearMutate, isPending: false }),
  // The cart now shows who the purchase is for; the switch is exercised by its own test.
  useSetCartBuyer: () => ({ mutate: vi.fn(), isPending: false, isError: false, variables: undefined }),
}));

import CartPage from "@/app/(commerce)/cart/page";

describe("CartPage", () => {
  beforeEach(() => vi.clearAllMocks());

  it("renders totals and applies a coupon against the cart product", async () => {
    useCart.mockReturnValue({
      isPending: false, isError: false, refetch: vi.fn(),
      data: { id: "cart1", currency: "USD", coupon: null, items: [{ id: "ci1", product_id: "p1", title: "Pro Plan", unit_amount_minor: 5000 }], subtotal_minor: 5000, discount_minor: 0, total_minor: 5000 },
    });
    renderWithI18n(<CartPage />);
    expect(screen.getByText("Pro Plan")).toBeInTheDocument();
    await userEvent.type(screen.getByPlaceholderText("Coupon code"), "SAVE10");
    await userEvent.click(screen.getByRole("button", { name: "Apply" }));
    expect(applyMutate).toHaveBeenCalledWith({ product: "p1", coupon_code: "SAVE10" }, expect.anything());
  });

  it("removes a single item (not the whole cart) via the item Remove button", async () => {
    useCart.mockReturnValue({
      isPending: false, isError: false, refetch: vi.fn(),
      data: { id: "cart1", currency: "USD", coupon: null, items: [{ id: "ci1", product_id: "p1", title: "Pro Plan", unit_amount_minor: 5000 }], subtotal_minor: 5000, discount_minor: 0, total_minor: 5000 },
    });
    renderWithI18n(<CartPage />);
    await userEvent.click(screen.getByRole("button", { name: /remove/i }));
    expect(removeMutate).toHaveBeenCalledWith("p1", expect.anything());
    expect(clearMutate).not.toHaveBeenCalled();
  });
});
