"use client";

import { Minus, Plus, Users } from "lucide-react";
import type { Price, Product } from "@/lib/commerce/api";
import { formatMoney } from "@/lib/format";
import { useI18n } from "@/lib/i18n/i18n-context";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

type Selection = NonNullable<NonNullable<Product["seats"]>["selection"]>;

/**
 * Clamp a typed count onto the grid the product is actually sold on.
 *
 * Exported and pure so the buy box, the tests and any future checkout step share one definition of
 * "a count this product sells" — the same one the server enforces. A UI that lets a buyer settle on
 * 7 seats and then has the API refuse it is worse than one that never offered 7.
 */
export function snapSeats(value: number, { min, max, increment }: Selection): number {
  if (!Number.isFinite(value)) return min;
  const steps = Math.round((value - min) / increment);
  const snapped = min + Math.max(0, steps) * increment;
  return max === null ? Math.max(min, snapped) : Math.min(max, Math.max(min, snapped));
}

/** What the buyer will be charged for `seats` of this product. */
export function seatTotalMinor(price: Price, seats: number, perSeat: boolean): number {
  return perSeat ? price.effective_minor * Math.max(1, seats) : price.effective_minor;
}

/**
 * The seat control on a company buy box: pick a count, see what it costs.
 *
 * The running total is shown next to the control rather than only at checkout, because the number
 * a company is choosing IS the price for a per-seat product, and making them add it to a cart to
 * discover that is how a purchase gets abandoned.
 */
export function SeatSelector({
  selection,
  price,
  perSeat,
  value,
  onChange,
}: {
  selection: Selection;
  price: Price | null;
  perSeat: boolean;
  value: number;
  onChange: (seats: number) => void;
}) {
  const { t, locale } = useI18n();
  const { min, max, increment } = selection;

  const step = (delta: number) => onChange(snapSeats(value + delta * increment, selection));
  const total = price ? seatTotalMinor(price, value, perSeat) : null;

  return (
    <div className="space-y-2 rounded-xl border border-border/70 bg-surface/40 p-3">
      <label htmlFor="seat-count" className="flex items-center gap-2 text-sm font-medium">
        <Users className="size-4 text-copper" aria-hidden />
        {t("commerce.seats.label")}
      </label>

      <div className="flex items-center gap-2">
        <Button
          type="button"
          size="icon"
          variant="outline"
          aria-label={t("commerce.seats.decrease")}
          disabled={value <= min}
          onClick={() => step(-1)}
        >
          <Minus className="size-4" aria-hidden />
        </Button>
        <Input
          id="seat-count"
          type="number"
          inputMode="numeric"
          className="w-24 text-center tabular-nums"
          min={min}
          max={max ?? undefined}
          step={increment}
          value={value}
          onChange={(e) => onChange(Number(e.target.value))}
          // Snapped on blur, not on every keystroke: correcting "2" to "5" while someone is still
          // typing "25" makes the field impossible to use.
          onBlur={(e) => onChange(snapSeats(Number(e.target.value), selection))}
        />
        <Button
          type="button"
          size="icon"
          variant="outline"
          aria-label={t("commerce.seats.increase")}
          disabled={max !== null && value >= max}
          onClick={() => step(1)}
        >
          <Plus className="size-4" aria-hidden />
        </Button>
      </div>

      <p className="text-xs text-muted-foreground">
        {t("commerce.seats.bounds")
          .replace("{min}", String(min))
          .replace("{max}", max === null ? t("commerce.seats.noMax") : String(max))
          .replace("{step}", String(increment))}
      </p>

      {total !== null && price ? (
        <p className="text-sm font-medium tabular-nums" data-testid="seat-total">
          {t("commerce.seats.total").replace("{total}", formatMoney(total, price.currency, locale))}
        </p>
      ) : null}
    </div>
  );
}
