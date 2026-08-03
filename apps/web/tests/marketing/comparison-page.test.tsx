import { describe, expect, it, vi } from "vitest";
import { screen } from "@testing-library/react";
import { renderWithI18n } from "../render";

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }), usePathname: () => "/compare" }));

import { ComparisonIndex, ComparisonDetail } from "@/components/marketing/comparison-page";

describe("comparison pages", () => {
  it("index links to each competitor and shows a neutral (non-fabricated) evidence state", () => {
    renderWithI18n(<ComparisonIndex />);
    const moodle = screen.getByRole("link", { name: /vs\s+Moodle/i });
    const thinkific = screen.getByRole("link", { name: /vs\s+Thinkific/i });
    expect(moodle).toHaveAttribute("href", "/compare/moodle");
    expect(thinkific).toHaveAttribute("href", "/compare/thinkific");
    // Neutral evidence state, not fabricated proof.
    expect(screen.getByText(/Verified case studies will appear here/i)).toBeInTheDocument();
    // No fabricated numbers/logos/ratings rendered.
    expect(screen.queryByText(/\d[\d,]*\+?\s*(customers|companies|learners)/i)).toBeNull();
  });

  it("detail renders the table, honest best-for guidance, review date, and real CTA routes", () => {
    renderWithI18n(<ComparisonDetail slug="moodle" />);
    // Dimension rows present.
    expect(screen.getByText(/Arabic-first & full RTL/i)).toBeInTheDocument();
    expect(screen.getByText(/Verifiable certificates/i)).toBeInTheDocument();
    // Both product columns.
    expect(screen.getAllByText("HElbaron").length).toBeGreaterThan(0);
    expect(screen.getAllByText("Moodle").length).toBeGreaterThan(0);
    // Honest guidance for both sides.
    expect(screen.getByText(/Choose HElbaron when/i)).toBeInTheDocument();
    expect(screen.getByText(/Choose the other when/i)).toBeInTheDocument();
    // Review date rendered as a <time>.
    const time = document.querySelector("time[datetime='2026-08-01']");
    expect(time).not.toBeNull();
    // CTAs resolve to real routes.
    expect(screen.getByRole("link", { name: /Talk to our team/i })).toHaveAttribute("href", "/enterprise");
    expect(screen.getByRole("link", { name: /See pricing/i })).toHaveAttribute("href", "/pricing");
  });

  it("returns null for an unknown competitor slug", () => {
    const { container } = renderWithI18n(<ComparisonDetail slug="does-not-exist" />);
    expect(container.textContent?.trim()).toBe("");
  });
});
