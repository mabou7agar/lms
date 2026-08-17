import { describe, expect, it, vi } from "vitest";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";
import { SeatSelector, seatTotalMinor, snapSeats } from "@/components/commerce/seat-selector";
import type { Price } from "@/lib/commerce/api";

const SELECTION = { min: 5, max: 100, increment: 5, default: 10 };
const PRICE: Price = {
  currency: "SAR",
  amount_minor: 40000,
  sale_amount_minor: null,
  on_sale: false,
  effective_minor: 40000,
};

describe("snapSeats", () => {
  it("keeps a count that is already on the grid", () => {
    expect(snapSeats(25, SELECTION)).toBe(25);
  });

  it("pulls a count between steps onto the nearest one", () => {
    // 7 is what a buyer types; 5 is what the product is sold in.
    expect(snapSeats(7, SELECTION)).toBe(5);
    expect(snapSeats(8, SELECTION)).toBe(10);
  });

  it("clamps to the bounds rather than offering a count the server would refuse", () => {
    expect(snapSeats(1, SELECTION)).toBe(5);
    expect(snapSeats(9999, SELECTION)).toBe(100);
  });

  it("has no ceiling when the admin set none", () => {
    expect(snapSeats(9999, { ...SELECTION, max: null })).toBe(10000);
  });

  it("falls back to the minimum for a cleared field", () => {
    expect(snapSeats(Number.NaN, SELECTION)).toBe(5);
  });
});

describe("seatTotalMinor", () => {
  it("multiplies by the seat count when the price is per seat", () => {
    expect(seatTotalMinor(PRICE, 25, true)).toBe(1000000);
  });

  it("charges the package price once otherwise", () => {
    expect(seatTotalMinor(PRICE, 25, false)).toBe(40000);
  });
});

describe("SeatSelector", () => {
  const setup = (value = 10) => {
    const onChange = vi.fn();
    renderWithI18n(
      <SeatSelector selection={SELECTION} price={PRICE} perSeat value={value} onChange={onChange} />,
    );
    return onChange;
  };

  it("shows what the chosen count will cost", () => {
    setup(25);
    expect(screen.getByTestId("seat-total")).toHaveTextContent("10,000");
  });

  it("states the bounds so the buyer is not guessing", () => {
    setup();
    expect(screen.getByText(/From 5 to 100, in steps of 5/i)).toBeInTheDocument();
  });

  it("steps by the increment, not by one", async () => {
    const onChange = setup(10);
    await userEvent.click(screen.getByRole("button", { name: /More seats/i }));
    expect(onChange).toHaveBeenCalledWith(15);
  });

  it("will not step below the minimum", () => {
    setup(5);
    expect(screen.getByRole("button", { name: /Fewer seats/i })).toBeDisabled();
  });

  it("will not step above the maximum", () => {
    setup(100);
    expect(screen.getByRole("button", { name: /More seats/i })).toBeDisabled();
  });

  it("says the price is per seat only where that is true", () => {
    const onChange = vi.fn();
    renderWithI18n(
      <SeatSelector selection={SELECTION} price={PRICE} perSeat={false} value={25} onChange={onChange} />,
    );
    // The package price, not 25 times it.
    expect(screen.getByTestId("seat-total")).toHaveTextContent("400");
  });
});
