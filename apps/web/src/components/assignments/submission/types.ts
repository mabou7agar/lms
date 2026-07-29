/**
 * Local view-model types for the learner submission UI. These mirror the FROZEN backend resources
 * exactly (LearnerAssignmentResource, LearnerSubmissionResource, RubricResource). They are declared
 * here so this D4 slice compiles independently of D3's exact type export names; the integrator may
 * swap them for `import type { ... } from '@/lib/assignments/assignments-types'` once confirmed.
 *
 * CRITICAL: the learner shapes below carry NO `private_notes` and NO unreleased score — the grade
 * block is present only when released. Never widen these to the instructor resource.
 */

export type SubmissionStatus =
  | 'draft'
  | 'submitted'
  | 'under_review'
  | 'changes_requested'
  | 'graded'
  | 'released';

export type SubmissionType = 'text' | 'file' | 'url' | 'text_file' | string;

export type LatePolicy = 'accept' | 'reject' | 'penalize' | string;

export interface RubricLevel {
  id: string;
  title: string;
  description: string | null;
  points: number;
  position: number;
}

export interface RubricCriterion {
  id: string;
  title: string;
  description: string | null;
  position: number;
  max_points: number;
  levels: RubricLevel[];
}

export interface Rubric {
  id: string;
  title: string;
  total_points: number;
  criteria: RubricCriterion[];
}

/** LearnerAssignmentResource */
export interface LearnerAssignment {
  id: string;
  title: string;
  instructions: string | null;
  submission_type: SubmissionType;
  allowed_file_types: string[] | null;
  max_file_size: number | null;
  max_files: number | null;
  attempt_limit: number | null;
  due_at: string | null;
  late_policy: LatePolicy;
  max_grade: number;
  passing_grade: number | null;
  rubric: Rubric | null;
}

export interface SubmissionFile {
  id: string;
  media_id: string;
  filename: string;
}

/** The released-grade block on LearnerSubmissionResource. NO private_notes. */
export interface ReleasedGrade {
  score: number | null;
  passed: boolean | null;
  feedback: string | null;
  rubric_result: RubricResultEntry[] | null;
  released_at: string | null;
}

export interface RubricResultEntry {
  criterion_public_id: string;
  level_public_id: string;
  // graders may attach a per-criterion comment on the snapshot result; optional/forward-compatible.
  comment?: string | null;
}

/** LearnerSubmissionResource */
export interface LearnerSubmission {
  id: string;
  attempt_no: number;
  status: SubmissionStatus;
  submitted_at: string | null;
  is_late: boolean;
  text_response: string | null;
  external_url: string | null;
  files: SubmissionFile[];
  rubric_snapshot: Rubric | null;
  grade: ReleasedGrade | null;
}
