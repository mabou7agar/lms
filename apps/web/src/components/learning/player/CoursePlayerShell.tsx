'use client';

import { useMemo, useState } from 'react';

import { Menu } from 'lucide-react';
import { Button, Drawer, DrawerContent, DrawerTitle, Spinner } from '@/components/ui';
import { flattenLessons, isLessonNavigable } from '@/lib/learning/player-api';
import { useCurriculum, useProgressSummary } from '@/lib/learning/player-hooks';
import {
  LearningPlayerI18nProvider,
  useLearningPlayerI18n,
} from '@/lib/learning/player-i18n';
import { CurriculumSidebar } from './CurriculumSidebar';
import { LessonView } from './LessonView';
import { PlayerError } from './PlayerError';
import { ProgressDisplay } from './ProgressDisplay';

export interface CoursePlayerShellProps {
  courseId: string;
  /** App locale, passed down to the player's module-local i18n. */
  locale?: string | null;
  /** Deep-link into a specific lesson; otherwise resumes / first lesson. */
  initialLessonId?: string | null;
  onLaunchAssessment?: (assessmentId: string) => void;
  onLaunchAssignment?: (assignmentId: string) => void;
}

/** Top-level learner course player. Wrap-and-compose; the integrator mounts this in the route. */
export function CoursePlayerShell(props: CoursePlayerShellProps): React.ReactElement {
  return (
    <LearningPlayerI18nProvider locale={props.locale}>
      <CoursePlayerShellInner {...props} />
    </LearningPlayerI18nProvider>
  );
}

function CoursePlayerShellInner({
  courseId,
  initialLessonId,
  onLaunchAssessment,
  onLaunchAssignment,
}: CoursePlayerShellProps): React.ReactElement {
  const { t, dir } = useLearningPlayerI18n();
  const curriculum = useCurriculum(courseId);
  const summary = useProgressSummary(courseId);

  const lessons = useMemo(() => flattenLessons(curriculum.data), [curriculum.data]);
  const firstNavigable = useMemo(() => lessons.find(isLessonNavigable)?.id ?? null, [lessons]);

  const [selectedLessonId, setSelectedLessonId] = useState<string | null>(initialLessonId ?? null);
  const [drawerOpen, setDrawerOpen] = useState(false);

  // Derive the active lesson during render: an explicit user selection wins; otherwise fall back to
  // the server resume point, then the first navigable lesson. (Previously a post-mount effect.)
  const resumeLessonId = summary.data?.resume_lesson_id ?? null;
  const defaultLessonId =
    resumeLessonId && lessons.some((l) => l.id === resumeLessonId) ? resumeLessonId : firstNavigable;
  const activeLessonId = selectedLessonId ?? defaultLessonId;

  const navigate = (lessonId: string) => {
    setSelectedLessonId(lessonId);
    setDrawerOpen(false);
  };

  if (curriculum.isLoading) {
    return (
      <div
        dir={dir}
        className="flex min-h-[16rem] items-center justify-center"
        data-testid="player-loading"
      >
        <Spinner aria-label={t('player.loading')} />
      </div>
    );
  }

  if (curriculum.isError || !curriculum.data) {
    return (
      <div dir={dir} className="p-4">
        <PlayerError
          message={t('player.error.curriculum')}
          onRetry={() => void curriculum.refetch()}
          isRetrying={curriculum.isFetching}
        />
      </div>
    );
  }

  const progressPercentage =
    summary.data?.progress_percentage ?? curriculum.data.enrollment.progress_percentage;

  const sidebar = (
    <CurriculumSidebar
      curriculum={curriculum.data}
      activeLessonId={activeLessonId}
      onSelectLesson={navigate}
    />
  );

  return (
    <div dir={dir} className="mx-auto max-w-6xl" data-testid="course-player">
      <header className="flex items-center justify-between gap-4 rounded-2xl border border-border/70 bg-card px-5 py-4">
        <div className="min-w-0">
          <p className="text-xs font-semibold uppercase tracking-[0.16em] text-copper">{t('player.curriculum')}</p>
          <h1 className="mt-0.5 truncate font-serif text-2xl font-semibold tracking-tight">{curriculum.data.course.title}</h1>
        </div>
        <Button
          variant="ghost"
          className="lg:hidden"
          onClick={() => setDrawerOpen(true)}
          aria-label={t('player.openMenu')}
          data-testid="open-curriculum"
        >
          <Menu aria-hidden className="size-5" />
        </Button>
      </header>

      <div className="pt-4">
        <ProgressDisplay
          progressPercentage={progressPercentage}
          completedLessons={summary.data?.completed_lessons}
          totalLessons={summary.data?.total_lessons}
          courseCompleted={summary.data?.course_completed}
          resumeLessonId={summary.data?.resume_lesson_id}
          onResume={navigate}
        />
      </div>

      <div className="grid gap-6 pt-6 lg:grid-cols-[19rem_1fr]">
        {/* Persistent sidebar on large screens */}
        <aside className="hidden lg:block" data-testid="sidebar-desktop">
          {sidebar}
        </aside>

        {/* Curriculum drawer on small screens. The sidebar MUST sit inside DrawerContent: the shared
            Drawer is vaul's Root, which renders its children inline rather than portalling them, so a
            bare child stayed permanently in the grid — duplicating the curriculum next to the desktop
            aside and pushing the lesson panel down a row. DrawerContent portals and is mounted only
            while open. Closing is driven by onOpenChange (vaul's API); the previous onClose prop was
            never called, so the drawer could not be dismissed. */}
        <Drawer open={drawerOpen} onOpenChange={setDrawerOpen}>
          <DrawerContent className="max-h-[85vh] overflow-y-auto p-4 lg:hidden">
            <DrawerTitle className="sr-only">{t('player.curriculum')}</DrawerTitle>
            {sidebar}
          </DrawerContent>
        </Drawer>

        <main data-testid="lesson-panel" className="min-w-0 rounded-2xl border border-border/70 bg-card p-5 sm:p-6">
          {activeLessonId ? (
            <LessonView
              courseId={courseId}
              curriculum={curriculum.data}
              lessonId={activeLessonId}
              onNavigate={navigate}
              onLaunchAssessment={onLaunchAssessment}
              onLaunchAssignment={onLaunchAssignment}
            />
          ) : (
            <p className="text-sm text-muted-foreground">{t('player.loading')}</p>
          )}
        </main>
      </div>
    </div>
  );
}
