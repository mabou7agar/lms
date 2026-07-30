import type { Meta, StoryObj } from "@storybook/react";
import { I18nProvider } from "@/lib/i18n/i18n-context";
import type { Order } from "@/lib/commerce/api";
import { OrderCard } from "@/components/commerce/order-card";

const base: Order = {
  id: "ord_10231",
  status: "paid",
  currency: "SAR",
  subtotal_minor: 29900,
  discount_minor: 0,
  tax_minor: 0,
  total_minor: 29900,
  placed_at: "2026-06-02T14:20:00Z",
  paid_at: "2026-06-02T14:21:00Z",
  fulfilled_at: null,
  items: [
    { title: "Project Management Foundations", unit_amount_minor: 14900 },
    { title: "Marketing Strategy Masterclass", unit_amount_minor: 15000 },
  ],
  invoice: { number: "INV-2026-0231", status: "issued" },
};

const meta = {
  title: "Commerce/OrderCard",
  component: OrderCard,
  tags: ["autodocs"],
  decorators: [(Story: () => import("react").ReactElement) => (
    <I18nProvider>
      <div className="w-96">
        {Story()}
      </div>
    </I18nProvider>
  )],
  args: { order: base },
} satisfies Meta<typeof OrderCard>;

export default meta;
type Story = StoryObj<typeof meta>;

/** Paid order — status maps to the `success` badge; items + invoice shown. */
export const Paid: Story = {};

/** Fulfilled order — also `success`. */
export const Fulfilled: Story = {
  args: {
    order: { ...base, id: "ord_10240", status: "fulfilled", fulfilled_at: "2026-06-03T09:00:00Z" },
  },
};

/** Pending payment — `warning` badge, no invoice yet. */
export const Pending: Story = {
  args: {
    order: {
      ...base,
      id: "ord_10255",
      status: "pending",
      paid_at: null,
      total_minor: 39900,
      items: [{ title: "Business AI for Decision Makers", unit_amount_minor: 39900 }],
      invoice: null,
    },
  },
};

/** Failed payment — `destructive` badge. */
export const Failed: Story = {
  args: {
    order: {
      ...base,
      id: "ord_10261",
      status: "failed",
      paid_at: null,
      invoice: null,
    },
  },
};

/** Refunded order — falls to the `secondary` badge. */
export const Refunded: Story = {
  args: {
    order: {
      ...base,
      id: "ord_10199",
      status: "refunded",
      invoice: { number: "INV-2026-0199", status: "refunded" },
    },
  },
};
