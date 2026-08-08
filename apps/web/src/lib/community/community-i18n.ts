/**
 * Community (reviews / Q&A / discussion) — module-local i18n.
 *
 * Mirrors `lib/assignments/assignments-i18n.ts` and `lib/learning/player-i18n.ts`: it reuses the
 * app's locale + direction from `useI18n`, but keeps the (many, specialised) community strings
 * self-contained instead of adding them to the shared `lib/i18n/dictionaries.ts`. That keeps this
 * feature slice conflict-free with parallel work on the shared dictionary. Missing keys fall back to
 * the key (dev-visible). Ships EN + AR — `COMMUNITY_DICTS` is exported for a key-parity test.
 */
"use client";

import { useI18n } from "@/lib/i18n/i18n-context";

type Dict = Record<string, string>;

const en: Dict = {
  // Generic
  "common.cancel": "Cancel",
  "common.save": "Save",
  "common.edit": "Edit",
  "common.delete": "Delete",
  "common.loading": "Loading…",
  "common.error": "Something went wrong",
  "common.retry": "Try again",
  "common.you": "You",
  "common.signIn": "Sign in",

  // Reviews
  "reviews.title": "Ratings & reviews",
  "reviews.count.zero": "No reviews yet",
  "reviews.count.one": "{count} review",
  "reviews.count.other": "{count} reviews",
  "reviews.outOfFive": "{rating} out of 5",
  "reviews.distribution": "Rating breakdown",
  "reviews.starsAria": "{stars}-star ratings",
  "reviews.empty": "No reviews yet.",
  "reviews.beFirst": "Be the first to review this course.",
  "reviews.write": "Write a review",
  "reviews.edit": "Edit your review",
  "reviews.yourRating": "Your rating",
  "reviews.ratingAria": "Rate {stars} out of 5",
  "reviews.bodyLabel": "Your review",
  "reviews.bodyPlaceholder": "Share what you thought about this course…",
  "reviews.submit": "Submit review",
  "reviews.update": "Update review",
  "reviews.saved": "Thanks for your review.",
  "reviews.updated": "Your review was updated.",
  "reviews.deleted": "Your review was removed.",
  "reviews.deleteConfirm": "Delete your review? This can't be undone.",
  "reviews.signInToReview": "Sign in to write a review.",
  "reviews.ratingRequired": "Choose a rating first.",
  "reviews.helpful": "Helpful",
  "reviews.helpfulCount": "{count} found this helpful",
  "reviews.instructorResponse": "Instructor response",
  "reviews.verified": "Verified learner",
  "reviews.you": "Your review",
  "reviews.sort": "Sort",
  "reviews.sort.recent": "Most recent",
  "reviews.sort.helpful": "Most helpful",
  "reviews.sort.rating": "Highest rated",

  // Q&A
  "qna.title": "Questions & answers",
  "qna.ask": "Ask a question",
  "qna.askInstructor": "Ask the instructor",
  "qna.filter.all": "All",
  "qna.filter.unanswered": "Unanswered",
  "qna.empty": "No questions yet.",
  "qna.beFirst": "Ask the first question.",
  "qna.titleLabel": "Question",
  "qna.titlePlaceholder": "What would you like to ask?",
  "qna.bodyLabel": "Details",
  "qna.bodyPlaceholder": "Add any detail that helps others answer…",
  "qna.submit": "Post question",
  "qna.posted": "Your question was posted.",
  "qna.titleRequired": "Add a short title.",
  "qna.bodyRequired": "Add some detail.",
  "qna.answers.zero": "No answers yet",
  "qna.answers.one": "{count} answer",
  "qna.answers.other": "{count} answers",
  "qna.answerLabel": "Your answer",
  "qna.answerPlaceholder": "Write an answer…",
  "qna.postAnswer": "Post answer",
  "qna.answerPosted": "Your answer was posted.",
  "qna.accept": "Mark as best answer",
  "qna.accepted": "Best answer",
  "qna.accepted.done": "Best answer selected.",
  "qna.instructor": "Instructor",
  "qna.pinned": "Pinned",
  "qna.resolved": "Resolved",
  "qna.atTimestamp": "Asked at {time}",
  "qna.view": "View",
  "qna.back": "Back to questions",

  // Discussion / forum
  "forum.title": "Discussion",
  "forum.new": "Start a discussion",
  "forum.empty": "No discussions yet.",
  "forum.beFirst": "Start the first discussion.",
  "forum.titleLabel": "Title",
  "forum.titlePlaceholder": "Give your discussion a title",
  "forum.bodyLabel": "Message",
  "forum.bodyPlaceholder": "What would you like to discuss?",
  "forum.submit": "Post discussion",
  "forum.posted": "Your discussion was posted.",
  "forum.titleRequired": "Add a title.",
  "forum.bodyRequired": "Add a message.",
  "forum.reply": "Reply",
  "forum.replyPlaceholder": "Write a reply…",
  "forum.postReply": "Post reply",
  "forum.replyPosted": "Your reply was posted.",
  "forum.pinned": "Pinned",
  "forum.locked": "Locked",
  "forum.solved": "Solved",
  "forum.lockedNote": "This discussion is locked — new replies are disabled.",
  "forum.posts.zero": "No replies yet",
  "forum.posts.one": "{count} reply",
  "forum.posts.other": "{count} replies",
  "forum.back": "Back to discussions",
  "forum.view": "Open",

  // Report (shared)
  "report.action": "Report",
  "report.title": "Report this content",
  "report.reason": "Reason",
  "report.reason.spam": "Spam",
  "report.reason.offensive": "Offensive",
  "report.reason.harassment": "Harassment",
  "report.reason.off_topic": "Off-topic",
  "report.reason.other": "Other",
  "report.note": "Details (optional)",
  "report.notePlaceholder": "Anything the moderators should know…",
  "report.submit": "Submit report",
  "report.submitted": "Thanks — this has been reported.",
};

