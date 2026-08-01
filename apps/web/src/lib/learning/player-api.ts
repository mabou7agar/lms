/**
 * Learner course-player API layer.
 *
 * Every function here maps 1:1 to a FROZEN Learning runtime endpoint
 * (app/Contexts/Learning/routes/learning_runtime.php). Field names mirror the
 * PHP Resources verbatim. Public ids only — never storage/media ids.
 *
 * Endpoints (base `/api/backend` BFF is applied by `@/lib/api/client`, then `/v1`):
 *   POST  /v1/courses/{course}/launch            -> CourseLaunchResource
 *   GET   /v1/courses/{course}/curriculum        -> RuntimeCurriculumResource
 *   GET   /v1/courses/{course}/resume            -> { resume_lesson_id, title }
 *   GET   /v1/courses/{course}/progress-summary  -> ProgressSummaryResource
 *   POST  /v1/lessons/{lesson}/viewed            -> { status }
 *   POST  /v1/lessons/{lesson}/complete          -> { status, course_progress_percentage }
 *   POST  /v1/lessons/{lesson}/video-progress    -> VideoProgressResource
 *   POST  /v1/lessons/{lesson}/blocks/{block}/complete -> { block_id, completed_at }
 *
 * NOT frozen in this snapshot (PlaybackPort/PlaybackTokenManager are referenced by
 * MediaServiceProvider but no HTTP resource exists yet, and there is no lesson-content
 * endpoint): `fetchLessonPlayback` and `fetchLessonContent` are coded against an
 * ASSUMED shape and kept injectable so the integrator can point them at the real routes
 * without touching the components. See CONTRACT ASSUMPTIONS in the delivery notes.
 */
import { api } from '@/lib/api/client';

// ---------------------------------------------------------------------------
// Frozen enums / scalars
// ---------------------------------------------------------------------------

/** LessonLockReason enum (App\Contexts\Learning\Enums\LessonLockReason). */
export type LessonLockReason =
  | 'prerequisite_incomplete'
  | 'drip_not_released'
  | 'unpublished';

/** Enrollment / lesson-progress status scalars (surfaced as opaque strings). */
export type EnrollmentStatus = string;
export type LessonProgressStatus = string;

// ---------------------------------------------------------------------------
// Frozen response shapes
// ---------------------------------------------------------------------------

/** RuntimeCurriculumResource -> lesson node. */
export interface RuntimeLesson {
  id: string;
  title: string;
  type: string;
  is_preview: boolean;
  has_media: boolean | null;
  completed: boolean;
  locked: boolean;
  lock_reason: LessonLockReason | null;
  prerequisites_met: boolean;
  released: boolean;
  available_at: string | null;
  estimated_duration_seconds: number | null;
}

/** RuntimeCurriculumResource -> section node. */
export interface RuntimeSection {
  id: string;
  title: string;
  lessons: RuntimeLesson[];
}

export interface CourseRef {
  id: string;
  title: string;
  slug: string;
}

export interface EnrollmentRef {
  id: string;
  status: EnrollmentStatus;
  progress_percentage: number;
}

/** RuntimeCurriculumResource. */
export interface RuntimeCurriculum {
  course: CourseRef;
  enrollment: EnrollmentRef;
  sections: RuntimeSection[];
}

/** CourseLaunchResource. */
export interface CourseLaunch {
  course: CourseRef;
  enrollment: EnrollmentRef;
  progress: {
    total_lessons: number;
    completed_lessons: number;
  };
  resume: {
    lesson_id: string;
    title: string;
  } | null;
}

/** ProgressSummaryResource. */
export interface ProgressSummary {
  course_id: string;
  status: EnrollmentStatus;
  progress_percentage: number;
  total_lessons: number;
  completed_lessons: number;
  course_completed: boolean;
  resume_lesson_id: string | null;
}

/** CourseLaunchController::resume payload. */
export interface ResumePointer {
  resume_lesson_id: string | null;
  title: string | null;
}

