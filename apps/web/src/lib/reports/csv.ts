/**
 * CSV serialisation for report payloads.
 *
 * The command centre exports whatever the server actually returned, not what the screen formatted:
 * money stays in integer minor units and rates stay unrounded, because the people who ask for a CSV
 * are the people who are going to do arithmetic on it. Formatting money into "SAR 2,875.00" would
 * make the file pleasant to read and useless to sum.
 *
 * Shape-driven like ReportView: a `summary` object becomes a key/value block, every array of objects
 * becomes its own table. A report that grows a new section exports it without a change here.
 */

type Payload = Record<string, unknown>;

/** RFC 4180 field escaping. Also guards against a leading =/+/-/@ being read as a formula. */
function cell(value: unknown): string {
  if (value == null) return "";
  const raw = typeof value === "object" ? JSON.stringify(value) : String(value);
  const safe = /^[=+\-@]/.test(raw) ? `'${raw}` : raw;
  return /[",\n\r]/.test(safe) ? `"${safe.replace(/"/g, '""')}"` : safe;
}

function line(values: unknown[]): string {
  return values.map(cell).join(",");
}

function isObjectArray(v: unknown): v is Record<string, unknown>[] {
  return Array.isArray(v) && v.length > 0 && v.every((x) => typeof x === "object" && x !== null);
}

/** Union of the keys across rows, so a row missing a key still lines up under the right column. */
function columns(rows: Record<string, unknown>[]): string[] {
  const seen: string[] = [];
  for (const row of rows) {
    for (const key of Object.keys(row)) if (!seen.includes(key)) seen.push(key);
  }
  return seen;
}

/**
 * Serialise a report payload to CSV. Sections are separated by a blank line and introduced by their
 * own name, so one file can carry the summary and every table the report returned.
 */
export function reportToCsv(payload: Payload, meta?: { from?: string; to?: string }): string {
  const sections: string[] = [];

  if (meta?.from || meta?.to) {
    sections.push([line(["range_from", meta.from ?? ""]), line(["range_to", meta.to ?? ""])].join("\n"));
  }

  const summary = payload.summary;
  if (summary && typeof summary === "object" && !Array.isArray(summary)) {
    sections.push(
      ["summary", line(["metric", "value"])]
        .concat(Object.entries(summary as Payload).map(([k, v]) => line([k, v])))
        .join("\n"),
    );
  }

  for (const [key, value] of Object.entries(payload)) {
    if (!isObjectArray(value)) continue;
    const cols = columns(value);
    sections.push([key, line(cols)].concat(value.map((row) => line(cols.map((c) => row[c])))).join("\n"));
  }

  return sections.join("\n\n");
}

/** Hand a generated CSV to the browser as a download. No-op outside the browser. */
export function downloadCsv(filename: string, csv: string): void {
  if (typeof document === "undefined") return;
  // The BOM makes Excel read the file as UTF-8, which matters for Arabic course and company names.
  const url = URL.createObjectURL(new Blob([`﻿${csv}`], { type: "text/csv;charset=utf-8" }));
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}
