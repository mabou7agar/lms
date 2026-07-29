# ADR-P2-02 — Lesson / LessonType as a compatibility facade over Blocks

**Status:** Accepted (P2/W02). **Scope:** backward compatibility guarantee.

## Context
Existing Lesson APIs, resources, and tests depend on `Lesson.type` (`LessonType`) and `lessons.content`.
P2 must not break them.

## Decision
`Lesson` and `LessonType` are retained unchanged as the public facade. A total, pure mapping
`BlockTypeMap::fromLessonType()` guarantees every legacy `LessonType` has exactly one `BlockType` with an
identical string value, so `BlockType` is a strict superset. Blocks are read/written only when
`authoring.blocks_enabled` is on; otherwise the legacy path is authoritative and unchanged.

## Consequences
Zero breaking change: no existing file is modified in W02. A pure unit test pins the mapping (total +
value-stable + superset). Future waves add dual-read behind the flag; the facade is removed only if/when
a dedicated deprecation ADR says so.
