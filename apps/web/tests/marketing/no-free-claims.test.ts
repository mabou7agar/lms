import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import { PRICING_FAQ } from "@/components/marketing/pricing-faq";

/**
 * The public sales surfaces must not advertise something the platform does not sell.
 *
 * Every product in the catalogue carries a price. The pricing page nonetheless offered a "Free
 * courses" model, its intro told visitors to "start with free courses", and the FAQ answer — which
 * is also emitted as FAQPage structured data, so search engines repeat it — said "many courses are
 * free". A visitor who arrived on that promise had nothing free to click.
 *
 * This reads the source rather than rendering, because the claim has to be absent from the copy
 * itself: the JSON-LD is built from the same constants without going through React.
 */

/**
 * The file with comments stripped. A comment explaining why the claim was removed necessarily
 * quotes it, and that must not count as making it — only shipped copy does.
 */
const read = (relative: string) =>
  readFileSync(resolve(__dirname, "../../", relative), "utf8")
    .replace(/\/\*[\s\S]*?\*\//g, "")
    .replace(/^\s*\/\/.*$/gm, "");

/** "Free" as a price claim. Deliberately not every use of the word — a free PREVIEW is honest. */
const FREE_PRICE_CLAIM = /free cours|courses are free|no payment required|دورات مجانية|الدورات المجانية|مجانية/i;

describe("public pricing copy", () => {
  it("does not offer free courses on the pricing page", () => {
    expect(read("src/components/marketing/pricing-page.tsx")).not.toMatch(FREE_PRICE_CLAIM);
  });

  it("does not promise free courses in the FAQ, which is also structured data", () => {
    const answers = PRICING_FAQ.map((entry) => `${entry.a.en} ${entry.a.ar}`).join(" ");
    expect(answers).not.toMatch(FREE_PRICE_CLAIM);
  });

  it("does not promise free courses in the page's search-result description", () => {
    expect(read("src/app/(marketing)/(site)/pricing/page.tsx")).not.toMatch(FREE_PRICE_CLAIM);
  });

  it("still states how a course IS priced", () => {
    const page = read("src/components/marketing/pricing-page.tsx");
    expect(page).toMatch(/price is shown on each course|priced individually/i);
  });
});
