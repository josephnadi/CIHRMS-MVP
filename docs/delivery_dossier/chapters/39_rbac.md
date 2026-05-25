# Chapter 39 — RBAC, Policies, Per-User Permissions Overlay

> CIHRMS authorises every request through a three-layer evaluator: a legacy `users.role` enum, a DB-backed `roles`/`permissions`/`role_permissions`/`user_roles` graph, and a per-user JSON overlay on `users.permissions`. The evaluator is `App\Models\User::hasPermission()`; the cache key is `user_perms_{id}_{updated_at}` with a 60-second TTL; the policies in `app/Policies/*.php` layer the row-level checks on top. Department scoping is a fourth axis, threaded through `User::managedDepartmentIds()` and `Employee::scopeVisibleTo()`. None of this is novel — it is conventional Laravel auth, *layered*, with the conventions documented so a new engineer never has to guess which source of truth is being read.

This chapter is the engineer's reference. It cites file paths, class names, and middleware aliases; it does not editorialise.

---

## 39.1  Why three layers and not one

The temptation to migrate fully off the role enum has been resisted twice in PR review. The argument for keeping all three:

- **Legacy code reads `User::ROLE_PERMISSIONS`** — a static const on the User model (lines 51-205 of `app/Models/User.php`). Every existing test that does `User::factory()->create(['role' => 'hr_admin'])` relies on this being the authoritative source until the seeder has run, because factories do not by default attach DB pivots. Dropping the const would break ~600 of the 182 feature test files in one PR.
- **The DB graph (`roles`, `permissions`, `role_permissions`, `user_roles`)** is what the admin UI at `/admin/users` and `/admin/roles` writes against. The admin needs the ability to *grant* a permission to a specific user, *create* a new role on the fly, *scope* a role assignment to a single department — none of which a hardcoded const supports.
- **The per-user JSON overlay (`users.permissions`)** is the escape hatch. A finance hire needs `gateway.refund` for one quarter; an interim acting-CEO needs `payroll.approve` for the duration of the CEO's medical leave. The overlay lets super_admin grant a permission to a single user without inventing a one-off role; the grant is logged and can be revoked by removing one line from a JSON column.

The three layers are unioned (`array_unique([...$legacy, ...$db, ...$custom])`) inside `User::allPermissions()` and the result is cached for 60 seconds. There is no concept of *deny* — the system is additive only. A permission held by any of the three layers is held, period. The trade-off is straightforward: revocation requires removing the slug from every layer that grants it, which is a one-line change in code, a `roles:sync` API call, or a JSON column edit. The simplicity of *additive only* has been worth the loss of negative grants.

The migration that introduces the JSON column is `database/migrations/2026_05_12_083600_add_permissions_to_users_table.php`:

```php
Schema::table('users', function (Blueprint $table) {
    $table->json('permissions')->nullable()->after('role');
});
```

The migration that introduces the DB graph is `database/migrations/2026_05_15_000010_create_roles_and_permissions_tables.php`. Four tables in one migration:

- `roles` — id, slug (unique), name, description, is_system, timestamps.
- `permissions` — id, slug (unique), name, group (indexed), description, timestamps.
- `role_permissions` — composite PK `(role_id, permission_id)`, both cascade-on-delete.
- `user_roles` — surrogate `id`, `user_id`, `role_id`, **nullable `department_id`**, timestamps, two compound indexes `(user_id, role_id)` and `(user_id, department_id)`.

The same migration also adds `departments.head_user_id` as a nullable FK to `users.id`. That column is what `User::headedDepartments()` reads to compute the dept-scoped permission lane (covered in 39.6 below).

The composite PK was reviewed and rejected for `user_roles`: nullable columns cannot participate in a composite primary key on Postgres or MySQL, and `department_id` has to be nullable for global-scope roles (e.g. super_admin holds the role with no department restriction). A surrogate id solves it.

---

## 39.2  The User::hasPermission evaluator

Authorisation in CIHRMS flows through one method:

```php
// app/Models/User.php — line 349
public function hasPermission(string $permission): bool
{
    $perms = $this->allPermissions();
    return in_array('*', $perms, true) || in_array($permission, $perms, true);
}
```

The work happens in `allPermissions()` immediately above it (lines 323-347). The method is cache-wrapped, keyed on the user id and the row's `updated_at` so any write that touches the user (a role attach, a permission grant, a wildcard backfill) invalidates the entry:

```php
public function allPermissions(): array
{
    $cacheKey = "user_perms_{$this->id}_{$this->updated_at?->timestamp}";

    return Cache::remember($cacheKey, 60, function () {
        $primaryRole = $this->role instanceof UserRole ? $this->role->value : $this->role;

        // 1. Legacy hardcoded mapping for the primary role
        $legacy = self::ROLE_PERMISSIONS[$primaryRole] ?? [];

        // 2. DB-backed roles (relation eager-loaded if available)
        $db = $this->relationLoaded('roles')
            ? $this->roles->flatMap->permissions->pluck('slug')->all()
            : Permission::whereHas('roles', fn ($q) => $q->whereIn(
                'roles.id',
                $this->roles()->pluck('roles.id')
            ))->pluck('slug')->all();

        // 3. Per-user overrides JSON column
        $custom = array_values(array_filter($this->permissions ?? []));

        return array_values(array_unique([...$legacy, ...$db, ...$custom]));
    });
}
```

