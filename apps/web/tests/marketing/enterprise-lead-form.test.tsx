import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";

const { submitEnterpriseLead } = vi.hoisted(() => ({
  submitEnterpriseLead: vi.fn().mockResolvedValue({ data: { id: "abc", status: "new" } }),
}));
const trackMock = vi.fn((..._a: unknown[]) => ({ event: "x", v: 1, props: {} }));

vi.mock("@/lib/enterprise/api", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/enterprise/api")>();
  return { ...actual, submitEnterpriseLead };
});
vi.mock("@/lib/analytics/track", () => ({ track: (...a: unknown[]) => trackMock(...a) }));

import { EnterpriseLeadForm } from "@/components/marketing/enterprise-lead-form";

beforeEach(() => {
  submitEnterpriseLead.mockClear();
  trackMock.mockClear();
});

async function fillRequired() {
  // Labels carry a required-asterisk + sr-only "(required)", so match by role/partial text.
  await userEvent.type(screen.getByLabelText(/full name/i), "Dana Buyer");
  await userEvent.type(screen.getByLabelText(/work email/i), "dana@acme-corp.com");
  // "Company" would also match the "Company size" select — scope to the textbox role.
  await userEvent.type(screen.getByRole("textbox", { name: /^company/i }), "Acme Corp");
}

describe("enterprise lead form", () => {
  it("submits a valid lead and fires the conversion event", async () => {
    renderWithI18n(<EnterpriseLeadForm />);
    await fillRequired();
    await userEvent.click(screen.getByRole("button", { name: /Send request/i }));

    expect(submitEnterpriseLead).toHaveBeenCalledTimes(1);
    expect(submitEnterpriseLead).toHaveBeenCalledWith(
      expect.objectContaining({
        name: "Dana Buyer",
        work_email: "dana@acme-corp.com",
        company: "Acme Corp",
        request_type: "demo",
      }),
    );
    expect(trackMock.mock.calls.some((c) => c[0] === "enterprise_demo_submitted")).toBe(true);
    expect(await screen.findByText(/we've received your request/i)).toBeInTheDocument();
  });

  it("silently accepts but never calls the API when the honeypot is filled", async () => {
    renderWithI18n(<EnterpriseLeadForm />);
    await fillRequired();
    await userEvent.type(screen.getByLabelText(/website/i), "http://spam.example");
    await userEvent.click(screen.getByRole("button", { name: /Send request/i }));

    expect(submitEnterpriseLead).not.toHaveBeenCalled();
    expect(trackMock.mock.calls.some((c) => c[0] === "enterprise_demo_submitted")).toBe(false);
    expect(await screen.findByText(/we've received your request/i)).toBeInTheDocument();
  });

  it("blocks submission and shows validation errors when required fields are empty", async () => {
    renderWithI18n(<EnterpriseLeadForm />);
    await userEvent.click(screen.getByRole("button", { name: /Send request/i }));

    expect(submitEnterpriseLead).not.toHaveBeenCalled();
    expect(screen.getAllByText(/This field is required/i).length).toBeGreaterThanOrEqual(1);
  });
});
