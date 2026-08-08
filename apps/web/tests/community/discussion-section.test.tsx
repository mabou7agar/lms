import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";

const { useThreads, useThread, createMutate, replyMutate } = vi.hoisted(() => ({
  useThreads: vi.fn(),
  useThread: vi.fn(),
  createMutate: vi.fn(),
  replyMutate: vi.fn(),
}));

vi.mock("@/lib/community/forum-hooks", () => ({
  useThreads,
  useThread,
  useCreateThread: () => ({ mutate: createMutate, isPending: false }),
  useReplyToThread: () => ({ mutate: replyMutate, isPending: false }),
  useReportThread: () => ({ mutateAsync: vi.fn(() => Promise.resolve()), isPending: false }),
  useReportPost: () => ({ mutateAsync: vi.fn(() => Promise.resolve()), isPending: false }),
}));

import { DiscussionSection } from "@/components/community/discussion-section";

const author = { name: "Sara", public_id: "u2" };

const listResult = () => ({
  isPending: false,
  isError: false,
  refetch: vi.fn(),
  data: {
    data: [
      {
        id: "t1",
        title: "Welcome thread",
        body: "Say hi",
        pinned: true,
        locked: false,
        solved: false,
        solved_post: null,
        posts_count: 2,
        last_post_at: null,
        created_at: null,
        updated_at: null,
        author,
      },
    ],
    meta: { current_page: 1, per_page: 20, total: 1, last_page: 1 },
    links: { first: null, last: null, prev: null, next: null },
  },
});

const detailResult = () => ({
  isPending: false,
  isError: false,
  refetch: vi.fn(),
  data: {
    data: {
      thread: {
        id: "t1",
        title: "Welcome thread",
        body: "Say hi",
        pinned: true,
        locked: false,
        solved: false,
        solved_post: null,
        posts_count: 1,
        last_post_at: null,
        created_at: null,
        updated_at: null,
        author,
      },
      posts: [
        {
          id: "p1",
          body: "First post",
          is_instructor: false,
          parent_id: null,
          created_at: null,
          updated_at: null,
          author,
          replies: [],
        },
      ],
    },
    meta: { posts: { current_page: 1, per_page: 20, total: 1, last_page: 1 } },
  },
});

describe("DiscussionSection", () => {
  beforeEach(() => vi.clearAllMocks());

  it("renders the thread list with badges + reply count", () => {
    useThreads.mockReturnValue(listResult());
    renderWithI18n(<DiscussionSection courseId="c1" />);

    expect(screen.getByText("Welcome thread")).toBeInTheDocument();
    expect(screen.getByText(/Pinned/i)).toBeInTheDocument();
    expect(screen.getByText(/2 replies/i)).toBeInTheDocument();
  });

  it("creates a new thread", async () => {
    useThreads.mockReturnValue(listResult());
    renderWithI18n(<DiscussionSection courseId="c1" />);

    await userEvent.click(screen.getByRole("button", { name: "Start a discussion" }));
    await userEvent.type(screen.getByLabelText("Title"), "New topic");
    await userEvent.type(screen.getByLabelText("Message"), "Body text");
    await userEvent.click(screen.getByRole("button", { name: "Post discussion" }));

    expect(createMutate).toHaveBeenCalledWith({ title: "New topic", body: "Body text" }, expect.anything());
  });

  it("opens a thread and posts a top-level reply", async () => {
    useThreads.mockReturnValue(listResult());
    useThread.mockReturnValue(detailResult());
    renderWithI18n(<DiscussionSection courseId="c1" />);

    await userEvent.click(screen.getByRole("button", { name: /Welcome thread/i }));
    expect(screen.getByText("First post")).toBeInTheDocument();

    await userEvent.type(screen.getByLabelText("Reply"), "Thanks!");
    await userEvent.click(screen.getByRole("button", { name: "Post reply" }));

    expect(replyMutate).toHaveBeenCalledWith({ body: "Thanks!", parent_id: null }, expect.anything());
  });
});