const ar: Dict = {
  // Generic
  "common.cancel": "إلغاء",
  "common.save": "حفظ",
  "common.edit": "تعديل",
  "common.delete": "حذف",
  "common.loading": "جارٍ التحميل…",
  "common.error": "حدث خطأ ما",
  "common.retry": "إعادة المحاولة",
  "common.you": "أنت",
  "common.signIn": "تسجيل الدخول",

  // Reviews
  "reviews.title": "التقييمات والمراجعات",
  "reviews.count.zero": "لا توجد مراجعات بعد",
  "reviews.count.one": "مراجعة واحدة",
  "reviews.count.other": "{count} مراجعة",
  "reviews.outOfFive": "{rating} من 5",
  "reviews.distribution": "توزيع التقييمات",
  "reviews.starsAria": "تقييمات {stars} نجوم",
  "reviews.empty": "لا توجد مراجعات بعد.",
  "reviews.beFirst": "كن أول من يراجع هذه الدورة.",
  "reviews.write": "اكتب مراجعة",
  "reviews.edit": "تعديل مراجعتك",
  "reviews.yourRating": "تقييمك",
  "reviews.ratingAria": "قيّم بـ {stars} من 5",
  "reviews.bodyLabel": "مراجعتك",
  "reviews.bodyPlaceholder": "شاركنا رأيك في هذه الدورة…",
  "reviews.submit": "إرسال المراجعة",
  "reviews.update": "تحديث المراجعة",
  "reviews.saved": "شكرًا على مراجعتك.",
  "reviews.updated": "تم تحديث مراجعتك.",
  "reviews.deleted": "تمت إزالة مراجعتك.",
  "reviews.deleteConfirm": "حذف مراجعتك؟ لا يمكن التراجع عن ذلك.",
  "reviews.signInToReview": "سجّل الدخول لكتابة مراجعة.",
  "reviews.ratingRequired": "اختر تقييمًا أولًا.",
  "reviews.helpful": "مفيدة",
  "reviews.helpfulCount": "{count} وجدوا هذه المراجعة مفيدة",
  "reviews.instructorResponse": "رد المدرّب",
  "reviews.verified": "متعلّم موثّق",
  "reviews.you": "مراجعتك",
  "reviews.sort": "ترتيب",
  "reviews.sort.recent": "الأحدث",
  "reviews.sort.helpful": "الأكثر إفادة",
  "reviews.sort.rating": "الأعلى تقييمًا",

  // Q&A
  "qna.title": "الأسئلة والأجوبة",
  "qna.ask": "اطرح سؤالًا",
  "qna.askInstructor": "اسأل المدرّب",
  "qna.filter.all": "الكل",
  "qna.filter.unanswered": "بلا إجابة",
  "qna.empty": "لا توجد أسئلة بعد.",
  "qna.beFirst": "كن أول من يطرح سؤالًا.",
  "qna.titleLabel": "السؤال",
  "qna.titlePlaceholder": "ما الذي تريد سؤاله؟",
  "qna.bodyLabel": "التفاصيل",
  "qna.bodyPlaceholder": "أضِف أي تفاصيل تساعد الآخرين على الإجابة…",
  "qna.submit": "نشر السؤال",
  "qna.posted": "تم نشر سؤالك.",
  "qna.titleRequired": "أضِف عنوانًا مختصرًا.",
  "qna.bodyRequired": "أضِف بعض التفاصيل.",
  "qna.answers.zero": "لا توجد إجابات بعد",
  "qna.answers.one": "إجابة واحدة",
  "qna.answers.other": "{count} إجابات",
  "qna.answerLabel": "إجابتك",
  "qna.answerPlaceholder": "اكتب إجابة…",
  "qna.postAnswer": "نشر الإجابة",
  "qna.answerPosted": "تم نشر إجابتك.",
  "qna.accept": "تحديد كأفضل إجابة",
  "qna.accepted": "أفضل إجابة",
  "qna.accepted.done": "تم اختيار أفضل إجابة.",
  "qna.instructor": "المدرّب",
  "qna.pinned": "مثبّت",
  "qna.resolved": "تم الحل",
  "qna.atTimestamp": "سُئل عند {time}",
  "qna.view": "عرض",
  "qna.back": "العودة إلى الأسئلة",

  // Discussion / forum
  "forum.title": "النقاش",
  "forum.new": "ابدأ نقاشًا",
  "forum.empty": "لا توجد نقاشات بعد.",
  "forum.beFirst": "ابدأ أول نقاش.",
  "forum.titleLabel": "العنوان",
  "forum.titlePlaceholder": "اكتب عنوانًا لنقاشك",
  "forum.bodyLabel": "الرسالة",
  "forum.bodyPlaceholder": "ما الذي تريد مناقشته؟",
  "forum.submit": "نشر النقاش",
  "forum.posted": "تم نشر نقاشك.",
  "forum.titleRequired": "أضِف عنوانًا.",
  "forum.bodyRequired": "أضِف رسالة.",
  "forum.reply": "رد",
  "forum.replyPlaceholder": "اكتب ردًا…",
  "forum.postReply": "نشر الرد",
  "forum.replyPosted": "تم نشر ردك.",
  "forum.pinned": "مثبّت",
  "forum.locked": "مقفل",
  "forum.solved": "تم الحل",
  "forum.lockedNote": "هذا النقاش مقفل — الردود الجديدة معطّلة.",
  "forum.posts.zero": "لا توجد ردود بعد",
  "forum.posts.one": "رد واحد",
  "forum.posts.other": "{count} ردود",
  "forum.back": "العودة إلى النقاشات",
  "forum.view": "فتح",

  // Report (shared)
  "report.action": "إبلاغ",
  "report.title": "الإبلاغ عن هذا المحتوى",
  "report.reason": "السبب",
  "report.reason.spam": "محتوى مزعج",
  "report.reason.offensive": "محتوى مسيء",
  "report.reason.harassment": "تحرّش",
  "report.reason.off_topic": "خارج الموضوع",
  "report.reason.other": "آخر",
  "report.note": "تفاصيل (اختياري)",
  "report.notePlaceholder": "أي شيء ينبغي أن يعرفه المشرفون…",
  "report.submit": "إرسال البلاغ",
  "report.submitted": "شكرًا — تم الإبلاغ عن هذا المحتوى.",
};

const DICTS: Record<string, Dict> = { en, ar };

/** Exported for the EN/AR key-parity test — the community surface ships bilingual. */
export const COMMUNITY_DICTS: Readonly<Record<string, Dict>> = DICTS;

export type CommunityT = (key: string, vars?: Record<string, string | number>) => string;

export interface UseCommunityI18n {
  t: CommunityT;
  locale: string;
  dir: "ltr" | "rtl";
}

/** Community-scoped translator; reuses the app locale + direction from `useI18n`. */
export function useCommunityI18n(): UseCommunityI18n {
  const { locale, dir } = useI18n();
  const dict = DICTS[locale] ?? en;
  const t: CommunityT = (key, vars) => {
    let s = dict[key] ?? en[key] ?? key;
    if (vars) {
      for (const [k, v] of Object.entries(vars)) s = s.replace(`{${k}}`, String(v));
    }
    return s;
  };
  return { t, locale, dir };
}

/** Pick a zero/one/other plural key for `count` and interpolate `{count}`. */
export function pluralKey(base: string, count: number): string {
  if (count === 0) return `${base}.zero`;
  if (count === 1) return `${base}.one`;
  return `${base}.other`;
}
