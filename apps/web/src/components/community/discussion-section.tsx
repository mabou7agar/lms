"use client";

import { useState } from "react";
import { ArrowLeft, Lock, Pin, CheckCircle2, MessagesSquare } from "lucide-react";
import { errorMessage } from "@/lib/api/errors";
import { useCommunityI18n, pluralKey } from "@/lib/community/community-i18n";
import {
  useCreateThread,
  useReplyToThread,
  useReportPost,
  useReportThread,
  useThread,
  useThreads,
} from "@/lib/community/forum-hooks";
import type { ForumPost, ForumThread } from "@/lib/community/forum-api";
import type { ReportInput } from "@/lib/community/reviews-api";
import { ReportControl } from "./report-control";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Pagination } from "@/components/ui/pagination";
import { toast } from "@/components/ui/toast";

interface DiscussionSectionProps {
  courseId: string;
}

/** Course discussion forum: thread list, a new-thread form, and a thread view with posts + replies. */
export function DiscussionSection({ courseId }: DiscussionSectionProps) {
  const { t } = useCommunityI18n();
  const [page, setPage] = useState(1);
  const [newOpen, setNewOpen] = useState(false);
  const [openThreadId, setOpenThreadId] = useState<string | null>(null);

  const query = useThreads(courseId, { page });

  if (openThreadId) {
    return (
      <div className="space-y-4">
        <Button variant="ghost" size="sm" className="gap-1" onClick={() => setOpenThreadId(null)}>
          <ArrowLeft className="size-4 rtl:rotate-180" aria-hidden /> {t("forum.back")}
        </Button>
        <ThreadView courseId={courseId} threadId={openThreadId} />
      </div>
    );
  }

  const threads = query.data?.data ?? [];
  const lastPage = query.data?.meta.last_page ?? 1;

  return (
    <section aria-labelledby="forum-heading" className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 id="forum-heading" className="text-h2 font-serif">
          {t("forum.title")}
        </h2>
        {!newOpen ? (
          <Button size="sm" onClick={() => setNewOpen(true)}>
            {t("forum.new")}
          </Button>
        ) : null}
      </div>

      {newOpen ? <NewThreadForm courseId={courseId} onDone={() => setNewOpen(false)} /> : null}

      {query.isPending ? (
        <p className="text-sm text-muted-foreground">{t("common.loading")}</p>
      ) : query.isError ? (
        <div className="space-y-2">
          <p className="text-sm text-muted-foreground">{errorMessage(query.error, t("common.error"))}</p>
          <Button size="sm" variant="outline" onClick={() => query.refetch()}>
            {t("common.retry")}
          </Button>
        </div>
      ) : threads.length === 0 ? (
        <p className="text-sm text-muted-foreground">{t("forum.beFirst")}</p>
      ) : (
        <ul className="space-y-3">
          {threads.map((thread) => (
            <li key={thread.id}>
              <ThreadRow thread={thread} onOpen={() => setOpenThreadId(thread.id)} />
            </li>
          ))}
        </ul>
      )}

      {lastPage > 1 ? <Pagination page={page} lastPage={lastPage} onPageChange={setPage} /> : null}
    </section>
  );
}

function ThreadBadges({ thread }: { thread: Pick<ForumThread, "pinned" | "locked" | "solved"> }) {
  const { t } = useCommunityI18n();
  return (
    <>
      {thread.pinned ? (
        <Badge variant="warning" className="gap-1">
          <Pin className="size-3" aria-hidden /> {t("forum.pinned")}
        </Badge>
      ) : null}
      {thread.solved ? (
        <Badge variant="success" className="gap-1">
          <CheckCircle2 className="size-3" aria-hidden /> {t("forum.solved")}
        </Badge>
      ) : null}
      {thread.locked ? (
        <Badge variant="secondary" className="gap-1">
          <Lock className="size-3" aria-hidden /> {t("forum.locked")}
        </Badge>
      ) : null}
    </>
  );
}

