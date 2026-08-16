import { api } from "@/lib/api/client";

/**
 * Course files and course Q&A — the two things attached to a course that are neither curriculum nor
 * commerce.
 *
 * A resource never carries a storage location. The list says what exists; bytes are obtained by
 * calling `downloadResource`, which re-checks entitlement server-side and returns a short-lived
 * signed URL. That is deliberate: a link minted once and cached would outlive the entitlement rules
 * that justified it, including a company seat's expiry.
 */

export type CourseResource = {
  id: string;
  title: string;
  description: string | null;
  visibility: "enrolled" | "preview";
  downloadable: boolean;
  is_preview: boolean;
  scope: "course" | "lesson";
  position: number;
  file: { mime_type: string | null; size_bytes: number | null };
  created_at: string | null;
};

/** `entitled` says whether the viewer may take any of these, not just see that they exist. */
export type CourseResourceList = { entitled: boolean; items: CourseResource[] };

export type QuestionStatus = "open" | "answered" | "resolved" | "closed" | "hidden";

export type QuestionAuthor = { id: string; name: string } | null;

export type CourseQuestion = {
  id: string;
  title: string;
  body: string;
  status: QuestionStatus;
  pinned: boolean;
  pinned_at: string | null;
  lesson_timestamp_seconds: number | null;
  answers_count: number;
  accepted_answer_id?: string | null;
  is_resolved: boolean;
  visibility: "public" | "private";
  is_private: boolean;
  /** Stamped by the first INSTRUCTOR reply — a peer answering does not start this clock. */
  first_response_at: string | null;
  first_response_minutes: number | null;
  awaiting_response: boolean;
  closed_at: string | null;
  author: QuestionAuthor;
  created_at: string | null;
  updated_at: string | null;
};

export type QuestionAnswer = {
  id: string;
  body: string;
  is_instructor: boolean;
  accepted: boolean;
  /** The course's authoritative answer. Distinct from `accepted`, which is the asker's verdict. */
  is_official: boolean;
  author: QuestionAuthor;
  created_at: string | null;
  updated_at: string | null;
};

export type QuestionThread = CourseQuestion & { answers: QuestionAnswer[] };

/** The instructor queue: their questions plus how responsive they have actually been. */
export type QnaMetrics = {
  questions: number;
  answered: number;
  unanswered: number;
  overdue: number;
  response_rate: number;
  avg_first_response_minutes: number | null;
  median_first_response_minutes: number | null;
  sla_hours: number;
};

export type InstructorQueue = {
  metrics: QnaMetrics;
  questions: CourseQuestion[];
  meta: { total: number };
};

// ── Resources ─────────────────────────────────────────────────────────────────────────────────────

/** GET /courses/{id}/resources — course-level files, or everything with scope=all. */
export const getCourseResources = (courseId: string, scope: "course" | "all" = "course") =>
  api.data<CourseResourceList>(`courses/${courseId}/resources?scope=${scope}`);

/** GET /lessons/{id}/resources — the files pinned to one lesson, for the player. */
export const getLessonResources = (lessonId: string) =>
  api.data<CourseResourceList>(`lessons/${lessonId}/resources`);

/** POST /resources/{id}/download — mint a short-lived signed URL. Entitlement is re-checked here. */
export const downloadResource = (resourceId: string) =>
  api.data<{ url: string; expires_at: string; title: string }>(`resources/${resourceId}/download`, {
    method: "POST",
  });

// ── Q&A ───────────────────────────────────────────────────────────────────────────────────────────

export type QuestionFilters = {
  lesson_id?: string;
  status?: QuestionStatus;
  sort?: "recent" | "pinned" | "unanswered" | "overdue";
  search?: string;
};

function questionQuery(filters: QuestionFilters): string {
  const params = new URLSearchParams();
  if (filters.lesson_id) params.set("lesson_id", filters.lesson_id);
  if (filters.status) params.set("status", filters.status);
  if (filters.sort) params.set("sort", filters.sort);
  if (filters.search) params.set("search", filters.search);
  const qs = params.toString();
  return qs ? `?${qs}` : "";
}

/** GET /courses/{id}/questions — the course's threads, filtered. Private ones are already excluded. */
export const getCourseQuestions = (courseId: string, filters: QuestionFilters = {}) =>
  api.data<CourseQuestion[]>(`courses/${courseId}/questions${questionQuery(filters)}`);

/** GET /questions/{id} — one thread with its answers. */
export const getQuestion = (questionId: string) =>
  api.data<QuestionThread>(`questions/${questionId}`);

export const askQuestion = (
  courseId: string,
  body: {
    title: string;
    body: string;
    lesson_id?: string | null;
    lesson_timestamp_seconds?: number | null;
    visibility?: "public" | "private";
  },
) => api.data<CourseQuestion>(`courses/${courseId}/questions`, { method: "POST", body });

export const answerQuestion = (questionId: string, body: string) =>
  api.data<QuestionAnswer>(`questions/${questionId}/answers`, { method: "POST", body: { body } });

export const acceptAnswer = (answerId: string) =>
  api.data<CourseQuestion>(`answers/${answerId}/accept`, { method: "POST" });

export const markAnswerOfficial = (answerId: string) =>
  api.data<QuestionAnswer>(`answers/${answerId}/official`, { method: "POST" });

export const closeQuestion = (questionId: string) =>
  api.data<CourseQuestion>(`questions/${questionId}/close`, { method: "POST" });

/** GET /instructor/questions — every course the instructor teaches, with SLA metrics attached. */
export const getInstructorQueue = (filter: "all" | "unanswered" | "overdue" | "answered" = "unanswered") =>
  api.data<InstructorQueue>(`instructor/questions?filter=${filter}`);
