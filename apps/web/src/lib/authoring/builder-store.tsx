/**
 * Course Builder — store (view state + undo/redo + save orchestration).
 *
 * Composes the data controller (hooks.ts) into high-level actions the UI calls. Reversible,
 * id-stable operations (rename, summary, content, publish, preview, reorder) are recorded as
 * undo/redo commands; structural create/delete are explicit actions (they change ids, so they are
 * not part of the history chain). Save status drives the sticky-toolbar autosave indicator.
 */
"use client";

import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from "react";
import { toast } from "@/components/ui/toast";
import { errorMessage } from "@/lib/api/errors";
import { StaleWriteError } from "./api";
import { blockDef } from "./block-registry";
import { useAuthoringController } from "./hooks";
import { validateCurriculum } from "./validation";
import type {
  Block,
  BlockContent,
  BlockKind,
  Curriculum,
  LocalizedText,
  PublishState,
  Section,
  Selection,
  SaveStatus,
  UpsertMediaInput,
  ValidationIssue,
} from "./types";

/** Non-destructive conflict surfaced after a stale-write (HTTP 409). */
export interface ConflictState {
  /** The server's authoritative version, when it reported one. */
  currentVersion?: number;
}

interface Command {
  label: string;
  redo: () => Promise<void>;
  undo: () => Promise<void>;
}

export interface BuilderContextValue {
  courseId: string;
  curriculum: Curriculum | undefined;
  isLoading: boolean;
  isError: boolean;
  refetch: () => void;

  selection: Selection;
  select: (s: Selection) => void;

  expanded: ReadonlySet<string>;
  toggleExpand: (sectionId: string) => void;
  expandAll: () => void;
  collapseAll: () => void;

  search: string;
  setSearch: (q: string) => void;

  saveStatus: SaveStatus;
  version: number;
  canUndo: boolean;
  canRedo: boolean;
  undo: () => void;
  redo: () => void;

  issues: ValidationIssue[];

  /** Set when a mutation was rejected as stale (409); drives the non-destructive conflict banner. */
  conflict: ConflictState | null;
  /** Refetch the tree and clear the conflict (discards local undo history, whose versions are stale). */
  reloadAfterConflict: () => void;
  /** Dismiss the banner and keep editing (the user's unsaved input in the editors is preserved). */
  dismissConflict: () => void;

  // High-level actions
  addSection: () => Promise<void>;
  setSectionTitle: (sectionId: string, title: LocalizedText) => Promise<void>;
  setSectionSummary: (sectionId: string, summary: LocalizedText) => Promise<void>;
  deleteSection: (sectionId: string) => Promise<void>;
  publishSection: (sectionId: string, state: PublishState) => Promise<void>;
  reorderSections: (orderedIds: string[]) => Promise<void>;

  addBlock: (sectionId: string, kind: BlockKind) => Promise<void>;
  setBlockTitle: (sectionId: string, blockId: string, title: LocalizedText) => Promise<void>;
  setBlockContent: (sectionId: string, blockId: string, content: BlockContent) => Promise<void>;
  setMedia: (sectionId: string, blockId: string, input: UpsertMediaInput) => Promise<void>;
  deleteBlock: (sectionId: string, blockId: string) => Promise<void>;
  publishBlock: (sectionId: string, blockId: string, state: PublishState) => Promise<void>;
  previewBlock: (sectionId: string, blockId: string) => Promise<void>;
  reorderBlocks: (sectionId: string, orderedIds: string[]) => Promise<void>;
  moveBlockAcross: (fromSectionId: string, toSectionId: string, blockId: string, toIndex: number) => Promise<void>;
  duplicateSection: (sectionId: string) => Promise<void>;
  duplicateBlock: (sectionId: string, blockId: string) => Promise<void>;
}

const BuilderContext = createContext<BuilderContextValue | null>(null);

export function useBuilder(): BuilderContextValue {
  const ctx = useContext(BuilderContext);
  if (!ctx) throw new globalThis.Error("useBuilder must be used within <BuilderProvider>");
  return ctx;
}

