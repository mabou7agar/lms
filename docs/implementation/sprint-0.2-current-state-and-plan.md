# Sprint 0.2 — CURRENT STATE + implementation plan (i18n + timezones)

Branch: `feat/sprint-0.2-i18n-timezones` (from `1a45513`). Phase 1 (verify current state) output. No production code edited yet.

## 1. What already exists

**Localization foundation (dormant but present)**
- `config/shared.php`: `locales=['en','ar']`, `default_locale=en`, `fallback_locale=en`, `rtl_locales=['ar']`. This is the source of truth (there is **no** `config/app.php` — Laravel 12 slim).
- `App\Platform\Shared\Traits\HasTranslations` — JSON `{locale=>value}` translatable trait (`translate()`, `setTranslation()`, `translatableAttributes()`). **Used by zero models today.**
- `App\Platform\Shared\Helpers\LocaleHelper` (`current()`, `fallback()`, `supported()`, `isRtl()`, `direction()`) + a `Locale` enum.
- **No `spatie/laravel-translatable`** and no other i18n package. → Decision: build on the existing custom `HasTranslations` trait; **no new Composer dependency**.

**CMS is ALREADY bilingual** (do not re-localize; keep contract)
- `StaticPage` (`title/body/excerpt/seo`), `HomepageSection` (`content/published_content/accessibility_label`), `NavItem` (`label/description/badge`) store nested `{ "en":…, "ar":… }` JSON in `array`-cast columns.
- Filament authors them via side-by-side `field.en` / `field.ar` dot-notation into the array column (no custom component, no raw JSON) — this is the pattern to replicate.
- HTML sanitized per-locale on save via `App\Platform\Shared\Html\HtmlSanitizer`; both models version-snapshot the whole bilingual blob (append-only).
- CMS API resources emit the `{en,ar}` **map** and the frontend resolves it with `pickLocale`.

**Locale on User/Org**
- `users.locale string(8) default 'en'` exists (fillable) but is **never applied** at runtime.
- `crm_organizations` has **no** locale column.

**Timezone**
- Only `live_sessions.timezone` (used correctly) and dormant `user_notification_settings.timezone` exist. User/Org have none.
- `App\Domains\Live\Services\TimezoneService` (`assertValid` / `toUtc` / `inZone`) — Live-scoped only; sound instant math.
- All timestamps are tz-naive Postgres `timestamp` **stored as UTC by convention** (zero `timestamptz`; `config/database.php` pins no session tz). App default tz = UTC (framework default; no `APP_TIMEZONE`).

**API / negotiation**
- Envelope: `App\Platform\Shared\Support\ApiResponse` (`data`/`error`/paginated). Domain resources emit **plain strings**; CMS resources emit `{en,ar}` maps.
- **No `Accept-Language` handling, no locale middleware, no `app()->setLocale()` anywhere.** `app()->getLocale()` is always the static `en`.

**Frontend (`apps/web`, Next.js 15 / React 19)**
- Hardcoded TS dictionaries (`lib/i18n/dictionaries.ts`), `I18nProvider` (`useI18n → {locale,dir,t,setLocale}`), locale persisted in a `locale` **cookie**; `pickLocale(v,locale)=v[locale]??v.en` for `{en,ar}` bags.
- No date library; dates formatted with native `Intl`, mostly assuming **browser timezone** (only events pass an explicit `timeZone`). The BFF proxy forwards `accept-language` but the browser client never sets it → **no functional locale signal to the API today**.
- Gate commands (in `apps/web`): `npm run typecheck` (`tsc --noEmit`), `npm run lint` (`eslint src tests`), `npm run test` (`vitest run`), `npm run build` (`next build`).

## 2. What is missing
- Deterministic locale resolution + middleware (nothing applies `users.locale`).
- Org-level locale (`crm_organizations.locale`).
- User/Org timezone columns; a **shared** (non-Live) timezone/date service.
- String-out localization of domain content (Course/Category/Product/Section/Lesson/Assessment/Rubric/Certificate-template/Badge).
- Locale-aware cache keys (`ReportCache` omits locale); tz-aware report boundaries (currently UTC-only, pinned by a test).
- Filament EN/AR authoring + RTL for the newly-localized models; `->timezone()` on admin datetime pickers.

## 3. Architecture decisions