/** VideoProgressResource. `completed` is SERVER-decided — never sent by the client. */
export interface VideoProgress {
  position_seconds: number;
  watched_seconds: number;
  duration_seconds: number | null;
  completed: boolean;
}

/** LessonCompletionController::viewed payload. */
export interface LessonViewedResult {
  status: LessonProgressStatus;
}

/** LessonCompletionController::complete payload. */
export interface LessonCompleteResult {
  status: LessonProgressStatus;
  course_progress_percentage: number;
}

/** BlockProgressController::store payload. */
export interface BlockCompleteResult {
  block_id: string;
  completed_at: string | null;
}

// ---------------------------------------------------------------------------
// Frozen request shapes
// ---------------------------------------------------------------------------

/** RecordVideoProgressRequest — NOTE: no `completed` field, by design. */
export interface RecordVideoProgressBody {
  position_seconds: number;
  duration_seconds?: number | null;
}

// ---------------------------------------------------------------------------
// ASSUMED (non-frozen) shapes — injectable, see delivery notes.
// ---------------------------------------------------------------------------

/**
 * Just-in-time signed playback ticket. ASSUMED shape modelled on Media conventions
 * (public ids, ISO8601 expiry, no storage ids). The URL expires; callers must re-fetch.
 */
export interface PlaybackTicket {
  url: string;
  expires_at: string;
  provider?: string;
  duration_seconds?: number | null;
  /** Optional poster / captions if the real endpoint supplies them. */
  poster_url?: string | null;
  captions?: Array<{ src: string; lang: string; label: string }>;
}

export type LessonBlockKind = 'video' | 'audio' | 'document' | 'text';

/** ASSUMED lesson content block. `media` blocks carry only a public block id; the signed URL is fetched JIT. */
export interface LessonBlock {
  id: string;
  kind: LessonBlockKind;
  /** text/html body for text blocks. */
  body?: string | null;
  /** display title / filename for document blocks. */
  label?: string | null;
  /** presigned download/view url for document blocks (may itself expire). */
  url?: string | null;
  mime_type?: string | null;
  /** whether completing this block is required to complete the lesson. */
  required?: boolean;
  /** server-side completion flag for the current learner. */
  completed?: boolean;
}

/** ASSUMED lesson-content payload. */
export interface LessonContent {
  id: string;
  title: string;
  type: string;
  blocks: LessonBlock[];
  /**
   * Server resume point for the lesson's primary video, so the player can seek
   * on load. There is no frozen GET for video-progress; this rides on content.
   */
  video?: { position_seconds: number | null; duration_seconds: number | null } | null;
  /** present when the lesson is/launches an assessment. */
  assessment?: { id: string; title: string; status?: string } | null;
  /** present when the lesson is/launches an assignment. */
  assignment?: { id: string; title: string; status?: string } | null;
}

// ---------------------------------------------------------------------------
// Frozen endpoint calls
// ---------------------------------------------------------------------------

// Base is un-prefixed (the api client prepends the `/api/backend` BFF origin),
// matching the media/gradebook lib convention: bare paths (base already includes /api/v1).
const enc = encodeURIComponent;
const courseBase = (id: string) => `courses/${enc(id)}`;
const lessonBase = (id: string) => `lessons/${enc(id)}`;

export function launchCourse(coursePublicId: string): Promise<CourseLaunch> {
  return api.data<CourseLaunch>(`${courseBase(coursePublicId)}/launch`, { method: 'POST' });
}

export function fetchCurriculum(coursePublicId: string): Promise<RuntimeCurriculum> {
  return api.data<RuntimeCurriculum>(`${courseBase(coursePublicId)}/curriculum`);
}

export function fetchResume(coursePublicId: string): Promise<ResumePointer> {
  return api.data<ResumePointer>(`${courseBase(coursePublicId)}/resume`);
}

