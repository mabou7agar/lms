/**
 * Instructor Media Library — module-local i18n (P2/W04).
 *
 * Mirrors the versioning module's `useVersioningI18n` pattern: reuses the app locale/direction from
 * `useI18n` but keeps media strings self-contained. `t(key, vars)` interpolates `{var}` and falls
 * back to the English string, then the key (dev-visible), when a string is missing. RTL-safe (ar).
 */
"use client";

import { useI18n } from "@/lib/i18n/i18n-context";

type Dict = Record<string, string>;

const en: Dict = {
  "media.title": "Media library",
  "media.subtitle": "Upload, process and manage the videos and files used across your courses.",
  "media.upload": "Upload media",
  "media.empty": "No media yet. Upload your first file to build your library.",
  "media.loadError": "Couldn't load your media library.",
  "media.retry": "Retry",
  "media.cancel": "Cancel",
  "media.close": "Close",
  "media.remove": "Remove",
  "media.loading": "Loading media…",

  "media.filter.all": "All",
  "media.filter.type": "Type",
  "media.filter.status": "Status",
  "media.type.video": "Video",
  "media.type.audio": "Audio",
  "media.type.image": "Image",
  "media.type.document": "Document",

  "media.phase.awaiting": "Awaiting upload",
  "media.phase.processing": "Processing",
  "media.phase.ready": "Ready",
  "media.phase.failed": "Failed",

  "media.card.duration": "Duration",
  "media.card.size": "Size",
  "media.card.details": "Details",
  "media.card.retry": "Retry",
  "media.card.untitled": "Untitled",
  "media.card.processingHint": "This can take a few minutes.",

  "media.upload.title": "Upload media",
  "media.upload.description": "Files upload directly to the provider. Keep this dialog open until processing starts.",
  "media.upload.drop": "Drag files here, or",
  "media.upload.browse": "browse",
  "media.upload.phase.creating": "Preparing…",
  "media.upload.phase.uploading": "Uploading {percent}%",
  "media.upload.phase.finalizing": "Finalizing…",
  "media.upload.phase.processing": "Processing…",
  "media.upload.phase.ready": "Ready",
  "media.upload.phase.failed": "Upload failed",
  "media.upload.retry": "Retry",
  "media.upload.done": "Done",
  "media.upload.empty": "No files selected yet.",

  "media.details.title": "Media details",
  "media.details.filename": "File name",
  "media.details.type": "Type",
  "media.details.status": "Status",
  "media.details.provider": "Provider",
  "media.details.size": "Size",
  "media.details.duration": "Duration",
  "media.details.dimensions": "Dimensions",
  "media.details.created": "Created",
  "media.details.usage": "Usage",
  "media.details.usageEmpty": "This asset is not attached to any content.",
  "media.details.failureReason": "Failure reason",
  "media.details.delete": "Delete media",
  "media.details.retry": "Retry processing",

  "media.delete.title": "Delete this media?",
  "media.delete.body": "This removes the asset from your library. This cannot be undone.",
  "media.delete.confirm": "Delete",
  "media.delete.inUseTitle": "This media is still in use",
  "media.delete.inUseBody": "It is attached to one or more pieces of content. Force delete to remove it anyway and detach it everywhere.",
  "media.delete.force": "Force delete",
  "media.deletedToast": "Media deleted.",

  "media.captions.title": "Captions & subtitles",
  "media.captions.empty": "No caption tracks yet.",
  "media.captions.loadError": "Couldn't load captions.",
  "media.captions.add": "Add caption",
  "media.captions.language": "Language",
  "media.captions.languagePlaceholder": "e.g. en, en-US, ar",
  "media.captions.label": "Label",
  "media.captions.labelPlaceholder": "e.g. English",
  "media.captions.format": "Format",
  "media.captions.submit": "Add caption",
  "media.captions.languageRequired": "A BCP-47 language tag is required.",
  "media.captions.labelRequired": "A label is required.",
  "media.captions.addedToast": "Caption added.",
  "media.captions.removedToast": "Caption removed.",
  "media.captions.deleteTitle": "Remove this caption?",
  "media.captions.deleteBody": "The {label} caption track will be removed.",
  "media.captions.status.pending": "Pending",
  "media.captions.status.ready": "Ready",
  "media.captions.status.failed": "Failed",

  "media.picker.title": "Choose media",
  "media.picker.description": "Only ready assets can be attached.",
  "media.picker.select": "Select",
  "media.picker.selected": "Selected",
  "media.picker.empty": "No ready media available.",
  "media.picker.notReady": "Still processing",

  "media.preview.processing": "This video is still processing.",
  "media.preview.failed": "This video failed to process.",
  "media.preview.unavailable": "Preview unavailable.",

  "media.error": "Something went wrong.",
};

