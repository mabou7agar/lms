# Authoring i18n — deferred localization of `lessons.content`

Sprint 0.2 localized the flat scalar text columns of the curriculum aggregate
(`course_sections.title` / `.summary`, `lessons.title`, `authoring_modules.title` / `.summary`)
via the `{base}_i18n` JSONB + `HasTranslations::localized()` convention.

The `lessons.content` column was intentionally **left as-is**. It is not a scalar string: it is a
JSON payload whose translatable keys are **type-dependent**. Per-key localization is deferred to a
follow-up, and only after the real payload schema is documented — which this file does.

## Where `content` lives and how it is shaped

- Column: `lessons.content` — `json`, nullable, cast `'array'` on `App\Domains\Authoring\Models\Lesson`.
- The same payload shape is mirrored (dormant, flag-gated) into `content_blocks.payload`
  (`App\Domains\Authoring\Models\Block`), backfilled 1:1 by `BlockBackfillService` — so any future
  content-localization scheme must cover **both** `lessons.content` and `content_blocks.payload`.
- The authoritative shape is the frontend block registry / typed accessors, which the backend
  mirrors (no separate backend schema is invented):
  - `apps/web/src/lib/authoring/block-content.ts` (typed accessors + per-kind interfaces)
  - `apps/web/src/lib/authoring/block-registry.ts` (`defaultContent()` factory per block kind)

`content` is a loosely-typed bag (`Record<string, unknown>`); a given lesson only carries the keys
relevant to its `LessonType` / `BlockType`. Empty values are dropped from the payload to keep it clean.

## Translatable vs non-translatable keys

Text keys that WOULD need localization in the follow-up:

| Key            | Carried by (type)                     | Nature                          | Server-side sanitized |
|----------------|---------------------------------------|---------------------------------|-----------------------|
| `html`         | `article`, and any type as supplementary reading text | Rich HTML (the only key rendered as rich HTML to the learner) | Yes — `HtmlSanitizer::sanitizeArray(['html','body'])` |
| `body`         | legacy alias of rich text             | Rich HTML                       | Yes (same sanitizer)  |
| `transcript`   | `audio`                               | **Plain text** (must never be HTML — NOT sanitized) | No |
| `label`        | `external_link`                       | Short plain-text link name      | No |
| `note`         | `quiz_placeholder`                    | Plain text                      | No |
| `instructions` | `assignment` (backend-unsupported today) | Plain text                   | No |
| `prompt`       | `discussion` (backend-unsupported today) | Plain text                   | No |
| `questions`    | `survey` (backend-unsupported today)  | Nested array of question text   | No |

Keys that must **NOT** be localized (ids, URLs, config, media handled elsewhere):

- `url` (`external_link`) — a URL, not prose.
- `starts_at` / `due_at` / `join_url` (`live_session`, `assignment`) — datetimes / URLs.
- `package_key`, `endpoint`, `au_index`, `template_id` (`scorm`, `xapi`, `cmi5`, `certificate`) —
  identifiers / config.
- Binary media (video / audio / pdf / download) is **not** in `content` at all — it lives in the
  `lesson_media` table via `PUT /admin/lessons/{lesson}/media`.
- Assessment-backed lessons (`quiz`) store no questions in `content`; they reference an Assessment
  record via `lessons.assessment_id` (localized in its own domain).

## Why per-key localization is deferred

1. The set of translatable keys is type-dependent and mixes rich HTML (sanitized), plain text
   (explicitly unsanitized, e.g. `transcript`), and nested structures (`questions`) — a single
   `content_i18n` scalar-swap does not fit.
2. Any scheme must preserve the existing server-side sanitization contract per key
   (`html`/`body` sanitized; `transcript` must stay plain text) across every locale.
3. It must localize `content_blocks.payload` identically, since Blocks mirror lesson content.
4. Several key-bearing block kinds (`assignment`, `survey`, `discussion`, `scorm`, `xapi`, `cmi5`)
   are not backend-supported yet; their payload shapes may still change.

The follow-up should introduce a per-key localized payload (e.g. a `content_i18n` JSONB keyed by
the translatable content keys above, each mapping `locale => value`) that reuses `HasTranslations`
and the shared `HtmlSanitizer`, and applies symmetrically to `content_blocks.payload`.
