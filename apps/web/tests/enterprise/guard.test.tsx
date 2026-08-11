import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import { renderWithI18n } from "../render";

const { useAuth } = vi.hoisted(() => ({ useAuth: vi.fn() }));
vi.mock("@/lib/auth/auth-context", () => ({ useAuth }));
vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace: vi.fn(), push: vi.fn() }),
  usePathname: () => "/manager",
  useSearchParams: () => new URLSearchParams(),
}));

import { RequireAuth } from "@/lib/auth/guards";

const MANAGER_ROLES = ["org_manager", "admin", "super_admin"];

describe("Manager portal role guard", () => {
  beforeEach(() => vi.clearAllMocks());

  it("hides the portal from a non-manager (access denied)", () => {
    useAuth.mockReturnValue({ status: "authenticated", user: { roles: ["student"] } });
    renderWithI18n(
      <RequireAuth roles={MANAGER_ROLES}>
        <div>Manager only content</div>
      </RequireAuth>,
    );
    expect(screen.getByText("Access denied")).toBeInTheDocument();
    expect(screen.queryByText("Manager only content")).not.toBeInTheDocument();
  });

  it("renders the portal for an org manager", () => {
    useAuth.mockReturnValue({ status: "authenticated", user: { roles: ["org_manager"] } });
    renderWithI18n(
      <RequireAuth roles={MANAGER_ROLES}>
        <div>Manager only content</div>
      </RequireAuth>,
    );
    expect(screen.getByText("Manager only content")).toBeInTheDocument();
  });
});
