"use client";

import { useMemo, useState } from "react";
import { BookOpenCheck, SendHorizontal } from "lucide-react";
import { errorMessage } from "@/lib/api/errors";
import { useCourses } from "@/lib/catalog/hooks";
import { useI18n } from "@/lib/i18n/i18n-context";
import type { CourseAssignmentTargetType } from "@/lib/enterprise/manager-api";
import { useAssignCourse, useDepartments, useMembers, useTeams } from "@/lib/enterprise/manager-hooks";
import { PurchasedTraining } from "@/components/enterprise/purchased-training";
import { PageHeader } from "@/components/student/page-header";
import { QueryState } from "@/components/student/query-state";
import { SectionCard } from "@/components/org/section-card";
import { Field } from "@/components/auth/field";
import { FormAlert } from "@/components/auth/form-alert";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";

const TARGET_TYPES: CourseAssignmentTargetType[] = ["organization", "member", "department", "team"];

export default function ManagerTrainingPage() {
  const { t } = useI18n();
  const courses = useCourses({ per_page: 60 });
  const members = useMembers(1);
  const departments = useDepartments();
  const teams = useTeams();
  const assign = useAssignCourse();

  const [courseId, setCourseId] = useState("");
  const [targetType, setTargetType] = useState<CourseAssignmentTargetType>("member");
  const [targetId, setTargetId] = useState("");
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const targets = useMemo(() => {
    if (targetType === "member") {
      return (members.data?.data ?? []).map((m) => ({ id: m.id, label: m.email, muted: m.status }));
    }
    if (targetType === "department") {
      return (departments.data?.data ?? []).map((d) => ({ id: d.id, label: d.name, muted: `${d.members_count ?? 0}` }));
    }
    if (targetType === "team") {
      return (teams.data?.data ?? []).map((tm) => ({ id: tm.id, label: tm.name, muted: null }));
    }
    return [];
  }, [departments.data?.data, members.data?.data, targetType, teams.data?.data]);

  const requiresTarget = targetType !== "organization";
  const canSubmit = courseId !== "" && (!requiresTarget || targetId !== "");

  const onAssign = () => {
    if (!canSubmit) return;
    setNotice(null);
    setError(null);
    assign.mutate(
      {
        course_id: courseId,
        target_type: targetType,
        target_id: requiresTarget ? targetId : null,
      },
      {
        onSuccess: (result) => {
          const s = result.summary;
          setNotice(
            t("manager.training.result")
              .replace("{assigned}", String(s.assigned))
              .replace("{already}", String(s.already_assigned))
              .replace("{skipped}", String(s.skipped_without_account)),
          );
        },
        onError: (err) => setError(errorMessage(err, t("manager.error"))),
      },
    );
  };

  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow={t("manager.training.eyebrow")}
        icon="GraduationCap"
        title={t("manager.training.title")}
        subtitle={t("manager.training.subtitle")}
      />

      {notice ? <FormAlert variant="success">{notice}</FormAlert> : null}
      {error ? <FormAlert>{error}</FormAlert> : null}

      {/* What the company actually paid for comes first: seats are finite and expire, so they are
          what a manager is here to manage. The free catalog grant below is the secondary action. */}
      <PurchasedTraining />

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <SectionCard title={t("manager.training.assignTitle")}>
          <div className="space-y-4">
            <QueryState
              query={courses}
              isEmpty={(d) => d.data.length === 0}
              empty={<p className="text-sm text-muted-foreground">{t("manager.training.noCourses")}</p>}
            >
              {(data) => (
                <Field id="training-course" label={t("manager.training.course")}>
                  <Select value={courseId} onValueChange={setCourseId}>
                    <SelectTrigger id="training-course">
                      <SelectValue placeholder={t("manager.training.coursePlaceholder")} />
                    </SelectTrigger>
                    <SelectContent>
                      {data.data.map((course) => (
                        <SelectItem key={course.id} value={course.id}>
                          {course.title}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </Field>
              )}
            </QueryState>

            <div className="grid gap-4 md:grid-cols-2">
              <Field id="training-target-type" label={t("manager.training.targetType")}>
                <Select
                  value={targetType}
                  onValueChange={(value) => {
                    setTargetType(value as CourseAssignmentTargetType);
                    setTargetId("");
                  }}
                >
                  <SelectTrigger id="training-target-type">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {TARGET_TYPES.map((type) => (
                      <SelectItem key={type} value={type}>
                        {t(`manager.training.targets.${type}`)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </Field>

              {requiresTarget ? (
                <Field id="training-target" label={t("manager.training.target")}>
                  <Select value={targetId} onValueChange={setTargetId}>
                    <SelectTrigger id="training-target">
                      <SelectValue placeholder={t("manager.training.targetPlaceholder")} />
                    </SelectTrigger>
                    <SelectContent>
                      {targets.map((target) => (
                        <SelectItem key={target.id} value={target.id}>
                          {target.label}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </Field>
              ) : (
                <div className="rounded-md border bg-muted/30 p-3 text-sm text-muted-foreground">
                  {t("manager.training.organizationScope")}
                </div>
              )}
            </div>

            <Button onClick={onAssign} disabled={!canSubmit || assign.isPending}>
              <SendHorizontal className="size-4" aria-hidden />
              {assign.isPending ? t("manager.training.assigning") : t("manager.training.assign")}
            </Button>
          </div>
        </SectionCard>

        <SectionCard title={t("manager.training.scopeTitle")}>
          <div className="space-y-4 text-sm">
            <div className="flex items-center justify-between gap-3">
              <span className="text-muted-foreground">{t("manager.training.members")}</span>
              <Badge variant="outline">{members.data?.meta.total ?? 0}</Badge>
            </div>
            <div className="flex items-center justify-between gap-3">
              <span className="text-muted-foreground">{t("manager.training.departments")}</span>
              <Badge variant="outline">{departments.data?.meta.total ?? 0}</Badge>
            </div>
            <div className="flex items-center justify-between gap-3">
              <span className="text-muted-foreground">{t("manager.training.teams")}</span>
              <Badge variant="outline">{teams.data?.meta.total ?? 0}</Badge>
            </div>
            <div className="rounded-md border p-3">
              <BookOpenCheck className="mb-2 size-5 text-primary" aria-hidden />
              <p className="text-muted-foreground">{t("manager.training.note")}</p>
            </div>
          </div>
        </SectionCard>
      </div>
    </div>
  );
}
