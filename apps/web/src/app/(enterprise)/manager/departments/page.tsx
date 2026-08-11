"use client";

import { useState } from "react";
import { Plus, Pencil, Trash2, Check, X } from "lucide-react";
import { errorMessage } from "@/lib/api/errors";
import { useI18n } from "@/lib/i18n/i18n-context";
import type { Department, EnterpriseMember, Team } from "@/lib/enterprise/manager-api";
import {
  useAssignDepartmentManager,
  useAssignTeamManager,
  useCreateDepartment,
  useCreateTeam,
  useDeleteDepartment,
  useDeleteTeam,
  useDepartments,
  useMembers,
  useTeams,
  useUpdateDepartment,
} from "@/lib/enterprise/manager-hooks";
import { PageHeader } from "@/components/student/page-header";
import { QueryState } from "@/components/student/query-state";
import { SectionCard } from "@/components/org/section-card";
import { Field } from "@/components/auth/field";
import { FormAlert } from "@/components/auth/form-alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Badge } from "@/components/ui/badge";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

const CLEAR = "__clear__";
const NO_DEPT = "__none__";

/** Write-only manager assignment select (current assignment is shown separately as manager id). */
function ManagerSelect({
  label,
  members,
  onAssign,
}: {
  label: string;
  members: EnterpriseMember[];
  onAssign: (memberId: string | null) => void;
}) {
  return (
    <Select
      value=""
      onValueChange={(val) => onAssign(val === CLEAR ? null : val)}
    >
      <SelectTrigger className="h-8 w-44" aria-label={label}>
        <SelectValue placeholder={label} />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value={CLEAR}>{label}</SelectItem>
        {members.map((m) => (
          <SelectItem key={m.id} value={m.id}>
            {m.email}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}

function DepartmentRow({
  department,
  members,
  onError,
  onNotice,
}: {
  department: Department;
  members: EnterpriseMember[];
  onError: (msg: string) => void;
  onNotice: (msg: string) => void;
}) {
  const { t } = useI18n();
  const update = useUpdateDepartment();
  const remove = useDeleteDepartment();
  const assign = useAssignDepartmentManager();

  const [editing, setEditing] = useState(false);
  const [name, setName] = useState(department.name);
  const [confirmDelete, setConfirmDelete] = useState(false);

  const saveName = () => {
    update.mutate(
      { id: department.id, name: name.trim() },
      {
        onSuccess: () => {
          setEditing(false);
          onNotice(t("manager.departments.updated"));
        },
        onError: (err) => onError(errorMessage(err, t("manager.error"))),
      },
    );
  };

  return (
    <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border p-3">
      <div className="flex min-w-0 items-center gap-2">
        {editing ? (
          <>
            <Input
              aria-label={t("manager.departments.name")}
              value={name}
              onChange={(e) => setName(e.target.value)}
              className="h-8 w-48"
            />
            <Button size="icon" variant="ghost" aria-label={t("manager.departments.save")} onClick={saveName} disabled={update.isPending}>
              <Check className="size-4" aria-hidden />
            </Button>
            <Button size="icon" variant="ghost" aria-label={t("common.cancel")} onClick={() => { setEditing(false); setName(department.name); }}>
              <X className="size-4" aria-hidden />
            </Button>
          </>
        ) : (
          <>
            <p className="truncate text-sm font-medium">{department.name}</p>
            <Badge variant="outline">
              {department.members_count ?? 0} {t("manager.departments.membersCount")}
            </Badge>
          </>
        )}
      </div>

      <div className="flex items-center gap-2">
        <span className="text-xs text-muted-foreground">
          {t("manager.departments.manager")}: {department.manager_id ?? t("manager.departments.noManager")}
        </span>
        <ManagerSelect
          label={t("manager.departments.assignManager")}
          members={members}
          onAssign={(memberId) =>
            assign.mutate(
              { id: department.id, memberId },
              {
                onSuccess: () => onNotice(t("manager.departments.managerAssigned")),
                onError: (err) => onError(errorMessage(err, t("manager.error"))),
              },
            )
          }
        />
        {!editing ? (
          <Button size="sm" variant="outline" onClick={() => setEditing(true)}>
            <Pencil className="size-4" aria-hidden /> {t("manager.departments.edit")}
          </Button>
        ) : null}
        <Button size="sm" variant="destructive" onClick={() => setConfirmDelete(true)}>
          <Trash2 className="size-4" aria-hidden /> {t("manager.departments.delete")}
        </Button>
      </div>

      <ConfirmDialog
        open={confirmDelete}
        onOpenChange={setConfirmDelete}
        title={t("manager.departments.deleteDeptTitle")}
        description={t("manager.departments.deleteDeptBody")}
        confirmLabel={t("manager.departments.delete")}
        loading={remove.isPending}
        onConfirm={() =>
          remove.mutate(department.id, {
            onSuccess: () => {
              setConfirmDelete(false);
              onNotice(t("manager.departments.deleted"));
            },
            onError: (err) => {
              setConfirmDelete(false);
              onError(errorMessage(err, t("manager.error")));
            },
          })
        }
      />
    </div>
  );
}

function TeamRow({
  team,
  departments,
  members,
  onError,
  onNotice,
}: {
  team: Team;
  departments: Department[];
  members: EnterpriseMember[];
  onError: (msg: string) => void;
  onNotice: (msg: string) => void;
}) {
  const { t } = useI18n();
  const remove = useDeleteTeam();
  const assign = useAssignTeamManager();
  const [confirmDelete, setConfirmDelete] = useState(false);

  const dept = departments.find((d) => Number(d.id) === team.department_id);

  return (
    <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border p-3">
      <div className="min-w-0">
        <p className="truncate text-sm font-medium">{team.name}</p>
        <p className="text-xs text-muted-foreground">
          {t("manager.departments.department")}: {dept?.name ?? t("manager.departments.none")}
        </p>
      </div>
      <div className="flex items-center gap-2">
        <span className="text-xs text-muted-foreground">
          {t("manager.departments.manager")}: {team.manager_id ?? t("manager.departments.noManager")}
        </span>
        <ManagerSelect
          label={t("manager.departments.assignManager")}
          members={members}
          onAssign={(memberId) =>
            assign.mutate(
              { id: team.id, memberId },
              {
                onSuccess: () => onNotice(t("manager.departments.managerAssigned")),
                onError: (err) => onError(errorMessage(err, t("manager.error"))),
              },
            )
          }
        />
        <Button size="sm" variant="destructive" onClick={() => setConfirmDelete(true)}>
          <Trash2 className="size-4" aria-hidden /> {t("manager.departments.delete")}
        </Button>
      </div>

      <ConfirmDialog
        open={confirmDelete}
        onOpenChange={setConfirmDelete}
        title={t("manager.departments.deleteTeamTitle")}
        description={t("manager.departments.deleteTeamBody")}
        confirmLabel={t("manager.departments.delete")}
        loading={remove.isPending}
        onConfirm={() =>
          remove.mutate(team.id, {
            onSuccess: () => {
              setConfirmDelete(false);
              onNotice(t("manager.departments.deleted"));
            },
            onError: (err) => {
              setConfirmDelete(false);
              onError(errorMessage(err, t("manager.error")));
            },
          })
        }
      />
    </div>
  );
}

export default function ManagerDepartmentsPage() {
  const { t } = useI18n();
  const departments = useDepartments();
  const teams = useTeams();
  const members = useMembers(1);

  const createDept = useCreateDepartment();
  const createTeam = useCreateTeam();

  const [deptName, setDeptName] = useState("");
  const [teamName, setTeamName] = useState("");
  const [teamDept, setTeamDept] = useState<string>(NO_DEPT);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const memberList = members.data?.data ?? [];
  const deptList = departments.data?.data ?? [];

  const onCreateDept = () => {
    if (deptName.trim() === "") return;
    setError(null);
    createDept.mutate(deptName.trim(), {
      onSuccess: () => {
        setDeptName("");
        setNotice(t("manager.departments.created"));
      },
      onError: (err) => setError(errorMessage(err, t("manager.error"))),
    });
  };

  const onCreateTeam = () => {
    if (teamName.trim() === "") return;
    setError(null);
    createTeam.mutate(
      { name: teamName.trim(), department_id: teamDept === NO_DEPT ? null : teamDept },
      {
        onSuccess: () => {
          setTeamName("");
          setTeamDept(NO_DEPT);
          setNotice(t("manager.departments.created"));
        },
        onError: (err) => setError(errorMessage(err, t("manager.error"))),
      },
    );
  };

  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow={t("manager.departments.eyebrow")}
        icon="Building"
        title={t("manager.departments.title")}
        subtitle={t("manager.departments.subtitle")}
      />

      {notice ? <FormAlert variant="success">{notice}</FormAlert> : null}
      {error ? <FormAlert>{error}</FormAlert> : null}

      <div className="grid gap-6 lg:grid-cols-2">
        <SectionCard title={t("manager.departments.deptsTitle")}>
          <div className="space-y-4">
            <div className="flex items-end gap-2">
              <Field id="new-dept" label={t("manager.departments.name")} className="flex-1">
                <Input
                  id="new-dept"
                  placeholder={t("manager.departments.namePlaceholder")}
                  value={deptName}
                  onChange={(e) => setDeptName(e.target.value)}
                />
              </Field>
              <Button onClick={onCreateDept} disabled={createDept.isPending}>
                <Plus className="size-4" aria-hidden />
                {createDept.isPending ? t("manager.departments.creating") : t("manager.departments.create")}
              </Button>
            </div>

            <QueryState
              query={departments}
              isEmpty={(d) => d.data.length === 0}
              empty={<p className="text-sm text-muted-foreground">{t("manager.departments.deptEmpty")}</p>}
            >
              {(data) => (
                <div className="space-y-2">
                  {data.data.map((d) => (
                    <DepartmentRow key={d.id} department={d} members={memberList} onError={setError} onNotice={setNotice} />
                  ))}
                </div>
              )}
            </QueryState>
          </div>
        </SectionCard>

        <SectionCard title={t("manager.departments.teamsTitle")}>
          <div className="space-y-4">
            <div className="space-y-2">
              <Field id="new-team" label={t("manager.departments.name")}>
                <Input
                  id="new-team"
                  placeholder={t("manager.departments.namePlaceholder")}
                  value={teamName}
                  onChange={(e) => setTeamName(e.target.value)}
                />
              </Field>
              <Field id="new-team-dept" label={t("manager.departments.department")}>
                <Select value={teamDept} onValueChange={setTeamDept}>
                  <SelectTrigger id="new-team-dept">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value={NO_DEPT}>{t("manager.departments.none")}</SelectItem>
                    {deptList.map((d) => (
                      <SelectItem key={d.id} value={d.id}>
                        {d.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </Field>
              <Button onClick={onCreateTeam} disabled={createTeam.isPending} className="w-full">
                <Plus className="size-4" aria-hidden />
                {createTeam.isPending ? t("manager.departments.creating") : t("manager.departments.create")}
              </Button>
            </div>

            <QueryState
              query={teams}
              isEmpty={(d) => d.data.length === 0}
              empty={<p className="text-sm text-muted-foreground">{t("manager.departments.teamEmpty")}</p>}
            >
              {(data) => (
                <div className="space-y-2">
                  {data.data.map((tm) => (
                    <TeamRow key={tm.id} team={tm} departments={deptList} members={memberList} onError={setError} onNotice={setNotice} />
                  ))}
                </div>
              )}
            </QueryState>
          </div>
        </SectionCard>
      </div>
    </div>
  );
}
