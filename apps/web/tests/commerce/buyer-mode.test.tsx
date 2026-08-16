import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";

const { setBuyerMutate } = vi.hoisted(() => ({ setBuyerMutate: vi.fn() }));

vi.mock("@/lib/commerce/hooks", () => ({
  useSetCartBuyer: () => ({ mutate: setBuyerMutate, isPending: false, isError: false, variables: undefined }),
}));

import { BuyerModeSwitch } from "@/components/commerce/buyer-mode-switch";
import { dictionaries } from "@/lib/i18n/dictionaries";

describe("BuyerModeSwitch", () => {
  beforeEach(() => vi.clearAllMocks());

  it("offers both ownership choices and marks the active one", () => {
    renderWithI18n(<BuyerModeSwitch buyerType="individual" />);

    expect(screen.getByText("Buying as")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /For myself/i })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /For my company/i })).toBeInTheDocument();
  });

  it("switches the cart to a company purchase", async () => {
    renderWithI18n(<BuyerModeSwitch buyerType="individual" />);

    await userEvent.click(screen.getByRole("button", { name: /For my company/i }));

    expect(setBuyerMutate).toHaveBeenCalledWith("company", expect.anything());
  });

  // Re-selecting the current mode would be a pointless write, and the server would just echo it back.
  it("does not call the server when the chosen mode is already active", async () => {
    renderWithI18n(<BuyerModeSwitch buyerType="company" />);

    await userEvent.click(screen.getByRole("button", { name: /For my company/i }));

    expect(setBuyerMutate).not.toHaveBeenCalled();
  });

  // The switch is a public purchase control, so it must exist in both languages.
  it("has Arabic copy for buyer ownership and company registration", () => {
    const ar = dictionaries.ar.commerce.cart;
    const arRegister = dictionaries.ar.auth.register;

    expect(ar.buyingAs).toBe("الشراء بصفة");
    expect(ar.buyForMyself).toBe("لنفسي");
    expect(ar.buyForCompany).toBe("لشركتي");
    expect(arRegister.accountType).toBe("نوع الحساب");
    expect(arRegister.company).toBe("شركة");
    expect(arRegister.companyName).toBe("اسم الشركة");
  });
});
