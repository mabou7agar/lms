/**
 * Demo content layer — sample courses for a lively marketing demo.
 * Toggle everything on/off with DEMO_ENABLED (set false to rely on real API data only).
 * YouTube ids are illustrative (famous business/leadership TED talks) — swap for real course trailers.
 */
import type { Localized, Swatch } from "./theme";
import type { CoverFaculty, CoverFamily } from "@/components/marketing/course-cover/types";

export const DEMO_ENABLED = true;

export type DemoCourse = {
  id: string;
  title: Localized;
  code: string;
  color: Swatch;
  category: Localized;
  level: Localized;
  trainer: string;
  price: string;
  rating: string;
  lessons: number;
  hours: number;
  youtubeId: string;
  // Editorial cover fields (marketing-only; not part of the real API contract). Optional —
  // the CourseCover adapter synthesizes sensible defaults when they are absent.
  family?: CoverFamily;
  subtitle?: Localized;
  school?: Localized;
  faculty?: CoverFaculty[];
};

export const featuredHeading = {
  eyebrow: { en: "FROM THE CATALOG", ar: "من الكتالوج" } as Localized,
  title1: { en: "Real courses.", ar: "دورات حقيقية." } as Localized,
  title2: { en: "Watch a preview.", ar: "شاهد المعاينة." } as Localized,
  subtitle: {
    en: "A taste of what's inside HElbaron — hands-on programs built for MENA business.",
    ar: "لمحة عمّا في HElbaron — برامج عملية مبنية لأعمال المنطقة.",
  } as Localized,
  cta: { en: "Browse all courses", ar: "تصفّح كل الدورات" } as Localized,
};