export function BuilderProvider({ courseId, children }: { courseId: string; children: ReactNode }) {
  const { query, actions } = useAuthoringController(courseId);
  const curriculum = query.data;

  const [selection, setSelection] = useState<Selection>({ kind: "course" });
  const [expanded, setExpanded] = useState<ReadonlySet<string>>(new Set());
  const [search, setSearch] = useState("");
  const [saveStatus, setSaveStatus] = useState<SaveStatus>("idle");
  const [conflict, setConflict] = useState<ConflictState | null>(null);
  const [past, setPast] = useState<Command[]>([]);
  const [future, setFuture] = useState<Command[]>([]);
  const savedTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  const findSection = useCallback(
    (id: string): Section | undefined => curriculum?.sections.find((s) => s.id === id),
    [curriculum],
  );
  const findBlock = useCallback(
    (sectionId: string, blockId: string): Block | undefined => findSection(sectionId)?.blocks.find((b) => b.id === blockId),
    [findSection],
  );

  const flashSaved = useCallback(() => {
    setSaveStatus("saved");
    if (savedTimer.current) clearTimeout(savedTimer.current);
    savedTimer.current = setTimeout(() => setSaveStatus("idle"), 1600);
  }, []);

  /**
   * A stale-write (409) is not a save failure to shout about — the server simply has newer state.
   * Surface it as a non-destructive banner instead of the generic error toast. The failing action
   * has already rolled back its optimistic patch, so there is no partial local mutation to undo.
   */
  const handleMutationError = useCallback((e: unknown) => {
    if (e instanceof StaleWriteError) {
      setSaveStatus("idle");
      setConflict({ currentVersion: e.currentVersion });
      return;
    }
    setSaveStatus("error");
    toast.error(errorMessage(e, "Couldn't save your changes"));
  }, []);

  /** Execute a side-effect with save-status + error handling. */
  const run = useCallback(
    async (fn: () => Promise<void>) => {
      setSaveStatus("saving");
      try {
        await fn();
        flashSaved();
      } catch (e) {
        handleMutationError(e);
      }
    },
    [flashSaved, handleMutationError],
  );

  /** Run a reversible command and record it for undo. */
  const runCommand = useCallback(
    async (cmd: Command) => {
      setSaveStatus("saving");
      try {
        await cmd.redo();
        setPast((p) => [...p, cmd]);
        setFuture([]);
        flashSaved();
      } catch (e) {
        handleMutationError(e);
      }
    },
    [flashSaved, handleMutationError],
  );

  const reloadAfterConflict = useCallback(() => {
    setConflict(null);
    setPast([]);
    setFuture([]);
    void query.refetch();
  }, [query]);

  const dismissConflict = useCallback(() => setConflict(null), []);

  const undo = useCallback(() => {
    setPast((p) => {
      if (p.length === 0) return p;
      const cmd = p[p.length - 1];
      void run(cmd.undo).then(() => setFuture((f) => [cmd, ...f]));
      return p.slice(0, -1);
    });
  }, [run]);

  const redo = useCallback(() => {
    setFuture((f) => {
      if (f.length === 0) return f;
      const [cmd, ...rest] = f;
      void run(cmd.redo).then(() => setPast((p) => [...p, cmd]));
      return rest;
    });
  }, [run]);

  // ── View state ────────────────────────────────────────────────────────────
  const toggleExpand = useCallback((sectionId: string) => {
    setExpanded((prev) => {
      const next = new Set(prev);
      if (next.has(sectionId)) next.delete(sectionId);
      else next.add(sectionId);
      return next;
    });
  }, []);
  const expandAll = useCallback(() => {
    setExpanded(new Set((curriculum?.sections ?? []).map((s) => s.id)));
  }, [curriculum]);
  const collapseAll = useCallback(() => setExpanded(new Set()), []);

  // ── Sections ───────────────────────────────────────────────────────────────
  const addSection = useCallback(async () => {
    await run(async () => {
      const created = await actions.addSection({ title: "Untitled section" });
      setExpanded((prev) => new Set(prev).add(created.id));
      setSelection({ kind: "section", sectionId: created.id });
      toast.success("Added");
    });
  }, [actions, run]);

  const setSectionTitle = useCallback(
    async (sectionId: string, title: LocalizedText) => {
      const prev = findSection(sectionId)?.title_i18n ?? { en: "", ar: "" };
      if (sameText(prev, title)) return;
      await runCommand({
        label: "rename section",
        redo: () => actions.updateSection(sectionId, { title_i18n: title }),
        undo: () => actions.updateSection(sectionId, { title_i18n: prev }),
      });
    },
    [actions, findSection, runCommand],
  );

  const setSectionSummary = useCallback(
    async (sectionId: string, summary: LocalizedText) => {
      const prev = findSection(sectionId)?.summary_i18n ?? { en: "", ar: "" };
      if (sameText(prev, summary)) return;
      await runCommand({
        label: "edit section summary",
        redo: () => actions.updateSection(sectionId, { summary_i18n: summary }),
        undo: () => actions.updateSection(sectionId, { summary_i18n: prev }),
      });
    },
    [actions, findSection, runCommand],
  );

  const deleteSection = useCallback(
    async (sectionId: string) => {
      await run(async () => {
        await actions.removeSection(sectionId);
        setPast([]);
        setFuture([]);
        setSelection({ kind: "course" });
        toast.success("Deleted");
      });
    },
    [actions, run],
  );

  const publishSection = useCallback(
    async (sectionId: string, state: PublishState) => {
      const prev = findSection(sectionId)?.publish_state ?? "draft";
      await runCommand({
        label: "publish section",
        redo: () => actions.publishSection(sectionId, state),
        undo: () => actions.publishSection(sectionId, prev),
      });
    },
    [actions, findSection, runCommand],
  );

  const reorderSections = useCallback(
    async (orderedIds: string[]) => {
      const prevOrder = (curriculum?.sections ?? []).map((s) => s.id);
      await runCommand({
        label: "reorder sections",
        redo: () => actions.reorderSections(orderedIds),
        undo: () => actions.reorderSections(prevOrder),
      });
    },
    [actions, curriculum, runCommand],
  );

  // ── Blocks ─────────────────────────────────────────────────────────────────
  const addBlock = useCallback(
    async (sectionId: string, kind: BlockKind) => {
      const def = blockDef(kind);
      if (!def.supported) {
        toast.error("That block type isn't available to save yet");
        return;
      }
      await run(async () => {
        const created = await actions.addBlock(sectionId, { title: "Untitled lesson", kind, content: def.defaultContent() });
        setExpanded((prev) => new Set(prev).add(sectionId));
        setSelection({ kind: "lesson", sectionId, blockId: created.id });
        toast.success("Added");
      });
    },
    [actions, run],
  );

  const setBlockTitle = useCallback(
    async (sectionId: string, blockId: string, title: LocalizedText) => {
      const prev = findBlock(sectionId, blockId)?.title_i18n ?? { en: "", ar: "" };
      if (sameText(prev, title)) return;
      await runCommand({
        label: "rename lesson",
        redo: () => actions.updateBlock(sectionId, blockId, { title_i18n: title }),
        undo: () => actions.updateBlock(sectionId, blockId, { title_i18n: prev }),
      });
    },
    [actions, findBlock, runCommand],
  );

  const setBlockContent = useCallback(
    async (sectionId: string, blockId: string, content: BlockContent) => {
      const prev = findBlock(sectionId, blockId)?.content ?? {};
      await runCommand({
        label: "edit lesson",
        redo: () => actions.updateBlock(sectionId, blockId, { content }),
        undo: () => actions.updateBlock(sectionId, blockId, { content: prev }),
      });
    },
    [actions, findBlock, runCommand],
  );

  /**
   * Attach / detach the lesson's media row. Undoable like every other authoring edit: the previous
   * `lesson_media` values are replayed as an upsert (nulls clear columns, per UpsertMediaInput).
   */
  const setMedia = useCallback(
    async (sectionId: string, blockId: string, input: UpsertMediaInput) => {
      const previous = findBlock(sectionId, blockId)?.media ?? null;
      const restore: UpsertMediaInput = {
        mux_asset_id: previous?.mux_asset_id ?? null,
        mux_playback_id: previous?.mux_playback_id ?? null,
        s3_key: previous?.s3_key ?? null,
        mime_type: previous?.mime_type ?? null,
        duration: previous?.duration ?? null,
        filesize: previous?.filesize ?? null,
      };
      await runCommand({
        label: "edit media",
        redo: () => actions.setMedia(sectionId, blockId, input),
        undo: () => actions.setMedia(sectionId, blockId, restore),
      });
    },
    [actions, findBlock, runCommand],
  );

  const deleteBlock = useCallback(
    async (sectionId: string, blockId: string) => {
      await run(async () => {
        await actions.removeBlock(sectionId, blockId);
        setPast([]);
        setFuture([]);
        setSelection({ kind: "section", sectionId });
        toast.success("Deleted");
      });
    },
    [actions, run],
  );

  const publishBlock = useCallback(
    async (sectionId: string, blockId: string, state: PublishState) => {
      const prev = findBlock(sectionId, blockId)?.publish_state ?? "draft";
      await runCommand({
        label: "publish lesson",
        redo: () => actions.publishBlock(sectionId, blockId, state),
        undo: () => actions.publishBlock(sectionId, blockId, prev),
      });
    },
    [actions, findBlock, runCommand],
  );

  const previewBlock = useCallback(
    async (sectionId: string, blockId: string) => {
      await runCommand({
        label: "toggle preview",
        redo: () => actions.previewBlock(sectionId, blockId),
        undo: () => actions.previewBlock(sectionId, blockId),
      });
    },
    [actions, runCommand],
  );

  const reorderBlocks = useCallback(
    async (sectionId: string, orderedIds: string[]) => {
      const prevOrder = (findSection(sectionId)?.blocks ?? []).map((b) => b.id);
      await runCommand({
        label: "reorder lessons",
        redo: () => actions.reorderBlocks(sectionId, orderedIds),
        undo: () => actions.reorderBlocks(sectionId, prevOrder),
      });
    },
    [actions, findSection, runCommand],
  );

  const moveBlockAcross = useCallback(
    async (fromSectionId: string, toSectionId: string, blockId: string, toIndex: number) => {
      const fromOrder = (findSection(fromSectionId)?.blocks ?? []).map((b) => b.id);
      const fromIndex = fromOrder.indexOf(blockId);
      await runCommand({
        label: "move lesson",
        redo: () => actions.moveBlockAcross(fromSectionId, toSectionId, blockId, toIndex),
        undo: () => actions.moveBlockAcross(toSectionId, fromSectionId, blockId, Math.max(0, fromIndex)),
      });
    },
    [actions, findSection, runCommand],
  );

  /**
   * Deep-copy a section via the backend's dedicated endpoint. The previous client-side re-creation
   * lost media, prerequisites, i18n maps and draft state; the server clone preserves them exactly.
   * We refetch the tree (in the controller) so ordering/positions are authoritative, then select the
   * returned copy.
   */
  const duplicateSection = useCallback(
    async (sectionId: string) => {
      if (!findSection(sectionId)) return;
      await run(async () => {
        const created = await actions.duplicateSection(sectionId);
        setExpanded((prev) => new Set(prev).add(created.id));
        setSelection({ kind: "section", sectionId: created.id });
        setPast([]);
        setFuture([]);
        toast.success("Added");
      });
    },
    [actions, findSection, run],
  );

  /**
   * Deep-copy a lesson via the backend's dedicated endpoint (preserves media, prerequisites, i18n
   * title and draft state). No client re-creation, so unsupported kinds no longer need a guard.
   */
  const duplicateBlock = useCallback(
    async (sectionId: string, blockId: string) => {
      if (!findBlock(sectionId, blockId)) return;
      await run(async () => {
        const created = await actions.duplicateBlock(sectionId, blockId);
        setSelection({ kind: "lesson", sectionId, blockId: created.id });
        toast.success("Added");
      });
    },
    [actions, findBlock, run],
  );

  const issues = useMemo(() => (curriculum ? validateCurriculum(curriculum) : []), [curriculum]);

  const value: BuilderContextValue = {
    courseId,
    curriculum,
    isLoading: query.isPending,
    isError: query.isError,
    refetch: () => void query.refetch(),
    selection,
    select: setSelection,
    expanded,
    toggleExpand,
    expandAll,
    collapseAll,
    search,
    setSearch,
    saveStatus,
    version: past.length,
    canUndo: past.length > 0,
    canRedo: future.length > 0,
    undo,
    redo,
    issues,
    conflict,
    reloadAfterConflict,
    dismissConflict,
    addSection,
    setSectionTitle,
    setSectionSummary,
    deleteSection,
    publishSection,
    reorderSections,
    addBlock,
    setBlockTitle,
    setBlockContent,
    setMedia,
    deleteBlock,
    publishBlock,
    previewBlock,
    reorderBlocks,
    moveBlockAcross,
    duplicateSection,
    duplicateBlock,
  };

  return <BuilderContext.Provider value={value}>{children}</BuilderContext.Provider>;
}

/** Structural equality for a bilingual field, so a no-op edit doesn't record an undo command. */
function sameText(a: LocalizedText, b: LocalizedText): boolean {
  return a.en === b.en && a.ar === b.ar;
}