Three sources, unioned, cached. The `relationLoaded('roles')` branch is a deliberate optimisation — when the controller eager-loads `with('roles.permissions')` (as `HandleInertiaRequests::share()` arguably should but does not yet, see 39.10 below), the second branch becomes a memory walk; otherwise it is a single `whereHas` query.

The wildcard `*` short-circuit at the top of `hasPermission()` is what lets super_admin (and CEO, since PR #38) cover every permission slug in the system without enumerating them. The const `ROLE_PERMISSIONS['super_admin']` is literally `['*']` (line 52 of User.php); the seeder's `ROLE_PERMS['super_admin']` is `null` which the seeder interprets as "grant every Permission row" (line 386 of `RolePermissionSeeder.php`). The two encodings disagree slightly — one says "the legacy lookup table holds a wildcard", the other says "the DB pivot holds every concrete slug" — but the union result is the same: super_admin passes every check.

There is one consequential gotcha. `$user->can('employees.manage')` does not invoke `hasPermission()` directly; it goes through Laravel's Gate. The bridge is the `Gate::before` shim in `AppServiceProvider::boot()` (lines 269-274 of `app/Providers/AppServiceProvider.php`):

```php
Gate::before(function ($user, string $ability) {
    if (str_contains($ability, '.') && method_exists($user, 'hasPermission')) {
        return $user->hasPermission($ability) ?: null;
    }
    return null;
});
```

The `str_contains($ability, '.')` filter is the trick — only ability strings shaped like `module.action` (the CIHRMS permission convention) fall through to `hasPermission()`; everything else (`view`, `create`, `update`, plain Eloquent ability names) goes to the registered Policies. Returning `null` instead of `false` is also load-bearing: returning `false` would *deny* the policy chain rather than *defer* to it, which would break every row-level check in the system.

The net effect is that three call-sites are equivalent for the engineer:

```php
$user->hasPermission('payroll.approve');     // direct
$user->can('payroll.approve');               // via Gate::before
Gate::authorize('payroll.approve');          // via Gate::before, throws 403
```

Route middleware (`->middleware('permission:payroll.approve')`) goes through `EnsurePermission::handle()`, which calls `hasPermission()` directly — see `app/Http/Middleware/EnsurePermission.php`, 25 lines total.

---

## 39.3  The role catalogue

The `UserRole` enum at `app/Enums/UserRole.php` enumerates **ten** cases. Nine are seeded into `ROLE_PERMS`; the tenth (`Marketing`) is reserved and described below.

```php
case SuperAdmin     = 'super_admin';
case Ceo            = 'ceo';
case HrAdmin        = 'hr_admin';
case Manager        = 'manager';
case DeptHead       = 'dept_head';
case Employee       = 'employee';
case FinanceOfficer = 'finance_officer';
case ItSupport      = 'it_support';
case Marketing      = 'marketing';        // reserved
case Auditor        = 'auditor';
```

**super_admin** — null entry in `ROLE_PERMS`, which the seeder reads as *grant every Permission row*. The legacy const `ROLE_PERMISSIONS['super_admin']` is `['*']`. Wildcard. Flagged `two_factor_required` (line 410 of seeder). Used by the IT lead.

**ceo** — same as super_admin permission-wise, kept as a separate slug. The seeder line that promotes CEO to wildcard was added in PR #38 of the V2 audit (per `project_audit_v2_complete.md`): *"CEO mirrors super_admin permission-wise (full access). Kept as a distinct role for org-chart / audit / reporting reasons; the chief executive must not hit a permission wall on any module."* Flagged `two_factor_required`. Backfilled into the per-user JSON `['*']` on existing CEO users by step 5 of the seeder (lines 419-421) — without that backfill, a CEO created before PR #38 with a curated permission list would shadow the new wildcard until their row was touched.

**hr_admin** — broad operational permissions. Carries `employees.manage`, `employees.transfer`, `employees.view_salary`, `leave.manage`, `payroll.run` (but *not* `payroll.approve` — segregation of duties), `recruitment.manage`, `offboarding.initiate`, `offboarding.settle`, `offboarding.manage` (but *not* `offboarding.approve`), `positions.manage`, `grades.manage`, `identity.verify`, `assets.manage`, `messaging.manage`, `sso.manage`, `governance.manage`, `announcements.manage`, `integrations.manage`, `users.manage`, `ai.use`, and the four portal slugs `portal.hr/it/finance/marketing/membership/pcp/cpd/administration` for cross-departmental oversight. *Does not* hold `audit.view`, `whistleblower.investigate`, `privacy.fulfill`, `payroll.approve`, `loans.approve`, `loans.disburse`, `offboarding.approve`. Flagged `two_factor_required`.

**manager** — the line manager's slim slice: `employees.view` (scoped), `leave.approve`, `tickets.manage`, `attendance.approve`, `attendance.correct`, `performance.view`, `performance.manage`, `learning.view`, `learning.manage`, `assets.assign`, `governance.acknowledge`, `reports.view`, `ai.use`. No `employees.manage`, no `employees.transfer`, no `payroll.*`. The dept-scoping happens at the row level via `Employee::scopeVisibleTo()` (39.6 below), not via a different role slug.

**dept_head** — the broader-than-manager-narrower-than-HR lane. Carries everything manager carries *plus* `employees.transfer` (the ability to initiate a department transfer for someone in their own department) and `positions.view`. The dept_head's reach is set by `User::headedDepartments()` (the `departments.head_user_id` FK) and the dept-scoped row in `user_roles` — both feed `User::managedDepartmentIds()`.

**employee** — the floor. `dashboard.view`, `leave.request`, `tickets.create`, `complaints.create`, `recruitment.apply`, `attendance.clock_self`, `attendance.correct`, `performance.view`, `learning.view`, `loans.apply`, `assets.view`, `benefits.view/enrol/claim`, `governance.view/acknowledge`. No approval anywhere, no view-others-salary, no cross-department visibility. This is the role 90 %+ of users hold.

**finance_officer** — money-touching role. Carries `payroll.view`, `payroll.manage`, `payroll.approve`, `payroll.view_all`, `payroll.disburse`, `statutory.export`, `employees.view_salary`, `loans.view/apply/approve/disburse`, `offboarding.view/settle/approve`, plus the full Finance Hub slate from F1-F5 — `accounts.view/manage`, `bank_accounts.view/manage`, `finance.hub`, `vendors.view/manage`, `ap_invoices.view/create/approve/pay`, `journal.view`, `customers.view/manage`, `ar_invoices.view/create/approve/receive/write_off`, `statements.view`, `gateway.view/create/refund`, `reconciliation.view/import/match/adjust`. The only Finance permission they *don't* carry is `journal.post_manual` — that one is super_admin only, because manual GL entries are the emergency hatch and should not be the daily tool. Flagged `two_factor_required`.

**it_support** — narrow. `tickets.create`, `tickets.manage`, `assets.view/manage/assign`, `attendance.correct` (for self), `learning.view`, `portal.it`. Does *not* hold `users.manage` — password resets and user provisioning are HR / super_admin work, segregated from help-desk triage.

**auditor** — narrow at write-time, wide at read-time. Carries `audit.view`, `payroll.view_all` (read-only), `positions.view`, `identity.view`, `statutory.export`, `attendance.view`, `whistleblower.view_all`, `whistleblower.investigate` (auditor is the default investigator), `performance.calibrate_apply` (the dual-control apply-side of calibration), `privacy.fulfill` (auditor doubles as DPO so erasure requests don't flow through HR), plus the full set of read-only Finance slugs (`accounts.view`, `bank_accounts.view`, `vendors.view`, `ap_invoices.view`, `journal.view`, `customers.view`, `ar_invoices.view`, `statements.view`, `gateway.view`, `reconciliation.view`). No `manage`, no `create`, no `approve`, no `pay` anywhere on the Finance side — auditor can reconcile but never alter.

**marketing — reserved.** The tenth case in the enum exists for a future communications role: campaign drafting, social-media scheduling, brand-asset library. Today the case is present in the enum and in `ROLE_PERMISSIONS` (lines 175-180 of User.php) with a minimal permission set (`dashboard.view`, `leave.request`, `tickets.create`, `complaints.create`, `recruitment.apply`, `learning.view`, `portal.marketing`), but it is *not* in the seeder's `ROLE_PERMS` array. The asymmetry is deliberate: the legacy lookup carries the minimal permissions so that an existing marketing user (assigned via the enum) behaves as a base employee with a marketing portal flag; the DB pivot is empty so the admin UI surfaces marketing as an unconfigured role. Phase 5 fills it in.

The role labels live in `RolePermissionSeeder::ROLE_LABELS` (lines 347-357) and the corresponding `UserRole::label()` method on the enum returns the same human-readable strings. Both are used by the Inertia layer when rendering role pills in the user-management UI.

---

## 39.4  The permission catalogue

The canonical source of truth is `RolePermissionSeeder::PERMISSIONS` — a const array, ~140 entries, grouped for the admin UI. The form is `slug => [group, description]`. Groups visible in the admin permission table: *Dashboard*, *Employees*, *Leave*, *Service Desk*, *Complaints*, *Recruitment*, *Payroll*, *Reports*, *Audit*, *Identity*, *Establishment*, *Attendance*, *Loans*, *Off-boarding*, *Whistleblower*, *Privacy*, *API*, *Performance*, *Assets*, *Messaging*, *SSO*, *Benefits*, *Governance*, *Communications*, *Finance*, *System*, *AI*.

The naming convention is `module.action`, lowercased, dot-separated:

- `view` — read access (usually scoped further by policy).
- `view_all` — org-wide read access, bypasses department scoping.
- `manage` — full CRUD on the module.
- `create` — write but not approve.
- `approve` — the second-pair-of-eyes click; never granted to the creator on the same record.
- Module-specific verbs: `disburse`, `reverse`, `refund`, `transfer`, `pay`, `receive`, `write_off`, `enrol`, `claim`, `acknowledge`, `calibrate`, `calibrate_apply`, `pip_manage`, `import`, `match`, `adjust`, `verify`, `fulfill`, `erase`, `investigate`.

The dot is also what the `Gate::before` shim uses to distinguish CIHRMS permission slugs from generic Laravel abilities (39.2 above) — anything without a dot stays in the policy chain.

A few catalogue notes worth knowing:

- **`*` is not a permission row.** It is an in-memory short-circuit inside `hasPermission()`. There is no Permission record with `slug = '*'`; the seeder iterates concrete permission rows for super_admin's DB pivot. The wildcard only exists in `ROLE_PERMISSIONS` (the legacy const) and in any per-user JSON that the seeder backfills for super_admin / CEO.
- **`finance.hub`** is the visibility gate, not a write permission. The layout's sidebar nav uses `can('finance.hub')` to decide whether to render the Finance band; the granular write permissions (`ap_invoices.pay`, `journal.post_manual`, etc.) gate the actual write endpoints. Granting `finance.hub` alone produces a read-only finance experience.
- **`portal.*`** — eight portal slugs, one per department (`portal.it`, `portal.hr`, `portal.marketing`, `portal.finance`, `portal.membership`, `portal.pcp`, `portal.cpd`, `portal.administration`). These do *not* gate data; they gate the *department portal landing pages* under `/departments/{slug}`. HR holds all eight (cross-functional oversight). Other roles hold only the slug matching their role's department by default.
- **`ai.use`** — Phase 4. Per-call LLM provider cost; granted to super_admin (wildcard), CEO (wildcard), hr_admin, and manager only. Employees do not get to drive provider cost from a chat box; if they want AI help they ask their manager.
- **`privacy.erase`** — irreversible. The seeder does *not* grant this to auditor; auditor holds `privacy.fulfill` (decide on the request) but the actual tombstoning click is super_admin only. `DataSubjectRequestPolicy::erase()` reflects this (line 39-42).

The full list of slugs is in the seeder. The total in `RolePermissionSeeder::PERMISSIONS` at the time of writing is ~140; the corresponding DB row count after seeding matches.

---

## 39.5  Policy map

Twenty-five policies live in `app/Policies/*.php`. They are registered in `AppServiceProvider::boot()` (lines 237-266 of the provider). The table below is the canonical Model → Policy mapping; the right column names the entry-point permission(s) the policy checks at `viewAny()` and the load-bearing verb each policy adds.

| Model | Policy | Entry permission | Notable verbs |
|---|---|---|---|
| `Employee` | `EmployeePolicy` | `employees.view`, `employees.manage` | `view`, `update`, `delete`, `viewSalary`, `transfer` |
| `LeaveRequest` | `LeaveRequestPolicy` | `leave.request` | `approve`, `cancel` |
| `Ticket` | `TicketPolicy` | `tickets.create`, `tickets.manage` | `view`, `update` |
| `Payment` | `PaymentPolicy` | `payments.view`, `payments.manage` | `create`, `void` |
| `Department` | `DepartmentPolicy` | `employees.view`, `employees.manage` | `update` (scoped to dept head) |
| `PayrollRun` | `PayrollRunPolicy` | `payroll.view_all`, `payroll.run`, `payroll.approve` | `approve` (refuses creator), `reverse` |
| `Position` | `PositionPolicy` | `positions.view`, `positions.manage` | `freeze`, `assign` |
| `IdentityVerification` | `IdentityVerificationPolicy` | `identity.view`, `identity.verify` | `submit`, `decide` |
| `AttendanceRecord` | `AttendancePolicy` | `attendance.view` | `correct`, `approveCorrection` |
| `LoanAccount` | `LoanAccountPolicy` | `loans.view`, `loans.apply`, `loans.manage` | `approve`, `disburse` |
| `IncidentReport` (+ `IncidentReportAttachment`) | `IncidentReportPolicy` | scope via `IncidentReport::scopeVisibleTo` | `assign`, `resolve` |
| `OffboardingCase` | `OffboardingCasePolicy` | `offboarding.view`, `offboarding.manage` | `initiate`, `clear`, `calculateSettlement`, `approveSettlement`, `complete` |
| `WhistleblowerReport` | `WhistleblowerReportPolicy` | `whistleblower.investigate`, `whistleblower.manage`, `whistleblower.view_all` | `triage`, `act`, `delete` — *no* `before()` super_admin pass |
| `PerformanceContract` | `PerformanceContractPolicy` | `performance.view`, `performance.manage` | `evaluate`, `lock` |
| `CalibrationSession` | `CalibrationSessionPolicy` | `performance.calibrate` | `apply` (gated on `performance.calibrate_apply`) |
| `PerformanceImprovementPlan` | `PerformanceImprovementPlanPolicy` | `performance.pip_manage` | `extend`, `close` |
| `DataSubjectRequest` | `DataSubjectRequestPolicy` | `privacy.fulfill` (DPO) | `fulfill`, `erase` (super_admin only), `withdraw`, `downloadExport` |
| `Asset` | `AssetPolicy` | `assets.view` | `assign`, `return`, `retire` |
| `BenefitPlan`, `BenefitEnrolment`, `BenefitClaim` | `BenefitsPolicy` | `benefits.view`, `benefits.view_all` | `managePlans`, `enrol`, `submitClaim`, `manageClaims` |
| `Policy` (governance) | `GovernancePolicy` | `governance.view`, `governance.manage` | `publish`, `acknowledge`, `manageCertifications` |
| `Document` (+ `DocumentAnnotation`) | `DocumentPolicy` | `documents.view` | `annotate`, `stamp`, `share` |
| `StampAsset` | `StampAssetPolicy` | scoped by owner / department | `use`, `manage` |
| `LetterheadTemplate` | `LetterheadTemplatePolicy` | scoped by owner / department | `use`, `manage` |
| `WatermarkTemplate` | `WatermarkTemplatePolicy` | scoped by owner / department | `use`, `manage` |
| `Conversation` (+ `ChatMessage`) | `ConversationPolicy` | participant-based (no permission slug) | `view`, `send`, `deleteMessage` |

Every policy except `ConversationPolicy`, `WhistleblowerReportPolicy`, and `BenefitsPolicy` carries the same `before()` shim:

```php
public function before(User $user): ?bool
{
    return $user->isSuperAdmin() ? true : null;
}
```

The three exceptions are deliberate:

- **`ConversationPolicy`** has no `before()` because chat membership is participant-based; even super_admin should not be able to *read* a 1:1 chat they are not a member of. The check is `$conversation->participants()->where('users.id', $user->id)->exists()`. (Super_admin can still see a chat by being added as a participant, which the audit log records.)
- **`WhistleblowerReportPolicy`** has no `before()` because the segregated-investigator principle requires even super_admin to hold the explicit `whistleblower.manage` permission to read case content. Super_admin *does* hold the wildcard `*` via `ROLE_PERMISSIONS`, so the practical effect is unchanged — but the *intent* of the policy is that case content is opt-in for the highest role too. The PR comment on `WhistleblowerReportPolicy` line 10-15 spells this out.
- **`BenefitsPolicy`** uses `$user->hasRole('super_admin')` instead of `$user->isSuperAdmin()` in its `before()`. Functionally identical (both check the same role slug); the variance is historical and worth flagging for a future style sweep.

`PaymentPolicy` and `IdentityVerificationPolicy` follow the same shape as `EmployeePolicy`. `AttendancePolicy` adds a row-level check for `attendance.correct` — an employee can submit a correction request for *their own* row only; manager approves with `attendance.approve` scoped to the department. `LoanAccountPolicy` enforces the same dual-control as payroll: the user who *approves* a loan cannot be the user who *applied* for it on someone else's behalf.

Notable verbs that appear in the policies but not the catalogue table above (because they were rolled into "Notable verbs" column): `lock`, `unlock`, `dispatch`, `void`, `reverse`, `extend`, `freeze` — each in the policy of the model they affect.

---

## 39.6  Department scoping — the fourth axis

Permissions answer "is this user allowed to do X?" Department scoping answers "on which rows?" The two are orthogonal: a manager holds `leave.approve` (yes, they can approve leave) but only for employees in departments they manage (which rows).

The mechanism is two methods on `User` and one scope on `Employee`.

**`User::headedDepartments()`** (line 291) is a `hasMany` against `Department` keyed on `departments.head_user_id`. A department has at most one head; a user can head multiple departments. This is the read side of the column added by the May 2026 migration.

**`User::managedDepartmentIds()`** (line 365) returns a Collection of department IDs the user can act on. The union of two sources:

```php
$headed = $this->headedDepartments()->pluck('id');
$scoped = $this->roles()->wherePivotNotNull('department_id')->pluck('user_roles.department_id');
return $headed->merge($scoped)->unique()->values()->all();
```

The first source is `departments.head_user_id`. The second is the dept-scoped pivot row in `user_roles` — if you assign a user the `manager` role *with* `user_roles.department_id = 7`, that user gets manager-level visibility on department 7 only. A user with multiple dept-scoped role pivots (manager of Dept 7, manager of Dept 9) sees the union.

`managedDepartmentIds()` is cache-wrapped on the same `(user_id, updated_at)` key shape, also 60-second TTL.

**`User::managesDepartment(?int $departmentId)`** is the boolean check most policies use. It returns true if the user is super_admin or if the department ID is in `managedDepartmentIds()`.

**`Employee::scopeVisibleTo(?User $user)`** (line 217 of `app/Models/Employee.php`) is the query-builder side of the same picture. Any query that needs to be RBAC-scoped just calls `->visibleTo($user)`:

```php
public function scopeVisibleTo(Builder $query, ?User $user): Builder
{
    if (! $user) return $query->whereRaw('1=0');

    if ($user->isSuperAdmin() || $user->hasPermission('employees.manage')) {
        return $query;
    }

    return $query->where(function (Builder $q) use ($user) {
        // Self
        $q->where('user_id', $user->id);

        // Departments they head/manage
        $managedIds = $user->managedDepartmentIds()->all();
        if (! empty($managedIds)) {
            $q->orWhereIn('department_id', $managedIds);
        }

        // Direct reports (where this user IS the manager via Employee record)
        if ($user->employee?->id) {
            $q->orWhere('manager_id', $user->employee->id);
        }
    });
}
```

Three OR-clauses inside one closure: *self*, *managed-department membership*, and *direct-report relationship*. The shape matters because it is what makes manager-vs-dept_head a meaningful distinction even though both roles hold `leave.approve`:

- **Manager** has no `headedDepartments`, no dept-scoped pivot. The OR collapses to *self + direct reports* — exactly the set the manager actually owns.
- **dept_head** has `headedDepartments` populated for their department. The OR widens to *self + entire department + direct reports* — the whole department, regardless of who the line manager is.

Identical permission slug, different row-level scope, courtesy of one well-placed `whereIn`.

`IncidentReport::scopeVisibleTo()` follows a similar shape but with model-specific predicates (the subject or any current assignee). The pattern is the same: one scope per model that needs cross-cutting RBAC visibility, the predicate lives next to the model.

---

## 39.7  Per-user permission overlay (`users.permissions` JSON)

The third layer in the evaluator is a JSON column on `users`. Cast to `'array'` (line 212 of User.php), nullable, default `null`. Three callers write it:

- **Admin user-management UI** at `/admin/users/{user}/permissions`. Multi-select against the full permission catalogue; submit posts the chosen slugs as a flat array. The controller validates each slug exists in the `permissions` table and writes the array verbatim.
- **The seeder.** Step 5 of `RolePermissionSeeder::run()` backfills `['*']` into the JSON of every super_admin / CEO user — a one-time correction so a curated list written by an earlier `Admin/UserController::store` does not shadow the wildcard.
- **Pest test factories.** The documented pattern (per `project_test_patterns.md`):

  ```php
  $user = User::factory()->create([
      'role'        => 'employee',
      'permissions' => ['documents.create'],
  ]);
  ```

  No Role row, no Permission row, no pivot insert. The factory writes the JSON column; `User::hasPermission('documents.create')` returns true. This is the lowest-friction grant in tests and is used across `tests/Feature/**/*.php` for any test that needs a specific slug without standing up the whole RBAC graph.

The overlay is *additive only* — there is no `denied_permissions` column, no negative grant. To "revoke" a permission you remove it from whichever layer is granting it. Most often that is the JSON column itself (the granular per-user grants); occasionally it is the role's pivot (`Role::syncPermissions(array $slugs)`); rarely it is the legacy const, which requires a code change and a deploy.

The overlay is the right tool for: temporary acting roles ("the CEO is on leave for two weeks, grant the deputy `payroll.approve`"), narrow exceptions ("this one finance hire needs `gateway.refund` for a quarter"), test grants. It is the *wrong* tool for: replacing a role's full permission set (use roles), department scoping (use `user_roles.department_id`), org-wide policy changes (edit the seeder).

---

## 39.8  Two-factor freshness gates

A second factor on the *session* is one of the gates layered above the permission check. Two modes, both implemented by `App\Http\Middleware\RequireTwoFactor` (`app/Http/Middleware/RequireTwoFactor.php`, alias `2fa`):

**`2fa` (required mode).** Used implicitly at login for roles flagged `two_factor_required` on the `users` table. The seeder flags `super_admin`, `ceo`, `hr_admin`, and `finance_officer` (line 408-410 of seeder). A flagged user with `two_factor_confirmed_at = null` is redirected to `/two-factor/enroll` on every non-2fa request until they enrol. The flag is per-user, not per-role, so an admin can opt an individual contributor into 2FA without changing the role catalogue.

**`2fa:fresh` (challenge mode).** Used as route middleware on sensitive endpoints. The user must have passed a TOTP challenge within `TwoFactorService::isFresh()`'s configured window (default 15 minutes) or they are bounced to `/two-factor/challenge?intended=<originalUrl>`. The current usage across `routes/web.php` (grep `2fa:fresh`):

| Endpoint | Permission | Why fresh-2FA |
|---|---|---|
| `POST /payroll-runs/{run}/approve` | `payroll.approve` | Approval materialises GhIPSS file. |
| `POST /payroll-runs/{run}/reverse` | `payroll.reverse` | Reversal undoes a posted payroll. |
| `POST /loans/{loan}/decide` | `loans.approve` | Loan approval triggers disbursement. |
| `POST /loans/{loan}/disburse` | `loans.disburse` | Materialises the AP-side journal entry. |
| `POST /performance/contracts/{contract}/evaluate` | `performance.manage` | Locks ratings into the cycle. |
| `POST /performance/calibration/{session}/apply` | `performance.calibrate_apply` | Dual-control apply. |
| `POST /performance/pips/{pip}/close` | `performance.pip_manage` | PIP closure is HR-permanent. |
| `POST /privacy/admin/{request}/fulfill` | `privacy.fulfill` (DPA Act 843) | DPO decision is statutorily logged. |
| `POST /disbursements/{batch}/dispatch` | `payroll.disburse` | GhIPSS dispatch. |
| `POST /messaging/pins/{user}/issue` | `messaging.manage` | USSD PIN issuance. |
| `POST /ag-reports/generate` | `statutory.export` | Auditor-General pack sealed ZIP. |
| `POST /admin/whistleblower/{report}/triage` | `whistleblower.investigate` | Case routing decision. |
| `POST /admin/whistleblower/{report}/assign` | `whistleblower.manage` | Investigator assignment. |
| `POST /offboarding/{case}/settlement/approve` | `offboarding.approve` | Dual-control settlement approval. |
| `POST /offboarding/{case}/complete` | `offboarding.manage` | Case closure. |
| `POST/PUT/DELETE /admin/sso/providers/{provider}` | `sso.manage` | IdP secret rotation. |
| Finance: `ap_invoices.pay`, `journal.post_manual`, `ar_invoices.write_off`, `ar_invoices.receive`, `gateway.create`, `gateway.refund`, `reconciliation.adjust` | (varies) | Every money-touching write in F2-F5. |

The pattern is: a fresh second factor is required on every *materially destructive or financially binding* action. Read endpoints never gate on `2fa:fresh`; bulk view-only endpoints never do; the gate appears only where a click leaves a trail the user cannot reverse with a follow-up click.

The freshness implementation lives in `App\Services\Auth\TwoFactorService::isFresh()` — it compares `two_factor_last_used_at` against `now()->subSeconds(config('auth.two_factor_fresh_seconds', 900))`. A successful challenge updates the timestamp inside `TwoFactorController::challenge`; an enrolment confirmation does the same. The implementation never inspects the route; the route declares its own freshness requirement via middleware.

---

## 39.9  Inertia surface

The web app needs to know the viewer's role and permissions on the client side to render the right buttons (the *server still gates the actions* — the client is a presentation hint, not an authority). `HandleInertiaRequests::share()` exposes three relevant lazy props on every Inertia response:

```php
'roles'                => fn () => $user?->allRoleSlugs() ?? [],
'permissions'          => fn () => $user?->allPermissions() ?? [],
'managedDepartmentIds' => fn () => $user?->managedDepartmentIds()->all() ?? [],
```

The `fn () =>` wrapper makes them *lazy* — Inertia only evaluates the closure if the client asks for the prop in `usePage().props.auth.permissions`. The sidebar component reads `auth.permissions.includes('finance.hub')` to decide whether to paint the Finance band; the page-level guard reads `auth.permissions.includes('payroll.approve')` to decide whether the Approve button exists in the DOM.

The Vue layer uses these props *informationally only*. Every action the button triggers still hits a Laravel route, and that route's middleware (`permission:foo.bar`, `2fa:fresh`) is what actually decides whether the action runs. A user who tampers with their Inertia props in DevTools to add `payroll.approve` to the array will see the button paint, but the POST it triggers will 403 at the middleware. The defence is in depth.

---

## 39.10  Test patterns

`tests/Pest.php` applies `Tests\TestCase` + `RefreshDatabase` to anything under `tests/Feature/` automatically. Feature tests do not need to `uses(...)` either trait. Unit tests do.

To grant a permission to a test user, the canonical pattern is the per-user JSON overlay:

```php
$user = User::factory()->create([
    'role'        => 'employee',
    'permissions' => ['documents.create', 'documents.share'],
]);

$this->actingAs($user)
    ->post(route('documents.store'), [...])
    ->assertCreated();
```

The grant flows through `User::hasPermission()` via the legacy const + the JSON overlay; no Role / Permission / pivot row is created. For super_admin tests use `'role' => 'super_admin'` — the const carries the wildcard.

To exercise the DB graph specifically (e.g. testing a route's `permission:foo.bar` middleware with a role that does *not* hold the slug in the legacy const), the longer pattern:

```php
$role = Role::factory()->create(['slug' => 'temp_role']);
$role->syncPermissions(['some.perm']);
$user = User::factory()->create(['role' => 'employee']);
$user->roles()->attach($role->id, ['department_id' => null]);
```

This is rarely needed; most tests use the overlay.

Two gotchas worth noting:

- The `User::allPermissions()` cache key is `user_perms_{id}_{updated_at}`. Tests that change permissions on a user mid-test must `touch()` the user or call `$user->refresh()` to bust the cache. The factory's initial `create()` returns a fresh `updated_at`, so the first read is uncached; subsequent in-test mutations need an explicit touch.
- `permission:foo.bar` middleware aborts with **403**. Tests that assert authentication failures should use `assertForbidden()`, not `assertUnauthorized()`. `assertUnauthorized()` is for 401 (missing session).

---

## 39.11  Cache invalidation

Five caches in the RBAC layer:

| Cache key | TTL | Invalidated by |
|---|---|---|
| `user_perms_{id}_{updated_at}` | 60 s | Any `User::touch()`; explicit `Cache::forget()` not used. |
| `user_roles_{id}_{updated_at}` | 60 s | Same. |
| `user_managed_depts_{id}_{updated_at}` | 60 s | Same. |
| `nav.{user_id}` | 5 min | Layout sidebar nav; not in this chapter but cited for completeness. |
| `roles.full_map` (in admin controller) | runtime | Cleared on any `Role::syncPermissions()` call. |

The first three all key on `updated_at`, so a single `User::touch()` invalidates all three for that user. The admin user-management controller calls `touch()` after any role attach / permission change. The seeder calls `Cache::flush()` at the end of `run()` (line 423) — a heavier hammer, justified because the seeder rewrites the whole permission graph and any stale entry would be wrong.

The 60-second TTL is the safety net for any code path that mutates the underlying data without calling `touch()`. It is short enough that a missed invalidation does not strand the user; it is long enough that the cache earns its keep on hot endpoints (sidebar nav, every `Inertia::render`).

---

## 39.12  Honest gaps

A small list of known sharp edges, by way of being honest with the reader:

- **`marketing` role is half-wired.** The enum carries it; the legacy `ROLE_PERMISSIONS` const has a minimal entry; the seeder's `ROLE_PERMS` does not. A user assigned the `marketing` role today gets the legacy minimum but no DB-pivot permissions. The asymmetry is intentional but easy to miss — Phase 5 closes it.
- **`BenefitsPolicy::before()` uses `hasRole('super_admin')` instead of `isSuperAdmin()`.** Functionally equivalent today; could drift if `isSuperAdmin()` ever grows to check additional sources. A future style sweep should normalise.
- **The DB graph and the legacy const can drift.** `User::ROLE_PERMISSIONS` (lines 51-205 of User.php) and `RolePermissionSeeder::ROLE_PERMS` (lines 203-345 of seeder) are *two arrays that must agree*. A grep-based CI check that diffs the two would be cheap insurance; it does not exist yet. The current convention is "edit both" with a code comment on the User const reminding the engineer of the lock-step requirement (line 41-50).
- **`HandleInertiaRequests` does not eager-load `user.roles.permissions`.** The lazy closures in `share()` re-query the DB when a client reads them. The 60-second cache hides most of the cost, but a cold-cache page load on a permission-heavy role (super_admin, hr_admin) is a `whereHas` away from being a noticeable cost. The fix is a `with('roles.permissions')` in the auth middleware; the work is on the Phase 1 cleanup list.
- **No deny-grants.** A user holding `payroll.approve` via *any* of the three layers passes the gate. Revocation is removal from the granting layer, not a negative grant. This is by design but worth understanding before designing a "temporarily suspend this user from approving payroll" workflow — the right answer today is to remove the slug from their JSON overlay (or unassign the role), not to add a deny row.

None of these are blockers. They are listed so that an engineer who notices them does not have to wonder whether someone else has already seen them — they have, and they are on a list.

---

## 39.13  Forward — Phase 4 SSO and federated identity

Two threads of work are queued behind this chapter:

**NITA SSO and ghana.gov OIDC.** The `sso.manage` permission already exists; the `App\Services\Sso\SamlSsoAdapter` and the hand-rolled OIDC adapter already cover SAML 2.0 and OpenID Connect against arbitrary IdPs; the missing piece is a NITA-specific profile (claims mapping, certificate pinning to NITA's signing cert, the just-in-time role-mapping rules that `SsoOrchestrator` consumes on first login). Phase 4 packages this as a per-tenant configuration so a Ghanaian institute can point CIHRMS at `auth.ghana.gov.gh` and have its existing public-service identity carry into the platform without re-provisioning.

**Microsoft 365 / Azure AD federation.** Same adapter pair, different IdP profile. Most CIHRMS buyers already run Microsoft 365 for email; landing the M365 SSO profile on top of the existing SAML adapter is the lower-effort half. The just-in-time role mapping (group claim → CIHRMS role slug) reuses the same `SsoOrchestrator` plumbing as NITA.

Both threads extend the RBAC layer described here without changing it. The permission catalogue stays the same; the role catalogue stays the same; the policies stay the same; what changes is *how the User row gets created and what their role becomes on first login*. The `User::hasPermission()` evaluator does not care whether the role was assigned by HR through the admin UI, by the seeder, or by an OIDC claim mapping — it reads the same three layers regardless.

The audit chain (Chapter 40) gets one upgrade for SSO: the audit row's `actor_id` continues to be the CIHRMS user id, but the auth event itself gains an `identity_provider` field so the AG report pack can show *which IdP authenticated the actor at the moment of the action*. That detail is the only RBAC-adjacent change Phase 4 actually requires; the rest of the SSO work happens at the edges of the system, not inside the evaluator.

---

That is RBAC, as it runs today. Three layers, twenty-five policies, nine seeded roles plus one reserved, a dept-scoping pivot, a per-user JSON overlay, a `Gate::before` shim wiring the whole thing into Laravel's authorisation surface, and a `2fa:fresh` belt on top of the braces for the actions that can never be quietly undone. The next chapter (40 — Audit Chain) picks up where this one ends: every check this chapter describes leaves a row in `audit_logs`, and that row is hash-chained to the one before it.