export function fetchProgressSummary(coursePublicId: string): Promise<ProgressSummary> {
  return api.data<ProgressSummary>(`${courseBase(coursePublicId)}/progress-summary`);
}

export function markLessonViewed(lessonPublicId: string): Promise<LessonViewedResult> {
  return api.data<LessonViewedResult>(`${lessonBase(lessonPublicId)}/viewed`, { method: 'POST' });
}

export function completeLesson(lessonPublicId: string): Promise<LessonCompleteResult> {
  return api.data<LessonCompleteResult>(`${lessonBase(lessonPublicId)}/complete`, { method: 'POST' });
}

export function recordVideoProgress(
  lessonPublicId: string,
  body: RecordVideoProgressBody,
): Promise<VideoProgress> {
  return api.data<VideoProgress>(`${lessonBase(lessonPublicId)}/video-progress`, {
    method: 'POST',
    body,
  });
}

export function completeBlock(
  lessonPublicId: string,
  blockRef: string,
): Promise<BlockCompleteResult> {
  return api.data<BlockCompleteResult>(
    `${lessonBase(lessonPublicId)}/blocks/${enc(blockRef)}/complete`,
    { method: 'POST' },
  );
}

// ---------------------------------------------------------------------------
// ASSUMED endpoint calls (integrator repoints paths if the real routes differ)
// ---------------------------------------------------------------------------

/** ASSUMED: GET v1/lessons/{lesson}/playback -> PlaybackTicket. Fetched JIT; URL expires. */
export function fetchLessonPlayback(lessonPublicId: string): Promise<PlaybackTicket> {
  return api.data<PlaybackTicket>(`${lessonBase(lessonPublicId)}/playback`);
}

/** ASSUMED: GET v1/lessons/{lesson} -> LessonContent (blocks + launch pointers). */
export function fetchLessonContent(lessonPublicId: string): Promise<LessonContent> {
  return api.data<LessonContent>(lessonBase(lessonPublicId));
}

// ---------------------------------------------------------------------------
// Derived helpers (pure, unit-testable)
// ---------------------------------------------------------------------------

/** Flatten sections into an ordered lesson list — the navigation order. */
export function flattenLessons(curriculum: RuntimeCurriculum | undefined): RuntimeLesson[] {
  if (!curriculum) return [];
  return curriculum.sections.flatMap((s) => s.lessons);
}

/**
 * A lesson is navigable when it is not locked. `locked` is the server-authoritative
 * gate (it already folds in unpublished/drip/prerequisite). Preview lessons are
 * navigable regardless because the backend reports them `locked:false`.
 *
 * `freeNavigation` (an optional, non-frozen course setting) only ever RELAXES for
 * already-unlocked lessons; it can never override a server lock.
 */
export function isLessonNavigable(lesson: RuntimeLesson): boolean {
  return !lesson.locked;
}

/** Ordered index of a lesson id within the flattened curriculum, or -1. */
export function lessonIndex(lessons: RuntimeLesson[], lessonId: string | null | undefined): number {
  if (!lessonId) return -1;
  return lessons.findIndex((l) => l.id === lessonId);
}

/** Previous navigable lesson relative to `lessonId`, or null. */
export function previousLesson(lessons: RuntimeLesson[], lessonId: string): RuntimeLesson | null {
  const idx = lessonIndex(lessons, lessonId);
  for (let i = idx - 1; i >= 0; i -= 1) {
    if (isLessonNavigable(lessons[i])) return lessons[i];
  }
  return null;
}

/** Next navigable lesson relative to `lessonId`, or null. */
export function nextLesson(lessons: RuntimeLesson[], lessonId: string): RuntimeLesson | null {
  const idx = lessonIndex(lessons, lessonId);
  if (idx < 0) return null;
  for (let i = idx + 1; i < lessons.length; i += 1) {
    if (isLessonNavigable(lessons[i])) return lessons[i];
  }
  return null;
}
