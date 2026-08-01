# Engineering Decisions (AI context)

Append-only log. Each entry: Date · Wave · Decision · Reason · Alternatives · Impact.

---

### 2026-07 · W05 · Money as integer minor units (or Money VO), never floats
- **Reason:** Financial correctness; avoids float rounding drift across tax/discount/refund math.
- **Alternatives:** Decimal casts; float. Rejected — precision + serialization hazards (JSON_PRESERVE_ZERO_FRACTION).
- **Impact:** All commerce fields are `*_minor` integers; deterministic half-up tax apportionment.

### 2026-07 · W05 · Cross-context communication only via Ports in the Shared kernel
- **Reason:** Keep bounded contexts decoupled; Deptrac-enforceable dependency direction.
- **Alternatives:** Direct model imports across contexts. Rejected — creates a big ball of mud.
- **Impact:** EntitlementPort, CourseEnrollmentPort, MediaReferencePort, CurriculumReadPort, etc. Contexts never import each other's Eloquent models.

### 2026-07 · W05 · Payments behind a PaymentGateway abstraction; adapters only touch SDKs; webhooks verified in-adapter
- **Reason:** Vendor-agnostic commerce code; single place for signature verification (fail closed).
- **Alternatives:** Inline SDK calls in actions. Rejected — untestable, unsafe.
- **Impact:** Fake default gateway for local/test; MENA adapters isolated. Gateway I/O never inside a DB transaction.

### 2026-07 · W05 · PHPStan level 6 + baseline; existing models use getAttribute(), only net-new models get @property
- **Reason:** Adding @property to baselined models invalidates many baselined `property.notFound` ignores (large blast radius).
- **Alternatives:** Add @property everywhere (breaks baseline) or lower the level. Rejected.
- **Impact:** New reads on existing models use `getAttribute('x')`; baseline edited surgically only when code legitimately changes the matched errors.

### 2026-07 · W06 · Idempotency key derived per attempt `{order_public_id}:r{attemptNo}`
- **Reason:** Dedupe duplicated/concurrent retries of the SAME dunning attempt; still allow a genuinely new attempt to charge.
- **Alternatives:** Single stable per-order key (blocks legitimate re-charge after a real decline). Rejected.
- **Impact:** InitiatePaymentAction computes attemptNo from PaymentAttempt max; PaymentRecoveryService persists attempts between tries.

### 2026-07 · W06 · Paginated list endpoints use ApiResponse::paginated (not success(collection))
- **Reason:** Establish the `{data, meta, links}` envelope contract consumed by the frontend.
- **Impact:** (Corrected in W07 for 5 commerce controllers that still wrapped a paginator in success(), silently dropping meta/links.)

### 2026-07 · W06 · Commerce workspace uses the shared AppShell (sidebar + mobile drawer)
- **Reason:** billing/subscriptions/contracts were reachable only by URL; no persistent in-app nav.
- **Alternatives:** Add links to LandingHeader user menu. Rejected — inconsistent with other authenticated areas.
- **Impact:** (commerce)/layout renders AppShell(commerceNav). Checkout test unaffected (imports the page, not the layout).

### 2026-07-30 · W07 · Assessment attempts gate on course ACCESS (active OR completed), not active-only enrollment
- **Reason:** W06 used active-only isEnrolled; a learner whose enrollment flipped to completed was 403'd from course-final/retake assessments.
- **Alternatives:** Broaden isEnrolled globally (also changes assignment-submission entitlement, intentionally active-only). Rejected.
- **Impact:** New `CourseEnrollmentPort::hasCourseAccess` (grantsAccess scope); StartAttemptAction uses it; 2 test doubles + adapter updated.

### 2026-07-30 · W07 · Legacy /lessons/{id}/progress completion gated by LessonCompletionPolicy
- **Reason:** The legacy endpoint accepted a client status=completed, bypassing required-assignment/blocks → certificate forgery.
- **Alternatives:** Reject completed entirely on the legacy endpoint (breaks simple-lesson self-report used by tests) or route through CompleteLessonAction (changes access check + adds video gate). Rejected — over-broad.
- **Impact:** Legacy path now enforces the same content-requirement policy; simple lessons (no requirements) still complete.

### 2026-07-30 · W07 · Webhook settles exactly one charge; partial refunds keep order Paid; refund → Refunded only when cumulatively full
- **Reason:** payment.succeeded flipped ALL charge rows; refund.succeeded flipped any Paid order to Refunded regardless of amount.
- **Impact:** Ledger shows one succeeded charge per payment; partial async refunds no longer revoke enrollments / mint full credit notes.

### 2026-07-30 · W07 · PaymentRecoveryService attempt-number read uses locked ordered value(), not lockForUpdate()->max()
- **Reason:** Postgres rejects `FOR UPDATE` combined with an aggregate — the dunning recorder crashed (had zero tests).
- **Impact:** Real bug fixed + regression test added.

### 2026-07-30 · W07 · Coupon redemption reconciled on OrderPaid (idempotent listener)
- **Reason:** A coupon order whose checkout charge failed had its redemption released; if later paid via dunning it kept the discount uncounted → escaped caps.
- **Impact:** ReconcileCouponRedemptionOnOrderPaid records the redemption if missing (backed by UNIQUE(order_id)).

### 2026-07-30 · W07 · Login enumeration/lockout-DoS hardening DEFERRED to a product decision
- **Reason:** Distinct 401/403/423 responses are an enumeration oracle + email-only lockout is a DoS vector, but the security-correct fix (uniform 401) removes the "account locked/disabled" UX and rewrites existing auth tests.
- **Alternatives:** Unilaterally change login UX. Rejected — product/UX tradeoff, MED severity, not launch-critical.
- **Impact:** Recorded in project_state.requires_product_decision + pending_items; awaiting user's choice.

### 2026-07 · ALL WAVES · No git commits; sync = file-write + SHA-256 byte-identity
- **Reason:** Standing user instruction "Do not create a git commit yet." Device VM has no PHP; gates run in the cloud sandbox against the real code, then files are written to the device and hash-verified.
- **Impact:** No version history yet. Establishing git is a recommended near-term step.