const ar: Dict = {
  "media.title": "مكتبة الوسائط",
  "media.subtitle": "ارفع وعالج وأدر مقاطع الفيديو والملفات المستخدمة في دوراتك.",
  "media.upload": "رفع وسائط",
  "media.empty": "لا توجد وسائط بعد. ارفع أول ملف لبناء مكتبتك.",
  "media.loadError": "تعذّر تحميل مكتبة الوسائط.",
  "media.retry": "إعادة المحاولة",
  "media.cancel": "إلغاء",
  "media.close": "إغلاق",
  "media.remove": "إزالة",
  "media.loading": "جارٍ تحميل الوسائط…",

  "media.filter.all": "الكل",
  "media.filter.type": "النوع",
  "media.filter.status": "الحالة",
  "media.type.video": "فيديو",
  "media.type.audio": "صوت",
  "media.type.image": "صورة",
  "media.type.document": "مستند",

  "media.phase.awaiting": "بانتظار الرفع",
  "media.phase.processing": "قيد المعالجة",
  "media.phase.ready": "جاهز",
  "media.phase.failed": "فشل",

  "media.card.duration": "المدة",
  "media.card.size": "الحجم",
  "media.card.details": "التفاصيل",
  "media.card.retry": "إعادة المحاولة",
  "media.card.untitled": "بدون عنوان",
  "media.card.processingHint": "قد يستغرق هذا بضع دقائق.",

  "media.upload.title": "رفع وسائط",
  "media.upload.description": "تُرفع الملفات مباشرةً إلى المزود. أبقِ هذه النافذة مفتوحة حتى تبدأ المعالجة.",
  "media.upload.drop": "اسحب الملفات هنا، أو",
  "media.upload.browse": "تصفّح",
  "media.upload.phase.creating": "جارٍ التحضير…",
  "media.upload.phase.uploading": "جارٍ الرفع {percent}%",
  "media.upload.phase.finalizing": "جارٍ الإنهاء…",
  "media.upload.phase.processing": "قيد المعالجة…",
  "media.upload.phase.ready": "جاهز",
  "media.upload.phase.failed": "فشل الرفع",
  "media.upload.retry": "إعادة المحاولة",
  "media.upload.done": "تم",
  "media.upload.empty": "لم يتم اختيار ملفات بعد.",

  "media.details.title": "تفاصيل الوسائط",
  "media.details.filename": "اسم الملف",
  "media.details.type": "النوع",
  "media.details.status": "الحالة",
  "media.details.provider": "المزود",
  "media.details.size": "الحجم",
  "media.details.duration": "المدة",
  "media.details.dimensions": "الأبعاد",
  "media.details.created": "أُنشئ",
  "media.details.usage": "الاستخدام",
  "media.details.usageEmpty": "هذا الأصل غير مرفق بأي محتوى.",
  "media.details.failureReason": "سبب الفشل",
  "media.details.delete": "حذف الوسائط",
  "media.details.retry": "إعادة المعالجة",

  "media.delete.title": "حذف هذه الوسائط؟",
  "media.delete.body": "سيؤدي هذا إلى إزالة الأصل من مكتبتك. لا يمكن التراجع.",
  "media.delete.confirm": "حذف",
  "media.delete.inUseTitle": "لا تزال هذه الوسائط قيد الاستخدام",
  "media.delete.inUseBody": "إنها مرفقة بمحتوى واحد أو أكثر. استخدم الحذف القسري لإزالتها وفصلها من كل مكان.",
  "media.delete.force": "حذف قسري",
  "media.deletedToast": "تم حذف الوسائط.",

  "media.captions.title": "التسميات التوضيحية والترجمات",
  "media.captions.empty": "لا توجد مسارات تسمية بعد.",
  "media.captions.loadError": "تعذّر تحميل التسميات.",
  "media.captions.add": "إضافة تسمية",
  "media.captions.language": "اللغة",
  "media.captions.languagePlaceholder": "مثال: en، en-US، ar",
  "media.captions.label": "التسمية",
  "media.captions.labelPlaceholder": "مثال: العربية",
  "media.captions.format": "الصيغة",
  "media.captions.submit": "إضافة تسمية",
  "media.captions.languageRequired": "يلزم رمز لغة BCP-47.",
  "media.captions.labelRequired": "التسمية مطلوبة.",
  "media.captions.addedToast": "تمت إضافة التسمية.",
  "media.captions.removedToast": "تمت إزالة التسمية.",
  "media.captions.deleteTitle": "إزالة هذه التسمية؟",
  "media.captions.deleteBody": "سيتم إزالة مسار التسمية {label}.",
  "media.captions.status.pending": "قيد الانتظار",
  "media.captions.status.ready": "جاهز",
  "media.captions.status.failed": "فشل",

  "media.picker.title": "اختر وسائط",
  "media.picker.description": "يمكن إرفاق الأصول الجاهزة فقط.",
  "media.picker.select": "اختيار",
  "media.picker.selected": "محدد",
  "media.picker.empty": "لا توجد وسائط جاهزة متاحة.",
  "media.picker.notReady": "قيد المعالجة",

  "media.preview.processing": "لا يزال هذا الفيديو قيد المعالجة.",
  "media.preview.failed": "فشلت معالجة هذا الفيديو.",
  "media.preview.unavailable": "المعاينة غير متاحة.",

  "media.error": "حدث خطأ ما.",
};

const dictionaries: Record<string, Dict> = { en, ar };

export function useMediaI18n() {
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
