import type { Meta, StoryObj } from "@storybook/react";
import { I18nProvider } from "@/lib/i18n/i18n-context";
import { PlatformPreview } from "@/components/landing/platform-preview";

/**
 * PlatformPreview — composed, static preview of the real learner product (course player,
 * curriculum, progress, certificate) used in the hero. Not a generic illustration.
 */
const meta = {
  title: "Homepage/PlatformPreview",
  component: PlatformPreview,
  parameters: { layout: "centered" },
  tags: ["autodocs"],
  decorators: [(Story: () => import("react").ReactElement) => (
    <I18nProvider>
      <div style={{ width: 560, maxWidth: "90vw", padding: 40 }}>{Story()}</div>
    </I18nProvider>
  )],
} satisfies Meta<typeof PlatformPreview>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Default: Story = {};

export const Arabic: Story = {
  decorators: [(Story: () => import("react").ReactElement) => (
    <I18nProvider initialLocale="ar">
      <div dir="rtl" style={{ width: 560, maxWidth: "90vw", padding: 40 }}>{Story()}</div>
    </I18nProvider>
  )],
};
