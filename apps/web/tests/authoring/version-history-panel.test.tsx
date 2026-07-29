import type { ComponentProps } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";
import type { ContentVersion, VersionHistoryPage } from "@/lib/authoring/versioning-api";

/**
 * The version-history surface. It reads the server's history verbatim, gates draft-replacing
 * actions behind confirmation, and surfaces backend refusals rather than inventing its own.
 */
const {
  useVersionHistory,
  useCreateSnapshot,
  useRestoreVersion,
  useRollbackVersion,
  useCloneVersion,
  useForkVersion,
} = vi.hoisted(() => ({
  useVersionHistory: vi.fn(),
  useCreateSnapshot: vi.fn(),
  useRestoreVersion: vi.fn(),
  useRollbackVersion: vi.fn(),
  useCloneVersion: vi.fn(),
  useForkVersion: vi.fn(),
}));

vi.mock("@/lib/authoring/versioning-hooks", () => ({
  useVersionHistory,
  useCreateSnapshot,
  useRestoreVersion,
  useRollbackVersion,
  useCloneVersion,
  useForkVersion,
}));

import { VersionHistoryPanel } from "@/components/authoring/versioning/version-history-panel";

function version(overrides: Partial<ContentVersion> = {}): ContentVersion {
  return {
    id: "v1",
    version_number: 1,
    label: null,
    reason: "manual",
    checksum: "abcd1234ef567890",
    schema_version: 1,
    created_by: 5,
    created_at: "2026-07-20T10:00:00Z",
    source: null,
    summary: { modules: 0, sections: 1, lessons: 2, blocks: 0 },
    ...overrides,
  };
}

function page(versions: ContentVersion[], meta: Partial<VersionHistoryPage["meta"]> = {}): VersionHistoryPage {
  return {
    data: versions,
    meta: { current_page: 1, per_page: 20, total: versions.length, last_page: 1, from: 1, to: versions.length, ...meta },
  };
}

function setHistory(data: VersionHistoryPage | undefined, extra: Record<string, unknown> = {}) {
  useVersionHistory.mockReturnValue({ data, isPending: false, isError: false, isFetching: false, refetch: vi.fn(), ...extra });
}

let createMutate: ReturnType<typeof vi.fn>;
let restoreMutate: ReturnType<typeof vi.fn>;
let rollbackMutate: ReturnType<typeof vi.fn>;
let cloneMutate: ReturnType<typeof vi.fn>;
let forkMutate: ReturnType<typeof vi.fn>;

beforeEach(() => {
  vi.clearAllMocks();
  createMutate = vi.fn().mockResolvedValue(version());
  restoreMutate = vi.fn().mockResolvedValue({ restored: version(), safety_snapshot: version({ id: "v2", reason: "safety" }) });
  rollbackMutate = vi.fn().mockResolvedValue(version({ id: "v3", reason: "rollback" }));
  cloneMutate = vi.fn().mockResolvedValue(version({ id: "v4", reason: "clone" }));
  forkMutate = vi.fn().mockResolvedValue(version({ id: "v5", reason: "fork" }));
  useCreateSnapshot.mockReturnValue({ mutateAsync: createMutate, isPending: false, error: null });
  useRestoreVersion.mockReturnValue({ mutateAsync: restoreMutate, isPending: false, error: null });
  useRollbackVersion.mockReturnValue({ mutateAsync: rollbackMutate, isPending: false, error: null });
  useCloneVersion.mockReturnValue({ mutateAsync: cloneMutate, isPending: false, error: null });
  useForkVersion.mockReturnValue({ mutateAsync: forkMutate, isPending: false, error: null });
});

function render(props: Partial<ComponentProps<typeof VersionHistoryPanel>> = {}) {
  const user = userEvent.setup();
  renderWithI18n(<VersionHistoryPanel courseId="c1" open onOpenChange={vi.fn()} {...props} />);
  return { user };
}

