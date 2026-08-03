import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }), usePathname: () => "/solutions" }));

const trackMock = vi.fn((..._args: unknown[]) => ({ event: "x", v: 1, props: {} }));
vi.mock("@/lib/analytics/track", () => ({ track: (...a: unknown[]) => trackMock(...a) }));

/** Version-agnostic view of recorded calls (avoids TS tuple-length inference differences). */
const recordedCalls = (): unknown[][] => trackMock.mock.calls as unknown as unknown[][];

import { PersonaPage } from "@/components/marketing/persona-page";
import { personasContent, personaOrder, personaSlug, slugToPersona, personaFromSlug } from "@/config/personas-content";
import { personaById, type Localized } from "@/config/messaging";

function pairs(node: unknown, out: Localized[] = []): Localized[] {
  if (node && typeof node === "object") {
    const o = node as Record<string, unknown>;
    if (typeof o.en === "string" && typeof o.ar === "string") { out.push(o as unknown as Localized); return out; }
    for (const v of Object.values(o)) pairs(v, out);
  }
  return out;
}

beforeEach(() => trackMock.mockClear());

describe("persona pages — data", () => {
  it("maps to stable, non-conflicting slugs", () => {
    expect(Object.values(personaSlug).sort()).toEqual(["academies", "enterprise", "government", "instructors"]);
    expect(personaFromSlug("enterprise")?.id).toBe("companies");
    expect(personaFromSlug("nope")).toBeNull();
    expect(slugToPersona.government).toBe("public_sector");
  });

  it("is distinct per persona (no noun-swapped duplication)", () => {
    const problems = personaOrder.map((id) => personaById[id].problem.en);
    const outcomes = personaOrder.map((id) => personaById[id].outcome.en);
    const firstPain = personaOrder.map((id) => personasContent[id].painPoints[0].en);
    const firstFaq = personaOrder.map((id) => personasContent[id].faqs[0].q.en);
    for (const arr of [problems, outcomes, firstPain, firstFaq]) {
      expect(new Set(arr).size).toBe(arr.length);
    }
    // each persona has its own pain/steps/faqs
    for (const id of personaOrder) {
      expect(personasContent[id].painPoints.length).toBeGreaterThanOrEqual(3);
      expect(personasContent[id].steps.length).toBeGreaterThanOrEqual(3);
      expect(personasContent[id].faqs.length).toBeGreaterThanOrEqual(3);
    }
  });

  it("has EN/AR parity and no fabricated/compliance claims", () => {
    const forbidden = [/ISO\s?\d{4,5}/i, /SOC\s?2/i, /\bGDPR\b/i, /\d[\d,]*\+?\s*(customers|learners|companies)\b/i, /\b\d+(\.\d+)?\s?%/, /\bguarantee(d|s)?\b/i];
    for (const id of personaOrder) {
      for (const p of pairs(personasContent[id])) {
        expect(p.en.trim().length).toBeGreaterThan(0);
        expect(p.ar.trim().length).toBeGreaterThan(0);
        expect(/[؀-ۿ]/.test(p.ar), `AR not Arabic: ${p.ar}`).toBe(true);
        for (const re of forbidden) expect(re.test(p.en), `claim in "${p.en}"`).toBe(false);
      }
    }
  });
});

describe("persona pages — render + analytics", () => {
  it("renders a distinct journey with CTAs to real routes", () => {
    renderWithI18n(<PersonaPage id="companies" />);
    expect(screen.getByRole("heading", { level: 1 })).toBeInTheDocument();
    // primary CTA (companies → enterprise route) + secondary (pricing)
    const primary = screen.getAllByRole("link", { name: new RegExp(personaById.companies.primaryCta.label.en, "i") })[0];
    expect(primary).toHaveAttribute("href", personaById.companies.primaryCta.href);
    expect(screen.getAllByRole("link", { name: /See pricing/i })[0]).toHaveAttribute("href", "/pricing");
  });

  it("fires page_view + persona_selected on mount and cta events on click (no PII)", async () => {
    renderWithI18n(<PersonaPage id="academies" />);
    const names = recordedCalls().map((c) => c[0]);
    expect(names).toContain("page_view");
    expect(names).toContain("persona_selected");

    const primary = screen.getAllByRole("link", { name: new RegExp(personaById.academies.primaryCta.label.en, "i") })[0];
    await userEvent.click(primary);
    expect(recordedCalls().some((c) => c[0] === "primary_cta_clicked")).toBe(true);

    // no PII in any payload
    for (const c of recordedCalls()) {
      const payload = c[1];
      for (const k of Object.keys((payload ?? {}) as Record<string, unknown>)) {
        expect(/email|phone|name|password|token|card|auth|otp|message/i.test(k)).toBe(false);
      }
    }
  });

  it("returns nothing meaningful for an unknown persona via route resolver", () => {
    expect(personaFromSlug("does-not-exist")).toBeNull();
  });
});
