"use client";

import { useState } from "react";
import { MessageCircleQuestion } from "lucide-react";
import { errorMessage } from "@/lib/api/errors";
import { useCommunityI18n } from "@/lib/community/community-i18n";
import { useAskQuestion } from "@/lib/community/qna-hooks";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from "@/components/ui/dialog";
import { toast } from "@/components/ui/toast";

interface AskQuestionDialogProps {
  courseId: string;
  lessonId: string;
  /** Optional: capture the current playback position (seconds) to anchor the question. */
  getTimestamp?: () => number | null;
}

/**
 * "Ask the instructor" affordance for a lesson. Opens a dialog pre-scoped to the current lesson
 * (lesson_id + an optional playback timestamp), posting to the course Q&A.
 */
export function AskQuestionDialog({ courseId, lessonId, getTimestamp }: AskQuestionDialogProps) {
  const { t } = useCommunityI18n();
  const ask = useAskQuestion(courseId);
  const [open, setOpen] = useState(false);
  const [title, setTitle] = useState("");
  const [body, setBody] = useState("");

  const submit = () => {
    if (!title.trim()) return toast.error(t("qna.titleRequired"));
    if (!body.trim()) return toast.error(t("qna.bodyRequired"));
    const ts = getTimestamp?.() ?? null;
    ask.mutate(
      {
        title: title.trim(),
        body: body.trim(),
        lesson_id: lessonId,
        lesson_timestamp_seconds: ts !== null ? Math.round(ts) : null,
      },
      {
        onSuccess: () => {
          toast.success(t("qna.posted"));
          setOpen(false);
          setTitle("");
          setBody("");
        },
        onError: (e) => toast.error(errorMessage(e, t("common.error"))),
      },
    );
  };

  return (
    <>
      <Button variant="outline" size="sm" className="gap-1.5" onClick={() => setOpen(true)}>
        <MessageCircleQuestion className="size-4" aria-hidden /> {t("qna.askInstructor")}
      </Button>
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t("qna.askInstructor")}</DialogTitle>
          </DialogHeader>
          <div className="space-y-3">
            <div>
              <label htmlFor="ask-title" className="mb-1.5 block text-sm font-medium">
                {t("qna.titleLabel")}
              </label>
              <Input id="ask-title" value={title} onChange={(e) => setTitle(e.target.value)} placeholder={t("qna.titlePlaceholder")} />
            </div>
            <div>
              <label htmlFor="ask-body" className="mb-1.5 block text-sm font-medium">
                {t("qna.bodyLabel")}
              </label>
              <Textarea id="ask-body" rows={4} value={body} onChange={(e) => setBody(e.target.value)} placeholder={t("qna.bodyPlaceholder")} />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)}>
              {t("common.cancel")}
            </Button>
            <Button loading={ask.isPending} onClick={submit}>
              {t("qna.submit")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
