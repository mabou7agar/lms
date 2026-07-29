"use client";

/**
 * Publish / unpublish controls for an assignment. The button offered follows the SERVER's
 * `publish_state` (never a locally derived guess): a published assignment can be unpublished, any
 * other state can be published. Both actions confirm first — publishing exposes the assignment to
 * learners; unpublishing hides it. Errors surface via toast rather than swallowing the failure.
 */

import { useState } from "react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { toast } from "@/components/ui/toast";
import { useAssignmentsI18n } from "@/lib/assignments/assignments-i18n";
import {
  usePublishAssignment,
  useUnpublishAssignment,
} from "@/lib/assignments/assignments-hooks";
import type { AssignmentPublishState } from "@/lib/assignments/assignments-api";

export interface PublishControlsProps {
  assignmentId: string;
  publishState: AssignmentPublishState;
  disabled?: boolean;
}

const BADGE_VARIANT: Record<AssignmentPublishState, "secondary" | "success" | "warning"> = {
  draft: "secondary",
  published: "success",
  unpublished: "warning",
};

export function PublishControls({ assignmentId, publishState, disabled = false }: PublishControlsProps) {
  const { t } = useAssignmentsI18n();
  const [confirming, setConfirming] = useState(false);
  const publish = usePublishAssignment(assignmentId);
  const unpublish = useUnpublishAssignment(assignmentId);

  const isPublished = publishState === "published";
  const pending = publish.isPending || unpublish.isPending;

  const run = async () => {
    try {
      if (isPublished) await unpublish.mutateAsync();
      else await publish.mutateAsync();
      setConfirming(false);
    } catch {
      toast.error(t("publish.error"));
    }
  };

  return (
    <div className="flex items-center gap-3">
      <Badge variant={BADGE_VARIANT[publishState]}>{t(`publish.state.${publishState}`)}</Badge>

      <Button
        type="button"
        variant={isPublished ? "outline" : "default"}
        disabled={disabled || pending}
        onClick={() => setConfirming(true)}
      >
        {pending
          ? isPublished
            ? t("publish.unpublishing")
            : t("publish.publishing")
          : isPublished
            ? t("publish.unpublish")
            : t("publish.publish")}
      </Button>

      <ConfirmDialog
        open={confirming}
        onOpenChange={setConfirming}
        title={isPublished ? t("publish.confirm.unpublish.title") : t("publish.confirm.publish.title")}
        description={isPublished ? t("publish.confirm.unpublish.body") : t("publish.confirm.publish.body")}
        confirmLabel={isPublished ? t("publish.unpublish") : t("publish.publish")}
        cancelLabel={t("action.cancel")}
        confirmVariant={isPublished ? "destructive" : "default"}
        loading={pending}
        onConfirm={run}
      />
    </div>
  );
}