export const demoCourses: DemoCourse[] = [
  { id: "d1", code: "PM", color: "teal", family: "governance", title: { en: "Project Management Foundations", ar: "أساسيات إدارة المشاريع" }, subtitle: { en: "Structures of delivery", ar: "بُنى التنفيذ" }, category: { en: "Project Management", ar: "إدارة المشاريع" }, level: { en: "Professional · L6", ar: "احترافي · L6" }, school: { en: "School of Practice", ar: "مدرسة الممارسة" }, trainer: "Yara Adel", faculty: [{ initials: "YA", key: "navy" }, { initials: "OF", key: "copper" }, { initials: "LM", key: "teal" }], price: "$29", rating: "4.9", lessons: 42, hours: 6, youtubeId: "u4ZoJKF_VuA" },
  { id: "d2", code: "LD", color: "teal", family: "leadership", title: { en: "Leadership in the Modern Workplace", ar: "القيادة في بيئة العمل الحديثة" }, subtitle: { en: "Judgement at scale", ar: "الحُكم على نطاق واسع" }, category: { en: "Leadership", ar: "القيادة" }, level: { en: "Executive · L8", ar: "تنفيذي · L8" }, school: { en: "Institute of Practice", ar: "معهد الممارسة" }, trainer: "Omar Farouk", faculty: [{ initials: "OF", key: "slate" }], price: "$34", rating: "4.8", lessons: 36, hours: 5, youtubeId: "arj7oStGLkU" },
  { id: "d3", code: "AI", color: "gold", family: "ai", title: { en: "Business AI for Decision Makers", ar: "الذكاء الاصطناعي للأعمال لصنّاع القرار" }, subtitle: { en: "Invisible intelligence", ar: "ذكاء غير مرئي" }, category: { en: "Business AI", ar: "الذكاء الاصطناعي للأعمال" }, level: { en: "Graduate · L7", ar: "دراسات عليا · L7" }, school: { en: "School of Computation", ar: "مدرسة الحوسبة" }, trainer: "Nour Hassan", faculty: [{ initials: "NH", key: "copper" }, { initials: "MC", key: "indigo" }, { initials: "AO", key: "teal" }, { initials: "YR", key: "navy" }], price: "$39", rating: "5.0", lessons: 28, hours: 4, youtubeId: "Ks-_Mh1QhMc" },
  { id: "d4", code: "MK", color: "gold", family: "leadership", title: { en: "Marketing Strategy Masterclass", ar: "ماستر كلاس استراتيجية التسويق" }, subtitle: { en: "Signals & positioning", ar: "الإشارات والتموضع" }, category: { en: "Marketing Strategies", ar: "استراتيجيات التسويق" }, level: { en: "Advanced · L7", ar: "متقدّم · L7" }, school: { en: "Institute of Practice", ar: "معهد الممارسة" }, trainer: "Laila Mansour", faculty: [{ initials: "LM", key: "olive" }, { initials: "KS", key: "copper" }], price: "$44", rating: "4.7", lessons: 48, hours: 8, youtubeId: "u4ZoJKF_VuA" },
  { id: "d5", code: "FN", color: "copper", family: "data", title: { en: "Finance & Analysis Essentials", ar: "أساسيات المالية والتحليل" }, subtitle: { en: "Structures of evidence", ar: "بُنى الأدلة" }, category: { en: "Finance & Analysis", ar: "المالية والتحليل" }, level: { en: "Professional · L6", ar: "احترافي · L6" }, school: { en: "School of Data", ar: "مدرسة البيانات" }, trainer: "Karim Saleh", faculty: [{ initials: "KS", key: "teal" }, { initials: "AG", key: "navy" }], price: "$32", rating: "4.8", lessons: 40, hours: 6, youtubeId: "arj7oStGLkU" },
  { id: "d6", code: "EN", color: "copper", family: "leadership", title: { en: "Entrepreneurship: 0 to Launch", ar: "ريادة الأعمال: من الصفر للإطلاق" }, subtitle: { en: "Passages & thresholds", ar: "معابر وعتبات" }, category: { en: "Entrepreneurship", ar: "ريادة الأعمال" }, level: { en: "Graduate · L7", ar: "دراسات عليا · L7" }, school: { en: "Institute of Practice", ar: "معهد الممارسة" }, trainer: "Hana Zaki", faculty: [{ initials: "HZ", key: "plum" }], price: "$36", rating: "4.9", lessons: 52, hours: 9, youtubeId: "Ks-_Mh1QhMc" },
  { id: "d7", code: "SL", color: "red", family: "leadership", title: { en: "Sales Management Playbook", ar: "دليل إدارة المبيعات" }, subtitle: { en: "Balance & authority", ar: "التوازن والسلطة" }, category: { en: "Sales Management", ar: "إدارة المبيعات" }, level: { en: "Professional · L6", ar: "احترافي · L6" }, school: { en: "Institute of Practice", ar: "معهد الممارسة" }, trainer: "Tarek Fahmy", faculty: [{ initials: "TF", key: "burgundy" }, { initials: "NF", key: "navy" }, { initials: "AM", key: "plum" }], price: "$30", rating: "4.6", lessons: 33, hours: 5, youtubeId: "u4ZoJKF_VuA" },
  { id: "d8", code: "BS", color: "teal", family: "governance", title: { en: "Business Strategy & Growth", ar: "استراتيجية الأعمال والنمو" }, subtitle: { en: "Standards & statutes", ar: "معايير وأنظمة" }, category: { en: "Business Strategies", ar: "استراتيجيات الأعمال" }, level: { en: "Advanced · L7", ar: "متقدّم · L7" }, school: { en: "School of Governance", ar: "مدرسة الحوكمة" }, trainer: "Salma Nabil", faculty: [{ initials: "SN", key: "teal" }, { initials: "DW", key: "slate" }], price: "$42", rating: "4.9", lessons: 45, hours: 7, youtubeId: "arj7oStGLkU" },
  { id: "d9", code: "IT", color: "teal", family: "data", title: { en: "Investment & Trading Basics", ar: "أساسيات الاستثمار والتداول" }, subtitle: { en: "Fields in motion", ar: "حقول في حركة" }, category: { en: "Investment & Trading", ar: "الاستثمار والتداول" }, level: { en: "Professional · L6", ar: "احترافي · L6" }, school: { en: "School of Data", ar: "مدرسة البيانات" }, trainer: "Amir Gamal", faculty: [{ initials: "AG", key: "olive" }], price: "$38", rating: "4.7", lessons: 30, hours: 5, youtubeId: "Ks-_Mh1QhMc" },
];
