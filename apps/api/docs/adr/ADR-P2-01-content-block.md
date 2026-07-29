# ADR-P2-01 — Content Block as a first-class aggregate

**Status:** Accepted (P2/W02). **Scope:** Authoring domain, additive.

## Context
The frontend `authoring/block-registry.ts` already models a rich block taxonomy (content/media/
interactive/package/engagement, incl. scorm/xapi/assignment/survey/discussion/certificate), and lessons
already persist block payloads inside `lessons.content` (jsonb). The backend `LessonType` enum is a flat
8-value set. The taxonomies can drift and blocks are not queryable/reusable.

## Decision
Introduce a first-class `content_blocks` table + `Block` aggregate in the Authoring domain, plus a
`BlockType`/`BlockFamily` enum that mirrors the frontend registry. A Block belongs to a Lesson, carries
`family`, `type`, `payload` (jsonb), `position`, `publish_state`, and a nullable `learning_object_id`
hook for the future Content Library (W05). An optional nested `authoring_modules` table is added
alongside (not replacing) legacy Sections.

## Consequences
Additive only; no existing table/column changed. Dormant behind `authoring.blocks_enabled` (false when
absent). Backfill wraps each existing lesson as one block (idempotent). Deptrac: new classes live inside
the existing Authoring layer and only depend on Authoring + Catalog\Course (as Section already does).
