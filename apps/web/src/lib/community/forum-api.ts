/**
 * Course discussion forum — typed API client.
 *
 * Wraps the authenticated forum endpoints reached through the same-origin BFF proxy
 * (`@/lib/api/client`). Paths, payload keys and resource fields are matched VERBATIM against
 * `app/Domains/Forum/Http/{Controllers,Resources}`. Every route is participation-gated server-side.
 *
 * NOTE the route shape: threads live under `courses/{course}/forum/threads` and `forum/threads/{thread}`;
 * posts under `forum/threads/{thread}/posts` and `forum/posts/{post}`.
 *
 * Thread listing uses the standard `Paginated<T>` envelope; a thread's detail returns a bespoke
 * `{ data: { thread, posts }, meta: { posts } }` envelope, modelled explicitly below.
 */
import { api } from "@/lib/api/client";
import type { Paginated } from "@/types/api";
import type { ReportInput } from "./reviews-api";

/** ForumAuthor — boundary-safe author projection. Note the `public_id` key (not `id`). */
export interface ForumAuthor {
  name: string;
  public_id: string;
}

/** ForumThreadResource — public shape of a discussion thread. */
export interface ForumThread {
  id: string;
  title: string;
  body: string;
  pinned: boolean;
  locked: boolean;
  solved: boolean;
  solved_post: string | null;
  posts_count: number;
  last_post_at: string | null;
  created_at: string | null;
  updated_at: string | null;
  author: ForumAuthor | null;
}

/** ForumPostResource — public shape of a post, with any loaded one-level replies. */
export interface ForumPost {
  id: string;
  body: string;
  is_instructor: boolean;
  parent_id: string | null;
  created_at: string | null;
  updated_at: string | null;
  author: ForumAuthor | null;
  replies: ForumPost[] | null;
}

/** GET thread detail envelope: `{ data: { thread, posts }, meta: { posts } }`. */
export interface ThreadDetailResponse {
  data: {
    thread: ForumThread;
    posts: ForumPost[];
  };
  meta: {
    posts: { current_page: number; per_page: number; total: number; last_page: number };
  };
}

export interface ThreadListParams {
  q?: string;
  page?: number;
  per_page?: number;
}

export interface CreateThreadInput {
  title: string;
  body: string;
}

export interface CreatePostInput {
  body: string;
  parent_id?: string | null;
}

function listQuery(params: ThreadListParams): string {
  const p = new URLSearchParams();
  if (params.q) p.set("q", params.q);
  if (params.page) p.set("page", String(params.page));
  if (params.per_page) p.set("per_page", String(params.per_page));
  const s = p.toString();
  return s ? `?${s}` : "";
}

/** GET /api/v1/courses/{course}/forum/threads — pinned first, then most-recent activity. */
export const listThreads = (course: string, params: ThreadListParams = {}): Promise<Paginated<ForumThread>> =>
  api.get<Paginated<ForumThread>>(`courses/${course}/forum/threads${listQuery(params)}`);

/** GET /api/v1/forum/threads/{thread} — the thread plus a page of posts (with one-level replies). */
export const getThread = (thread: string, page?: number): Promise<ThreadDetailResponse> => {
  const q = page ? `?page=${page}` : "";
  return api.get<ThreadDetailResponse>(`forum/threads/${thread}${q}`);
};

/** POST /api/v1/courses/{course}/forum/threads — start a thread (enrollment enforced server-side). */
export const createThread = (course: string, input: CreateThreadInput): Promise<ForumThread> =>
  api.data<ForumThread>(`courses/${course}/forum/threads`, { method: "POST", body: input });

/** POST /api/v1/forum/threads/{thread}/posts — reply to a thread (optionally to a top-level post). */
export const replyToThread = (thread: string, input: CreatePostInput): Promise<ForumPost> =>
  api.data<ForumPost>(`forum/threads/${thread}/posts`, { method: "POST", body: input });

/** POST /api/v1/forum/threads/{thread}/report — flag a thread for moderation. */
export const reportThread = (thread: string, input: ReportInput): Promise<void> =>
  api.post<void>(`forum/threads/${thread}/report`, input);

/** POST /api/v1/forum/posts/{post}/report — flag a post for moderation. */
export const reportPost = (post: string, input: ReportInput): Promise<void> =>
  api.post<void>(`forum/posts/${post}/report`, input);