describe("rendering & states", () => {
  it("renders every field of a version row", () => {
    setHistory(page([version()]));
    render();

    expect(screen.getByText("Version 1")).toBeInTheDocument();
    expect(screen.getByText("Snapshot")).toBeInTheDocument(); // reason badge
    expect(screen.getByText("by #5")).toBeInTheDocument();
    expect(screen.getByText("2026-07-20 10:00 UTC")).toBeInTheDocument();
    expect(screen.getByText("checksum abcd1234")).toBeInTheDocument();
    expect(screen.getByText("1 sections · 2 lessons · 0 blocks · 0 modules")).toBeInTheDocument();
  });

  it("shows a source reference for a forked version", () => {
    setHistory(page([version({ reason: "fork", source: { id: "s1", version_number: 7, from_other_course: true } })]));
    render();

    expect(screen.getByText("forked from v7")).toBeInTheDocument();
  });

  it("shows a loading state", () => {
    useVersionHistory.mockReturnValue({ data: undefined, isPending: true, isError: false, refetch: vi.fn() });
    render();

    expect(screen.getByRole("status")).toBeInTheDocument();
  });

  it("shows an empty state", () => {
    setHistory(page([]));
    render();

    expect(screen.getByText("No versions yet. Create the first snapshot to start a history.")).toBeInTheDocument();
  });

  it("shows an error state with a retry", async () => {
    const refetch = vi.fn();
    useVersionHistory.mockReturnValue({ data: undefined, isPending: false, isError: true, refetch });
    const { user } = render();

    expect(screen.getByText("Couldn't load version history.")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Retry" }));
    expect(refetch).toHaveBeenCalled();
  });
});

describe("operations", () => {
  it("creates a snapshot", async () => {
    setHistory(page([version()]));
    const { user } = render();

    await user.click(screen.getByRole("button", { name: "Create snapshot" }));
    const dialog = await screen.findByRole("dialog", { name: "Create a snapshot" });
    await user.click(within(dialog).getByRole("button", { name: "Create snapshot" }));

    await waitFor(() => expect(createMutate).toHaveBeenCalledWith({ label: null, force: false }));
  });

  it("confirms and explains the safety snapshot before restoring", async () => {
    setHistory(page([version()]));
    const { user } = render();

    await user.click(screen.getByRole("button", { name: "Restore" }));
    const dialog = await screen.findByRole("dialog", { name: "Restore this version into the draft?" });
    expect(within(dialog).getByText(/safety snapshot/i)).toBeInTheDocument();

    await user.click(within(dialog).getByRole("button", { name: "Restore" }));
    await waitFor(() => expect(restoreMutate).toHaveBeenCalledWith("v1"));
  });

  it("confirms before rolling back", async () => {
    setHistory(page([version()]));
    const { user } = render();

    await user.click(screen.getByRole("button", { name: "Rollback" }));
    const dialog = await screen.findByRole("dialog", { name: "Roll back to this version?" });
    await user.click(within(dialog).getByRole("button", { name: "Roll back" }));

    await waitFor(() => expect(rollbackMutate).toHaveBeenCalledWith("v1"));
  });

  it("clones a version", async () => {
    setHistory(page([version()]));
    const { user } = render();

    await user.click(screen.getByRole("button", { name: "Clone" }));
    const dialog = await screen.findByRole("dialog", { name: "Clone this version" });
    await user.click(within(dialog).getByRole("button", { name: "Clone" }));

    await waitFor(() => expect(cloneMutate).toHaveBeenCalledWith({ versionId: "v1", label: null }));
  });

  it("requires a destination before forking, then forks", async () => {
    setHistory(page([version()]));
    const { user } = render();

    await user.click(screen.getByRole("button", { name: "Fork" }));
    const dialog = await screen.findByRole("dialog", { name: "Fork this version into another course" });

    expect(within(dialog).getByRole("button", { name: "Fork" })).toBeDisabled();

    await user.type(within(dialog).getByPlaceholderText("Course public id"), "course-2");
    await user.click(within(dialog).getByRole("button", { name: "Fork" }));

    await waitFor(() =>
      expect(forkMutate).toHaveBeenCalledWith({
        versionId: "v1",
        input: { destination_course_id: "course-2", label: null },
      }),
    );
  });

  it("surfaces a backend refusal verbatim", () => {
    setHistory(page([version()]));
    useRestoreVersion.mockReturnValue({
      mutateAsync: restoreMutate,
      isPending: false,
      error: new Error("The stored snapshot failed its integrity check."),
    });
    render();

    expect(screen.getByRole("alert")).toHaveTextContent("The stored snapshot failed its integrity check.");
  });
});

describe("permission-aware actions", () => {
  it("hides restore and rollback when the user may not restore", () => {
    setHistory(page([version()]));
    render({ canRestore: false });

    expect(screen.queryByRole("button", { name: "Restore" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Rollback" })).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Clone" })).toBeInTheDocument();
  });

  it("hides create, clone and fork when the user may not manage", () => {
    setHistory(page([version()]));
    render({ canManage: false });

    expect(screen.queryByRole("button", { name: "Create snapshot" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Clone" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Fork" })).not.toBeInTheDocument();
  });
});
