import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";

const { useLinkedAccounts, unlinkMutate } = vi.hoisted(() => ({
  useLinkedAccounts: vi.fn(),
  unlinkMutate: vi.fn(),
}));
vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }) }));
vi.mock("@/lib/sso/hooks", () => ({
  useLinkedAccounts,
  useUnlinkAccount: () => ({ mutate: unlinkMutate, isPending: false }),
}));

import AccountSecurityPage from "@/app/(account)/security/page";

const ok = (data: unknown) => ({ isPending: false, isError: false, refetch: vi.fn(), data });

const ACCOUNT = { id: "acc_1", provider: "google", email: "me@ex.test", linked_at: null };

describe("AccountSecurityPage — linked accounts", () => {
  beforeEach(() => vi.clearAllMocks());

  it("lists linked providers", () => {
    useLinkedAccounts.mockReturnValue(ok({ accounts: [ACCOUNT], has_password: true }));
    renderWithI18n(<AccountSecurityPage />);
    expect(screen.getByText("Google")).toBeInTheDocument();
    expect(screen.getByText("me@ex.test")).toBeInTheDocument();
  });

  it("confirms before unlinking a provider", async () => {
    useLinkedAccounts.mockReturnValue(ok({ accounts: [ACCOUNT], has_password: true }));
    renderWithI18n(<AccountSecurityPage />);

    // Row action carries the provider in its accessible name; the dialog confirm is the bare label.
    await userEvent.click(screen.getByRole("button", { name: /Unlink — Google/i }));
    await userEvent.click(screen.getByRole("button", { name: "Unlink" }));

    expect(unlinkMutate).toHaveBeenCalledWith("acc_1", expect.anything());
  });

  it("disables unlink for the last method of a social-only account", () => {
    useLinkedAccounts.mockReturnValue(ok({ accounts: [ACCOUNT], has_password: false }));
    renderWithI18n(<AccountSecurityPage />);

    expect(screen.getByRole("button", { name: /Unlink — Google/i })).toBeDisabled();
    expect(screen.getByText(/only way to sign in/i)).toBeInTheDocument();
  });
});
