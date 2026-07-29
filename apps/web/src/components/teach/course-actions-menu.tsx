"use client";

import {
  Archive,
  ClipboardCheck,
  Eye,
  GitCompare,
  GraduationCap,
  MoreHorizontal,
  Pencil,
  Send,
  Undo2,
  Users,
} from "lucide-react";
import Link from "next/link";
import { useState } from "react";
import { toast } from "@/components/ui/toast";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { errorMessage } from "@/lib/api/errors";
import { ApiRequestError } from "@/lib/api/client";
import { useI18n } from "@/lib/i18n/i18n-context";
import type { CourseStatus } from "@/lib/teach/api";
import { useArchiveCourse, usePublishCourse, useUnpublishCourse } from "@/lib/teach/hooks";

export interface CourseActionsMenuProps {
  courseId: string;
  title: string;
  status: CourseStatus;
  onReviewReadiness: (courseId: string) => void;
  onViewChanges: (courseId: string) => void;
}

type PendingAction = "publish" | "unpublish" | "archive" | null;

/**
 * Row actions for one course.
 *
 * Two rules drive the shape of this component.
 *
 * State is NEVER changed optimistically. Publishing can be refused by the readiness guard, and
 * flipping the badge to "Published" before the server agrees would show an author a lie they then
 * have to un-believe. The row only changes when the invalidated query comes back.
 *
 * Actions that cannot apply are not rendered at all — publish is absent for an already-published
 * course, unpublish for a draft. This mirrors intent, not authority: the backend remains the only
 * thing enforcing what an instructor may do, and hiding an item is a usability decision, never a
 * security one.
 */
export function CourseActionsMenu({
  courseId,
  title,
  status,
  onReviewReadiness,
  onViewChanges,
}: CourseActionsMenuProps) {
  const { t } = useI18n();
  const [confirming, setConfirming] = useState<PendingAction>(null);

  const publish = usePublishCourse();
  const unpublish = useUnpublishCourse();
  const archive = useArchiveCourse();

  const busy = publish.isPending || unpublish.isPending || archive.isPending;

  const run = async (action: Exclude<PendingAction, null>) => {
    const mutation = action === "publish" ? publish : action === "unpublish" ? unpublish : archive;
    const successKey =
      action === "publish"
        ? "teach.courses.publishedToast"
        : action === "unpublish"
          ? "teach.courses.unpublishedToast"
          : "teach.courses.archivedToast";

    try {
      await mutation.mutateAsync(courseId);
      setConfirming(null);
      toast.success(t(successKey));
    } catch (error) {
      setConfirming(null);

      // A refused publish returns 422 with the readiness blockers in `details`. Surfacing the
      // server's own message plus the blocker count is the difference between "something went
      // wrong" and an author knowing what to go and fix.
      const blockers =
        error instanceof ApiRequestError && Array.isArray(error.details?.blockers)
          ? (error.details.blockers as unknown[]).length
          : 0;

      toast.error(errorMessage(error, t("common.error")), {
        description: blockers > 0 ? t("teach.readiness.blockersFound") : undefined,
        action:
          blockers > 0
            ? { label: t("teach.readiness.review"), onClick: () => onReviewReadiness(courseId) }
            : undefined,
      });
    }
  };

  return (
    <>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button
            variant="ghost"
            size="icon"
            loading={busy}
            aria-label={`${t("teach.actions.menu")}: ${title}`}
          >
            <MoreHorizontal className="size-4" aria-hidden />
          </Button>
        </DropdownMenuTrigger>

        <DropdownMenuContent align="end" className="w-56">
          <DropdownMenuItem asChild>
            <Link href={`/teach/courses/${courseId}/edit`}>
              <Pencil className="size-4" aria-hidden /> {t("teach.actions.continueEditing")}
            </Link>
          </DropdownMenuItem>
          <DropdownMenuItem asChild>
            <Link href={`/teach/courses/${courseId}`}>
              <Eye className="size-4" aria-hidden /> {t("teach.actions.preview")}
            </Link>
          </DropdownMenuItem>

          <DropdownMenuSeparator />

          <DropdownMenuItem onSelect={() => onReviewReadiness(courseId)}>
            <ClipboardCheck className="size-4" aria-hidden /> {t("teach.actions.reviewReadiness")}
          </DropdownMenuItem>
          <DropdownMenuItem onSelect={() => onViewChanges(courseId)}>
            <GitCompare className="size-4" aria-hidden /> {t("teach.actions.viewChanges")}
          </DropdownMenuItem>

          <DropdownMenuSeparator />

          <DropdownMenuItem asChild>
            <Link href={`/teach/courses/${courseId}#students`}>
              <Users className="size-4" aria-hidden /> {t("teach.actions.viewLearners")}
            </Link>
          </DropdownMenuItem>
          <DropdownMenuItem asChild>
            <Link href={`/teach/courses/${courseId}/edit#assessments`}>
              <GraduationCap className="size-4" aria-hidden /> {t("teach.actions.viewAssessments")}
            </Link>
          </DropdownMenuItem>

          <DropdownMenuSeparator />

          {status !== "published" ? (
            <DropdownMenuItem onSelect={() => setConfirming("publish")}>
              <Send className="size-4" aria-hidden /> {t("teach.courses.publish")}
            </DropdownMenuItem>
          ) : null}

          {status === "published" ? (
            <DropdownMenuItem onSelect={() => setConfirming("unpublish")}>
              <Undo2 className="size-4" aria-hidden /> {t("teach.courses.unpublish")}
            </DropdownMenuItem>
          ) : null}

          {status !== "archived" ? (
            <DropdownMenuItem onSelect={() => setConfirming("archive")}>
              <Archive className="size-4" aria-hidden /> {t("teach.courses.archive")}
            </DropdownMenuItem>
          ) : null}
        </DropdownMenuContent>
      </DropdownMenu>

      <ConfirmDialog
        open={confirming === "publish"}
        onOpenChange={(open) => !open && setConfirming(null)}
        title={t("teach.confirm.publishTitle")}
        description={`${title} — ${t("teach.confirm.publishBody")}`}
        confirmLabel={t("teach.courses.publish")}
        confirmVariant="default"
        loading={publish.isPending}
        onConfirm={() => run("publish")}
      />

      <ConfirmDialog
        open={confirming === "unpublish"}
        onOpenChange={(open) => !open && setConfirming(null)}
        title={t("teach.confirm.unpublishTitle")}
        description={`${title} — ${t("teach.confirm.unpublishBody")}`}
        confirmLabel={t("teach.courses.unpublish")}
        loading={unpublish.isPending}
        onConfirm={() => run("unpublish")}
      />

      <ConfirmDialog
        open={confirming === "archive"}
        onOpenChange={(open) => !open && setConfirming(null)}
        title={t("teach.courses.archiveConfirmTitle")}
        description={t("teach.courses.archiveConfirmBody")}
        confirmLabel={t("teach.courses.archive")}
        loading={archive.isPending}
        onConfirm={() => run("archive")}
      />
    </>
  );
}
