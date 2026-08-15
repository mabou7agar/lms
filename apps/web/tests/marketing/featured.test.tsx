import { describe, expect, it, vi } from "vitest";
import { screen } from "@testing-library/react";
import { renderWithI18n } from "../render";
import type { CourseListItem } from "@/lib/catalog/api";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn() }),
  usePathname: () => "/",
  useSearchParams: () => new URLSearchParams(),
}));

// FeaturedCourses now renders the REAL published featured courses via the useFeaturedCourses query
// (the homepage rebuild replaced the old static demo grid). Mock the hook so the component gets data
// without a QueryClient/network, and assert the covers render.
const featured: CourseListItem[] = [
  {
    id: "c1",
    title: "Project Management Foundations",
    slug: "project-management-foundations",
    subtitle: null,
    thumbnail_path: null,
    is_featured: true,
    published_at: "2026-01-01T00:00:00Z",
  },
  {
    id: "c2",
    title: "Leadership in the Modern Workplace",
    slug: "leadership-in-the-modern-workplace",
    subtitle: null,
    thumbnail_path: null,
    is_featured: true,
    published_at: "2026-01-01T00:00:00Z",
  },
];

vi.mock("@/lib/catalog/hooks", () => ({
  useFeaturedCourses: () => ({ data: { data: featured } }),
}));

import { FeaturedCourses } from "@/components/marketing/featured-courses";
import { ServicePage } from "@/components/marketing/service-page";

describe("Marketing demo content", () => {
  it("renders the featured courses as covers", () => {
    renderWithI18n(<FeaturedCourses />);
    expect(screen.getByText("Project Management Foundations")).toBeInTheDocument();
    expect(screen.getByText("Leadership in the Modern Workplace")).toBeInTheDocument();
  });

  it("renders a service page hero + features + highlights", () => {
    renderWithI18n(<ServicePage pageKey="cohorts" />);
    expect(screen.getByText("Mentor-led")).toBeInTheDocument();
    expect(screen.getByText("Peer community")).toBeInTheDocument();
  });
});
