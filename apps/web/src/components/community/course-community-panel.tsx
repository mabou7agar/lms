"use client";

import { useCommunityI18n } from "@/lib/community/community-i18n";
import { QnaSection } from "./qna-section";
import { DiscussionSection } from "./discussion-section";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";

interface CourseCommunityPanelProps {
  courseId: string;
}

/**
 * The enrolled-learner community surface for the course player: Q&A and Discussion as tabs. Scoped
 * to a single course; both children are participation-gated server-side.
 */
export function CourseCommunityPanel({ courseId }: CourseCommunityPanelProps) {
  const { t } = useCommunityI18n();
  return (
    <Tabs defaultValue="qna" className="w-full">
      <TabsList>
        <TabsTrigger value="qna">{t("qna.title")}</TabsTrigger>
        <TabsTrigger value="discussion">{t("forum.title")}</TabsTrigger>
      </TabsList>
      <TabsContent value="qna" className="pt-4">
        <QnaSection courseId={courseId} />
      </TabsContent>
      <TabsContent value="discussion" className="pt-4">
        <DiscussionSection courseId={courseId} />
      </TabsContent>
    </Tabs>
  );
}
