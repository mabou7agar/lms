# HANDOFF (كامل) — CoreLMS / Sprint 0.1 (RBAC) — استكمال على "On your computer"

> **الغرض:** ده الملف اللي المهمة الجديدة تقراه فتبقى عارفة **كل اللي حصل** كأنها كانت حاضرة. اقرأه بالكامل قبل أي تنفيذ.
> **المهمة:** إكمال Sprint 0.1 (RBAC) من الفرع `feat/sprint-0.1-rbac` من عند Ticket 0.1.4 (verify+commit) ثم 0.1.5 ثم 0.1.6.

---

## 0) المصدر الوحيد للحقيقة (SoT) + قواعد التنفيذ
- الوثائق على الديسك: `HELBARON_FINAL_MASTER.md` + `HELBARON_EXECUTION_PLAN_SPRINTS.md` (نُسخ المستخدم `..._2.md`) + `docs/backlog/sprint-0.1-rbac-backlog.md`.
- الريبو: `D:\Claude_Files\Projects\LMS\CoreLMS Implementation\corelms`؛ الشغل في `apps/api` (Laravel 12 + Filament 4، PHP 8.3، PostgreSQL).
- **قواعد التنفيذ الـ20 (سارية):** ممنوع features خارج الوثيقة (اكتب Backlog) · بالترتيب · Tickets صغيرة (لكل واحد: الهدف/الملفات/DB/Backend/Frontend/API/Tests/المخاطر/DoD) · **اقرأ الكود قبل ما تكتب** · أعِد استخدام الموجود، ممنوع تكرار · ممنوع كسر backward-compat · commits صغيرة منطقية · **بعد كل commit: pint/phpstan/deptrac/tests/build كلها PASS** · ممنوع بيانات وهمية/TODO/FIXME/تعطيل tests/تجاهل errors · ادّعِ فقط ما يمكن إثباته · اكتب **CURRENT STATE** قبل و **IMPLEMENTED** بعد · أوقف عند أي **BLOCKER**. الأولوية: **Correctness > Maintainability > Security > Performance > UI**.
- ممنوع push، ممنوع tag، commits محلية بس.

---

## 1) حقائق البيئة (تعلّمناها بالتجربة — مهمة جدًا)
- PHP **8.3.32** host (Herd). DB PostgreSQL `127.0.0.1:55432` db=`helbaron`. Redis `6380`. Docker services: `helbaron-postgres` / `helbaron-redis` / `helbaron-api`.
- **`.env`** فيه `DB_HOST=127.0.0.1`، لكن ممكن يوجد `bootstrap/cache/config.php` مكيّش بيقلب DB_HOST لـ `postgres` (اسم خدمة Docker، غير قابل للحل من الـ host) → **دايمًا شغّل `php artisan config:clear` قبل أي artisan/test على الـ host**، وإلا فشل "could not translate host name postgres".
- الاختبارات على الـ host: `php artisan test` (serial) و `php artisan test --parallel`. مفيش `.env.testing`؛ tests تستخدم db=`helbaron` (المستخدم بيعملها `migrate:fresh --seed` بانتظام، فآمن).
- `RefreshDatabase` بيعمل `migrate:fresh` مرة في بداية أي run → أول test بياخد **~160s** (كل الميجريشنز). ده طبيعي.
- Static gates: `vendor/bin/pint --test` · `vendor/bin/phpstan analyse --memory-limit=2G` · `php vendor/bin/deptrac analyse --no-progress` · `composer audit`. كانت كلها خضراء قبل السبرنت.
- **deptrac بيحلّل `app/` فقط** — أي كود cross-domain (يشير لأكتر من دومين) حطّه في `database/seeders/` مش في `app/` (زي DatabaseSeeder) لتجنّب violation.
- `.gitattributes` بيفرض LF؛ pint بيطبّع الأسطر → تحذير "CRLF will be replaced by LF" عند commit طبيعي.
- عند تعديل ملف موجود بأداة نصية: **شغّل `vendor/bin/pint <file>` (autofix) قبل `pint --test`** لتطبيع الأسطر/الـ imports.

---