**Locale resolution policy (deterministic, per sprint order), validated against allowlist `['en','ar']`, never from IP:**
1. Authenticated user preference (`users.locale`)
2. Explicit request locale — `?locale=` then `Accept-Language` (normalized, allowlisted)
3. Organization default (`crm_organizations.locale`, read via `TenantContext` so it never leaks across tenants)
4. App fallback (`config('shared.fallback_locale')` = `en`)

A `SetLocale` middleware on the `api` (and web/Filament) path resolves once and calls `app()->setLocale()`. Missing/empty translation never overrides a valid fallback; empty string is treated as absent.

**Content storage:** existing `HasTranslations` JSON `{locale=>value}` shape (identical to CMS `{en,ar}`). Newly-localized scalar columns are converted **in place** to `jsonb` (small/medium tables) via `ALTER COLUMN … TYPE jsonb USING (jsonb_build_object('en', col))`, with a reversing down migration (`USING col->>'en'`); `NULL` stays `NULL`. Model gains `HasTranslations` + `$translatable` + `array` cast; the column name/nullability is unchanged. The `HasTranslations` setter validates locale keys against the allowlist (no arbitrary-key mass assignment).

**API compatibility:** newly-localized domain fields stay **string-out** — resources call `->translate()` to emit a single localized string (same field name, same nullability) using the resolved locale, with fallback. CMS endpoints keep their **existing `{en,ar}` map contract** (frontend already resolves it) — documented exception, unchanged to avoid breaking the FE.

**Timezone:** DB timestamps stay canonical UTC (no bulk `timestamptz` conversion). Add `users.timezone` + `crm_organizations.timezone` (nullable IANA, validated). Promote a **shared** `TimezoneResolver`/date service (Platform/Shared) reused by Live + reporting + presentation; keep Live's `TimezoneService` behavior. Conversion only at boundaries (display/scheduling/input). Pin the pgsql session `timezone => 'UTC'` in `config/database.php` to harden the guarantee. Add explicit DST policy (nonexistent→shift forward; ambiguous→earlier offset) in the shared `toUtc`. Filament datetime pickers get `->timezone(<resolved admin tz>)`. Never mutate global tz per request in a way that affects queue/concurrent behavior.

## 3a. Architecture amendments (locked — supersede §3 where they conflict)
1. **Migrations use Expand → Migrate → Contract, NOT in-place `ALTER … TYPE jsonb`.** Per localized column `col`: (Expand) add nullable `jsonb` sibling `col_i18n`; (Migrate) backfill `col_i18n = jsonb_build_object('en', col)` where `col` is non-null, leave null as null; (Switch code) model reads/writes `col_i18n` via `HasTranslations`, resources emit `translate('col_i18n')`; (Contract) a **later** forward-only migration drops `col`. This sprint ships Expand+Migrate+Switch; Contract is a follow-up migration so no window exists where old code sees JSON in the original column.
2. **Central `App\Platform\Shared\I18n\TranslationResolver`.** Fallback/empty/null/locale logic lives ONLY here. `HasTranslations::translate()` delegates to it (no per-model fallback logic). Resolver rules: requested locale → app fallback → first non-empty value → null; empty string is treated as absent (never overrides a valid fallback); non-array (legacy scalar) passes through unchanged. `setTranslation()` validates the locale key against the `['en','ar']` allowlist (no arbitrary-key mass assignment).
3. **Cache review (complete).** Only `Cache::remember` surfaces are analytics: `KpiEngine` (`analytics:{key}`) and `ReportCache` (`analytics:report:…`) — keys omit locale. Because localized course/category titles can surface inside report/insight payloads, **add the resolved locale to the analytics cache key**. `CheckoutAction` cache is idempotency/lock (not localized) — no change. **Response cache / Homepage cache / CMS cache / Query cache: NOT PRESENT** (no such layer; `HomepageContentResolver` is not cached).
4. **Templates.** Email/Notification templates: **PRESENT and already localized** via row-per-locale `notification_templates` + `TemplateRenderer` (locale → fallback → generic). Do not re-localize. Automation: `AutomationRule` holds only `name`/`trigger_key`/`conditions` and references template keys — **no separate translatable user-visible strings** (localized content flows through `NotificationTemplate`). No new template system created.
5. **Filament admin locale comes from the authenticated admin user only** (`users.locale`), never `Accept-Language`. The `SetLocale` middleware (full policy incl. header) is scoped to the `api` group only; the admin panel (web guard) resolves locale from the panel user in the Filament increment.

