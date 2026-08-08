import { beforeEach, describe, expect, it, vi } from "vitest";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";
import type { UseLessonBlocks } from "@/lib/authoring/content-blocks/hooks";
import type { ContentBlock } from "@/lib/authoring/content-blocks/types";

/**
 * The nested content-blocks panel. What matters: it stays invisible in production (flag OR backend
 * off), it renders every load state, and a 409 conflict surfaces the non-destructive banner WITHOUT
 * dropping the blocks already on screen.
 */
const { useFeatureFlag, useLessonBlocks } = vi.hoisted(() => ({
  useFeatureFlag: vi.fn(),
  useLessonBlocks: vi.fn(),
}));

vi.mock("@/lib/flags/hooks", () => ({ useFeatureFlag }));
vi.mock("@/lib/authoring/content-blocks/hooks", () => ({ useLessonBlocks }));

import { LessonBlocksPanel } from "@/components/authoring/blocks/lesson-blocks-panel";

function block(overrides: Partial<ContentBlock> = {}): ContentBlock {
  return {
    id: "b1",
    type: "article",
    family: "content",
    position: 0,
    publish_state: "draft",
    lock_version: 1,
    content: { html: "<p>hi</p>" },
    content_i18n: { en: { html: "<p>hi</p>" } },
    config: null,
    learning_object_id: null,
    ...overrides,
  };
}

function hook(overrides: Partial<UseLessonBlocks> = {}): UseLessonBlocks {
  return {
    blocks: [],
    isLoading: false,
    isError: false,
    featureDisabled: false,
    permissionDenied: false,
    refetch: vi.fn(),
    conflict: null,
    reloadAfterConflict: vi.fn(),
    dismissConflict: vi.fn(),
    addBlock: vi.fn(),
    editBlock: vi.fn(),
    removeBlock: vi.fn(),
    duplicateBlock: vi.fn(),
    publishBlock: vi.fn(),
    reorderBlocks: vi.fn(),
    ...overrides,
  };
}

function render() {
  return renderWithI18n(<LessonBlocksPanel lessonId="l1" lessonVersion={3} />);
}

beforeEach(() => {
  vi.clearAllMocks();
  useFeatureFlag.mockReturnValue(true);
  useLessonBlocks.mockReturnValue(hook());
});

describe("feature gating", () => {
  it("renders nothing when the presentation flag is off", () => {
    useFeatureFlag.mockReturnValue(false);
    const { container } = render();
    expect(container).toBeEmptyDOMElement();
  });

  it("renders nothing when the backend feature flag is off (list 404 → featureDisabled)", () => {
    useLessonBlocks.mockReturnValue(hook({ featureDisabled: true }));
    const { container } = render();
    expect(container).toBeEmptyDOMElement();
  });

  it("passes the flag state through as the hook's `enabled` argument", () => {
    render();
    expect(useLessonBlocks).toHaveBeenCalledWith("l1", 3, true);
  });
});

describe("states", () => {
  it("shows a loading state while blocks are being fetched", () => {
    useLessonBlocks.mockReturnValue(hook({ isLoading: true }));
    render();
    expect(screen.getByRole("status")).toBeInTheDocument();
  });

  it("shows an error state with a retry that refetches", async () => {
    const refetch = vi.fn();
    useLessonBlocks.mockReturnValue(hook({ isError: true, refetch }));
    const user = userEvent.setup();
    render();

    expect(screen.getByText("Couldn't load this lesson's blocks.")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Retry" }));
    expect(refetch).toHaveBeenCalled();
  });

  it("shows an empty state with an add affordance", () => {
    render();
    expect(screen.getByText("No content blocks yet")).toBeInTheDocument();
    // The add affordance appears in the header and again in the empty body.
    expect(screen.getAllByRole("button", { name: "Add block" }).length).toBeGreaterThan(0);
  });

  it("shows a permission message and hides the add menu on a 403", () => {
    useLessonBlocks.mockReturnValue(hook({ permissionDenied: true }));
    render();
    expect(screen.getByText("You don't have permission to edit this lesson's blocks.")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Add block" })).not.toBeInTheDocument();
  });

  it("lists the lesson's blocks", () => {
    useLessonBlocks.mockReturnValue(hook({ blocks: [block(), block({ id: "b2", type: "video" })] }));
    render();
    expect(screen.getByText("Article")).toBeInTheDocument();
    expect(screen.getByText("Video")).toBeInTheDocument();
  });
});

describe("409 conflict UX", () => {
  it("shows the non-destructive banner and keeps the on-screen blocks (never overwrites)", async () => {
    const reloadAfterConflict = vi.fn();
    useLessonBlocks.mockReturnValue(
      hook({ blocks: [block()], conflict: { currentVersion: 5 }, reloadAfterConflict }),
    );
    const user = userEvent.setup();
    render();

    // Banner is shown…
    expect(screen.getByText("This was changed elsewhere")).toBeInTheDocument();
    // …and the block that was on screen is still there (the failed write was rolled back, not applied).
    expect(screen.getByText("Article")).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Reload latest" }));
    expect(reloadAfterConflict).toHaveBeenCalled();
  });
});

describe("duplicate", () => {
  it("calls the duplicate action from a block's menu", async () => {
    const duplicateBlock = vi.fn();
    useLessonBlocks.mockReturnValue(hook({ blocks: [block()], duplicateBlock }));
    const user = userEvent.setup();
    render();

    await user.click(screen.getByRole("button", { name: "More actions" }));
    await user.click(await screen.findByRole("menuitem", { name: "Duplicate" }));
    expect(duplicateBlock).toHaveBeenCalledWith("b1");
  });
});
