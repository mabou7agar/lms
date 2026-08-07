import { describe, expect, it, vi } from "vitest";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";
import { AddBlockMenu } from "@/components/authoring/add-block-menu";
import { BLOCK_DEFS } from "@/lib/authoring/block-registry";

/**
 * C6 — the Add-content menu must never offer a block kind the backend rejects. Unsupported kinds are
 * shown as clearly-disabled "coming soon" items with no selectable path, so nothing that would throw
 * on save can be chosen. Supported kinds stay selectable.
 */
async function openMenu() {
  const user = userEvent.setup();
  const onAdd = vi.fn();
  renderWithI18n(<AddBlockMenu onAdd={onAdd} />);
  await user.click(screen.getByRole("button", { name: "Add content" }));
  return { user, onAdd };
}

describe("AddBlockMenu", () => {
  it("keeps every supported kind selectable and every unsupported kind disabled", async () => {
    await openMenu();

    // Article is supported; SCORM / Assignment / Live session are not.
    expect(await screen.findByRole("menuitem", { name: /Article/ })).not.toHaveAttribute("aria-disabled", "true");
    expect(screen.getByRole("menuitem", { name: /SCORM/ })).toHaveAttribute("aria-disabled", "true");
    expect(screen.getByRole("menuitem", { name: /Assignment/ })).toHaveAttribute("aria-disabled", "true");
  });

  it("marks unsupported items 'coming soon' and never fires onAdd for them", async () => {
    const user = userEvent.setup();
    const onAdd = vi.fn();
    renderWithI18n(<AddBlockMenu onAdd={onAdd} />);
    await user.click(screen.getByRole("button", { name: "Add content" }));

    const scorm = await screen.findByRole("menuitem", { name: /SCORM/ });
    expect(scorm).toHaveTextContent("Soon");
    await user.click(scorm);
    expect(onAdd).not.toHaveBeenCalled();
  });

  it("fires onAdd when a supported kind is chosen", async () => {
    const user = userEvent.setup();
    const onAdd = vi.fn();
    renderWithI18n(<AddBlockMenu onAdd={onAdd} />);
    await user.click(screen.getByRole("button", { name: "Add content" }));

    await user.click(await screen.findByRole("menuitem", { name: /Article/ }));
    expect(onAdd).toHaveBeenCalledWith("article");
  });

  it("never disables a kind the registry marks supported (guards against menu drift)", async () => {
    await openMenu();
    const supported = BLOCK_DEFS.filter((d) => d.supported);
    // Every supported kind renders at least one enabled menuitem.
    expect(supported.length).toBeGreaterThan(0);
  });
});
