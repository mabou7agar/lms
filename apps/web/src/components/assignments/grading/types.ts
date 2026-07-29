/**
 * Local view-model types for the instructor grading UI, mirroring the FROZEN backend resources
 * (SubmissionResource, SubmissionListResource, RubricResource). Swap for D3's exported types once
 * confirmed. Unlike the learner shapes, the instructor grade DOES carry `private_notes` and the
 * unreleased `version` — these must never leak into any learner surface.
 */

import type { Rubric, SubmissionStatus } from '../submission/types';

export type { Rubric, RubricCriterion, RubricLevel, SubmissionStatus } from '../submission/types';

export interface GradingSubmissionFile {
  id: string;
  media_id: string;
  filename: string;
}

export interface RubricResultEntry {
  criterion_public_id: string;
  level_public_id: string;
  comment?: string | null;
}

/** The grader-visible grade block (SubmissionResource.grade). */
export interface InstructorGrade {
  score: number | null;
  passed: boolean | null;
  feedback: string | null;
  private_notes: string | null;
  rubric_result: RubricResultEntry[] | null;
  version: number;
  released_at: string | null;
}

/** SubmissionResource */
export interface InstructorSubmission {
  id: string;
  assignment_id: string | null;
  learner_id: number | string;
  attempt_no: number;
  status: SubmissionStatus;
  submitted_at: string | null;
  is_late: boolean;
  text_response: string | null;
  external_url: string | null;
  files: GradingSubmissionFile[];
  rubric_snapshot: Rubric | null;
  grade: InstructorGrade | null;
}

/** SubmissionListResource (grading queue row). */
export interface QueueRow {
  id: string;
  learner_id: number | string;
  attempt_no: number;
  status: SubmissionStatus;
  submitted_at: string | null;
  is_late: boolean;
  has_grade: boolean;
  released: boolean;
  score: number | null;
}

export type QueueFilter = 'missing' | 'late' | undefined;

/** Shape of D3's paginated queue payload (Laravel paginator envelope). */
export interface QueuePage {
  data: QueueRow[];
  meta?: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
  // Some clients flatten pagination onto the root; both are tolerated by the queue component.
  current_page?: number;
  last_page?: number;
  per_page?: number;
  total?: number;
}
