import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18nAsync } from "../render";

const { acceptMutate, declineMutate } = vi.hoisted(() => ({ acceptMutate: vi.fn(), declineMutate: vi.fn() }));
vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }) }));
vi.mock("@/lib/enterprise/manager-hooks", () => ({
  useAcceptInvitation: () => ({ mutate: acceptMutate, isPending: false }),
  useDeclineInvitation: () => ({ mutate: declineMutate, isPending: false }),
}));

import InvitationPage from "@/app/(invitation)/invitations/[token]/page";

describe("InvitationPage", () => {
  beforeEach(() => vi.clearAllMocks());

  it("accepts an invitation and confirms success", async () => {
    acceptMutate.mockImplementation((_token: string, opts: { onSuccess?: () => void }) => opts?.onSuccess?.());
    await renderWithI18nAsync(<InvitationPage params={Promise.resolve({ token: "tok_123" })} />);

    await userEvent.click(screen.getByRole("button", { name: /Accept invitation/i }));
    expect(acceptMutate).toHaveBeenCalledWith("tok_123", expect.anything());
    expect(await screen.findByText(/Invitation accepted/i)).toBeInTheDocument();
  });

  it("declines an invitation", async () => {
    declineMutate.mockImplementation((_token: string, opts: { onSuccess?: () => void }) => opts?.onSuccess?.());
    await renderWithI18nAsync(<InvitationPage params={Promise.resolve({ token: "tok_123" })} />);

    await userEvent.click(screen.getByRole("button", { name: /Decline/i }));
    expect(declineMutate).toHaveBeenCalledWith("tok_123", expect.anything());
    expect(await screen.findByText(/Invitation declined/i)).toBeInTheDocument();
  });
});
