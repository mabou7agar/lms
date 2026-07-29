/**
 * Course Builder — versioning module-local i18n (P2/W03).
 *
 * Mirrors the builder's `useAuthoringI18n` pattern: reuses the app locale/direction from `useI18n`
 * but keeps versioning strings self-contained. `t(key, vars)` interpolates `{var}` placeholders and
 * falls back to the key (dev-visible) when a string is missing.
 */
"use client";

import { useI18n } from "@/lib/i18n/i18n-context";

type Dict = Record<string, string>;

const en: Dict = {
  "versions.title": "Version history",
  "versions.subtitle": "Immutable snapshots of this course's authoring content.",
  "versions.create": "Create snapshot",
  "versions.empty": "No versions yet. Create the first snapshot to start a history.",
  "versions.loadError": "Couldn't load version history.",
  "versions.retry": "Retry",
  "versions.close": "Close",
  "versions.number": "Version {n}",
  "versions.by": "by #{id}",
  "versions.bySystem": "—",
  "versions.checksum": "checksum {short}",
  "versions.source": "from v{n}",
  "versions.sourceForked": "forked from v{n}",
  "versions.noSource": "—",
  "versions.counts": "{sections} sections · {lessons} lessons · {blocks} blocks · {modules} modules",
  "versions.reason.manual": "Snapshot",
  "versions.reason.safety": "Safety",
  "versions.reason.rollback": "Rollback",
  "versions.reason.clone": "Clone",
  "versions.reason.fork": "Fork",
  "versions.action.details": "Details",
  "versions.action.restore": "Restore",
  "versions.action.rollback": "Rollback",
  "versions.action.clone": "Clone",
  "versions.action.fork": "Fork",
  "versions.details.title": "Version details",
  "versions.details.reason": "Reason",
  "versions.details.created": "Created",
  "versions.details.creator": "Created by",
  "versions.details.checksum": "Checksum",
  "versions.details.schema": "Schema version",
  "versions.details.source": "Source",
  "versions.details.summary": "Contents",
  "versions.snapshot.title": "Create a snapshot",
  "versions.snapshot.label": "Label (optional)",
  "versions.snapshot.labelPlaceholder": "e.g. Before Q3 revision",
  "versions.snapshot.force": "Force a new version even if nothing changed",
  "versions.snapshot.submit": "Create snapshot",
  "versions.restore.title": "Restore this version into the draft?",
  "versions.restore.body": "The current draft is replaced with version {n}. A safety snapshot of the current draft is created first, so this is reversible.",
  "versions.restore.confirm": "Restore",
  "versions.rollback.title": "Roll back to this version?",
  "versions.rollback.body": "A new version is created from version {n} and the draft is set to its content. Later versions are kept.",
  "versions.rollback.confirm": "Roll back",
  "versions.clone.title": "Clone this version",
  "versions.clone.body": "Creates a copy of version {n} in this course as a new version. The draft is not changed.",
  "versions.clone.submit": "Clone",
  "versions.fork.title": "Fork this version into another course",
  "versions.fork.body": "Materialises version {n} as the draft of another course with fresh identifiers.",
  "versions.fork.destination": "Destination course id",
  "versions.fork.destinationPlaceholder": "Course public id",
  "versions.fork.submit": "Fork",
  "versions.cancel": "Cancel",
};

const ar: Dict = {
  "versions.title": "سجل الإصدارات",
  "versions.subtitle": "لقطات غير قابلة للتعديل لمحتوى تأليف هذه الدورة.",
  "versions.create": "إنشاء لقطة",
  "versions.empty": "لا توجد إصدارات بعد. أنشئ أول لقطة لبدء السجل.",
  "versions.loadError": "تعذّر تحميل سجل الإصدارات.",
  "versions.retry": "إعادة المحاولة",
  "versions.close": "إغلاق",
  "versions.number": "إصدار {n}",
  "versions.by": "بواسطة #{id}",
  "versions.bySystem": "—",
  "versions.checksum": "بصمة {short}",
  "versions.source": "من الإصدار v{n}",
  "versions.sourceForked": "منسوخ من v{n}",
  "versions.noSource": "—",
  "versions.counts": "{sections} أقسام · {lessons} دروس · {blocks} عناصر · {modules} وحدات",
  "versions.reason.manual": "لقطة",
  "versions.reason.safety": "أمان",
  "versions.reason.rollback": "استرجاع",
  "versions.reason.clone": "نسخ",
  "versions.reason.fork": "تفريع",
  "versions.action.details": "تفاصيل",
  "versions.action.restore": "استعادة",
  "versions.action.rollback": "استرجاع",
  "versions.action.clone": "نسخ",
  "versions.action.fork": "تفريع",
  "versions.details.title": "تفاصيل الإصدار",
  "versions.details.reason": "السبب",
  "versions.details.created": "أُنشئ",
  "versions.details.creator": "أُنشئ بواسطة",
  "versions.details.checksum": "البصمة",
  "versions.details.schema": "إصدار المخطط",
  "versions.details.source": "المصدر",
  "versions.details.summary": "المحتويات",
  "versions.snapshot.title": "إنشاء لقطة",
  "versions.snapshot.label": "تسمية (اختياري)",
  "versions.snapshot.labelPlaceholder": "مثال: قبل مراجعة الربع الثالث",
  "versions.snapshot.force": "إنشاء إصدار جديد حتى لو لم يتغير شيء",
  "versions.snapshot.submit": "إنشاء لقطة",
  "versions.restore.title": "استعادة هذا الإصدار إلى المسودة؟",
  "versions.restore.body": "سيتم استبدال المسودة الحالية بالإصدار {n}. يتم إنشاء لقطة أمان للمسودة الحالية أولاً، لذا يمكن التراجع.",
  "versions.restore.confirm": "استعادة",
  "versions.rollback.title": "الاسترجاع إلى هذا الإصدار؟",
  "versions.rollback.body": "يتم إنشاء إصدار جديد من الإصدار {n} وتُضبط المسودة على محتواه. تبقى الإصدارات اللاحقة.",
  "versions.rollback.confirm": "استرجاع",
  "versions.clone.title": "نسخ هذا الإصدار",
  "versions.clone.body": "ينشئ نسخة من الإصدار {n} في هذه الدورة كإصدار جديد. لا تتغير المسودة.",
  "versions.clone.submit": "نسخ",
  "versions.fork.title": "تفريع هذا الإصدار إلى دورة أخرى",
  "versions.fork.body": "ينشئ الإصدار {n} كمسودة لدورة أخرى بمعرّفات جديدة.",
  "versions.fork.destination": "معرّف الدورة الوجهة",
  "versions.fork.destinationPlaceholder": "المعرّف العام للدورة",
  "versions.fork.submit": "تفريع",
  "versions.cancel": "إلغاء",
};

const dictionaries: Record<string, Dict> = { en, ar };

export function useVersioningI18n() {
  const { locale, dir } = useI18n();
  const dict = dictionaries[locale] ?? en;

  const t = (key: string, vars?: Record<string, string | number>): string => {
    let value = dict[key] ?? en[key] ?? key;
    if (vars) {
      for (const [name, replacement] of Object.entries(vars)) {
        value = value.replace(new RegExp(`\\{${name}\\}`, "g"), String(replacement));
      }
    }
    return value;
  };

  return { t, dir, locale };
}
