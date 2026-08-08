import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";

const { useReviews, createMutate, updateMutate, deleteMutate, helpfulMutate, reportAsync } = vi.hoisted(() => ({
  useReviews: vi.fn(),
  createMutate: vi.fn(),
  updateMutate: vi.fn(),
  deleteMutate: vi.fn(),
  helpfulMutate: vi.fn(),
  reportAsync: vi.fn(() => Promise.resolve()),
}));

vi.mock("@/lib/community/reviews-hooks", () => ({
  useReviews,
  useCreateReview: () => ({ mutate: createMutate, isPending: false }),
  useUpdateReview: () => ({ mutate: updateMutate, isPending: false }),
  useDeleteReview: () => ({ mutate: deleteMutate, isPending: false }),
  useMarkReviewHelpful: () => ({ mutate: helpfulMutate, isPending: false }),
  useReportReview: () => ({ mutateAsync: reportAsync, isPending: false }),
}));

import { ReviewsSection } from "@/components/community/reviews-section";

const listResult = (over: Record<string, unknown> = {}) => ({
  isPending: false,
  isError: false,
  refetch: vi.fn(),
  data: {
    data: [
      {
        id: "rev1",
        rating: 4,
        body: "Really solid course",
        status: "published",
        verified: true,
        helpful_count: 0,
        instructor_response: "Thanks for the kind words!",
        responded_at: null,
        created_at: null,
        updated_at: null,
      },
    ],
    meta: {
      aggregate: {
        reviews_count: 1,
        average_rating: 4.0,
        distribution: { "1": 0, "2": 0, "3": 0, "4": 1, "5": 0 },
      },
      pagination: { current_page: 1, per_page: 10, total: 1, last_page: 1 },
    },
  },
  ...over,
});

describe("ReviewsSection", () => {
  beforeEach(() => vi.clearAllMocks());

  it("renders the aggregate, a review, and the instructor response", () => {
    useReviews.mockReturnValue(listResult());
    renderWithI18n(<ReviewsSection courseId="c1" canReview isAuthenticated />);

    expect(screen.getByText("4.0")).toBeInTheDocument();
    expect(screen.getByText("Really solid course")).toBeInTheDocument();
    expect(screen.getByText(/Instructor response/i)).toBeInTheDocument();
    expect(screen.getByText("Thanks for the kind words!")).toBeInTheDocument();
  });

  it("records a helpful vote", async () => {
    useReviews.mockReturnValue(listResult());
    renderWithI18n(<ReviewsSection courseId="c1" canReview isAuthenticated />);

    await userEvent.click(screen.getByRole("button", { name: "Helpful" }));
    expect(helpfulMutate).toHaveBeenCalledWith("rev1");
  });

  it("submits a new review from the write form", async () => {
    useReviews.mockReturnValue(listResult());
    renderWithI18n(<ReviewsSection courseId="c1" canReview isAuthenticated />);

    await userEvent.click(screen.getByRole("button", { name: "Write a review" }));
    await userEvent.click(screen.getByRole("radio", { name: /Rate 5 out of 5/i }));
    await userEvent.type(screen.getByLabelText("Your review"), "Excellent");
    await userEvent.click(screen.getByRole("button", { name: "Submit review" }));

    expect(createMutate).toHaveBeenCalledWith({ rating: 5, body: "Excellent" }, expect.anything());
  });

  it("hides the write form when the viewer cannot review", () => {
    useReviews.mockReturnValue(listResult());
    renderWithI18n(<ReviewsSection courseId="c1" canReview={false} isAuthenticated={false} />);

    expect(screen.queryByRole("button", { name: "Write a review" })).not.toBeInTheDocument();
    expect(screen.getByText(/Sign in to write a review/i)).toBeInTheDocument();
  });
});
