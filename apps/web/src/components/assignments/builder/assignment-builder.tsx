"use client";

/**
 * Assignment builder — the authoring surface's orchestrator. Loads the instructor view of an
 * assignment, holds a local editable draft, and wires the settings form, the rubric builder and the
 * publish controls to their mutations. Validation lives here (the settings form + rubric builder are
 * presentational); the server remains the authority (this never derives publish/grade state).
 *
 * Permission gating is coarse and client-side only: it hides the surface from roles that plainly
 * cannot author. The backend still enforces course OWNERSHIP per request — a role check here is a
 * courtesy, never the security boundary.
 */

import { useEffect, useMemo, useState } from "react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { toast } from "@/components/ui/toast";
import { useAuth } from "@/lib/auth/auth-context";
import { useAssignmentsI18n } from "@/lib/assignments/assignments-i18n";
import { useAssignment, useBuildRubric, useUpdateAssignment } from "@/lib/assignments/assignments-hooks";
import { assignmentToDraft } from "@/lib/assignments/assignments-format";
import type {
  AssignmentInput,
  Rubric,
  RubricInput,
} from "@/lib/assignments/assignments-api";
import { AssignmentSettingsForm } from "./assignment-settings-form";
import { RubricBuilder } from "./rubric-builder";
import { PublishControls } from "./publish-controls";

/** Roles allowed to reach the authoring surface (backend still enforces course ownership). */
export const ASSIGNMENT_AUTHOR_ROLES = ["admin", "instructor", "trainer", "course-manager"] as const;

export interface AssignmentBuilderProps {
  assignmentId: string;
  /**
   * Override the coarse role check. When omitted, membership in {@link ASSIGNMENT_AUTHOR_ROLES}
   * decides. The server is the real authority regardless.
   */
  canManage?: boolean;
}

type SettingsErrors = Partial<Record<keyof AssignmentInput, string>>;

/** Convert a loaded rubric into the editable input shape (drop server-assigned ids/positions). */
function rubricToInput(rubric: Rubric | null): RubricInput {
  if (!rubric) return { title: null, criteria: [] };
  return {
    title: rubric.title,
    criteria: rubric.criteria.map((c) => ({
      title: c.title,
      description: c.description,
      levels: c.levels.map((l) => ({ title: l.title, description: l.description, points: l.points })),
    })),
  };
}

export function AssignmentBuilder({ assignmentId, canManage }: AssignmentBuilderProps) {
  const { t } = useAssignmentsI18n();
  const { user } = useAuth();

  const allowed = useMemo(() => {
    if (canManage !== undefined) return canManage;
    return Boolean(user?.roles?.some((r) => (ASSIGNMENT_AUTHOR_ROLES as readonly string[]).includes(r)));
  }, [canManage, user]);

  const query = useAssignment(allowed ? assignmentId : null);
  const update = useUpdateAssignment(assignmentId);
  const buildRubric = useBuildRubric(assignmentId);

  const [draft, setDraft] = useState<AssignmentInput | null>(null);
  const [rubricDraft, setRubricDraft] = useState<RubricInput | null>(null);
  const [errors, setErrors] = useState<SettingsErrors>({});
  const [rubricError, setRubricError] = useState<string | undefined>();

  // Seed local drafts once the assignment loads (and whenever it is refetched fresh).
  useEffect(() => {
    if (query.data) {
      setDraft(assignmentToDraft(query.data));
      setRubricDraft(rubricToInput(query.data.rubric));
    }
  }, [query.data]);

  if (!allowed) {
    return (
      <div role="alert" className="rounded-md border border-border p-6 text-sm text-muted-foreground">
        {t("builder.forbidden")}
      </div>
    );
  }

  if (query.isPending || !query.data || !draft || !rubricDraft) {
    if (query.isError) {
      return (
        <div role="alert" className="space-y-3 rounded-md border border-destructive/40 p-6">
          <p className="text-sm text-destructive">{t("builder.loadError")}</p>
          <Button type="button" variant="outline" size="sm" onClick={() => query.refetch()}>
            {t("builder.retry")}
          </Button>
        </div>
      );
    }
    return (
      <div className="space-y-4" aria-busy>
        <Skeleton className="h-8 w-48" />
        <Skeleton className="h-40 w-full" />
      </div>
    );
  }

  function validateSettings(d: AssignmentInput): SettingsErrors {
    const next: SettingsErrors = {};
    if (!d.title || d.title.trim() === "") next.title = t("validation.title.required");
    if (!d.submission_type) next.submission_type = t("validation.submissionType.required");
    if (d.max_grade == null || d.max_grade <= 0) next.max_grade = t("validation.maxGrade.positive");
    if (d.passing_grade != null && d.max_grade != null && d.passing_grade > d.max_grade) {
      next.passing_grade = t("validation.passingGrade.range");
    }
    if (
      d.late_policy === "penalised" &&
      d.late_penalty_percent != null &&
      (d.late_penalty_percent < 0 || d.late_penalty_percent > 100)
    ) {
      next.late_penalty_percent = t("validation.penalty.range");
    }
    return next;
  }

  function validateRubric(r: RubricInput): string | undefined {
    if (r.criteria.length === 0) return t("validation.rubric.minCriteria");
    for (const c of r.criteria) {
      if (c.title.trim() === "") return t("validation.rubric.criterionTitle");
      if (c.levels.length === 0) return t("validation.rubric.minLevels");
      for (const l of c.levels) if (l.title.trim() === "") return t("validation.rubric.levelTitle");
    }
    return undefined;
  }

  const saveSettings = async () => {
    const found = validateSettings(draft);
    setErrors(found);
    if (Object.keys(found).length > 0) return;
    try {
      await update.mutateAsync(draft);
      toast.success(t("builder.saved"));
    } catch {
      toast.error(t("builder.saveError"));
    }
  };

  const saveRubric = async () => {
    const err = validateRubric(rubricDraft);
    setRubricError(err);
    if (err) return;
    try {
      await buildRubric.mutateAsync(rubricDraft);
      toast.success(t("rubric.saved"));
    } catch {
      toast.error(t("rubric.error"));
    }
  };

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-center justify-between gap-4">
        <h1 className="text-xl font-semibold text-foreground">{draft.title || t("builder.title")}</h1>
        <PublishControls assignmentId={assignmentId} publishState={query.data.publish_state} />
      </header>

      <Tabs defaultValue="settings">
        <TabsList>
          <TabsTrigger value="settings">{t("builder.settings")}</TabsTrigger>
          <TabsTrigger value="rubric">{t("builder.rubric")}</TabsTrigger>
        </TabsList>

        <TabsContent value="settings" className="space-y-6 pt-4">
          <AssignmentSettingsForm
            value={draft}
            onChange={setDraft}
            errors={errors}
            disabled={update.isPending}
          />
          <div className="flex justify-end">
            <Button type="button" onClick={saveSettings} disabled={update.isPending}>
              {update.isPending ? t("builder.saving") : t("builder.save")}
            </Button>
          </div>
        </TabsContent>

        <TabsContent value="rubric" className="space-y-6 pt-4">
          <RubricBuilder
            value={rubricDraft}
            onChange={setRubricDraft}
            error={rubricError}
            disabled={buildRubric.isPending}
          />
          <div className="flex justify-end">
            <Button type="button" onClick={saveRubric} disabled={buildRubric.isPending}>
              {buildRubric.isPending ? t("rubric.saving") : t("rubric.save")}
            </Button>
          </div>
        </TabsContent>
      </Tabs>
    </div>
  );
}
