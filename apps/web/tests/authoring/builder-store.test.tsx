import { beforeEach, describe, expect, it, vi } from "vitest";
import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";
import { StaleWriteError } from "@/lib/authoring/api";
import type { AuthoringActions } from "@/lib/authoring/hooks";
import type { Curriculum } from "@/lib/authoring/types";

/**
 * The builder store composes the data controller into high-level actions. These cover the two
 * behaviours the refactor changes:
 *  • C2 — duplication delegates to the backend deep-copy action (no client re-creation loop).
 *  • C3 — a stale-write (409) surfaces a non-destructive conflict without overwriting local state.
 */

const { useAuthoringController } = vi.hoisted(() => ({ useAuthoringController: vi.fn() }));
vi.mock("@/lib/authoring/hooks", () => ({ useAuthoringController }));
vi.mock("@/components/ui/toast", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

import { BuilderProvider, useBuilder } from "@/lib/authoring/builder-store";

function curriculum(): Curriculum {
  return {
    course_id: "c1",
    sections: [
      {
        id: "s1",
        title: "Section",
        title_i18n: { en: "Section", ar: "" },
        summary: null,
        summary_i18n: { en: "", ar: "" },
        position: 0,
        publish_state: "draft",
        lock_version: 1,
        blocks: [
          {
            id: "l1",
            title: "Lesson",
            title_i18n: { en: "Lesson", ar: "درس" },
            kind: "article",
            content: {},
            position: 0,
            publish_state: "draft",
            lock_version: 1,
            is_preview: false,
            media: null,
            prerequisites: [],
          },
        ],
      },
    ],
  };
}

let actions: AuthoringActions;
let refetch: ReturnType<typeof vi.fn>;

beforeEach(() => {
  vi.clearAllMocks();
  refetch = vi.fn();
  actions = {
    addSection: vi.fn(),
    updateSection: vi.fn().mockResolvedValue(undefined),
    removeSection: vi.fn().mockResolvedValue(undefined),
    publishSection: vi.fn().mockResolvedValue(undefined),
    reorderSections: vi.fn().mockResolvedValue(undefined),
    duplicateSection: vi.fn().mockResolvedValue({ id: "s2", blocks: [] }),
    addBlock: vi.fn(),
    updateBlock: vi.fn().mockResolvedValue(undefined),
    removeBlock: vi.fn().mockResolvedValue(undefined),
    publishBlock: vi.fn().mockResolvedValue(undefined),
    previewBlock: vi.fn().mockResolvedValue(undefined),
    setMedia: vi.fn().mockResolvedValue(undefined),
    reorderBlocks: vi.fn().mockResolvedValue(undefined),
    moveBlockAcross: vi.fn().mockResolvedValue(undefined),
    duplicateBlock: vi.fn().mockResolvedValue({ id: "l2" }),
  } as unknown as AuthoringActions;
  useAuthoringController.mockReturnValue({
    query: { data: curriculum(), isPending: false, isError: false, refetch },
    actions,
  });
});

function Harness() {
  const b = useBuilder();
  return (
    <div>
      <span data-testid="conflict">{b.conflict ? `conflict:${b.conflict.currentVersion ?? "?"}` : "none"}</span>
      <span data-testid="title">{b.curriculum?.sections[0]?.blocks[0]?.title}</span>
      <button onClick={() => void b.setBlockTitle("s1", "l1", { en: "Changed", ar: "" })}>edit</button>
      <button onClick={() => void b.duplicateBlock("s1", "l1")}>dupe-lesson</button>
      <button onClick={() => void b.duplicateSection("s1")}>dupe-section</button>
      <button onClick={b.reloadAfterConflict}>reload</button>
    </div>
  );
}

function renderBuilder() {
  const user = userEvent.setup();
  renderWithI18n(
    <BuilderProvider courseId="c1">
      <Harness />
    </BuilderProvider>,
  );
  return { user };
}

describe("C2 — duplication uses the backend deep-copy action", () => {
  it("duplicates a lesson through the controller, not by re-creating it client-side", async () => {
    const { user } = renderBuilder();

    await user.click(screen.getByRole("button", { name: "dupe-lesson" }));

    await waitFor(() => expect(actions.duplicateBlock).toHaveBeenCalledWith("s1", "l1"));
    // The old lossy path re-created blocks with addBlock — it must be gone.
    expect(actions.addBlock).not.toHaveBeenCalled();
  });

  it("duplicates a section through the controller, not by re-creating its lessons", async () => {
    const { user } = renderBuilder();

    await user.click(screen.getByRole("button", { name: "dupe-section" }));

    await waitFor(() => expect(actions.duplicateSection).toHaveBeenCalledWith("s1"));
    expect(actions.addSection).not.toHaveBeenCalled();
    expect(actions.addBlock).not.toHaveBeenCalled();
  });
});

describe("C3 — stale-write conflict UX", () => {
  it("shows the conflict banner and does not overwrite local state on a 409", async () => {
    (actions.updateBlock as ReturnType<typeof vi.fn>).mockRejectedValue(new StaleWriteError(7));
    const { user } = renderBuilder();

    expect(screen.getByTestId("conflict")).toHaveTextContent("none");
    await user.click(screen.getByRole("button", { name: "edit" }));

    await waitFor(() => expect(screen.getByTestId("conflict")).toHaveTextContent("conflict:7"));
    // Local state is untouched — the newer server value is never clobbered by the failed edit.
    expect(screen.getByTestId("title")).toHaveTextContent("Lesson");
  });

  it("reloads the latest tree and clears the conflict when the user chooses to reload", async () => {
    (actions.updateBlock as ReturnType<typeof vi.fn>).mockRejectedValue(new StaleWriteError(7));
    const { user } = renderBuilder();

    await user.click(screen.getByRole("button", { name: "edit" }));
    await waitFor(() => expect(screen.getByTestId("conflict")).toHaveTextContent("conflict:7"));

    await user.click(screen.getByRole("button", { name: "reload" }));
    expect(refetch).toHaveBeenCalled();
    await waitFor(() => expect(screen.getByTestId("conflict")).toHaveTextContent("none"));
  });
});