## 2) المعمارية القائمة اللي اكتشفناها (لا تعيد بناءها)
- **RBAC موجود وناضج قبل السبرنت:** التصريح **permission-based** (`$user->can('domain.resource.action')`) + super_admin bypass لكل policy. **39 permission** في **12 enum** (كل دومين يملك enum بتاعه؛ AnalyticsPermission قيمه لا تظهر في grep بسهولة — استخدم الـ enum: cases مؤكدة ViewAnalytics/ExportAnalytics/ViewRevenue). **35 policy يدوية**. كل دومين يزرع صلاحياته في seeder بتاعه (CatalogSeeder…).
- **Spatie/laravel-permission 6.25** مفعّل (config من default الباكدج — `config/permission.php` غير منشور وده مقصود، لا تنشره). جداول Spatie في migration: `app/Platform/Identity/Database/Migrations/2025_01_01_000130_create_permission_tables.php`.
- **Policies تتسجّل بالـ convention:** كل domain provider فيه `protected array $policies = [Model => Policy]`، و`BaseDomainServiceProvider::boot()` بينادي `Gate::policy()` لكلٍّ منها + بينادي `bootDomain()`.
- **Filament panel:** `app/Providers/AdminPanelProvider.php` — auto-discovery للـ Resources من 16 namespace، navigationGroups فيها "System"، guard `web`. `User::canAccessPanel()` = super_admin/admin.
- **الأدوار الأربعة (النظام):** super_admin, admin, instructor, student (enum `App\Platform\Identity\Enums\Role`). super_admin **بلا صلاحيات صريحة** — وصوله عبر bypass. admin يملك identity.users/roles view+manage.
- **Filament Shield 4.2 + Filament 4.11 + Spatie 6.25** متوافقين.

---

## 3) اللي اتعمل في السبرنت (بالتفصيل + سبب كل قرار)

### Ticket 0.1.1 — تفعيل Shield (Commit `0d05548`) ✅
- **الملفات:** `apps/api/config/filament-shield.php` (جديد) + `apps/api/app/Providers/AdminPanelProvider.php` (سجّل `FilamentShieldPlugin::make()`).
- **القرارات (مهمة):** Shield **كواجهة إدارة أدوار فقط**، بإعدادات آمنة تمنع أي تدمير:
  - `policies.generate=false` و `policies.merge=false` → **Shield لا يولّد/يدهس الـ35 policy اليدوية أبدًا**.
  - `permissions.generate=false` → لا يولّد صلاحيات (المنصة تملك الـ39).
  - `super_admin.define_via_gate=true` → **bypass مركزي عبر `Gate::before`** (آلية Shield، لا يدوية) — يحقّق قاعدة "super_admin bypass مركزي".
  - `tabs.custom_permissions=true` → الـ39 صلاحية القائمة تظهر قابلة للتخصيص في مُحرّر الدور.
  - `auth_provider_model=App\Platform\Identity\Models\User` (تصحيح؛ الـ default `App\Models\User` غلط لهذا المشروع).
  - `register_role_policy=false` → RolePolicy نسجّلها بأنفسنا لاحقًا (0.1.4).
- **ممنوع تشغيل `shield:generate` أو نشر migration Spatie** (الجداول موجودة).
- **تحقّق:** المسار `admin/shield/roles` حيّ (route:list). pint/phpstan/deptrac خضراء.

### Ticket 0.1.2 — RBAC contract tests (Commit `4a892fa`) ✅
- **الملف:** `apps/api/tests/Feature/Identity/RbacShieldContractTest.php` (5 tests, 50 assertions).
- يثبت: الـ39 صلاحية تُزرع مرة واحدة على guard=web · إعدادات Shield الآمنة مقفولة (generate=false×2, define_via_gate=true, model=User) · super_admin bypass المركزي يعمل · لا يتسرّب لغير super_admin · دور مخصّص يأخذ بالظبط صلاحياته (منع escalation).
- **لا كود إنتاج** (النظام كان سليمًا) — tests فقط تقفل السلوك.

