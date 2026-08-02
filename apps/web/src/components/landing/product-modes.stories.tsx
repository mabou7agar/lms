import type { Meta, StoryObj } from "@storybook/react";
import { I18nProvider } from "@/lib/i18n/i18n-context";
import { ProductModes } from "@/components/landing/product-modes";

/**
 * ProductModes — the five HElbaron product modes (Courses, Live Cohorts, Workshops, B2B/B2G
 * Training, Advisory) in a bento layout with per-mode accents, from the brand serviceLines.
 */
const meta = {
  title: "Homepage/ProductModes",
  component: ProductModes,
  parameters: { layout: "fullscreen" },
  tags: ["autodocs"],
  decorators: [(Story: () => import("react").ReactElement) => <I18nProvider>{Story()}</I18nProvider>],
} satisfies Meta<typeof ProductModes>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Default: Story = {};

export const Arabic: Story = {
  decorators: [(Story: () => import("react").ReactElement) => <I18nProvider initialLocale="ar">{Story()}</I18nProvider>],
};