## 4. Localized-field inventory (string-out unless noted)
- **Catalog:** Course `title, subtitle, description, seo`; Category `name, description, seo`; CourseLevel `name`; CourseLanguage `name` (not `code`); CourseTag `name`. (No objectives/requirements/target-audience columns exist.)
- **Curriculum:** Section `title, summary`; Lesson `title` (+ JSON `content` text keys: `html/label/note/instructions/prompt` — per-key, phase 2b); Module `title, summary` (dormant).
- **Commerce:** Product `title, description`.
- **Assessment:** Assessment `title, description`; Question `prompt, explanation, hint`; Option `label, feedback` (not `value`); Assignment `title, instructions`; Rubric `title`; Criterion `title, description`; Level `title, description`.
- **Certification (template side only):** CertificateTemplate `name, html`; CertificateSetting `issuer_name, signature_name, signature_title`; Badge `name, description, criteria`.
- **CMS:** already bilingual — no change.
- **DO NOT localize / immutable:** all slugs & `course_languages.code`, enums/status, ids/public_id, prices/currency, provider ids, audit action codes, idempotency keys; and issued-certificate evidence `certificates.{number, verification_code, signature_hash, issued_at, user_id, course_id, signature_name, signature_title}` (signed payload — convention-enforced; do not touch).

## 5. Timezone-sensitive flows to fix/verify (ranked)
1. Filament DateTimePickers (StaticPage, Homepage, CourseAnnouncement, LiveSession admin form) — apply resolved tz; route LiveSession form through `TimezoneService::toUtc`.
2. Live session scheduling/reschedule + `*_local` resource output (already sound — add tests + `ends_at_local`/list-local where missing).
3. Reminders (already instant-safe — add DST test).
4. Report/KPI day boundaries — make tz-aware via an explicit tz param **defaulting to UTC** so `PerformanceIndexAndSargableDateTest` semantics are preserved; localized ranges only when a tz is supplied.
5. Scheduled publishing windows (StaticPage/Homepage) — interpret admin tz at the picker boundary.
6. Presentation of subscription/coupon/certificate `issued_at` dates — add local presentation; **do not change billing compares**.
7. `daily()` scheduler jobs — documented as UTC-midnight (server=UTC); no change needed.
8. **ICS/calendar: none exists** (only a Null calendar abstraction). Building an ICS subsystem is net-new; **deferred** with a note (not an existing flow to "fix").
9. Dormant: `ScheduledAutomation.run_at`, `user_notification_settings.timezone`, digests — will need tz when built; out of scope now.

## 6. Risks / compatibility
- Report boundary change could break `PerformanceIndexAndSargableDateTest` → mitigated by UTC-default tz param.
- In-place text→jsonb conversion: single atomic deploy (migration+model together) avoids an "old code sees JSON" window; migration tests must cover Arabic/English/HTML/NULL.
- Slug uniqueness must remain single-value (slugs not localized).
- `ReportCache` key must gain locale if report payloads become localized.
- Certificate signed fields must not be touched.
- Frontend: keeping domain fields string-out means minimal FE change; a profile timezone selector + sending the chosen locale as `Accept-Language` are the only likely FE edits (run FE gates if touched).

## 7. Execution increments (each ends with a real verification block; commit only on green)
1. **Foundation** — locale-resolution service + `SetLocale` middleware; `users.timezone` + `crm_organizations.locale`/`.timezone` migrations; shared timezone/date service + DST policy; pgsql session tz=UTC; harden `HasTranslations` (allowlist validation, empty-as-absent). Commit: `feat(i18n): localized content storage + locale/timezone resolution foundation`.
2. **Catalog + Curriculum + Commerce localization** — models/migrations/resources string-out. 
3. **Assessment + Certification-template + Badge localization.**
4. **Admin authoring** — Filament EN/AR + RTL for all localized resources; picker `->timezone()`. Commit: `feat(admin): bilingual content authoring + tz-aware pickers`.
5. **Timezone-sensitive flows** — reports tz param, live/reminder tests, presentation. Commit: `feat(timezones): tz-safe scheduling, reporting and display`.
6. **Regression + contract tests** + docs. Commit: `test(i18n): localization + timezone regression coverage`.

SHA-256 baselines for every file to be modified were captured during Phase 1 (in the investigation transcript) and will be re-verified before each edit.