### Ticket 0.1.3 — Staff role templates (Commit `4e913d9`) ✅
- **الملفات:** `database/seeders/StaffRoleTemplatesSeeder.php` (جديد) + تعديل `database/seeders/DatabaseSeeder.php` (استدعاؤه بعد كل الدومينات) + `tests/Feature/Identity/StaffRoleTemplatesTest.php` + `docs/backlog/sprint-0.1-rbac-backlog.md`.
- **القرار:** **15 staff role template** قابلة للتعديل (مش enum ثابت — قاعدة 7): تُنشأ مرة بصلاحيات افتراضية least-privilege من الـ39، وبعدها مملوكة للأدمن. الـ seeder **non-clobber** (لو الدور موجود، يتخطّاه؛ لا يدهس تعديل الأدمن) + **defensive** (يمنح فقط صلاحيات موجودة). موقعه في `database/seeders/` (خارج deptrac) لأنه cross-domain.
- الأدوار: content_author/editor/publisher/reviewer, translator, assessment_manager, certification_manager, enrollment_manager, live_manager, finance_manager (commerce فقط), sales_agent, crm_manager, marketing_manager, analytics_viewer, support_agent (قراءة محدودة). **media_manager/moderator مؤجّلان** (صلاحياتهما في sprints لاحقة) في الـ backlog.
- **8 tests PASS:** mapping دقيق · idempotent · non-clobber · finance/support مفصولان (least privilege).

### Ticket 0.1.4 — RolePolicy + حماية أدوار النظام (الحالة: **الكود على الديسك، مطبّق، UNCOMMITTED**)
- **الملفات (كلها موجودة على الديسك):**
  - `app/Platform/Identity/Exceptions/ProtectedRoleException.php` (جديد؛ يمتد `IdentityException`, errorCode `IDENTITY_PROTECTED_ROLE`, status 422).
  - `app/Platform/Identity/Policies/RolePolicy.php` (جديد؛ لموديل `Spatie\Permission\Models\Role`).
  - `app/Platform/Identity/Providers/IdentityServiceProvider.php` (**معدّل ومطبّق**): imports (RolePolicy/ProtectedRoleException/Spatie Role) + `Role::class => RolePolicy::class` في `$policies` + `$this->protectSystemRoles()` في `bootDomain()` + method `protectSystemRoles()` فيها `Role::deleting` guard.
  - `tests/Feature/Identity/RolePolicyTest.php` (جديد، 5 tests).
- **المنطق:** viewAny/view = `identity.roles.view`؛ create/update/delete = `identity.roles.manage`؛ الأدوار الأربعة المحمية: update/delete=false لغير super_admin؛ و**الحذف ممنوع على مستوى الموديل** عبر `Role::deleting` guard (لأن الـ super_admin gate يتخطّى الـ policy، فالحماية لازم تكون أدنى من الـ gate) → يرمي `ProtectedRoleException`.
- **⚠️ خطوة الإغلاق المتبقّية (نفّذها أول حاجة):**
  ```
  cd apps/api
  vendor/bin/pint app/Platform/Identity/Exceptions/ProtectedRoleException.php app/Platform/Identity/Policies/RolePolicy.php app/Platform/Identity/Providers/IdentityServiceProvider.php tests/Feature/Identity/RolePolicyTest.php
  vendor/bin/pint --test
  vendor/bin/phpstan analyse --memory-limit=2G
  php vendor/bin/deptrac analyse --no-progress
  php artisan config:clear
  php artisan test tests/Feature/Identity/RolePolicyTest.php tests/Feature/Identity/RbacShieldContractTest.php tests/Feature/Identity/StaffRoleTemplatesTest.php
  # كله أخضر:
  git add app/Platform/Identity/Exceptions/ProtectedRoleException.php app/Platform/Identity/Policies/RolePolicy.php app/Platform/Identity/Providers/IdentityServiceProvider.php tests/Feature/Identity/RolePolicyTest.php
  git commit -m "feat(identity): gate role management via RolePolicy and protect system roles (Sprint 0.1.4)"
  ```
- **ملاحظة:** آخر محاولة verify فشلت **لأسباب PowerShell فقط** (سطر `elseif` منفصل في PowerShell 5.1 مش مدعوم فالباتش ما اتطبّقش في المحاولة الأولى) — أنا طبّقت الباتش يدويًا بعدها وأكّدته (RolePolicy سطر 50، protectSystemRoles سطر 76، Role::deleting سطر 78). دلوقتي إنت على الجهاز، فمفيش PowerShell — نفّذ الأوامر مباشرة.

