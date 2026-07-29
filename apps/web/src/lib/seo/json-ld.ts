/**
 * Serialize a value for safe embedding inside a <script type="application/ld+json"> element.
 *
 * `JSON.stringify` does NOT escape `<`, `>` or `&`, so a string field containing `</script>` can
 * break out of the script element and inject markup — a stored-XSS vector whenever any JSON-LD field
 * is author/admin-controlled (brand name, CMS page title/excerpt, event title/description). Escaping
 * those three characters to their `\uXXXX` forms keeps the JSON byte-for-byte equivalent to any JSON
 * parser (search engines read the block as JSON, never as executable JS) while making an HTML
 * `</script>` breakout impossible.
 */
export function jsonLdScript(data: unknown): string {
  return JSON.stringify(data)
    .replace(/</g, "\\u003c")
    .replace(/>/g, "\\u003e")
    .replace(/&/g, "\\u0026");
}
