import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";

const { useBundles, useProduct, addMutate, push, authState } = vi.hoisted(() => ({
  useBundles: vi.fn(),
  useProduct: vi.fn(),
  addMutate: vi.fn(),
  push: vi.fn(),
  authState: { status: "authenticated" as "authenticated" | "guest" },
}));

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push, replace: vi.fn() }),
  useParams: () => ({ public_id: "bundle-1" }),
}));
vi.mock("@/lib/auth/auth-context", () => ({ useAuth: () => authState }));
vi.mock("@/lib/commerce/hooks", () => ({
  useBundles,
  useProduct,
  useAddToCart: () => ({ mutate: addMutate, isPending: false, variables: undefined }),
}));

import { BundlesPageClient } from "@/app/(marketing)/(site)/bundles/bundles-page-client";
import { BundleDetailsClient } from "@/app/(marketing)/(site)/bundles/[public_id]/bundle-details-client";

const bundle = {
  id: "bundle-1",
  type: "bundle" as const,
  title: "Leadership Essentials",
  slug: "leadership-essentials",
  description: "Six courses for new managers.",
  prices: [
    { currency: "SAR", amount_minor: 90000, sale_amount_minor: 60000, on_sale: true, effective_minor: 60000 },
  ],
  audience: "both" as const,
  courses: [
    { id: "c1", title: "Managing People", slug: "managing-people" },
    { id: "c2", title: "Negotiation", slug: "negotiation" },
  ],
  access: { duration_type: "fixed_months" as const, duration_value: 12, ends_at: null },
  certificate: { enabled: true, expiry_type: "none" as const, expiry_value: null },
  seats: {
    mode: "fixed" as const,
    default_count: 25,
    reassignment_policy: "before_start",
    reassignment_progress_threshold: null,
    employee_access_expires_with_purchase: true,
  },
};

const listResult = {
  isPending: false,
  isError: false,
  refetch: vi.fn(),
  data: {
    data: [bundle],
    meta: { current_page: 1, per_page: 15, total: 1, last_page: 1 },
    links: { first: null, last: null, prev: null, next: null },
  },
};

const detailResult = { isPending: false, isError: false, refetch: vi.fn(), data: bundle };

describe("Bundle sales surfaces", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    authState.status = "authenticated";
  });

  it("lists a bundle with its price, sale price and included course count", () => {
    useBundles.mockReturnValue(listResult);
    renderWithI18n(<BundlesPageClient />);

    expect(screen.getByText("Leadership Essentials")).toBeInTheDocument();
    // Effective price shown, pre-sale price struck through.
    expect(screen.getByText(/600/)).toBeInTheDocument();
    expect(screen.getByText(/900/)).toBeInTheDocument();
    expect(screen.getByText(/2 courses included/i)).toBeInTheDocument();
  });

  it("shows the included courses, access and seat terms on the detail page", () => {
    useProduct.mockReturnValue(detailResult);
    renderWithI18n(<BundleDetailsClient />);

    expect(screen.getByRole("heading", { name: "Leadership Essentials" })).toBeInTheDocument();
    expect(screen.getByText("Managing People")).toBeInTheDocument();
    expect(screen.getByText("Negotiation")).toBeInTheDocument();
    expect(screen.getByText(/12 months of access/i)).toBeInTheDocument();
    expect(screen.getByText(/25 seats included/i)).toBeInTheDocument();
    // Company checkout does not exist yet, so the page says where setup continues.
    expect(screen.getByText(/Company setup continues at checkout/i)).toBeInTheDocument();
  });

  it("adds the bundle to the cart for a signed-in buyer", async () => {
    useProduct.mockReturnValue(detailResult);
    renderWithI18n(<BundleDetailsClient />);

    await userEvent.click(screen.getByRole("button", { name: /Add to cart/i }));

    expect(addMutate).toHaveBeenCalledWith({ product: "bundle-1" }, expect.anything());
  });

  it("sends a guest to sign in and back to the bundle instead of adding to the cart", async () => {
    authState.status = "guest";
    useProduct.mockReturnValue(detailResult);
    renderWithI18n(<BundleDetailsClient />);

    await userEvent.click(screen.getByRole("button", { name: /Sign in to purchase/i }));

    expect(addMutate).not.toHaveBeenCalled();
    expect(push).toHaveBeenCalledWith("/login?redirect=/bundles/bundle-1");
  });
});
