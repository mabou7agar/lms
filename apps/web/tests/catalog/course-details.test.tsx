import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import { renderWithI18n } from "../render";

const { useCourse } = vi.hoisted(() => ({ useCourse: vi.fn() }));
vi.mock("next/navigation", () => ({
  useParams: () => ({ public_id: "c1" }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
}));
vi.mock("@/lib/catalog/hooks", () => ({ useCourse }));
vi.mock("@/lib/commerce/hooks", () => ({
  useAddToCart: () => ({ mutate: vi.fn(), isPending: false, variables: undefined }),
}));
vi.mock("@/lib/auth/auth-context", () => ({ useAuth: () => ({ status: "guest" }) }));
// ReviewsSection is a React-Query-driven child with its own tests; stub it so this page test needs no QueryClient.
vi.mock("@/components/community/reviews-section", () => ({ ReviewsSection: () => null }));

// The course/lesson pages now embed the courseware panels (files + Q&A). Those are react-query
// backed, and this suite renders without a QueryClientProvider, so their hooks are stubbed the same
// way every other data hook here is.
vi.mock("@/lib/courseware/hooks", () => ({
  useCourseResources: () => ({ isPending: false, isError: false, data: { entitled: false, items: [] } }),
  useLessonResources: () => ({ isPending: false, isError: false, data: { entitled: false, items: [] } }),
  useCourseQuestions: () => ({ isPending: false, isError: false, data: [] }),
  useQuestion: () => ({ isPending: false, isError: false, data: undefined }),
  useInstructorQueue: () => ({ isPending: false, isError: false, data: undefined }),
  useDownloadResource: () => ({ mutate: vi.fn(), isPending: false, variables: undefined }),
  useAskQuestion: () => ({ mutate: vi.fn(), isPending: false }),
  useAnswerQuestion: () => ({ mutate: vi.fn(), isPending: false }),
  useAcceptAnswer: () => ({ mutate: vi.fn(), isPending: false }),
  useMarkAnswerOfficial: () => ({ mutate: vi.fn(), isPending: false }),
  useCloseQuestion: () => ({ mutate: vi.fn(), isPending: false }),
}));


import CourseDetailsPage from "@/app/(marketing)/(site)/courses/[public_id]/page";

describe("CourseDetailsPage", () => {
  beforeEach(() => vi.clearAllMocks());

  it("renders the course detail and a purchase CTA for guests", () => {
    useCourse.mockReturnValue({
      isPending: false,
      isError: false,
      refetch: vi.fn(),
      data: {
        id: "c1", title: "Deep Dive", slug: "dd", subtitle: "Advanced topics", description: "Body",
        status: "published", visibility: "public", is_featured: true, thumbnail_path: null,
        level: { id: "l1", name: "Advanced" }, language: { id: "lang1", name: "English" },
        categories: [], tags: [], trainers: [], related: [],
        // Every public course is sold, so the page always carries a purchase summary.
        purchase: {
          purchasable: true,
          product_id: "p1",
          product_type: "course",
          price: { currency: "SAR", amount_minor: 19900, effective_minor: 19900, on_sale: false },
          audience: "individual",
          access: { duration_type: "lifetime", duration_value: null, ends_at: null },
          certificate: { enabled: true, expiry_type: "none", expiry_value: null },
          included_in_bundles: [],
        },
      },
    });
    renderWithI18n(<CourseDetailsPage />);
    expect(screen.getByRole("heading", { name: "Deep Dive" })).toBeInTheDocument();
    // The guest CTA appears in both the desktop sticky panel and the mobile bottom bar, and sells
    // rather than offering a payment-free enrolment.
    expect(screen.getAllByRole("button", { name: "Sign in to purchase" }).length).toBeGreaterThan(0);
    expect(screen.queryByRole("link", { name: /Sign in to enroll/i })).not.toBeInTheDocument();
  });
});
