import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderAuth } from "./util";

const { verifyEmail, refresh, replace, authState } = vi.hoisted(() => ({
  verifyEmail: vi.fn().mockResolvedValue({}),
  refresh: vi.fn().mockResolvedValue(undefined),
  replace: vi.fn(),
  authState: {
    status: "authenticated" as "guest" | "authenticated",
    user: null as { email_verified: boolean } | null,
  },
}));
vi.mock("next/navigation", () => ({ useRouter: () => ({ replace, push: vi.fn() }) }));
vi.mock("@/lib/auth/api", () => ({ verifyEmail }));
vi.mock("@/lib/auth/auth-context", () => ({
  useAuth: () => ({ refresh, user: authState.user, status: authState.status, login: vi.fn(), logout: vi.fn() }),
}));
vi.mock("@/lib/api/client", () => ({ hasSession: () => true }));

import VerifyEmailPage from "@/app/(marketing)/(auth)/verify-email/page";

describe("VerifyEmailPage", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    authState.status = "authenticated";
    authState.user = null;
  });

  it("submits the OTP code to verify-email", async () => {
    renderAuth(<VerifyEmailPage />);
    await userEvent.type(await screen.findByLabelText("Verification code"), "123456");
    await userEvent.click(screen.getByRole("button", { name: "Verify" }));
    expect(verifyEmail).toHaveBeenCalledWith("123456");
  });

  // The page is no longer guest-guarded, so an account with nothing left to verify must be sent on
  // rather than shown a code form it cannot use.
  it("sends an already-verified account to the dashboard", async () => {
    authState.user = { email_verified: true };

    renderAuth(<VerifyEmailPage />);

    expect(replace).toHaveBeenCalledWith("/dashboard");
  });

  it("keeps an unverified account on the form", async () => {
    authState.user = { email_verified: false };

    renderAuth(<VerifyEmailPage />);

    expect(await screen.findByLabelText("Verification code")).toBeInTheDocument();
    expect(replace).not.toHaveBeenCalled();
  });
});
