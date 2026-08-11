import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import { renderWithI18n } from "../render";

const { useAuth } = vi.hoisted(() => ({ useAuth: vi.fn() }));
vi.mock("@/lib/auth/auth-context", () => ({ useAuth }));
vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace: vi.fn(), push: vi.fn() }),
  usePathname: () => "/manager/sso",
  useSearchParams: () => new URLSearchParams(),
}));

import { RequireAuth } from "@/lib/auth/guards";

// The org-admin SSO settings live in the enterprise shell, gated to these roles.
const MANAGER_ROLES = ["org_manager", "admin", "super_admin"];

describe("SSO settings role guard", () => {
  beforeEach(() => vi.clearAllMocks());

  it("hides SSO settings from a plain member (access denied)", () => {
    useAuth.mockReturnValue({ status: "authenticated", user: { roles: ["student"] } });
    renderWithI18n(
      <RequireAuth roles={MANAGER_ROLES}>
        <div>SSO domain settings</div>
      </RequireAuth>,
    );
    expect(screen.getByText("Access denied")).toBeInTheDocument();
    expect(screen.queryByText("SSO domain settings")).not.toBeInTheDocument();
  });

  it("renders SSO settings for an org admin", () => {
    useAuth.mockReturnValue({ status: "authenticated", user: { roles: ["admin"] } });
    renderWithI18n(
      <RequireAuth roles={MANAGER_ROLES}>
        <div>SSO domain settings</div>
      </RequireAuth>,
    );
    expect(screen.getByText("SSO domain settings")).toBeInTheDocument();
  });
});
