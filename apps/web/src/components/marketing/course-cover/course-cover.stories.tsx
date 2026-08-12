import type { Meta, StoryObj } from "@storybook/react";
import type { ReactElement } from "react";
import { I18nProvider } from "@/lib/i18n/i18n-context";
import { CourseCover } from "./course-cover";
import type { CoverCourse } from "./types";

const base: CoverCourse = {
  id: "cov_ai_502",
  code: "AIE",
  pressCode: "HEL · AIE · 502",
  title: { en: "AI Ethics & Responsible Innovation", ar: "أخلاقيات الذكاء الاصطناعي والابتكار المسؤول" },
  subtitle: { en: "The duty of care", ar: "واجب العناية" },
  family: "ai",
  level: { en: "Graduate · L7", ar: "دراسات عليا · L7" },
  school: { en: "School of Computation", ar: "مدرسة الحوسبة" },
  faculty: [
    { initials: "RO", key: "copper" },
    { initials: "MC", key: "indigo" },
    { initials: "AO", key: "teal" },
    { initials: "YR", key: "navy" },
  ],
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
        <div style={{ width: 340 }}>{Story()}</div>
      </I18nProvider>
    ),
  ],
  args: { course: base, index: 2 },
} satisfies Meta<typeof CourseCover>;

export default meta;
type Story = StoryObj<typeof meta>;

/** AI family — neural constellation artwork, four overlapping faculty medallions. */
export const AiFourFaculty: Story = {};

/** Data family — vector-field / signals artwork. */
export const Data: Story = {
  args: {
    index: 3,
    course: {
      ...base,
      id: "cov_data_511",
      code: "SFF",
      pressCode: "HEL · SFF · 511",
      title: { en: "Signals, Flows & Forecasting", ar: "الإشارات والتدفقات والتنبؤ" },
      subtitle: { en: "Fields in motion", ar: "حقول في حركة" },
      family: "data",
      school: { en: "School of Data", ar: "مدرسة البيانات" },
      faculty: [
        { initials: "GD", key: "teal" },
        { initials: "DW", key: "slate" },
      ],
    },
  },
};

/** Governance family — institutional architecture / archival grid artwork. */
export const Governance: Story = {
  args: {
    index: 3,
    course: {
      ...base,
      id: "cov_gov_495",
      code: "RGC",
      pressCode: "HEL · RGC · 495",
      title: { en: "Regulation & Compliance", ar: "التنظيم والامتثال" },
      subtitle: { en: "Standards & statutes", ar: "معايير وأنظمة" },
      family: "governance",
      level: { en: "Graduate · L7", ar: "دراسات عليا · L7" },
      school: { en: "School of Governance", ar: "مدرسة الحوكمة" },
      faculty: [
        { initials: "PN", key: "plum" },
        { initials: "NF", key: "navy" },
        { initials: "AM", key: "burgundy" },
      ],
    },
  },
};

/** Leadership family — decision-architecture artwork, a single faculty seal. */
export const LeadershipOneFaculty: Story = {
  args: {
    index: 1,
    course: {
      ...base,
      id: "cov_sld_701",
      code: "SLD",
      pressCode: "HEL · SLD · 701",
      title: { en: "Strategic Analytics Leadership", ar: "قيادة التحليلات الاستراتيجية" },
      subtitle: { en: "Judgement at scale", ar: "الحُكم على نطاق واسع" },
      family: "leadership",
      level: { en: "Executive · L8", ar: "تنفيذي · L8" },
      school: { en: "Institute of Practice", ar: "معهد الممارسة" },
      faculty: [{ initials: "IS", key: "olive" }],
    },
  },
};

/** A long English title must stay optically stable and wrap gracefully. */
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

/** Overflow — six faculty collapse to four seals plus a "+N" seal. */
export const FacultyOverflow: Story = {
  args: {
    course: {
      ...base,
      id: "cov_overflow",
      faculty: [
        { initials: "RO", key: "copper" },
        { initials: "MC", key: "indigo" },
        { initials: "AO", key: "teal" },
        { initials: "YR", key: "navy" },
        { initials: "PN", key: "plum" },
        { initials: "DW", key: "slate" },
      ],
    },
  },
};

/** Arabic (RTL) — title composes in the Arabic face; metadata + medallions mirror intentionally. */
export const ArabicRtl: Story = {
  decorators: [
    (Story: () => ReactElement) => (
      <I18nProvider initialLocale="ar">
        <div dir="rtl" style={{ width: 340 }}>
          {Story()}
        </div>
      </I18nProvider>
    ),
  ],
};
