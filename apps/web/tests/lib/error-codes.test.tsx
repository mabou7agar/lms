import { describe, expect, it, vi } from "vitest";
import { screen } from "@testing-library/react";
import { renderWithI18n } from "../render";
import { ApiRequestError } from "@/lib/api/client";
import { errorCode, isAccessExpired, isAuthorizationError, isCourseAccessError } from "@/lib/api/errors";
import { QueryState } from "@/components/student/query-state";

const refusal = (code: string, message: string, status = 403) =>
  new ApiRequestError(status, code, message);

const failing = (error: unknown) =>
  ({ isPending: false, isError: true, error, data: undefined, refetch: vi.fn() }) as never;

describe("error codes", () => {
  it("reads the code off an API refusal", () => {
    expect(errorCode(refusal("COURSE_ACCESS_DENIED", "No."))).toBe("COURSE_ACCESS_DENIED");
  });

  it("has no code for a plain failure", () => {
    expect(errorCode(new Error("network down"))).toBeNull();
  });

  it("groups the entitlement refusals and separates expiry from the rest", () => {
    expect(isCourseAccessError(refusal("LEARNING_ACCESS_EXPIRED", "Ended."))).toBe(true);
    expect(isCourseAccessError(refusal("COURSE_ACCESS_DENIED", "No."))).toBe(true);
    expect(isCourseAccessError(refusal("HTTP_FORBIDDEN", "Forbidden"))).toBe(false);

    expect(isAccessExpired(refusal("LEARNING_ACCESS_EXPIRED", "Ended."))).toBe(true);
    expect(isAccessExpired(refusal("COURSE_ACCESS_DENIED", "No."))).toBe(false);
  });

  it("treats a generic forbidden as an authorization refusal, but not a course-access one", () => {
    expect(isAuthorizationError(refusal("HTTP_FORBIDDEN", "Nope"))).toBe(true);
    expect(isAuthorizationError(refusal("LEARNING_ACCESS_EXPIRED", "Ended."))).toBe(true);
    expect(isAuthorizationError(refusal("HTTP_NOT_FOUND", "Gone", 404))).toBe(false);
    expect(isAuthorizationError(new Error("network down"))).toBe(false);
  });
});

describe("QueryState on a refusal", () => {
  it("offers no retry for access that ended, because retrying cannot help", () => {
    renderWithI18n(
      <QueryState query={failing(refusal("LEARNING_ACCESS_EXPIRED", "Your access to this course has ended."))}>
        {() => <p>content</p>}
      </QueryState>,
    );

    expect(screen.getByText(/Your access has ended/i)).toBeInTheDocument();
    expect(screen.getByText(/Your access to this course has ended\./i)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Try again/i })).not.toBeInTheDocument();
  });

  it("distinguishes never having had access from having lost it", () => {
    renderWithI18n(
      <QueryState query={failing(refusal("COURSE_ACCESS_DENIED", "You do not have access to this course."))}>
        {() => <p>content</p>}
      </QueryState>,
    );

    expect(screen.getByText(/You do not have access$/i)).toBeInTheDocument();
    expect(screen.queryByText(/Your access has ended/i)).not.toBeInTheDocument();
  });

  it("offers no retry on a plain permission refusal either, and says so plainly", () => {
    renderWithI18n(
      <QueryState query={failing(refusal("HTTP_FORBIDDEN", "You are not allowed to do that."))}>
        {() => <p>content</p>}
      </QueryState>,
    );

    // A manager landing on an admin-only panel used to see "Something went wrong … Try again",
    // which reads as a broken app rather than a closed door.
    expect(screen.getByText(/This area is not available to you/i)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Try again/i })).not.toBeInTheDocument();
  });

  it("still offers a retry for a genuine failure", () => {
    renderWithI18n(
      <QueryState query={failing(new Error("network down"))}>{() => <p>content</p>}</QueryState>,
    );

    expect(screen.getByRole("button", { name: /Try again/i })).toBeInTheDocument();
  });

  it("shows the message a framework refusal carried rather than a status word", () => {
    renderWithI18n(
      <QueryState query={failing(refusal("HTTP_CONFLICT", "That conflicts with the current state.", 409))}>
        {() => <p>content</p>}
      </QueryState>,
    );

    expect(screen.getByText(/That conflicts with the current state\./i)).toBeInTheDocument();
  });
});
