import type { Meta, StoryObj } from "@storybook/react";
import type { ReactElement } from "react";
import { I18nProvider } from "@/lib/i18n/i18n-context";
import { CourseCover } from "./course-cover";
import type { CoverCourse, CoverInstructor } from "./types";

const href = "/trainers";
const FOUR: CoverInstructor[] = [
  { name: "Nour Hassan", initials: "NH", key: "copper", href },
  { name: "Maya Cohen", initials: "MC", key: "indigo", href },
  { name: "Adam Osei", initials: "AO", key: "teal", href },
  { name: "Yousef Rahal", initials: "YR", key: "navy", href },
];

const base: CoverCourse = {
  id: "cov_ai_502",
  code: "AIE",
  title: { en: "AI Ethics & Responsible Innovation", ar: "أخلاقيات الذكاء الاصطناعي والابتكار المسؤول" },
  subtitle: { en: "The duty of care", ar: "واجب العناية" },
  family: "ai",
  level: { en: "Graduate · L7", ar: "دراسات عليا · L7" },
  school: { en: "School of Computation", ar: "مدرسة الحوسبة" },
  instructors: FOUR,
  href: "/courses",
  folio: 24,
};

const meta = {
  title: "Marketing/CourseCover",
  component: CourseCover,
  parameters: { layout: "centered" },
  tags: ["autodocs"],
  decorators: [
    (Story: () => ReactElement) => (
      <I18nProvider>
        <div style={{ width: 320 }}>{Story()}</div>
      </I18nProvider>
    ),
  ],
  args: { course: base, wave: "cradle" },
} satisfies Meta<typeof CourseCover>;

export default meta;
type Story = StoryObj<typeof meta>;

/** Homepage treatment — cradle wave, four instructor avatars riding it. */
export const HomeCradle: Story = {};

/** Courses-page treatment — the flowing reference-style wave. */
export const CoursesFlow: Story = {
  args: {
    wave: "flow",
    course: {
      ...base,
      id: "cov_data_511",
      code: "SFF",
      title: { en: "Signals, Flows & Forecasting", ar: "الإشارات والتدفقات والتنبؤ" },
      subtitle: { en: "Fields in motion", ar: "حقول في حركة" },
      family: "data",
      school: { en: "School of Data", ar: "مدرسة البيانات" },
      instructors: [
        { name: "Gina Daher", initials: "GD", key: "teal", href },
        { name: "Dana West", initials: "DW", key: "slate", href },
      ],
    },
  },
};

/** Governance family artwork. */
export const Governance: Story = {
  args: {
    course: {
      ...base,
      id: "cov_gov_495",
      code: "RGC",
      title: { en: "Regulation & Compliance", ar: "التنظيم والامتثال" },
      subtitle: { en: "Standards & statutes", ar: "معايير وأنظمة" },
      family: "governance",
      school: { en: "School of Governance", ar: "مدرسة الحوكمة" },
      instructors: [
        { name: "Priya Nair", initials: "PN", key: "plum", href },
        { name: "Nadia Fouad", initials: "NF", key: "navy", href },
      ],
    },
  },
};

/** A single instructor — one centered avatar on the wave. */
export const OneInstructor: Story = {
  args: {
    course: {
      ...base,
      id: "cov_sld_701",
      code: "SLD",
      title: { en: "Strategic Analytics Leadership", ar: "قيادة التحليلات الاستراتيجية" },
      subtitle: { en: "Judgement at scale", ar: "الحُكم على نطاق واسع" },
      family: "leadership",
      level: { en: "Executive · L8", ar: "تنفيذي · L8" },
      school: { en: "Institute of Practice", ar: "معهد الممارسة" },
      instructors: [{ name: "Idris Sami", initials: "IS", key: "olive", href }],
    },
  },
};

/** A long English title must stay readable and wrap gracefully. */
export const LongTitle: Story = {
  args: {
    course: {
      ...base,
      id: "cov_long",
      title: {
        en: "Machine Learning for Decision-Makers: Invisible Intelligence in Practice",
        ar: "التعلّم الآلي لصنّاع القرار: الذكاء غير المرئي في التطبيق",
      },
    },
  },
};

/** Overflow — six instructors collapse to four avatars plus a "+N" seal. */
export const Overflow: Story = {
  args: {
    course: {
      ...base,
      id: "cov_overflow",
      instructors: [
        ...FOUR,
        { name: "Priya Nair", initials: "PN", key: "plum", href },
        { name: "Dana West", initials: "DW", key: "slate", href },
      ],
    },
  },
};

/** Arabic (RTL) — title composes in the Arabic face; avatars mirror intentionally. */
export const ArabicRtl: Story = {
  decorators: [
    (Story: () => ReactElement) => (
      <I18nProvider initialLocale="ar">
        <div dir="rtl" style={{ width: 320 }}>
          {Story()}
        </div>
      </I18nProvider>
    ),
  ],
};
