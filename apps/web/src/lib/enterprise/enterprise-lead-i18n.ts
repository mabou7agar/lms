"use client";

import { useI18n } from "@/lib/i18n/i18n-context";

type Dict = Record<string, string>;

const en: Dict = {
  "form.title": "Request a demo",
  "form.subtitle": "Tell us about your program and our team will follow up.",
  "form.name": "Full name",
  "form.workEmail": "Work email",
  "form.company": "Company",
  "form.phone": "Phone (optional)",
  "form.companySize": "Company size",
  "form.companySizePlaceholder": "Select a size",
  "form.country": "Country (optional)",
  "form.requestType": "How can we help?",
  "form.type.demo": "Request a demo",
  "form.type.pricing": "Discuss pricing",
  "form.type.contact": "General enquiry",
  "form.type.partnership": "Partnership",
  "form.message": "Message (optional)",
  "form.messagePlaceholder": "What would you like to achieve?",
  "form.consent": "I agree to be contacted about my request and to receive occasional related communications.",
  "form.submit": "Send request",
  "form.submitting": "Sending…",
  "form.successTitle": "Thanks — we've received your request.",
  "form.successBody": "Our team will be in touch shortly.",
  "form.errorGeneric": "Something went wrong. Please try again.",
  "form.required": "This field is required.",
  "form.invalidEmail": "Enter a valid work email.",
};

const ar: Dict = {
  "form.title": "اطلب عرضًا توضيحيًا",
  "form.subtitle": "أخبِرنا عن برنامجك وسيتواصل فريقنا معك.",
  "form.name": "الاسم الكامل",
  "form.workEmail": "بريد العمل الإلكتروني",
  "form.company": "الشركة",
  "form.phone": "الهاتف (اختياري)",
  "form.companySize": "حجم الشركة",
  "form.companySizePlaceholder": "اختر الحجم",
  "form.country": "الدولة (اختياري)",
  "form.requestType": "كيف يمكننا المساعدة؟",
  "form.type.demo": "طلب عرض توضيحي",
  "form.type.pricing": "مناقشة الأسعار",
  "form.type.contact": "استفسار عام",
  "form.type.partnership": "شراكة",
  "form.message": "رسالة (اختياري)",
  "form.messagePlaceholder": "ما الذي ترغب في تحقيقه؟",
  "form.consent": "أوافق على التواصل معي بشأن طلبي وعلى تلقّي رسائل ذات صلة من حين لآخر.",
  "form.submit": "إرسال الطلب",
  "form.submitting": "جارٍ الإرسال…",
  "form.successTitle": "شكرًا — تم استلام طلبك.",
  "form.successBody": "سيتواصل فريقنا معك قريبًا.",
  "form.errorGeneric": "حدث خطأ ما. يُرجى المحاولة مرة أخرى.",
  "form.required": "هذا الحقل مطلوب.",
  "form.invalidEmail": "أدخِل بريد عمل صحيحًا.",
};

const DICTS: Record<string, Dict> = { en, ar };

/** Exported for the EN/AR key-parity test. */
export const ENTERPRISE_LEAD_DICTS = DICTS;

export function useEnterpriseLeadI18n(): { t: (key: string) => string; locale: string; dir: "ltr" | "rtl" } {
  const { locale, dir } = useI18n();
  const dict = DICTS[locale] ?? en;
  const t = (key: string): string => dict[key] ?? en[key] ?? key;

  return { t, locale, dir };
}
