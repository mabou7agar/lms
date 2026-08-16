import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";

const { useLesson, progressMutate, bookmarkMutate, noteMutate } = vi.hoisted(() => ({
  useLesson: vi.fn(),
  progressMutate: vi.fn(),
  bookmarkMutate: vi.fn(),
  noteMutate: vi.fn(),
}));
vi.mock("next/navigation", () => ({
  useParams: () => ({ public_id: "les1" }),
  useRouter: () => ({ replace: vi.fn(), push: vi.fn() }),
  usePathname: () => "/learn",
  useSearchParams: () => new URLSearchParams(),
}));
vi.mock("@/lib/auth/auth-context", () => ({ useAuth: () => ({ status: "authenticated", user: { id: "u1" } }) }));
vi.mock("@/lib/learning/hooks", () => ({
  useLesson,
  useRecordProgress: () => ({ mutate: progressMutate, isPending: false }),
  useToggleBookmark: () => ({ mutate: bookmarkMutate, isPending: false }),
  useUpsertNote: () => ({ mutate: noteMutate, isPending: false }),
}));

import LessonPage from "@/app/(learning)/(player)/lessons/[public_id]/page";

const lesson = (over: Record<string, unknown> = {}) => ({
  isPending: false,
  isError: false,
  refetch: vi.fn(),
  data: {
    id: "les1", title: "Intro Lesson", type: "article", content: { body: "Hello world" }, is_preview: false,
    playback: null, progress: { status: "in_progress", position_seconds: null }, bookmarked: false, note: "",
    navigation: { previous: null, next: "les2" }, ...over,
  },
});

describe("LessonPage", () => {
  beforeEach(() => vi.clearAllMocks());

  it("renders the lesson and wires complete/bookmark/note actions", async () => {
    useLesson.mockReturnValue(lesson());
    renderWithI18n(<LessonPage />);
    expect(screen.getByRole("heading", { name: "Intro Lesson" })).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: /Mark as complete/i }));
    expect(progressMutate).toHaveBeenCalledWith({ status: "completed" }, expect.anything());

    await userEvent.click(screen.getByRole("button", { name: /Bookmark/i }));
    expect(bookmarkMutate).toHaveBeenCalled();

    await userEvent.type(screen.getByPlaceholderText(/private note/i), "note text");
    await userEvent.click(screen.getByRole("button", { name: "Save note" }));
    expect(noteMutate).toHaveBeenCalledWith("note text", expect.anything());
  });

  it("auto-marks a not-started lesson as in progress", () => {
    useLesson.mockReturnValue(lesson({ progress: { status: "not_started", position_seconds: null } }));

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

    renderWithI18n(<LessonPage />);
    expect(progressMutate).toHaveBeenCalledWith({ status: "in_progress" });
  });
});
