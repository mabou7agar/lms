"use client";

import { useParams, useRouter } from "next/navigation";
import { useI18n } from "@/lib/i18n/i18n-context";
import { RequireAuth } from "@/lib/auth/guards";
import { CoursePlayerShell } from "@/components/learning/player";
import { CourseCommunityPanel } from "@/components/community/course-community-panel";

function LearnInner() {
  const { locale } = useI18n();
  const params = useParams<{ public_id: string }>();
  const router = useRouter();

  // The route param IS the course public id — the same value the shell's own data
  // hooks (useCurriculum / useProgressSummary) key off. No page-level fetch: the
  // shell owns curriculum, progress, resume, locking and completion.
  const courseId = params.public_id;

  return (
    <div className="mx-auto max-w-6xl px-4 py-6 sm:py-8">
      <CoursePlayerShell
        courseId={courseId}
        locale={locale}
        // Assessments/assignments launch into the existing lesson playback route,
        // threading the course context (`?course=`) exactly as the curriculum links do.
        onLaunchAssessment={(assessmentId) =>
          router.push(`/lessons/${assessmentId}?course=${courseId}`)
        }
        onLaunchAssignment={(assignmentId) =>
          router.push(`/lessons/${assignmentId}?course=${courseId}`)
        }
      />

      {/* Enrolled-learner community: Q&A + Discussion (preserved from the prior page). */}
      <div className="mt-8">
        <CourseCommunityPanel courseId={courseId} />
      </div>
    </div>
  );
}

export default function CourseLearnPage() {
  return (
    <RequireAuth>
      <LearnInner />
    </RequireAuth>
  );
}
