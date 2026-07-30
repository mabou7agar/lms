import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import { renderWithI18n } from "../render";

const { useInvoices, useInvoice } = vi.hoisted(() => ({
  useInvoices: vi.fn(),
  useInvoice: vi.fn(),
}));

vi.mock("@/lib/auth/auth-context", () => ({
  useAuth: () => ({ status: "authenticated", user: { roles: ["student"] } }),
}));
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  usePathname: () => "/billing",
  useSearchParams: () => new URLSearchParams(),
  useParams: () => ({ id: "inv_1" }),
}));
vi.mock("@/lib/commerce/billing-hooks", () => ({ useInvoices, useInvoice }));

import BillingPage from "@/app/(commerce)/billing/page";
import InvoiceDetailPage from "@/app/(commerce)/billing/[id]/page";

describe("BillingPage", () => {
  beforeEach(() => vi.clearAllMocks());

  it("shows the billing header and lists the user's invoices", () => {
    useInvoices.mockReturnValue({
      isPending: false,
      isError: false,
      refetch: vi.fn(),
      data: {
        data: [
          {
            id: "inv_1",
            number: "INV-1001",
            status: "paid",
            currency: "USD",
            subtotal_minor: 5000,
            tax_minor: 750,
            total_minor: 5750,
            issued_at: "2026-01-10T00:00:00Z",
            paid_at: "2026-01-10T00:00:00Z",
          },
        ],
        meta: { current_page: 1, per_page: 10, total: 1, last_page: 1 },
        links: { first: null, last: null, prev: null, next: null },
      },
    });

    renderWithI18n(<BillingPage />);

    expect(screen.getByText("Billing")).toBeInTheDocument();
    expect(screen.getByText(/INV-1001/)).toBeInTheDocument();
    expect(screen.getByText("Download PDF")).toBeInTheDocument();
    expect(screen.getByText("$57.50")).toBeInTheDocument();
  });

  it("renders the empty state when there are no invoices", () => {
    useInvoices.mockReturnValue({
      isPending: false,
      isError: false,
      refetch: vi.fn(),
      data: {
        data: [],
        meta: { current_page: 1, per_page: 10, total: 0, last_page: 1 },
        links: { first: null, last: null, prev: null, next: null },
      },
    });

    renderWithI18n(<BillingPage />);
    expect(screen.getByText("No invoices yet.")).toBeInTheDocument();
  });
});

describe("InvoiceDetailPage", () => {
  beforeEach(() => vi.clearAllMocks());

  it("shows the invoice number, tax breakdown and total", () => {
    useInvoice.mockReturnValue({
      isPending: false,
      isError: false,
      refetch: vi.fn(),
      data: {
        id: "inv_1",
        number: "INV-2001",
        status: "paid",
        currency: "USD",
        subtotal_minor: 5000,
        tax_minor: 750,
        total_minor: 5750,
        issued_at: "2026-01-10T00:00:00Z",
        paid_at: "2026-01-10T00:00:00Z",
        lines: [
          {
            id: "il_1",
            description: "Pro Plan (annual)",
            quantity: 1,
            unit_amount_minor: 5000,
            tax_minor: 750,
            total_minor: 5000,
          },
        ],
      },
    });

    renderWithI18n(<InvoiceDetailPage />);

    expect(screen.getByText(/INV-2001/)).toBeInTheDocument();
    expect(screen.getByText("Pro Plan (annual)")).toBeInTheDocument();
    expect(screen.getByText("Subtotal")).toBeInTheDocument();
    expect(screen.getByText("Tax")).toBeInTheDocument();
    expect(screen.getByText("Total")).toBeInTheDocument();
    expect(screen.getByText("Download PDF")).toBeInTheDocument();
  });
});
