# Architecture Overview (AI context)

Concise map for an AI reviewer. Update whenever architecture changes.

## Topology

Modular monolith, two apps under `corelms/`:

- `apps/api` — Laravel 12 REST API (JSON only, under `/api/v1`) + Filament admin panels. PHP 8.4, PostgreSQL 16, Redis (port 6380). DDD boundaries enforced by Deptrac.
- `apps/web` — Next.js 15 (App Router, React 19, TypeScript strict, Tailwind 4, TanStack React Query). SSR shell + a BFF proxy to the API.

Legacy `corelms-api` exists in the tree and is NOT touched.

## Backend layering (`apps/api/app`)

Three layers; Deptrac enforces that a Context depends only on the Shared kernel + published Contracts, never on another Context's internals.

- **Contexts/** — bounded contexts with their own models/actions/services/routes:
  - `Commerce/` — the largest. Products, ProductPrice, Cart, Coupon (+redemptions/promotions), Order, OrderItem, OrderCourseGrant, Invoice, InvoiceLine, CreditNote(+lines), Refund, Subscription(+plan/price/change), PaymentTransaction, PaymentAttempt, PaymentWebhookEvent, Contract(+template/acceptance), TaxRate. Payments behind `Contracts/PaymentGateway` (Fake default; adapters: Paymob/Moyasar/HyperPay/Tap/AmazonPaymentServices/Stripe). Tax behind `Contracts/TaxCalculator`. Cross-context entitlement via `app/Platform/Shared/Commerce/Contracts/EntitlementPort`.
  - `Learning/` — Enrollment, LessonProgress, LessonVideoProgress, LearnerBlockProgress; access/runtime services; completion policy; adapters implementing `CourseEnrollmentPort`.
  - `Analytics/` — read-model analytics.
- **Domains/** — supporting domains: `Catalog` (Course, instructor portal), `Authoring` (Section/Lesson/curriculum), `Assessment` (Assessment/Question/Attempt, Assignment/Submission, Gradebook), `Certification`, `Live` (sessions/reminders), `Crm` (Organization, Lead, ConsultingRequest).
- **Platform/** — cross-cutting: `Identity` (User, auth, MFA/OTP, Spatie roles/permissions, tenancy), `Notifications` (dispatcher, fan-out jobs, dead-letter), `Media` (upload, playback tokens, provider webhooks), `Homepage`/`Branding`/`Navigation`/`Pages`/`Seo`/`Features` (CMS + flags), and **`Shared/`** — the shared kernel.

### Shared kernel (`app/Platform/Shared`)

- `Support/ApiResponse` — canonical envelopes: `success()` → `{data[,message][,meta]}`; `paginated($paginator, ResourceClass)` → `{data, meta:{current_page,per_page,total,last_page,from,to}, links}`.
- `Actions/BaseAction`, `Services/BaseService`, `Requests/BaseFormRequest`, `Audit/AuditLogger`.
- `<Cap>/Contracts/*` — the ONLY cross-context surface. Key ports: `Commerce/Contracts/EntitlementPort`, `Learning/Contracts/CourseEnrollmentPort` (`isEnrolled`, `hasCourseAccess`, `enrolledLearnerIds`), `Media/Contracts/MediaReferencePort`/`MediaAssetPort`, `Curriculum/Contracts/CurriculumReadPort`, `Learning/Contracts/AssignmentRequirementPort`/`LessonRequiredBlocksPort`.

### Routing / bootstrap

- `bootstrap/app.php` — API is JSON-only (`ForceJsonForApi`), `ResolveTenant` on the `api` group, `SecurityHeaders`, correlation id, trusted proxies **fail-closed** (empty unless `TRUSTED_PROXIES` set). Health: `/up`, `/api/v1/health`, `/api/v1/health/ready`.
- Each context/domain registers its own `routes/*.php` under `api/v1` via a `BaseDomainServiceProvider`.

### Authentication / Authorization

- Sanctum tokens. `RequireAuth` equivalent is `auth:sanctum` middleware; MFA/OTP in Identity.
- Authorization = Policies + `can:<permission>` route gates (permissions from enums, Spatie-backed). Learner reads scoped to `auth id`; admin behind capability gates.
- Assessment attempts gated on **course access** (active OR completed enrollment) via `CourseEnrollmentPort::hasCourseAccess`. Lesson completion gated by `LessonCompletionPolicy` (required assignment + required blocks) on BOTH the runtime `/complete` endpoint and the legacy `/progress` endpoint.

### Commerce integrity invariants

- Money = integer minor units. Idempotency keys on charges (`{public_id}:r{attemptNo}`). Gateway I/O always OUTSIDE DB transactions.
- Coupon per-user/first-order rules re-checked under the coupon row lock at checkout; DB `UNIQUE(order_id)` on `coupon_redemptions`; redemption reconciled on OrderPaid (dunning path).
- Invoice lines apportion the order discount + VAT so they reconcile to the invoice total; credit note issued only on a cumulatively-full refund. Webhook settles exactly one charge row; partial refunds keep the order Paid.
- Invoice/credit-note numbers allocated under a locked ordered read (no `count()+1`, no `FOR UPDATE` + aggregate on Postgres).

## Frontend structure (`apps/web/src`)

- `app/` — App Router route groups: `(marketing)` (public + auth pages), `(learning)/(app)`, `(instructor)`, `(commerce)`, `(account)`, `(organization)`, `(crm)`, `(analytics)`.
- `middleware.ts` — edge session-cookie check for protected prefixes (real URL prefixes only, incl. /profile, /notifications, /billing, /subscriptions, /admin, /cart).
- `components/layout/AppShell` — authenticated shell: desktop `Sidebar` (longest-prefix active state) + mobile `Drawer` (closes on navigation) + `Topbar`. Used by learning/instructor/commerce/account/organization/crm/analytics. Public + checkout use `LandingHeader` (with a mobile hamburger drawer).
- `config/nav.ts` — nav configs (learningNav, accountNav, commerceNav, instructorNav, organizationNav, crmNav, analyticsNav). `config/theme.ts` — brand + footer.
- `lib/api` — typed client; `api.data<T>()` unwraps `.data`, `Paginated<T>` for `{data, meta, links}`.
- `lib/i18n/dictionaries.ts` — EN + AR dictionaries; RTL via logical CSS properties.
- Rich/author HTML rendered via DOMPurify (lesson-content, homepage rich-text, cms-page, question-presenter).

## Integrations

- Payment gateways (adapters only): Fake (local/test), Stripe, Paymob, Moyasar, HyperPay, Tap, Amazon Payment Services.
- Media provider webhooks: mux, s3, fake (fake registered only in local/testing).
- Sentry (optional, no-op without DSN).

## Infrastructure

- Backend: `docker-compose.yml` (Postgres 16, Redis). Queue = redis; scheduler runs dunning + subscription renewals hourly with `withoutOverlapping()->onOneServer()`.
- Frontend: `next build` (non-standalone for `next start`). Playwright config self-starts a mock API + Next for public E2E.
- Gates (9): backend migrate:fresh --seed / PHPUnit / PHPStan / Deptrac / Pint; frontend Typecheck / Lint / Vitest / Build. Additional: Playwright E2E (chromium) + axe a11y.
