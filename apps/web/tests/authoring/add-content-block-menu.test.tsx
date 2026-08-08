import { describe, expect, it, vi } from "vitest";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";
import { AddContentBlockMenu } from "@/components/authoring/blocks/add-content-block-menu";
import { SUPPORTED_BLOCK_KINDS } from "@/lib/authoring/content-blocks/registry";

/**
 * C5 — the nested-blocks add menu persists ONLY runtime-supported kinds, so unlike the curriculum
 * tree's picker it lists no disabled "coming soon" items at all: there is no path to pick a kind the
 * server would reject.
 */
describe("AddContentBlockMenu", () => {
  it("offers every runtime-supported kind and nothing unsupported", async () => {
    const user = userEvent.setup();
    renderWithI18n(<AddContentBlockMenu onAdd={vi.fn()} />);
    await user.click(screen.getByRole("button", { name: "Add block" }));

    const items = await screen.findAllByRole("menuitem");
    expect(items).toHaveLength(SUPPORTED_BLOCK_KINDS.length);

    // Supported kinds are present…
    for (const name of ["Article", "PDF", "Download", "External link", "Video", "Audio"]) {
      expect(screen.getByRole("menuitem", { name })).toBeInTheDocument();
    }
    // …and unsupported kinds never appear (no disabled row to fumble onto).
    for (const name of ["SCORM", "Assignment", "Live session", "xAPI", "Survey"]) {
      expect(screen.queryByRole("menuitem", { name })).not.toBeInTheDocument();
    }
  });

  it("fires onAdd with the chosen kind", async () => {
    const user = userEvent.setup();
    const onAdd = vi.fn();
    renderWithI18n(<AddContentBlockMenu onAdd={onAdd} />);
    await user.click(screen.getByRole("button", { name: "Add block" }));
    await user.click(await screen.findByRole("menuitem", { name: "Article" }));

    expect(onAdd).toHaveBeenCalledWith("article");
  });
});
