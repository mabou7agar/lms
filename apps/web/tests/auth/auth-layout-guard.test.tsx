import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import { renderWithI18n } from "../render";

const { replace, authState, pathname } = vi.hoisted(() => ({
  replace: vi.fn(),
  authState: { status: "guest" as "guest" | "loading" | "authenticated", user: null as unknown },
  pathname: { value: "/login" },
}));

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace, push: vi.fn() }),
  usePathname: () => pathname.value,
  useSearchParams: () => new URLSearchParams(),
}));
vi.mock("@/lib/auth/auth-context", () => ({ useAuth: () => authState }));

import AuthLayout from "@/app/(marketing)/(auth)/layout";

describe("Auth layout guard", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    authState.status = "guest";
    pathname.value = "/login";
  });

  it("renders the sign-in surface for a guest", () => {
    renderWithI18n(<AuthLayout><p>login form</p></AuthLayout>);

    expect(screen.getByText("login form")).toBeInTheDocument();
    expect(replace).not.toHaveBeenCalled();
  });

  // An authenticated visitor has no business on sign-in or registration.
  it.each(["/login", "/register", "/forgot-password", "/reset-password"])(
    "keeps %s guest-only, redirecting an authenticated visitor away",
    (route) => {
      pathname.value = route;
      authState.status = "authenticated";

      renderWithI18n(<AuthLayout><p>guest only</p></AuthLayout>);

      expect(screen.queryByText("guest only")).not.toBeInTheDocument();
      expect(replace).toHaveBeenCalledWith("/");
    },
  );

  // Registration signs the account in and sends it straight here, so guarding this route as
  // guest-only made email verification unreachable exactly when it was needed.
  it("lets an authenticated user reach email verification", () => {
    pathname.value = "/verify-email";
    authState.status = "authenticated";

    renderWithI18n(<AuthLayout><p>verification form</p></AuthLayout>);

    expect(screen.getByText("verification form")).toBeInTheDocument();
    expect(replace).not.toHaveBeenCalled();
  });

  it("still renders email verification for a guest, which the page itself handles", () => {
    pathname.value = "/verify-email";
    authState.status = "guest";

    renderWithI18n(<AuthLayout><p>verification form</p></AuthLayout>);

    expect(screen.getByText("verification form")).toBeInTheDocument();
    expect(replace).not.toHaveBeenCalled();
  });
});
