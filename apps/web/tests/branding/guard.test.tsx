import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import { renderWithI18n } from "../render";

const { useAuth } = vi.hoisted(() => ({ useAuth: vi.fn() }));
vi.mock("@/lib/auth/auth-context", () => ({ useAuth }));
vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace: vi.fn(), push: vi.fn() }),
  usePathname: () => "/manager/branding",
  useSearchParams: () => new URLSearchParams(),
}));

import { RequireAuth } from "@/lib/auth/guards";

// The org-admin brand settings live in the enterprise shell, gated to these roles.
const MANAGER_ROLES = ["org_manager", "admin", "super_admin"];

describe("Brand settings role guard", () => {
  beforeEach(() => vi.clearAllMocks());

  it("hides brand settings from a plain member (access denied)", () => {
    useAuth.mockReturnValue({ status: "authenticated", user: { roles: ["student"] } });
    renderWithI18n(
      <RequireAuth roles={MANAGER_ROLES}>
        <div>Brand editor</div>
      </RequireAuth>,
    );
    expect(screen.getByText("Access denied")).toBeInTheDocument();
    expect(screen.queryByText("Brand editor")).not.toBeInTheDocument();
  });

  it("renders brand settings for an org admin", () => {
    useAuth.mockReturnValue({ status: "authenticated", user: { roles: ["org_manager"] } });
    renderWithI18n(
      <RequireAuth roles={MANAGER_ROLES}>
        <div>Brand editor</div>
      </RequireAuth>,
    );
    expect(screen.getByText("Brand editor")).toBeInTheDocument();
  });
});
