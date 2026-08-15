/**
 * Server-safe pricing FAQ data. Kept in its OWN module (NOT the "use client" pricing-page component)
 * so the server page can import it for FAQPage JSON-LD without pulling in a client module — importing a
 * plain array from a "use client" file yields a client-reference proxy on the server (`.map is not a
 * function`), which crashed /pricing. Both the server page and the client UI import this array.
 */
export type FaqItem = { q: { en: string; ar: string }; a: { en: string; ar: string } };

export const PRICING_FAQ: FaqItem[] = [
  { q: { en: "How much does a course cost?", ar: "كم تكلفة الدورة؟" }, a: { en: "Each paid course shows its own price on its course page; many courses are free.", ar: "كل دورة مدفوعة تعرض سعرها على صفحتها؛ والعديد من الدورات مجانية." } },
  { q: { en: "Do you offer a subscription?", ar: "هل تقدّمون اشتراكًا؟" }, a: { en: "Purchasing is per course, per program, or by enterprise agreement — we don't advertise a public subscription price here.", ar: "الشراء يكون لكل دورة أو لكل برنامج أو باتفاق مؤسسي — ولا نعلن سعر اشتراك عام هنا." } },
  { q: { en: "How do refunds, taxes, and invoices work?", ar: "كيف تعمل المبالغ المستردّة والضرائب والفواتير؟" }, a: { en: "Applicable taxes and invoicing are handled at checkout, and any refund follows the terms shown at checkout and in our Terms.", ar: "تُعالَج الضرائب والفوترة المطبّقة عند الدفع، وأي استرداد يتبع الشروط المعروضة عند الدفع وفي شروطنا." } },
];
