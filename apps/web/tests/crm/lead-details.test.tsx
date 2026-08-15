import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import { renderWithI18nAsync } from "../render";

const { useLeads, useConvertLead } = vi.hoisted(() => ({ useLeads: vi.fn(), useConvertLead: vi.fn() }));
vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }) }));
vi.mock("@/lib/crm/hooks", () => ({ useLeads, useConvertLead }));

import LeadDetailsPage from "@/app/(crm)/crm/leads/[public_id]/page";

const ok = (items: unknown[]) => ({
  isPending: false, isError: false, refetch: vi.fn(),
  data: { data: items, meta: { current_page: 1, per_page: 100, total: items.length, last_page: 1 }, links: { first: null, last: null, prev: null, next: null } },
});

describe("LeadDetailsPage", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    useConvertLead.mockReturnValue({ mutate: vi.fn(), isPending: false });
  });

  it("resolves the lead by public_id and renders profile + unavailable sections", async () => {
    useLeads.mockReturnValue(ok([{ id: "lead_1", name: "Jane Buyer", email: "jane@co.test", phone: null, source: "referral", status: "working", value_minor: null, currency: null, created_at: null }]));
    await renderWithI18nAsync(<LeadDetailsPage params={Promise.resolve({ public_id: "lead_1" })} />);
    expect(await screen.findByText("Jane Buyer")).toBeInTheDocument();
    expect(screen.getByText("Timeline")).toBeInTheDocument();
    expect(screen.getAllByText("This section has no read API endpoint yet.").length).toBeGreaterThan(0);
  });

  it("shows not-found when the lead is absent", async () => {
    useLeads.mockReturnValue(ok([]));
    await renderWithI18nAsync(<LeadDetailsPage params={Promise.resolve({ public_id: "missing" })} />);
    expect(await screen.findByText(/Lead not found/i)).toBeInTheDocument();
  });
});
