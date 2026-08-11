import { describe, expect, it, vi } from "vitest";
import { useState } from "react";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";
import { VideoSourceEditor } from "@/components/authoring/blocks/video-source-editor";
import type { BlockFormValues } from "@/lib/authoring/content-blocks/registry";

/**
 * A stateful harness so the controlled editor behaves like it does in the dialog: values flow back in
 * on every change, so typing accumulates and provider switches actually clear the other sources.
 */
function Harness({ onChange }: { onChange: (v: BlockFormValues) => void }) {
  const [values, setValues] = useState<BlockFormValues>({});
  return (
    <VideoSourceEditor
      values={values}
      onChange={(next) => {
        setValues(next);
        onChange(next);
      }}
    />
  );
}

describe("VideoSourceEditor — provider selector", () => {
  it("offers only the genuinely-supported providers (no invented YouTube/Vimeo)", async () => {
    const user = userEvent.setup();
    renderWithI18n(<Harness onChange={vi.fn()} />);

    await user.click(screen.getByRole("combobox", { name: /Video provider/i }));

    const options = await screen.findAllByRole("option");
    expect(options).toHaveLength(3);
    expect(screen.getByRole("option", { name: /Mux/i })).toBeInTheDocument();
    expect(screen.getByRole("option", { name: /Direct URL/i })).toBeInTheDocument();
    expect(screen.getByRole("option", { name: /Uploaded file/i })).toBeInTheDocument();

    // No fake provider is ever offered.
    expect(screen.queryByRole("option", { name: /youtube/i })).not.toBeInTheDocument();
    expect(screen.queryByRole("option", { name: /vimeo/i })).not.toBeInTheDocument();
  });

  it("applies the picked provider and stores the value under that provider's field only", async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    renderWithI18n(<Harness onChange={onChange} />);

    await user.click(screen.getByRole("combobox", { name: /Video provider/i }));
    await user.click(await screen.findByRole("option", { name: /Direct URL/i }));

    await user.type(screen.getByLabelText(/Direct URL/i), "https://cdn.example.com/v.mp4");

    const last = onChange.mock.calls.at(-1)?.[0] as BlockFormValues;
    expect(last.url).toEqual({ en: "https://cdn.example.com/v.mp4", ar: "https://cdn.example.com/v.mp4" });
    // The other providers' fields are never populated for this block.
    expect(last.mux_playback_id?.en ?? "").toBe("");
    expect(last.s3_key?.en ?? "").toBe("");
  });
});
