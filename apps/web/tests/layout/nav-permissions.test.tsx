import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import { renderWithI18n } from "../render";
import { managerNav } from "@/config/nav";

/**
 * The sidebar must not invite someone into a room they cannot enter.
 *
 * A company owner was shown Brand & Domains and SSO in the manager portal. Both authorize against
 * `identity.users.manage`, which owning an organization does not grant, so every click ended in a
 * refusal. Hiding the entry is presentation only — the route and its server-side guard are
 * untouched, and the refusal page remains the real boundary.
 */

const { authState, flags } = vi.hoisted(() => ({
  authState: { user: null as { permissions?: string[] | null } | null },
  flags: {} as Record<string, boolean>,
}));

vi.mock("next/navigation", () => ({ usePathname: () => "/manager" }));
vi.mock("@/lib/auth/auth-context", () => ({ useAuth: () => authState }));
vi.mock("@/lib/flags/hooks", () => ({ useFeatureFlags: () => flags }));
// The CMS-driven menu overrides the static nav entirely; null keeps the static path under test.
vi.mock("@/lib/navigation/hooks", () => ({ useNavigation: () => null }));
vi.mock("@/hooks/use-media-query", () => ({ useMediaQuery: () => true }));
vi.mock("@/components/layout/topbar", () => ({ Topbar: () => null }));
vi.mock("@/components/layout/page-transition", () => ({
  PageTransition: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

import { AppShell } from "@/components/layout/app-shell";

const shell = () => renderWithI18n(<AppShell nav={managerNav}>{null}</AppShell>);

const MANAGE_USERS = "identity.users.manage";

describe("permission-aware navigation", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    authState.user = null;
  });

  it("hides the admin-only white-label entries from an owner who lacks the permission", () => {
    authState.user = { permissions: ["crm.organizations.manage"] };
    shell();

    expect(screen.queryByRole("link", { name: /Brand & Domains/i })).not.toBeInTheDocument();
    expect(screen.queryByRole("link", { name: /SSO/i })).not.toBeInTheDocument();
  });

  it("still shows every entry the owner can actually use", () => {
    authState.user = { permissions: ["crm.organizations.manage"] };
    shell();

    expect(screen.getByRole("link", { name: /Seats/i })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /Members/i })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /Training/i })).toBeInTheDocument();
  });

  it("shows them to someone who does hold the permission", () => {
    authState.user = { permissions: [MANAGE_USERS] };
    shell();

    expect(screen.getByRole("link", { name: /Brand & Domains/i })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /SSO/i })).toBeInTheDocument();
  });

  it("keeps every entry when the session does not disclose permissions at all", () => {
    // A cached user from before the contract existed. Hiding half the portal on a stale payload is
    // far worse than showing a link that refuses politely, so unknown means show.
    authState.user = { permissions: undefined };
    shell();

    expect(screen.getByRole("link", { name: /Brand & Domains/i })).toBeInTheDocument();
  });

  it("declares the permission its API actually requires", () => {
    const gated = managerNav.filter((item) => item.permission);

    expect(gated.map((item) => item.href).sort()).toEqual(["/manager/branding", "/manager/sso"]);
    expect(gated.every((item) => item.permission === MANAGE_USERS)).toBe(true);
  });
});
