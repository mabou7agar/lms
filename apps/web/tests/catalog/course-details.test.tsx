import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import { renderWithI18n } from "../render";

const { useCourse } = vi.hoisted(() => ({ useCourse: vi.fn() }));
vi.mock("next/navigation", () => ({ useParams: () => ({ public_id: "c1" }) }));
vi.mock("@/lib/catalog/hooks", () => ({ useCourse, useEnroll: () => ({ mutate: vi.fn(), isPending: false }) }));
vi.mock("@/lib/auth/auth-context", () => ({ useAuth: () => ({ status: "guest" }) }));

import CourseDetailsPage from "@/app/(marketing)/(site)/courses/[public_id]/page";

describe("CourseDetailsPage", () => {
  beforeEach(() => vi.clearAllMocks());

  it("renders the course detail and a sign-in CTA for guests", () => {
    useCourse.mockReturnValue({
      isPending: false,
      isError: false,
      refetch: vi.fn(),
      data: { id: "c1", title: "Deep Dive", slug: "dd", subtitle: "Advanced topics", description: "Body", status: "published", visibility: "public", is_featured: true, thumbnail_path: null, level: { id: "l1", name: "Advanced" }, language: { id: "lang1", name: "English" }, categories: [], tags: [], trainers: [], related: [] },
    });
    renderWithI18n(<CourseDetailsPage />);
    expect(screen.getByRole("heading", { name: "Deep Dive" })).toBeInTheDocument();
    // Redesign exposes the guest CTA in both the desktop sticky panel and the mobile bottom bar.
    expect(screen.getAllByRole("link", { name: "Sign in to enroll" }).length).toBeGreaterThan(0);
  });
});
