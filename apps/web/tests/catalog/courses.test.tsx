import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import { renderWithI18n } from "../render";

const { useCourses, useCategories } = vi.hoisted(() => ({ useCourses: vi.fn(), useCategories: vi.fn() }));
vi.mock("next/navigation", () => ({
  useSearchParams: () => new URLSearchParams(),
  useRouter: () => ({ replace: vi.fn(), push: vi.fn(), prefetch: vi.fn(), back: vi.fn(), forward: vi.fn(), refresh: vi.fn() }),
  usePathname: () => "/courses",
}));
vi.mock("@/lib/catalog/hooks", () => ({ useCourses, useCategories }));

import CoursesPage from "@/app/(marketing)/(site)/courses/page";

const paged = (items: unknown[]) => ({
  isPending: false,
  isError: false,
  refetch: vi.fn(),
  data: { data: items, meta: { current_page: 1, per_page: 12, total: items.length, last_page: 1 }, links: { first: null, last: null, prev: null, next: null } },
});

describe("CoursesPage", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    useCategories.mockReturnValue({ isPending: false, isError: false, data: [], refetch: vi.fn() });
  });

  it("renders course cards from the API", () => {
    useCourses.mockReturnValue(paged([{ id: "c1", title: "React Basics", slug: "react", subtitle: null, thumbnail_path: null, is_featured: false, level: "Beginner", language: "English", published_at: null }]));
    renderWithI18n(<CoursesPage />);
    expect(screen.getByText("React Basics")).toBeInTheDocument();
  });

  it("shows the empty state with no results", () => {
    useCourses.mockReturnValue(paged([]));
    renderWithI18n(<CoursesPage />);
    expect(screen.getByText("No courses match your filters.")).toBeInTheDocument();
  });
});
