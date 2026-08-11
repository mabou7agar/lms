import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";

const { useSsoDomains, useSsoCapabilities, createMutate, deleteMutate } = vi.hoisted(() => ({
  useSsoDomains: vi.fn(),
  useSsoCapabilities: vi.fn(),
  createMutate: vi.fn(),
  deleteMutate: vi.fn(),
}));
vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }) }));
vi.mock("@/lib/sso/hooks", () => ({
  useSsoDomains,
  useSsoCapabilities,
  useCreateSsoDomain: () => ({ mutate: createMutate, isPending: false }),
  useDeleteSsoDomain: () => ({ mutate: deleteMutate, isPending: false }),
  useUpdateSsoDomainMode: () => ({ mutate: vi.fn(), isPending: false }),
}));

import ManagerSsoPage from "@/app/(enterprise)/manager/sso/page";

const ok = (data: unknown) => ({ isPending: false, isError: false, refetch: vi.fn(), data });

const CAPS = {
  sso_enabled: true,
  oidc: { supported: true, label: "OpenID Connect (OIDC)", providers: ["google"] },
  saml: {
    supported: false,
    label: "SAML 2.0",
    reason: "SAML SSO is not available — no signed-assertion (XML-DSIG) support; use OIDC.",
  },
};

describe("ManagerSsoPage — domain mappings", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    useSsoCapabilities.mockReturnValue(ok(CAPS));
  });

  it("shows the honest, data-driven SAML-unsupported notice", () => {
    useSsoDomains.mockReturnValue(ok([]));
    renderWithI18n(<ManagerSsoPage />);
    expect(screen.getByText("SAML is not supported")).toBeInTheDocument();
    expect(screen.getByText(/XML-DSIG/)).toBeInTheDocument();
  });

  it("lists a mapped domain with its verified badge", () => {
    useSsoDomains.mockReturnValue(
      ok([{ id: "d_1", domain: "acme.com", mode: "auto_join", verified: true, verified_at: null, created_at: null }]),
    );
    renderWithI18n(<ManagerSsoPage />);
    expect(screen.getByText("acme.com")).toBeInTheDocument();
    expect(screen.getByText("Verified")).toBeInTheDocument();
  });

  it("adds a domain", async () => {
    useSsoDomains.mockReturnValue(ok([]));
    renderWithI18n(<ManagerSsoPage />);
    await userEvent.type(screen.getByLabelText("Email domain"), "acme.com");
    await userEvent.click(screen.getByRole("button", { name: /Add domain/i }));
    expect(createMutate).toHaveBeenCalledWith({ domain: "acme.com", mode: "auto_join" }, expect.anything());
  });

  it("confirms before removing a domain", async () => {
    useSsoDomains.mockReturnValue(
      ok([{ id: "d_1", domain: "acme.com", mode: "restrict", verified: false, verified_at: null, created_at: null }]),
    );
    renderWithI18n(<ManagerSsoPage />);

    await userEvent.click(screen.getByRole("button", { name: /Remove — acme\.com/i }));
    await userEvent.click(screen.getByRole("button", { name: "Remove" }));

    expect(deleteMutate).toHaveBeenCalledWith("d_1", expect.anything());
  });
});
