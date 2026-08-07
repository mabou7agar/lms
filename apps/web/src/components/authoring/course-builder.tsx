"use client";

import { useEffect, useState } from "react";
import { AlertTriangle } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Drawer, DrawerContent, DrawerTitle } from "@/components/ui/drawer";
import { useAuthoringI18n } from "@/lib/authoring/authoring-i18n";
import { BuilderProvider, useBuilder } from "@/lib/authoring/builder-store";
import { BuilderToolbar } from "./builder-toolbar";
import { ConflictBanner } from "./conflict-banner";
import { CurriculumTree } from "./curriculum-tree";
import { EditorPanel } from "./editor-panel";
import { InspectorPanel } from "./inspector-panel";

/** Public entry point — mounts the builder for a course. */
export function CourseBuilder({ courseId }: { courseId: string }) {
  return (
    <BuilderProvider courseId={courseId}>
      <BuilderShell />
    </BuilderProvider>
  );
}

function BuilderShell() {
  const { t } = useAuthoringI18n();
  const builder = useBuilder();
  const { undo, redo } = builder;
  const [treeOpen, setTreeOpen] = useState(false);
  const [inspectorOpen, setInspectorOpen] = useState(false);

  useEffect(() => {
    function onKey(e: KeyboardEvent) {
      if (!(e.metaKey || e.ctrlKey)) return;
      // Let text editing surfaces own their own history: inside an input, textarea or the rich-text
      // editor (contenteditable), Ctrl/Cmd+Z must undo the keystroke, not the last curriculum command.
      const target = e.target;
      if (target instanceof HTMLElement && (target.isContentEditable || target.closest("input, textarea"))) {
        return;
      }
      const key = e.key.toLowerCase();
      if (key === "z" && !e.shiftKey) {
        e.preventDefault();
        undo();
      } else if ((key === "z" && e.shiftKey) || key === "y") {
        e.preventDefault();
        redo();
      }
    }
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [undo, redo]);

  if (builder.isError) {
    return (
      <div className="flex min-h-[60vh] flex-col items-center justify-center gap-3 p-8 text-center">
        <AlertTriangle className="size-8 text-destructive" aria-hidden />
        <p className="text-sm text-muted-foreground">{t("builder.loadError")}</p>
        <Button variant="outline" onClick={builder.refetch}>
          {t("builder.retry")}
        </Button>
      </div>
    );
  }

  return (
    <div className="flex h-[calc(100dvh-3.5rem)] min-h-[560px] flex-col">
      <BuilderToolbar onOpenTree={() => setTreeOpen(true)} onOpenInspector={() => setInspectorOpen(true)} />
      <ConflictBanner />

      <div className="flex min-h-0 flex-1">
        <aside className="hidden w-[300px] shrink-0 border-e border-border lg:block">
          <CurriculumTree />
        </aside>
        <main className="min-w-0 flex-1 overflow-y-auto bg-muted/20">
          <EditorPanel />
        </main>
        <aside className="hidden w-[320px] shrink-0 border-s border-border xl:block">
          <InspectorPanel />
        </aside>
      </div>

      <Drawer open={treeOpen} onOpenChange={setTreeOpen}>
        <DrawerContent className="h-[85vh]">
          <DrawerTitle className="sr-only">{t("tree.curriculum")}</DrawerTitle>
          <div className="h-full min-h-0">
            <CurriculumTree />
          </div>
        </DrawerContent>
      </Drawer>

      <Drawer open={inspectorOpen} onOpenChange={setInspectorOpen}>
        <DrawerContent className="h-[85vh]">
          <DrawerTitle className="sr-only">{t("inspector.title")}</DrawerTitle>
          <div className="h-full min-h-0">
            <InspectorPanel />
          </div>
        </DrawerContent>
      </Drawer>
    </div>
  );
}
