import type { Meta, StoryObj } from "@storybook/react";
import { I18nProvider } from "@/lib/i18n/i18n-context";
import type { Product } from "@/lib/commerce/api";
import { ProductCard } from "@/components/commerce/product-card";

// A product is either a single course or a bundle of them — the storefront never sells a
// subscription through this card.
const base: Product = {
  id: "prd_leadership_bundle",
  type: "bundle",
  title: "Leadership Essentials — Bundle",
  slug: "leadership-essentials-bundle",
  description: "Six courses covering management, negotiation and executive communication.",
  prices: [
    { currency: "SAR", amount_minor: 14900, sale_amount_minor: null, on_sale: false, effective_minor: 14900 },
  ],
};

const meta = {
  title: "Commerce/ProductCard",
  component: ProductCard,
  tags: ["autodocs"],
  decorators: [(Story: () => import("react").ReactElement) => (
    <I18nProvider>
      <div className="w-80">
        {Story()}
      </div>
    </I18nProvider>
  )],
  argTypes: {
    onAdd: { action: "add-to-cart" },
    adding: { control: { type: "boolean" } },
  },
  args: { product: base, adding: false, onAdd: () => {} },
} satisfies Meta<typeof ProductCard>;

export default meta;
type Story = StoryObj<typeof meta>;

/** Standard product with a single SAR price. */
export const Default: Story = {};

/** On sale — shows the `warning` "Sale" badge, sale price, and struck-through original. */
export const OnSale: Story = {
  args: {
    product: {
      ...base,
      id: "prd_annual",
      title: "HElbaron Pro — Annual",
      slug: "helbaron-pro-annual",
      description: "Save 2 months when you pay yearly. Full catalog + certificates.",
      prices: [
        { currency: "SAR", amount_minor: 149000, sale_amount_minor: 119000, on_sale: true, effective_minor: 119000 },
      ],
    },
  },
};

/** Arabic (RTL) product copy. */
export const Arabic: Story = {
  args: {
    product: {
      ...base,
      id: "prd_team",
      title: "باقة الفرق",
      slug: "team-plan",
      description: "وصول كامل للفريق مع تقارير التقدّم وإدارة المقاعد.",
      prices: [
        { currency: "SAR", amount_minor: 49900, sale_amount_minor: null, on_sale: false, effective_minor: 49900 },
      ],
    },
  },
};

/** Add-to-cart in progress — button shows its loading spinner. */
export const Adding: Story = {
  args: { adding: true },
};
