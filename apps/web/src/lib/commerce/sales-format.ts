import type { CoursePurchase } from "@/lib/catalog/api";
import type { Price, Product } from "@/lib/commerce/api";
import { formatMoney } from "@/lib/format";

/**
 * Presentation helpers for the public sales surfaces. Every course and bundle page renders the same
 * commercial facts — price, access duration, certificate terms, audience — so the wording lives here
 * once instead of drifting between the card, the course page and the bundle page.
 *
 * Locale is passed in rather than read from context so these stay plain functions usable from any
 * component or test.
 */
export type Locale = "en" | "ar";

const L = (locale: Locale, en: string, ar: string): string => (locale === "ar" ? ar : en);

type Unit = "day" | "month" | "year";

/** "1 year" / "6 months" — a duration of one must not read as a plural. */
function plural(n: number, unit: Unit): string {
  return `${n} ${unit}${n === 1 ? "" : "s"}`;
}

/**
 * Arabic duration wording. Arabic inflects by count, so 1 and 2 take their own forms, 3–10 take the
 * plural, and 11+ return to the singular noun — printing "1 سنوات" would read as broken Arabic.
 */
function arabicUnit(n: number, unit: Unit): string {
  const forms: Record<Unit, { one: string; two: string; few: string; many: string }> = {
    day: { one: "لمدة يوم واحد", two: "لمدة يومين", few: "أيام", many: "يومًا" },
    month: { one: "لمدة شهر واحد", two: "لمدة شهرين", few: "أشهر", many: "شهرًا" },
    year: { one: "لمدة سنة واحدة", two: "لمدة سنتين", few: "سنوات", many: "سنة" },
  };
  const f = forms[unit];
  if (n === 1) return f.one;
  if (n === 2) return f.two;
  if (n >= 3 && n <= 10) return `لمدة ${n} ${f.few}`;
  return `لمدة ${n} ${f.many}`;
}

/** The price a buyer actually pays, plus the struck-through original when a sale is running. */
export type DisplayPrice = {
  effective: string;
  original: string | null;
  onSale: boolean;
};

/** Format a product price row. Returns null when the product carries no price at all. */
export function displayPrice(price: Price | null | undefined, locale: Locale): DisplayPrice | null {
  if (!price) return null;
  return {
    effective: formatMoney(price.effective_minor, price.currency, locale),
    original: price.on_sale ? formatMoney(price.amount_minor, price.currency, locale) : null,
    onSale: price.on_sale,
  };
}

/** Format the price carried on a course's `purchase` summary. */
export function coursePurchasePrice(purchase: CoursePurchase | null | undefined, locale: Locale): DisplayPrice | null {
  if (!purchase?.purchasable) return null;
  const { currency, amount_minor, effective_minor, on_sale } = purchase.price;
  if (currency === null || effective_minor === null) return null;
  return {
    effective: formatMoney(effective_minor, currency, locale),
    original: on_sale && amount_minor !== null ? formatMoney(amount_minor, currency, locale) : null,
    onSale: on_sale,
  };
}

/** The default price row for a product — the one flagged default, else the first. */
export function defaultPrice(product: Pick<Product, "prices">): Price | null {
  return product.prices?.[0] ?? null;
}

type AccessShape = {
  duration_type: string | null;
  duration_value: number | null;
  ends_at?: string | null;
};

/**
 * How long the buyer keeps access, in words. Returns null when there is nothing meaningful to say,
 * so a caller can omit the row entirely rather than print an empty label.
 */
export function accessLabel(access: AccessShape | null | undefined, locale: Locale): string | null {
  if (!access?.duration_type) return null;
  const n = access.duration_value;

  switch (access.duration_type) {
    case "lifetime":
      return L(locale, "Lifetime access", "وصول مدى الحياة");
    case "fixed_days":
      return n === null ? null : L(locale, `${plural(n, "day")} of access`, `وصول ${arabicUnit(n, "day")}`);
    case "fixed_months":
      return n === null ? null : L(locale, `${plural(n, "month")} of access`, `وصول ${arabicUnit(n, "month")}`);
    case "fixed_years":
      return n === null ? null : L(locale, `${plural(n, "year")} of access`, `وصول ${arabicUnit(n, "year")}`);
    case "fixed_date": {
      if (!access.ends_at) return null;
      const date = new Date(access.ends_at).toLocaleDateString(locale === "ar" ? "ar" : "en", {
        year: "numeric",
        month: "short",
        day: "numeric",
      });
      return L(locale, `Access until ${date}`, `الوصول حتى ${date}`);
    }
    default:
      return null;
  }
}

type CertificateShape = {
  enabled: boolean;
  expiry_type: string | null;
  expiry_value: number | null;
};

/** Whether a certificate is included and, when it expires, for how long it stays valid. */
export function certificateLabel(cert: CertificateShape | null | undefined, locale: Locale): string | null {
  if (!cert) return null;
  if (!cert.enabled) return L(locale, "No certificate", "بدون شهادة");

  const n = cert.expiry_value;
  switch (cert.expiry_type) {
    case "fixed_days":
      return n === null ? null : L(locale, `Certificate valid ${plural(n, "day")}`, `شهادة صالحة ${arabicUnit(n, "day")}`);
    case "fixed_months":
      return n === null ? null : L(locale, `Certificate valid ${plural(n, "month")}`, `شهادة صالحة ${arabicUnit(n, "month")}`);
    case "fixed_years":
      return n === null ? null : L(locale, `Certificate valid ${plural(n, "year")}`, `شهادة صالحة ${arabicUnit(n, "year")}`);
    default:
      // `none`, `fixed_date` and unknown values all read as a plain included certificate.
      return L(locale, "Certificate included", "شهادة معتمدة");
  }
}

/** Who the product is sold to, as short badge labels. Empty when the audience is unknown. */
export function audienceLabels(audience: string | null | undefined, locale: Locale): string[] {
  const individual = L(locale, "For individuals", "متاح للأفراد");
  const company = L(locale, "For companies", "متاح للشركات");

  switch (audience) {
    case "individual":
      return [individual];
    case "company":
      return [company];
    case "both":
      return [individual, company];
    default:
      return [];
  }
}

/** Seat wording for a company buyer. Null when the product carries no seats. */
export function seatLabel(seats: Product["seats"] | null | undefined, locale: Locale): string | null {
  if (!seats?.mode || seats.mode === "not_applicable") return null;

  switch (seats.mode) {
    case "fixed": {
      const n = seats.default_count;
      if (n === null) return null;
      return L(locale, `${n} seat${n === 1 ? "" : "s"} included`, `يشمل ${n} ${n <= 10 ? "مقاعد" : "مقعدًا"}`);
    }
    case "buyer_selects": {
      const { min, max } = seats.selection ?? { min: 1, max: null };
      return max === null
        ? L(locale, `Choose your seat count (from ${min})`, `اختر عدد المقاعد (من ${min})`)
        : L(locale, `Choose ${min}–${max} seats`, `اختر من ${min} إلى ${max} مقعدًا`);
    }
    case "unlimited":
      return L(locale, "Unlimited seats", "مقاعد غير محدودة");
    case "quote_only":
      return L(locale, "Seats are arranged with our team", "تُرتَّب المقاعد مع فريقنا");
    default:
      return null;
  }
}