---

## 4) المتبقّي
### 0.1.4 — إغلاق: verify + commit (فوق).
### 0.1.5 — Impersonation آمن للدعم (P2؛ **مش في الـ DoD**):
- **اقرأ الأول:** هل يوجد package impersonation في composer (`lab404/laravel-impersonate` / `stechstudio/filament-impersonate`)؟ لو مفيش أو غير متوافق مع Filament 4، اعمل حلًّا داخليًا بسيطًا آمنًا (قاعدة 14 — لا تُدخِل dependency ضعيفة).
- **المطلوب (قاعدة 13):** صلاحية مستقلة (`identity.users.impersonate` — أضفها لـ enum + seed)، super_admin/support المخوّل فقط، **منع impersonating super_admin**، تسجيل البداية/النهاية في **AuditLog** (اقرأ إزاي بيتكتب — `app/Platform/Shared/Audit/AuditLog.php` immutable؛ دوّر على writer/`AuditLog::create`)، **banner واضح** (Filament render hook — `renderHook`/`PanelsRenderHook`)، إمكانية الخروج، ومنع العمليات الحساسة أثناء impersonation عند الحاجة.
### 0.1.6 — البطارية الكاملة + توثيق مختصر + إغلاق:
```
php artisan config:clear
php artisan migrate:fresh --seed
php artisan test
php artisan test --parallel
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=2G
php vendor/bin/deptrac analyse --no-progress
composer audit
```
كلها خضراء + working tree clean.

---

## 5) DoD بتاع Sprint 0.1 (كما كتبه المستخدم — **مفيهوش impersonation**)
- إنشاء/تعديل دور وصلاحياته من لوحة الإدارة (Shield RoleResource + RolePolicy). ✅ يتأكد بـ 0.1.4.
- كل موظف يرى/يصل فقط لما تسمح به صلاحياته (policies permission-based + staff roles + `InstructorScope`). ✅ قائم ومغطّى.
- الوصول المباشر محمي مش الـ nav بس (Filament يصرّح server-side عبر policies). ✅
- الأدوار المحمية آمنة (0.1.4). ✅
- serial + parallel خضراء · static gates خضراء · working tree clean.
- **ملاحظة:** `docs/verification/ux-wave1/` untracked وموجود **من قبل السبرنت** (ليس من شغلنا) — ليس جزءًا من "clean tree" الخاص بنا.
- **قرار موثّق:** الجزء "scoped navigation + server-side authz + resource scoping" من 0.1.4 **كان قائمًا ومختبَرًا قبل السبرنت** (policies + InstructorScope + AnalyticsAuthorizationTest + فصل finance/support في 0.1.3)؛ الجزء الناقص فعلًا كان RolePolicy (تم). لم نضف tests هشّة مكررة.

---

## 6) دروس/مطبّات (عشان ما تتكررش)
- **اقرأ قبل الكتابة دايمًا** — اكتشفنا أن Shield/Spatie/سياسات كتير موجودة بالفعل؛ البناء من الصفر كان هيخالف "أعِد الاستخدام".
- **config:clear قبل أي DB command على الـ host** (مشكلة الـ cached "postgres").
- **helper functions في ملفات الـ tests عامة** (Pest): لو الـ full suite رمى "cannot redeclare function"، غيّر أسماء helpers في ملفاتنا لـ prefix `rbac_` (الأسماء الحالية: roleManager, seedTemplatePermissions, rbacDeclaredPermissions, rbacSeedAllPermissions).
- **cross-domain code → database/seeders/** (لتجنّب deptrac).
- **متشغّلش `shield:generate`** أبدًا (يدهس policies/permissions).
- لا تنشر `config/permission.php` (defaults الباكدج تكفي).

**ابدأ بـ:** `git log --oneline -8` + `git status` + تأكّد إنك على `feat/sprint-0.1-rbac`، ثم أمر إغلاق 0.1.4 (القسم 3).