function ThreadRow({ thread, onOpen }: { thread: ForumThread; onOpen: () => void }) {
  const { t } = useCommunityI18n();
  return (
    <Card className="transition-colors hover:border-primary/30">
      <CardContent className="p-4">
        <button type="button" onClick={onOpen} className="block w-full text-start">
          <div className="mb-1 flex flex-wrap items-center gap-2">
            <ThreadBadges thread={thread} />
          </div>
          <p className="font-medium leading-snug">{thread.title}</p>
          <div className="mt-1.5 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
            <span className="inline-flex items-center gap-1">
              <MessagesSquare className="size-3.5" aria-hidden />
              {t(pluralKey("forum.posts", thread.posts_count), { count: thread.posts_count })}
            </span>
            {thread.author ? <span>{thread.author.name}</span> : null}
          </div>
        </button>
      </CardContent>
    </Card>
  );
}

function NewThreadForm({ courseId, onDone }: { courseId: string; onDone: () => void }) {
  const { t } = useCommunityI18n();
  const create = useCreateThread(courseId);
  const [title, setTitle] = useState("");
  const [body, setBody] = useState("");

  const submit = () => {
    if (!title.trim()) return toast.error(t("forum.titleRequired"));
    if (!body.trim()) return toast.error(t("forum.bodyRequired"));
    create.mutate(
      { title: title.trim(), body: body.trim() },
      {
        onSuccess: () => {
          toast.success(t("forum.posted"));
          onDone();
        },
        onError: (e) => toast.error(errorMessage(e, t("common.error"))),
      },
    );
  };

  return (
    <Card>
      <CardContent className="space-y-3 p-5">
        <div>
          <label htmlFor="forum-title" className="mb-1.5 block text-sm font-medium">
            {t("forum.titleLabel")}
          </label>
          <Input id="forum-title" value={title} onChange={(e) => setTitle(e.target.value)} placeholder={t("forum.titlePlaceholder")} />
        </div>
        <div>
          <label htmlFor="forum-body" className="mb-1.5 block text-sm font-medium">
            {t("forum.bodyLabel")}
          </label>
          <Textarea id="forum-body" rows={4} value={body} onChange={(e) => setBody(e.target.value)} placeholder={t("forum.bodyPlaceholder")} />
        </div>
        <div className="flex items-center gap-2">
          <Button loading={create.isPending} onClick={submit}>
            {t("forum.submit")}
          </Button>
          <Button variant="ghost" onClick={onDone}>
            {t("common.cancel")}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}

function ThreadView({ courseId, threadId }: { courseId: string; threadId: string }) {
  const { t } = useCommunityI18n();
  const query = useThread(threadId);
  const reply = useReplyToThread(courseId, threadId);
  const reportThread = useReportThread();
  const reportPost = useReportPost();
  const [body, setBody] = useState("");
  const [replyTo, setReplyTo] = useState<string | null>(null);

  if (query.isPending) return <p className="text-sm text-muted-foreground">{t("common.loading")}</p>;
  if (query.isError) {
    return (
      <div className="space-y-2">
        <p className="text-sm text-muted-foreground">{errorMessage(query.error, t("common.error"))}</p>
        <Button size="sm" variant="outline" onClick={() => query.refetch()}>
          {t("common.retry")}
        </Button>
      </div>
    );
  }

  const { thread, posts } = query.data.data;

  const postReply = (parentId: string | null) => {
    if (!body.trim()) return;
    reply.mutate(
      { body: body.trim(), parent_id: parentId },
      {
        onSuccess: () => {
          setBody("");
          setReplyTo(null);
          toast.success(t("forum.replyPosted"));
        },
        onError: (e) => toast.error(errorMessage(e, t("common.error"))),
      },
    );
  };

  return (
    <div className="space-y-4">
      <Card>
        <CardContent className="space-y-2 p-5">
          <div className="flex flex-wrap items-center gap-2">
            <ThreadBadges thread={thread} />
          </div>
          <h3 className="font-serif text-lg font-semibold">{thread.title}</h3>
          <p className="whitespace-pre-line text-sm leading-relaxed text-muted-foreground">{thread.body}</p>
          {thread.author ? <p className="text-xs text-muted-foreground">{thread.author.name}</p> : null}
          <ReportControl onSubmit={(input) => reportThread.mutateAsync({ thread: thread.id, input })} />
        </CardContent>
      </Card>

      <ul className="space-y-3">
        {posts.map((post) => (
          <li key={post.id}>
            <PostCard
              post={post}
              locked={thread.locked}
              onReport={(input) => reportPost.mutateAsync({ post: post.id, input })}
              onReportReply={(replyId, input) => reportPost.mutateAsync({ post: replyId, input })}
              replyingTo={replyTo}
              onStartReply={() => setReplyTo(post.id)}
              onCancelReply={() => setReplyTo(null)}
              replyBody={body}
              onReplyBodyChange={setBody}
              onSubmitReply={() => postReply(post.id)}
              replyPending={reply.isPending}
            />
          </li>
        ))}
      </ul>

      {thread.locked ? (
        <p className="rounded-lg border border-border bg-surface/40 p-3 text-sm text-muted-foreground">{t("forum.lockedNote")}</p>
      ) : (
        <Card>
          <CardContent className="space-y-3 p-4">
            <label htmlFor="forum-reply" className="block text-sm font-medium">
              {t("forum.reply")}
            </label>
            <Textarea
              id="forum-reply"
              rows={3}
              value={replyTo === null ? body : ""}
              onChange={(e) => {
                setReplyTo(null);
                setBody(e.target.value);
              }}
              placeholder={t("forum.replyPlaceholder")}
            />
            <Button size="sm" loading={reply.isPending && replyTo === null} disabled={replyTo !== null || !body.trim()} onClick={() => postReply(null)}>
              {t("forum.postReply")}
            </Button>
          </CardContent>
        </Card>
      )}
    </div>
  );
}

function PostCard({
  post,
  locked,
  onReport,
  onReportReply,
  replyingTo,
  onStartReply,
  onCancelReply,
  replyBody,
  onReplyBodyChange,
  onSubmitReply,
  replyPending,
}: {
  post: ForumPost;
  locked: boolean;
  onReport: (input: ReportInput) => Promise<void>;
  onReportReply: (replyId: string, input: ReportInput) => Promise<void>;
  replyingTo: string | null;
  onStartReply: () => void;
  onCancelReply: () => void;
  replyBody: string;
  onReplyBodyChange: (value: string) => void;
  onSubmitReply: () => void;
  replyPending: boolean;
}) {
  const { t } = useCommunityI18n();
  const isReplying = replyingTo === post.id;

  return (
    <Card>
      <CardContent className="space-y-2 p-4">
        <div className="flex flex-wrap items-center gap-2">
          {post.is_instructor ? <Badge variant="info">{t("qna.instructor")}</Badge> : null}
          {post.author ? <span className="text-xs text-muted-foreground">{post.author.name}</span> : null}
        </div>
        <p className="whitespace-pre-line text-sm leading-relaxed text-muted-foreground">{post.body}</p>
        <div className="flex flex-wrap items-center gap-2">
          {!locked ? (
            <Button variant="ghost" size="sm" className="h-auto px-1.5 py-0.5 text-xs" onClick={onStartReply}>
              {t("forum.reply")}
            </Button>
          ) : null}
          <ReportControl onSubmit={onReport} />
        </div>

        {/* One-level replies */}
        {post.replies && post.replies.length > 0 ? (
          <ul className="mt-2 space-y-2 border-s-2 border-border ps-3">
            {post.replies.map((child) => (
              <li key={child.id} className="space-y-1">
                <div className="flex flex-wrap items-center gap-2">
                  {child.is_instructor ? <Badge variant="info">{t("qna.instructor")}</Badge> : null}
                  {child.author ? <span className="text-xs text-muted-foreground">{child.author.name}</span> : null}
                </div>
                <p className="whitespace-pre-line text-sm leading-relaxed text-muted-foreground">{child.body}</p>
                <ReportControl onSubmit={(input) => onReportReply(child.id, input)} />
              </li>
            ))}
          </ul>
        ) : null}

        {isReplying && !locked ? (
          <div className="mt-2 space-y-2">
            <Textarea rows={2} value={replyBody} onChange={(e) => onReplyBodyChange(e.target.value)} placeholder={t("forum.replyPlaceholder")} />
            <div className="flex items-center gap-2">
              <Button size="sm" loading={replyPending} disabled={!replyBody.trim()} onClick={onSubmitReply}>
                {t("forum.postReply")}
              </Button>
              <Button size="sm" variant="ghost" onClick={onCancelReply}>
                {t("common.cancel")}
              </Button>
            </div>
          </div>
        ) : null}
      </CardContent>
    </Card>
  );
}
