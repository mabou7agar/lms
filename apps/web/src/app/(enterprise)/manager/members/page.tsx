"use client";

import { useState } from "react";
import { UserX, Trash2 } from "lucide-react";
import { errorMessage } from "@/lib/api/errors";
import { useI18n } from "@/lib/i18n/i18n-context";
import type { EnterpriseMember, MemberRole } from "@/lib/enterprise/manager-api";
import {
  useChangeMemberRole,
  useDeactivateMember,
  useMembers,
  useRemoveMember,
} from "@/lib/enterprise/manager-hooks";
import { PageHeader } from "@/components/student/page-header";
import { QueryState } from "@/components/student/query-state";
import { EmptyState } from "@/components/states/empty-state";
import { FormAlert } from "@/components/auth/form-alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Pagination } from "@/components/ui/pagination";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

const ROLES: MemberRole[] = ["owner", "admin", "manager", "member"];

const roleVariant: Record<string, "default" | "secondary" | "outline"> = {
  owner: "default",
  admin: "default",
  manager: "secondary",
  member: "outline",
};
const statusVariant: Record<string, "success" | "warning" | "outline"> = {
  active: "success",
  invited: "warning",
  inactive: "outline",
  removed: "outline",
};

type PendingAction = { member: EnterpriseMember; action: "remove" | "deactivate" };

export default function ManagerMembersPage() {
  const { t } = useI18n();
  const [page, setPage] = useState(1);
  const query = useMembers(page);

  const changeRole = useChangeMemberRole();
  const deactivate = useDeactivateMember();
  const remove = useRemoveMember();

  const [pending, setPending] = useState<PendingAction | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const onChangeRole = (member: EnterpriseMember, role: MemberRole) => {
    setError(null);
    setNotice(null);
    changeRole.mutate(
      { id: member.id, role },
      {
        onSuccess: () => setNotice(t("manager.members.roleUpdated")),
        onError: (err) => setError(errorMessage(err, t("manager.error"))),
      },
    );
  };

  const onConfirm = () => {
    if (!pending) return;
    setError(null);
    setNotice(null);
    const done = (message: string) => {
      setNotice(message);
      setPending(null);
    };
    const fail = (err: unknown) => {
      setError(errorMessage(err, t("manager.error")));
      setPending(null);
    };

    if (pending.action === "remove") {
      remove.mutate(pending.member.id, {
        // Removing a member releases their seats — surface that to the manager.
        onSuccess: () => done(`${t("manager.members.removed")} ${t("manager.members.seatReleaseNote")}`),
        onError: fail,
      });
    } else {
      deactivate.mutate(pending.member.id, {
        onSuccess: () => done(`${t("manager.members.deactivated")} ${t("manager.members.seatReleaseNote")}`),
        onError: fail,
      });
    }
  };

  const dialogText =
    pending?.action === "remove"
      ? { title: t("manager.members.removeTitle"), body: t("manager.members.removeBody") }
      : { title: t("manager.members.deactivateTitle"), body: t("manager.members.deactivateBody") };

  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow={t("manager.members.eyebrow")}
        icon="Users"
        title={t("manager.members.title")}
        subtitle={t("manager.members.subtitle")}
      />

      {notice ? <FormAlert variant="success">{notice}</FormAlert> : null}
      {error ? <FormAlert>{error}</FormAlert> : null}

      <QueryState
        query={query}
        isEmpty={(d) => d.data.length === 0}
        empty={<EmptyState title={t("manager.members.empty")} />}
      >
        {(data) => (
          <div className="space-y-4">
            <div className="overflow-x-auto rounded-lg border">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t("manager.members.email")}</TableHead>
                    <TableHead>{t("manager.members.role")}</TableHead>
                    <TableHead>{t("manager.members.status")}</TableHead>
                    <TableHead className="text-end">{t("manager.members.actions")}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {data.data.map((m) => (
                    <TableRow key={m.id}>
                      <TableCell className="font-medium">{m.email}</TableCell>
                      <TableCell>
                        <div className="flex items-center gap-2">
                          <Badge variant={roleVariant[m.role] ?? "outline"}>{t(`manager.roles.${m.role}`)}</Badge>
                          <Select value={m.role} onValueChange={(val) => onChangeRole(m, val as MemberRole)}>
                            <SelectTrigger
                              className="h-8 w-32"
                              aria-label={`${t("manager.members.changeRole")} — ${m.email}`}
                            >
                              <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                              {ROLES.map((r) => (
                                <SelectItem key={r} value={r}>
                                  {t(`manager.roles.${r}`)}
                                </SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                        </div>
                      </TableCell>
                      <TableCell>
                        <Badge variant={statusVariant[m.status] ?? "outline"}>
                          {t(`manager.memberStatus.${m.status}`)}
                        </Badge>
                      </TableCell>
                      <TableCell>
                        <div className="flex items-center justify-end gap-2">
                          <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setPending({ member: m, action: "deactivate" })}
                          >
                            <UserX className="size-4" aria-hidden /> {t("manager.members.deactivate")}
                          </Button>
                          <Button
                            variant="destructive"
                            size="sm"
                            onClick={() => setPending({ member: m, action: "remove" })}
                          >
                            <Trash2 className="size-4" aria-hidden /> {t("manager.members.remove")}
                          </Button>
                        </div>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>

            {data.meta.last_page > 1 ? (
              <Pagination page={page} lastPage={data.meta.last_page} onPageChange={setPage} />
            ) : null}
          </div>
        )}
      </QueryState>

      <ConfirmDialog
        open={pending !== null}
        onOpenChange={(open) => {
          if (!open) setPending(null);
        }}
        title={dialogText.title}
        description={dialogText.body}
        confirmLabel={pending?.action === "remove" ? t("manager.members.remove") : t("manager.members.deactivate")}
        loading={remove.isPending || deactivate.isPending}
        onConfirm={onConfirm}
      />
    </div>
  );
}
