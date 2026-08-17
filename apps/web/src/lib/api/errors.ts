import type { FieldValues, Path, UseFormSetError } from "react-hook-form";
import { ApiRequestError } from "./client";

/**
 * Maps the standard error envelope's validation details
 * (`{ error: { code: "VALIDATION_ERROR", details: { fields: { email: ["…"] } } } }`)
 * onto React Hook Form field errors. Returns true if any field error was applied.
 */
export function applyApiFieldErrors<T extends FieldValues>(
  error: unknown,
  setError: UseFormSetError<T>,
): boolean {
  if (!(error instanceof ApiRequestError) || !error.details) return false;

  const fields = (error.details as { fields?: Record<string, string[] | string> }).fields;
  if (!fields || typeof fields !== "object") return false;

  let applied = false;
  for (const [name, messages] of Object.entries(fields)) {
    const message = Array.isArray(messages) ? messages[0] : String(messages);
    setError(name as Path<T>, { type: "server", message });
    applied = true;
  }
  return applied;
}

/** Human-readable message for any thrown error, falling back to a translated default. */
export function errorMessage(error: unknown, fallback: string): string {
  if (error instanceof ApiRequestError) return error.message || fallback;
  if (error instanceof Error) return error.message || fallback;
  return fallback;
}

/** True when the backend signalled that MFA is required for this login. */
export function isMfaRequired(error: unknown): boolean {
  return error instanceof ApiRequestError && error.code === "AUTH_MFA_REQUIRED";
}

/**
 * The stable machine-readable code the API refused with, or null for anything that is not an API
 * refusal. Every refusal carries one — a domain code where the server knew why, and an
 * `HTTP_*` code where all it knew was the status.
 *
 * Branch on this, not on the message: messages are prose, get reworded, and are translated.
 */
export function errorCode(error: unknown): string | null {
  return error instanceof ApiRequestError ? error.code : null;
}

/** Codes that all mean "this learner's entitlement to the course is the problem". */
const ACCESS_CODES = new Set(["LEARNING_ACCESS_EXPIRED", "COURSE_ACCESS_DENIED", "LEARNING_NOT_ENROLLED"]);

/** True when a refusal was about course entitlement rather than anything the caller did wrong. */
export function isCourseAccessError(error: unknown): boolean {
  const code = errorCode(error);
  return code !== null && ACCESS_CODES.has(code);
}

/** True when the learner HAD access and it ran out — the one case where renewal is the remedy. */
export function isAccessExpired(error: unknown): boolean {
  return errorCode(error) === "LEARNING_ACCESS_EXPIRED";
}

/**
 * True when the server refused because of WHO is asking, not because anything went wrong.
 *
 * Covers the course-entitlement codes plus the generic authorization refusals every surface can
 * hit. The distinction matters for one reason: a refusal is not retryable, and a screen that offers
 * "Try again" on a permission failure reads as a broken app rather than a closed door.
 */
export function isAuthorizationError(error: unknown): boolean {
  const code = errorCode(error);
  if (code === null) return false;

  return ACCESS_CODES.has(code) || code === "HTTP_FORBIDDEN" || code === "UNAUTHENTICATED";
}
