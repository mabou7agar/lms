import { describe, expect, it, vi } from "vitest";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";
import { BilingualField } from "@/components/authoring/editors/bilingual-field";
import type { LocalizedText } from "@/lib/authoring/types";

/**
 * The bilingual authoring control (C1). It must offer both languages, mark the Arabic control RTL,
 * signal completeness, and tell the author that empty Arabic falls back to English for learners.
 */
function setup(value: LocalizedText, extra: Partial<Parameters<typeof BilingualField>[0]> = {}) {
  const onChange = vi.fn();
  renderWithI18n(<BilingualField label="Title" value={value} onChange={onChange} {...extra} />);
  return { onChange };
}

describe("BilingualField", () => {
  it("renders an English and an Arabic control", () => {
    setup({ en: "Hello", ar: "مرحبا" });
    expect(screen.getByLabelText("Title · English")).toHaveValue("Hello");
    expect(screen.getByLabelText("Title · Arabic")).toHaveValue("مرحبا");
  });

  it("marks the Arabic control RTL and the English control LTR", () => {
    setup({ en: "Hello", ar: "مرحبا" });
    expect(screen.getByLabelText("Title · Arabic")).toHaveAttribute("dir", "rtl");
    expect(screen.getByLabelText("Title · English")).toHaveAttribute("dir", "ltr");
    expect(screen.getByLabelText("Title · Arabic")).toHaveAttribute("lang", "ar");
  });

  it("shows a completeness badge for both languages filled", () => {
    setup({ en: "Hello", ar: "مرحبا" });
    expect(screen.getByText("EN · AR")).toBeInTheDocument();
  });

  it("shows English-only and a fallback hint when Arabic is empty", () => {
    setup({ en: "Hello", ar: "" });
    expect(screen.getByText("EN only")).toBeInTheDocument();
    expect(screen.getByText(/English is shown to Arabic learners/i)).toBeInTheDocument();
  });

  it("reports edits per language", async () => {
    const user = userEvent.setup();
    const { onChange } = setup({ en: "", ar: "" });

    await user.type(screen.getByLabelText("Title · English"), "A");
    expect(onChange).toHaveBeenCalledWith("en", "A");

    await user.type(screen.getByLabelText("Title · Arabic"), "ب");
    expect(onChange).toHaveBeenCalledWith("ar", "ب");
  });
});
