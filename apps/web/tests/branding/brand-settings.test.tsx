import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";
import type { Branding } from "@/lib/branding/api";

const {
  useOrgBranding,
  useOrgDomains,
  useAuth,
  updateMutate,
  createMutate,
  deleteMutate,
  verifyMutate,
} = vi.hoisted(() => ({
  useOrgBranding: vi.fn(),
  useOrgDomains: vi.fn(),
  useAuth: vi.fn(),
  updateMutate: vi.fn(),
  createMutate: vi.fn(),
  deleteMutate: vi.fn(),
  verifyMutate: vi.fn(),
}));

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }) }));
vi.mock("@/lib/auth/auth-context", () => ({ useAuth }));
vi.mock("@/lib/branding/org-hooks", () => ({
  useOrgBranding,
  useOrgDomains,
  useUpdateOrgBranding: () => ({ mutate: updateMutate, isPending: false }),
  useCreateOrgDomain: () => ({ mutate: createMutate, isPending: false }),
  useDeleteOrgDomain: () => ({ mutate: deleteMutate, isPending: false }),
  useVerifyOrgDomain: () => ({ mutate: verifyMutate, isPending: false }),
}));

import ManagerBrandingPage from "@/app/(enterprise)/manager/branding/page";

const ok = (data: unknown) => ({ isPending: false, isError: false, refetch: vi.fn(), data });

const BRANDING = {
  identity: { brand_name: { en: "Acme Academy", ar: "أكاديمية أكمي" } },
  logos: { logo_light: "https://cdn.example.com/logo.png", favicon: "https://cdn.example.com/fav.ico" },
  theme: { colors: { primary: "#0F766E", secondary: "#134E4A" } },
} as unknown as Branding;

const domain = (over: Record<string, unknown> = {}) => ({
  id: "d_1",
  host: "learn.acme.com",
  is_primary: false,
  verified: true,
  verified_at: null,
  verification_token: null,
  created_at: null,
  ...over,
});

describe("ManagerBrandingPage — brand settings", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    useOrgBranding.mockReturnValue(ok(BRANDING));
    useOrgDomains.mockReturnValue(ok([]));
    useAuth.mockReturnValue({ status: "authenticated", user: { roles: ["admin"] } });
  });

  it("renders the brand form seeded from the loaded branding", () => {
    renderWithI18n(<ManagerBrandingPage />);
    expect(screen.getByLabelText("Brand name (English)")).toHaveValue("Acme Academy");
    expect(screen.getByLabelText("Brand name (Arabic)")).toHaveValue("أكاديمية أكمي");
    expect(screen.getByLabelText("Primary color")).toHaveValue("#0F766E");
  });

  it("validates hex colors client-side and blocks save on an invalid value", async () => {
    const user = userEvent.setup();
    renderWithI18n(<ManagerBrandingPage />);

    const primary = screen.getByLabelText("Primary color");
    await user.clear(primary);
    await user.type(primary, "not-a-hex");

    expect(screen.getByText(/valid hex color/i)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Save brand" })).toBeDisabled();
  });

  it("saves the override via the mocked update hook", async () => {
    const user = userEvent.setup();
    renderWithI18n(<ManagerBrandingPage />);

    await user.click(screen.getByRole("button", { name: "Save brand" }));
    expect(updateMutate).toHaveBeenCalledWith(
      expect.objectContaining({ brand_name_en: "Acme Academy", primary_color: "#0F766E" }),
      expect.anything(),
    );
  });

  it("adds a custom domain", async () => {
    const user = userEvent.setup();
    renderWithI18n(<ManagerBrandingPage />);

    await user.type(screen.getByLabelText("Domain"), "learn.acme.com");
    await user.click(screen.getByRole("button", { name: /Add domain/i }));
    expect(createMutate).toHaveBeenCalledWith({ host: "learn.acme.com" }, expect.anything());
  });

  it("confirms before removing a domain", async () => {
    const user = userEvent.setup();
    useOrgDomains.mockReturnValue(ok([domain({ verified: false })]));
    renderWithI18n(<ManagerBrandingPage />);

    await user.click(screen.getByRole("button", { name: /Remove — learn\.acme\.com/i }));
    await user.click(screen.getByRole("button", { name: "Remove" }));
    expect(deleteMutate).toHaveBeenCalledWith("d_1", expect.anything());
  });

  it("shows the verify action only to a super_admin", () => {
    useOrgDomains.mockReturnValue(ok([domain({ verified: false })]));
    useAuth.mockReturnValue({ status: "authenticated", user: { roles: ["super_admin"] } });
    const { unmount } = renderWithI18n(<ManagerBrandingPage />);
    expect(screen.getByRole("button", { name: /Verify — learn\.acme\.com/i })).toBeInTheDocument();
    unmount();

    // A non-super_admin org admin never sees the verify affordance.
    useAuth.mockReturnValue({ status: "authenticated", user: { roles: ["admin"] } });
    renderWithI18n(<ManagerBrandingPage />);
    expect(screen.queryByRole("button", { name: /Verify —/i })).not.toBeInTheDocument();
  });
});
