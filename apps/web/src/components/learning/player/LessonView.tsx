'use client';

import { useEffect, useRef } from 'react';

import { Skeleton } from '@/components/ui';
import { ResourceList } from '@/components/courseware/resource-list';
import { VideoEmbed, hasEmbeddableVideo } from '@/components/media/video-embed';
import { flattenLessons, type RuntimeCurriculum } from '@/lib/learning/player-api';
import {
  useCompleteBlock,
  useLessonContent,
  useMarkLessonViewed,
} from '@/lib/learning/player-hooks';
import { useLearningPlayerI18n } from '@/lib/learning/player-i18n';
import { AssessmentLaunch } from './AssessmentLaunch';
import { AssignmentLaunch } from './AssignmentLaunch';
import { BlockRenderer } from './blocks/BlockRenderer';
import { CompletionControls } from './CompletionControls';
import { LessonNav } from './LessonNav';
import { LockedLessonNotice } from './LockedLessonNotice';
import { PlayerError } from './PlayerError';

export interface LessonViewProps {
  courseId: string;
  curriculum?: RuntimeCurriculum;
  lessonId: string;
  onNavigate: (lessonId: string) => void;
  onLaunchAssessment?: (assessmentId: string) => void;
  onLaunchAssignment?: (assignmentId: string) => void;
}

/**
 * Single-lesson runtime view. Marks the lesson "viewed" on entry, renders its
 * blocks (video via the signed JIT player), surfaces assessment/assignment
 * launch entry points, and hosts server-authoritative completion + prev/next.
 * A locked lesson renders only its reason and is not playable.
 */
export function LessonView({
  courseId,
  curriculum,
  lessonId,
  onNavigate,
  onLaunchAssessment,
  onLaunchAssignment,
}: LessonViewProps): React.ReactElement {
  const { t } = useLearningPlayerI18n();
  const lesson = flattenLessons(curriculum).find((l) => l.id === lessonId);
  const locked = Boolean(lesson?.locked);

  const viewed = useMarkLessonViewed(courseId);
  const content = useLessonContent(lessonId, { enabled: !locked });
  const externalUrl = content.data?.externalUrl ?? null;
  const completeBlock = useCompleteBlock(courseId);

  // Fire "viewed" exactly once per unlocked lesson entry.
  const viewedRef = useRef<string | null>(null);
  const viewedMutate = viewed.mutate;
  useEffect(() => {
    if (locked) return;
    if (viewedRef.current === lessonId) return;
    viewedRef.current = lessonId;
    viewedMutate(lessonId);
  }, [locked, lessonId, viewedMutate]);

  if (lesson && locked) {
    return (
      <div className="space-y-6" data-testid="lesson-view-locked">
        <h2 className="font-serif text-xl font-semibold tracking-tight">{lesson.title}</h2>
        <LockedLessonNotice lesson={lesson} />
        <LessonNav curriculum={curriculum} currentLessonId={lessonId} onNavigate={onNavigate} />
      </div>
    );
  }

  return (
    <article className="space-y-6" data-testid="lesson-view">
      <h2 className="font-serif text-xl font-semibold tracking-tight">{lesson?.title ?? content.data?.title ?? ''}</h2>

      {content.isLoading ? (
        <div className="space-y-3" data-testid="lesson-loading">
          <Skeleton className="aspect-video w-full" />
          <Skeleton className="h-4 w-2/3" />
        </div>
      ) : content.isError ? (
        <PlayerError
          message={t('player.error.lesson')}
          onRetry={() => void content.refetch()}
          isRetrying={content.isFetching}
        />
      ) : content.data ? (
        <>
          {/*
            An external_link lesson (a Vimeo module, say) defines no blocks — its target lives on
            content.url. Play it inline here rather than leaving the lesson body empty; the raw link
            stays available underneath for anything the embedder cannot render.
          */}
          {externalUrl ? (
            <div className="space-y-2" data-testid="lesson-external-embed">
              {hasEmbeddableVideo(externalUrl) ? (
                <VideoEmbed url={externalUrl} title={content.data.title} className="overflow-hidden rounded-lg" />
              ) : null}
              <a
                href={externalUrl}
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center gap-1.5 text-sm underline underline-offset-4"
              >
                {t('player.openExternal')}
              </a>
            </div>
          ) : null}

          <div className="space-y-6">
            {content.data.blocks.map((block, idx) => (
              <BlockRenderer
                key={block.id}
                lessonId={lessonId}
                block={block}
                initialPositionSeconds={idx === 0 ? content.data?.video?.position_seconds : null}
                durationSeconds={idx === 0 ? content.data?.video?.duration_seconds : null}
                onCompleteBlock={(blockRef) => completeBlock.mutate({ lessonId, blockRef })}
                isCompletingBlock={completeBlock.isPending}
              />
            ))}
          </div>

          {/* The files attached to THIS lesson, in the canonical player rather than only on /lessons. */}
          <ResourceList lessonId={lessonId} title={t('player.lessonResources')} />

          {content.data.assessment && onLaunchAssessment ? (
            <AssessmentLaunch
              assessment={content.data.assessment}
              onLaunch={onLaunchAssessment}
            />
          ) : null}
          {content.data.assignment && onLaunchAssignment ? (
            <AssignmentLaunch
              assignment={content.data.assignment}
              onLaunch={onLaunchAssignment}
            />
          ) : null}
        </>
      ) : null}

      {lesson ? (
        <CompletionControls
          courseId={courseId}
          lessonId={lessonId}
          completed={lesson.completed}
        />
      ) : null}

      <div className="border-t border-border/60 pt-5">
        <LessonNav curriculum={curriculum} currentLessonId={lessonId} onNavigate={onNavigate} />
      </div>
    </article>
  );
}
