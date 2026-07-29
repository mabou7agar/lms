import type { LearnerAssignment, SubmissionFile } from './types';

/** Human-readable byte size. */
export function formatBytes(bytes: number | null | undefined): string {
  if (bytes == null || Number.isNaN(bytes)) return '';
  if (bytes < 1024) return `${bytes} B`;
  const units = ['KB', 'MB', 'GB'];
  let value = bytes / 1024;
  let i = 0;
  while (value >= 1024 && i < units.length - 1) {
    value /= 1024;
    i += 1;
  }
  return `${value.toFixed(value < 10 ? 1 : 0)} ${units[i]}`;
}

/** Lowercased extension without the dot, or '' if none. */
export function fileExtension(filename: string): string {
  const dot = filename.lastIndexOf('.');
  if (dot < 0 || dot === filename.length - 1) return '';
  return filename.slice(dot + 1).toLowerCase();
}

export interface FileValidationResult {
  ok: boolean;
  /** i18n key hint; UI maps to a localized message. */
  code?: 'file_type' | 'file_size' | 'max_files';
  message?: string;
}

/**
 * Validate a candidate file against the assignment's constraints BEFORE any bytes leave the browser.
 * `allowed_file_types` entries are matched as extensions (case-insensitive, leading dots tolerated).
 */
export function validateFile(
  file: { name: string; size: number },
  assignment: Pick<LearnerAssignment, 'allowed_file_types' | 'max_file_size' | 'max_files'>,
  currentCount: number,
): FileValidationResult {
  const max = assignment.max_files ?? Infinity;
  if (currentCount >= max) {
    return { ok: false, code: 'max_files', message: `You can attach at most ${max} file(s).` };
  }
  const allowed = assignment.allowed_file_types ?? [];
  if (allowed.length > 0) {
    const ext = fileExtension(file.name);
    const normalized = allowed.map((t) => t.replace(/^\./, '').toLowerCase());
    if (!ext || !normalized.includes(ext)) {
      return {
        ok: false,
        code: 'file_type',
        message: `Allowed file types: ${normalized.join(', ')}.`,
      };
    }
  }
  const maxSize = assignment.max_file_size ?? Infinity;
  if (file.size > maxSize) {
    return {
      ok: false,
      code: 'file_size',
      message: `File exceeds the maximum size of ${formatBytes(maxSize)}.`,
    };
  }
  return { ok: true };
}

/** True when the due date has passed relative to `now` (defaults to Date.now). */
export function isPastDue(dueAt: string | null | undefined, now: number = Date.now()): boolean {
  if (!dueAt) return false;
  const due = Date.parse(dueAt);
  return Number.isFinite(due) && now > due;
}

/** Remaining attempts given a limit (null = unlimited) and how many have been used. */
export function attemptsRemaining(
  attemptLimit: number | null | undefined,
  attemptsUsed: number,
): number | null {
  if (attemptLimit == null) return null;
  return Math.max(0, attemptLimit - attemptsUsed);
}

export function canSubmitAgain(
  attemptLimit: number | null | undefined,
  attemptsUsed: number,
): boolean {
  const remaining = attemptsRemaining(attemptLimit, attemptsUsed);
  return remaining == null || remaining > 0;
}

export function fileKey(f: SubmissionFile): string {
  return f.id ?? f.media_id;
}
