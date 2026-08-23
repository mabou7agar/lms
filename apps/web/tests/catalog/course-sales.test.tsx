import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";

const { useCourse, addMutate, enrollMutate, push, authState } = vi.hoisted(() => ({
  useCourse: vi.fn(),
  addMutate: vi.fn(),
  enrollMutate: vi.fn(),
  push: vi.fn(),
  authState: { status: "authenticated" as "authenticated" | "guest" },
}));

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push, replace: vi.fn() }),
  useParams: () => ({ public_id: "course-1" }),
}));
vi.mock("@/lib/auth/auth-context", () => ({ useAuth: () => authState }));
vi.mock("@/lib/catalog/hooks", () => ({
  useCourse,
  useEnroll: () => ({ mutate: enrollMutate, isPending: false }),
}));
vi.mock("@/lib/commerce/hooks", () => ({
  useAddToCart: () => ({ mutate: addMutate, isPending: false, variables: undefined }),
}));
// The reviews panel on this page talks to react-query directly; stub it so the test exercises the
// sales panel without standing up a QueryClient.
vi.mock("@/components/community/reviews-section", () => ({
  ReviewsSection: () => <section data-testid="reviews" />,
}));

import { CourseDetailsClient } from "@/app/(marketing)/(site)/courses/[public_id]/course-details-client";

const purchasable = {
  purchasable: true as const,
  product_id: "prod-1",
  product_type: "course" as const,
  price: { currency: "SAR", amount_minor: 49900, effective_minor: 29900, on_sale: true },
  audience: "individual" as const,
  access: { duration_type: "fixed_months" as const, duration_value: 6, ends_at: null },
  certificate: { enabled: true, expiry_type: "none" as const, expiry_value: null },
  included_in_bundles: [],
};

const course = (purchase: unknown) => ({
  isPending: false,
  isError: false,
  refetch: vi.fn(),
  data: {
    id: "course-1",
    title: "Business AI",
    slug: "business-ai",
    subtitle: "Applied AI",
    description: "About the course",
    status: "published",
    visibility: "public",
    is_featured: false,
    thumbnail_path: null,
    trailer_path: null,
    level: null,
    language: null,
    categories: [],
    tags: [],
    trainers: [],
    related: [],
    published_at: null,
    purchase,
  },
});

describe("Course detail sales panel", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    authState.status = "authenticated";
  });

  it("shows the sale price, access and certificate terms with an add-to-cart CTA", () => {
    useCourse.mockReturnValue(course(purchasable));
    renderWithI18n(<CourseDetailsClient />);

    expect(screen.getAllByText(/299/).length).toBeGreaterThan(0);
    expect(screen.getAllByText(/499/).length).toBeGreaterThan(0);
    expect(screen.getByText(/6 months of access/i)).toBeInTheDocument();
    expect(screen.getByText(/Certificate included/i)).toBeInTheDocument();
    expect(screen.getAllByRole("button", { name: /Add to cart/i }).length).toBeGreaterThan(0);
  });

  // Every public course is paid, so the old payment-free wording must never reappear: the enrol
  // endpoint refuses a sold course, which would make these CTAs a dead end.
  it("never renders free-enrolment wording", () => {
    useCourse.mockReturnValue(course(purchasable));
    renderWithI18n(<CourseDetailsClient />);

    expect(screen.queryByText(/free account/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/enroll in seconds/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/sign in to enroll/i)).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /^Enroll now$/i })).not.toBeInTheDocument();
  });

  it("adds the course product to the cart for a signed-in buyer", async () => {
    useCourse.mockReturnValue(course(purchasable));
    renderWithI18n(<CourseDetailsClient />);

    await userEvent.click(screen.getAllByRole("button", { name: /Add to cart/i })[0]);

    expect(addMutate).toHaveBeenCalledWith({ product: "prod-1" }, expect.anything());
  });

  it("sends a guest to sign in and back to the course instead of adding to the cart", async () => {
    authState.status = "guest";
    useCourse.mockReturnValue(course(purchasable));
    renderWithI18n(<CourseDetailsClient />);

    await userEvent.click(screen.getAllByRole("button", { name: /Sign in to purchase/i })[0]);

    expect(addMutate).not.toHaveBeenCalled();
    expect(push).toHaveBeenCalledWith("/login?redirect=/courses/course-1");
  });

  it("offers free enrollment when no active product sells the course", async () => {
    useCourse.mockReturnValue(course({ purchasable: false }));

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

    renderWithI18n(<CourseDetailsClient />);

    const cta = screen.getAllByRole("button", { name: /Enroll for free/i })[0];
    await userEvent.click(cta);

    expect(enrollMutate).toHaveBeenCalledWith("course-1", expect.anything());
    expect(addMutate).not.toHaveBeenCalled();
  });
});
