import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import { renderWithI18n } from "../render";

const { useLearnCourse } = vi.hoisted(() => ({ useLearnCourse: vi.fn() }));
vi.mock("next/navigation", () => ({
  useParams: () => ({ public_id: "crs1" }),
  useRouter: () => ({ replace: vi.fn(), push: vi.fn() }),
  usePathname: () => "/learn",
  useSearchParams: () => new URLSearchParams(),
}));
vi.mock("@/lib/auth/auth-context", () => ({ useAuth: () => ({ status: "authenticated", user: { id: "u1" } }) }));
vi.mock("@/lib/learning/hooks", () => ({ useLearnCourse }));
// The community panel is data-driven (React Query); stub it so this page test stays hermetic.
vi.mock("@/components/community/course-community-panel", () => ({ CourseCommunityPanel: () => null }));

import CourseLearnPage from "@/app/(learning)/(player)/learn/[public_id]/page";

describe("CourseLearnPage", () => {
  beforeEach(() => vi.clearAllMocks());

  it("renders curriculum + progress and a continue link to the first open lesson", () => {
    useLearnCourse.mockReturnValue({
      isPending: false,
      isError: false,
      refetch: vi.fn(),
      data: {
        course: { id: "crs1", title: "Course One", slug: "c1" },
        enrollment: { id: "e1", status: "active", progress_percentage: 50 },
        sections: [{ id: "s1", title: "Basics", lessons: [
          { id: "l1", title: "Lesson A", type: "article", is_preview: false, has_media: false, completed: true, locked: false },
          { id: "l2", title: "Lesson B", type: "video", is_preview: false, has_media: true, completed: false, locked: false },
        ] }],
      },
    });
    renderWithI18n(<CourseLearnPage />);
    expect(screen.getByRole("heading", { name: "Course One" })).toBeInTheDocument();
    expect(screen.getByText("Lesson B")).toBeInTheDocument();
    expect(screen.getByText("50%")).toBeInTheDocument();
    // First open (not completed, not locked) lesson is l2 → continue link.
    expect(screen.getAllByRole("link").some((a) => a.getAttribute("href") === "/lessons/l2")).toBe(true);
  });
});
