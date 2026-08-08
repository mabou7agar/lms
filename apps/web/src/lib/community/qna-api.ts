/**
 * Course Q&A — typed API client.
 *
 * Wraps the authenticated Q&A endpoints reached through the same-origin BFF proxy
 * (`@/lib/api/client`). Paths, payload keys and resource fields are matched VERBATIM against
 * `app/Domains/Qna/Http/{Controllers,Resources}`. Every route is participation-gated server-side.
 *
 * Listing uses the standard `Paginated<T>` envelope; a single question (with its answers) uses the
 * `{ data }` success envelope.
 */
import { api } from "@/lib/api/client";
import type { Paginated } from "@/types/api";
import type { ReportInput } from "./reviews-api";

/** Boundary-safe author projection ({ public_id, name }). `id` here IS the user public_id. */
export interface CommunityAuthor {
  id: string;
  name: string;
}

export type QuestionStatus = "open" | "resolved";

/** QuestionResource — list/summary shape of a question. */
export interface Question {
  id: string;
  title: string;
  body: string;
  status: QuestionStatus;
  pinned: boolean;
  pinned_at: string | null;
  lesson_timestamp_seconds: number | null;
  answers_count: number;
  /** Present only when the accepted-answer relation is loaded (list view). */
  accepted_answer_id?: string | null;
  is_resolved: boolean;
  author: CommunityAuthor | null;
  created_at: string | null;
  updated_at: string | null;
}

/** AnswerResource — the wire shape of an answer. */
export interface Answer {
  id: string;
  body: string;
  is_instructor: boolean;
  accepted: boolean;
  author: CommunityAuthor | null;
  created_at: string | null;
  updated_at: string | null;
}

/** QuestionDetailResource — full question view with embedded answers (accepted first). */
export interface QuestionDetail extends Question {
  accepted_answer_id: string | null;
  answers: Answer[];
}

export type QuestionSort = "recent" | "pinned" | "unanswered";

export interface QuestionListParams {
  sort?: QuestionSort;
  status?: QuestionStatus;
  lesson_id?: string;
  page?: number;
  per_page?: number;
}

export interface AskQuestionInput {
  title: string;
  body: string;
  lesson_id?: string | null;
  lesson_timestamp_seconds?: number | null;
}

export interface AnswerInput {
  body: string;
}

function listQuery(params: QuestionListParams): string {
  const p = new URLSearchParams();
  if (params.sort) p.set("sort", params.sort);
  if (params.status) p.set("status", params.status);
  if (params.lesson_id) p.set("lesson_id", params.lesson_id);
  if (params.page) p.set("page", String(params.page));
  if (params.per_page) p.set("per_page", String(params.per_page));
  const s = p.toString();
  return s ? `?${s}` : "";
}

/** GET /api/v1/courses/{course}/questions — paginated, filterable. */
export const listQuestions = (course: string, params: QuestionListParams = {}): Promise<Paginated<Question>> =>
  api.get<Paginated<Question>>(`courses/${course}/questions${listQuery(params)}`);

/** GET /api/v1/questions/{question} — the full thread with answers. */
export const getQuestion = (question: string): Promise<QuestionDetail> =>
  api.data<QuestionDetail>(`questions/${question}`);

/** POST /api/v1/courses/{course}/questions — ask a question (enrollment enforced server-side). */
export const askQuestion = (course: string, input: AskQuestionInput): Promise<Question> =>
  api.data<Question>(`courses/${course}/questions`, { method: "POST", body: input });

/** POST /api/v1/questions/{question}/answers — post an answer. */
export const answerQuestion = (question: string, input: AnswerInput): Promise<Answer> =>
  api.data<Answer>(`questions/${question}/answers`, { method: "POST", body: input });

/** POST /api/v1/answers/{answer}/accept — question author (or instructor) accepts this answer. */
export const acceptAnswer = (answer: string): Promise<Question> =>
  api.data<Question>(`answers/${answer}/accept`, { method: "POST" });

/** POST /api/v1/questions/{question}/report — flag a question for moderation. */
export const reportQuestion = (question: string, input: ReportInput): Promise<void> =>
  api.post<void>(`questions/${question}/report`, input);

/** POST /api/v1/answers/{answer}/report — flag an answer for moderation. */
export const reportAnswer = (answer: string, input: ReportInput): Promise<void> =>
  api.post<void>(`answers/${answer}/report`, input);
