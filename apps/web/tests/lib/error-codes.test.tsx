import { describe, expect, it, vi } from "vitest";
import { screen } from "@testing-library/react";
import { renderWithI18n } from "../render";
import { ApiRequestError } from "@/lib/api/client";
import { errorCode, isAccessExpired, isCourseAccessError } from "@/lib/api/errors";
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

  it("still offers a retry for a genuine failure", () => {
    renderWithI18n(
      <QueryState query={failing(new Error("network down"))}>{() => <p>content</p>}</QueryState>,
    );

    expect(screen.getByRole("button", { name: /Try again/i })).toBeInTheDocument();
  });

  it("shows the message a framework refusal carried rather than a status word", () => {
    renderWithI18n(
      <QueryState query={failing(refusal("HTTP_FORBIDDEN", "You are not allowed to do that."))}>
        {() => <p>content</p>}
      </QueryState>,
    );

    expect(screen.getByText(/You are not allowed to do that\./i)).toBeInTheDocument();
  });
});
