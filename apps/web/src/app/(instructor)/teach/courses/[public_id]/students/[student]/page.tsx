"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { ArrowLeft } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { Button } from "@/components/ui/button";
import { LearnerProgressPanel } from "@/components/teach/learner-progress-panel";

export default function TeachLearnerProgressPage() {
  const { t } = useI18n();
  const params = useParams<{ public_id: string; student: string }>();

  return (
    <div className="space-y-6">
      <Button asChild variant="ghost" size="sm" className="mb-2">
        <Link href={`/teach/courses/${params.public_id}`}>
          <ArrowLeft className="size-4 rtl:rotate-180" aria-hidden /> {t("teach.learner.back")}
        </Link>
      </Button>

      <LearnerProgressPanel courseId={params.public_id} studentId={params.student} />
    </div>
  );
}
