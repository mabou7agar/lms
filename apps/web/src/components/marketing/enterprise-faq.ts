/**
 * Server-safe enterprise FAQ data. Kept in its OWN module (NOT the "use client" enterprise-page
 * component) so the server page can import it for FAQPage JSON-LD without pulling in a client module —
 * importing a plain array from a "use client" file yields a client-reference proxy on the server
 * (`.map is not a function`), which crashed /enterprise. Both the server page and the client UI import
 * this array.
 */
export type FaqItem = { q: { en: string; ar: string }; a: { en: string; ar: string } };

export const ENTERPRISE_FAQ: FaqItem[] = [
  { q: { en: "Can we administer many learners and teams?", ar: "هل يمكننا إدارة عدد كبير من المتعلّمين والفِرق؟" }, a: { en: "Yes — organization and member administration is built in, with reporting.", ar: "نعم — إدارة المؤسسة والأعضاء مدمجة، مع التقارير." } },
  { q: { en: "Do you support Arabic for the whole experience?", ar: "هل تدعمون العربية للتجربة بالكامل؟" }, a: { en: "Arabic is first-class with full right-to-left support across the product.", ar: "العربية من الدرجة الأولى مع دعم كامل للاتجاه من اليمين إلى اليسار عبر المنتج." } },
  { q: { en: "How is pricing determined?", ar: "كيف يُحدَّد التسعير؟" }, a: { en: "Enterprise programs are scoped and quoted to your size and needs — talk to our team.", ar: "تُحدَّد برامج المؤسسات وتُسعَّر حسب حجمك واحتياجك — تحدّث إلى فريقنا." } },
  { q: { en: "Do you claim ISO or SOC 2 certification?", ar: "هل تدّعون اعتماد ISO أو SOC 2؟" }, a: { en: "No. We describe how the platform is actually built (roles, MFA, tenant isolation, audit logs) and do not claim certifications we don't hold.", ar: "لا. نصف كيف بُنيت المنصّة فعليًا (الأدوار، المصادقة متعدّدة العوامل، عزل المستأجرين، سجلّات التدقيق) ولا نَدّعي اعتمادات لا نملكها." } },
];
