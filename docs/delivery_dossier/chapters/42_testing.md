# Chapter 42 — Testing Strategy

> The suite is 192 test files, 973 tests, 3,405 assertions, all passing, in about 42 seconds on a parallel run. 182 of those files are Feature; 10 are Unit. That ratio is the strategy in a sentence: everything that matters routes through HTTP, RBAC, transactions, and events, and the only way to catch a regression in that chain is to drive it from the outside. Unit tests cover the few pure calculators where isolation actually pays. CI is GitHub Actions, matrix across SQLite and PostgreSQL, no coverage gate. End-to-end browser tests and load tests are not in the suite and are scheduled for Phase 4.

---

## 42.1  Stack

`pestphp/pest ^4.7` with `pestphp/pest-plugin-laravel ^4.1` (see `composer.json`). PHPUnit underneath, configured by `phpunit.xml` at the project root. Mockery for spying, Faker for fixtures, both pulled in by the Laravel skeleton.

The PHPUnit config is unremarkable but worth quoting once because the env block matters:

```xml
<php>
    <env name="APP_ENV" value="testing"/>
    <env name="BCRYPT_ROUNDS" value="4"/>
    <env name="CACHE_STORE" value="array"/>
    <env name="DB_CONNECTION" value="sqlite"/>
    <env name="DB_DATABASE" value=":memory:"/>
    <env name="MAIL_MAILER" value="array"/>
    <env name="QUEUE_CONNECTION" value="sync"/>
    <env name="SESSION_DRIVER" value="array"/>
    <env name="PULSE_ENABLED" value="false"/>
    <env name="TELESCOPE_ENABLED" value="false"/>
    <env name="NIGHTWATCH_ENABLED" value="false"/>
    <ini name="memory_limit" value="512M"/>
</php>
```

A few things in there are loadbearing:

- **`DB_CONNECTION=sqlite` + `DB_DATABASE=:memory:`** is what makes 973 tests finish in 42 seconds. Each parallel worker gets its own in-memory SQLite instance, migrations run once per worker at boot, and `RefreshDatabase` rolls each test back inside a transaction. Disk would more than double the wall clock.
- **`QUEUE_CONNECTION=sync`** is the reason we can assert side effects (audit writes, notification fan-out, identity verification) directly in the same test that triggers them. The few places we want to *assert dispatch without execution* use `Bus::fake()` / `Notification::fake()` explicitly (see `AuditChainNotificationTest`).
- **`BCRYPT_ROUNDS=4`** turns hashing from a multi-millisecond operation into a microsecond one. Login tests still exercise the real `Hash::check` path, just with a cheaper cost factor.
- **`MAIL_MAILER=array`** keeps every `Mailable` in an in-memory transport — you assert against `Mail::fake()` or `Notification::fake()`, never against an outbound SMTP that doesn't exist in the runner.
- **`PULSE_ENABLED`, `TELESCOPE_ENABLED`, `NIGHTWATCH_ENABLED`** are all forced off. Pulse and Telescope both write to the DB on every request and would double the test runtime; Nightwatch is the production APM hook and has no business firing inside a test boot.

The Pest bootstrap (`tests/Pest.php`) is small enough to read in full:

```php
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Unit/Policies');
```

`Tests\TestCase` itself is empty — it inherits everything from `Illuminate\Foundation\Testing\TestCase`. The bootstrap auto-applies `RefreshDatabase` to anything under `tests/Feature/` and to `tests/Unit/Policies/` (because policy tests need a real DB to resolve role grants), but **not** to the rest of `tests/Unit/`. Pure calculators (`PiiRedactor`, the Finance enums, `DocumentRoutingService`) don't need migrations, and skipping `RefreshDatabase` for them saves a few hundred milliseconds per test multiplied by however many workers paratest spawns.

That implicit auto-extend is also the reason you'll never see `uses(Tests\TestCase::class, RefreshDatabase::class)` at the top of a Feature test in this codebase. If you add one, Pest treats it as a no-op duplicate — it's not wrong, it's just noise. The convention is: don't write it.

---

## 42.2  Volume, by the numbers

Counts from the working tree at the time of writing:

| Surface | Count | Notes |
|---|---|---|
| Feature test files | **182** | `tests/Feature/**/*.php` |
| Unit test files | **10** | `tests/Unit/**/*.php` |
| Total tests (Pest) | **973** | last full run |
| Total assertions | **3,405** | last full run |
| Parallel runtime | **~42 s** | 8 workers, in-memory SQLite, M-class developer laptop |
| Suite name (PHPUnit) | `Unit`, `Feature` | `phpunit.xml` testsuites |

The 182 / 10 split is the unusual number on that table. Most Laravel codebases will tell you to invert it. The argument for doing it the other way around in this codebase is in Chapter 36 §36.3:

> Test ratio leans heavily Feature. 182 Feature tests to 10 Unit tests is unusual elsewhere but defensible here: most logic that matters is routed through HTTP + permission + transaction + event, and only end-to-end Feature tests catch regressions in that chain. Unit tests cover pure calculators (`PayeCalculator`, `PiiRedactor`, enum methods) where isolation pays.

A practical example. The PAYE calculator (`App\Services\Payroll\PayeCalculator`) has both — `tests/Feature/Payroll/PayeCalculatorTest.php` exercises the calculator inside a payroll run, against a seeded `GhanaStatutoryReferenceSeeder`, with real Employee rows and real bracket lookups. It also has implicit unit-style coverage because the calculator is a pure function and the Feature test feeds it concrete numbers. There is no separate `tests/Unit/Payroll/PayeCalculatorTest.php` because there's nothing to isolate — the only collaborators are the bracket table and `Money`, both of which are themselves cheap.

Where unit tests *do* exist:

```
tests/Unit/ExampleTest.php                          ← Laravel scaffold, harmless
tests/Unit/Ai/PiiRedactorTest.php                   ← regex-driven redactor, no DB needed
tests/Unit/Services/DocumentRoutingServiceTest.php  ← graph traversal over a DTO
tests/Unit/Policies/IncidentReportPolicyTest.php    ← policy isolation; uses DB
tests/Unit/Policies/DocumentPolicyMoveAnnotationTest.php
tests/Unit/Finance/EnumsTest.php                    ← labels(), values(), case lookups
tests/Unit/Finance/EnumsF2Test.php
tests/Unit/Finance/EnumsF4Test.php
tests/Unit/Finance/EnumsF4RTest.php
tests/Unit/Finance/EnumsF5Test.php
```

Five of the ten are enum tests for Finance. They earn their keep because every Finance enum doubles as a label provider (`InvoiceStatus::Paid->label()` is used in both server-side resources and Vue value lookups), and a typo in a label or a removed case is a regression you want to catch in milliseconds.

The other surface counts that bracket this chapter:

- **128 Inertia page components** (`resources/js/Pages/**/*.vue`) — every one of these is reachable from a Feature test that asserts the `assertInertia(...)` component string. Coverage isn't 1:1 (a few admin index pages share a single test with their show / edit counterparts), but every page that has business rules behind it has at least one test that touches it.
- **97 controllers / 130 FormRequests / 121 Services** — Feature tests reach all three layers in one HTTP round trip. There's no separate "controller test" and "service test" stack; the canonical pattern (Chapter 37) treats the Service as the unit of behavioural coverage and the Feature test as the integration check around it.
- **62 domain events / 16 queued listeners** — listeners are tested by triggering the event via the Service that owns it and asserting the side effect (`Notification::assertSentTo`, an `AuditLog::where(...)` query, an `AnalyticsEvent` row count). A handful of listeners that gate on configuration are also tested directly by instantiating them and calling `handle($event)`.

---

## 42.3  Base classes and the bootstrap

There are no per-domain test base classes. `Tests\TestCase` is empty:

```php
namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    //
}
```

That is deliberate. The temptation when a codebase has 30-odd modules is to grow a `Tests\Feature\Payroll\PayrollTestCase` with a `setUpPayroll()` helper, a `Tests\Feature\Finance\FinanceTestCase` with a `seedChartOfAccounts()` helper, and so on. CIHRMS doesn't. The reasons:

1. **`beforeEach()` is just as good and locally scoped.** Where a domain needs the same setup across all its tests, the file opens with `beforeEach(function () { (new RolePermissionSeeder())->run(); });` — see `TierOneFixesTest.php:14`, `TierTwoCeoGatesTest.php:11`, `TierFiveFiltersTest.php:33`, `TierThreeSupplementPermGatesTest.php:30`. The next person who reads the file sees the seeders right there, not three folders up.
2. **Helpers go in the test file or at the bottom of `tests/Pest.php`.** `TierThreeSupplementPermGatesTest.php:160-178` defines `makeEmployeeUser()` and `makeCourse()` at the bottom of the same file. If a helper genuinely needs to be shared, it goes into `tests/Pest.php` (the bootstrap exposes `something()` as a global function specifically for this purpose) or into a small `tests/Support/` namespace. We have not needed the latter yet.
3. **No fixture inheritance graph to debug.** When `WhistleblowerSubmissionTest` fails, you read one file. There is no `BaseSecureModuleTestCase` whose `setUp()` is silently overriding what `setUp()` in the test class did.

The only thing the bootstrap adds is the auto-extend wire-up described in §42.1 and a custom expectation (`->toBeOne()`) that maps to `->toBe(1)`. The expectation is mostly cosmetic and underused — it's worth knowing it exists if you read code that uses it, but you don't need to reach for it.

---

## 42.4  Granting permissions in tests

The single most-asked question for a new engineer is: how do I make a test user with permission X? The answer is documented in `project_test_patterns.md` and reproduced here because it's the convention that touches every Feature test file.

CIHRMS uses a custom RBAC (Chapter 39). `User::hasPermission($slug)` evaluates three sources, in this order:

1. The legacy `User::ROLE_PERMISSIONS` constant, keyed by the `role` enum on `users.role`. `super_admin` and `ceo` carry the wildcard `*` here.
2. DB-backed `roles` → `role_permissions` pivots, attached via `user_roles`.
3. The per-user JSON `permissions` column on `users`, which is the per-user override.

In tests, the cheapest path is the third one. The pattern:

```php
$user = User::factory()->create([
    'role'        => 'employee',          // legacy enum — keeps the rest of the
                                          //   app's role checks happy
    'permissions' => ['documents.create'], // per-user JSON override
]);
```

That's it. No `Permission::firstOrCreate`, no `Role::create`, no `attachRole`, no `syncPermissions`. The JSON column is cast on the `User` model and `hasPermission` reads it directly. The trade-off — you can't test "did the role-pivot lookup work?" with this pattern — is acceptable because the role-pivot path is itself covered by a small handful of tests under `tests/Feature/RouteIntegrityTest.php` and the role seeder tests under each Finance F-series (`F2PermissionsSeedTest`, `F4PermissionsSeedTest`, `F5PermissionsSeedTest`).

For admin tests, the shortcut is the role enum:

```php
$admin = User::factory()->create(['role' => 'super_admin']);  // gets '*' via legacy table
$ceo   = User::factory()->create(['role' => 'ceo']);          // also '*'
$hr    = User::factory()->create(['role' => 'hr_admin']);     // gets the HR slug bundle
```

`super_admin` and `ceo` carry wildcard permissions. Anything else has a bundle defined in `User::ROLE_PERMISSIONS` (the audit-v2 wave bolted the CEO wildcard mirror in PR #38 — see `project_audit_v2_complete.md`).

Where the test needs the production role bundle exactly as the seeder grants it (so the test also covers the bundle definition itself), call the seeder in `beforeEach`:

```php
beforeEach(function () {
    (new RolePermissionSeeder())->run();
});
```

The audit regression tests do this uniformly. The Finance F-series tests use `F4PermissionsSeedTest` and friends as standalone assertions on the seeder's output — these are the tests that fail loudest if someone removes a permission slug without realising it's used elsewhere.

A second pattern shows up around 2FA-gated routes. Anything behind `2fa:fresh` (payroll approve, loan disburse, AP payment post, mass message send, AI write actions) needs the test user to have a recent TOTP challenge marked. The two-line incantation:

```php
$user->forceFill(['two_factor_confirmed_at' => now()])->save();
app(\App\Services\Auth\TwoFactorService::class)->markFresh($user);
```

See `TierOneFixesTest.php:56-58` for the canonical use. `markFresh()` sets the in-memory fresh-window flag without going through the actual challenge controller. Tests that want to assert the gate itself (rather than the route behind it) skip the second line and assert the 2FA challenge redirect.

---

## 42.5  Route binding caveats

Two things bite tests written by someone new to the codebase.

**First: bound model keys.** Several models override `getRouteKeyName()` because the surfaced identifier isn't the primary key. `Document` returns `'uuid'`, for example, so anywhere a route is `/documents/{document}`, the binding is by `uuid`, not by `id`. In tests:

```php
$this->get(route('documents.show', $doc->uuid));     // ✓ works
$this->get(route('documents.show', $doc->id));       // ✗ 404 — wrong key
$this->get(route('documents.show', $doc));           // ✓ works — Laravel calls
                                                     //   getRouteKey() on the model
```

You can pass the model itself (Laravel will call `getRouteKey()`); you can pass the route-key value; you cannot pass the `id` directly unless the model's route key *is* the id. The Documents tests pass `$doc` directly almost everywhere precisely to dodge the question.

**Second: the `--filter` slash trap.** This one cost time more than once:

```bash
php artisan test --filter=Documents/   # → 0 tests found
```

PHPUnit's filter is a regex applied to test names, not a directory path. The slash in `Documents/` makes it match nothing. The right way to scope a run by directory:

```bash
php artisan test tests/Feature/Documents       # ✓ runs the whole Documents folder
php artisan test --filter=ComposeDocumentTest  # ✓ runs one file by name
php artisan test --filter='it composes'        # ✓ runs by test description
```

This is documented in `project_test_patterns.md` and would be funny if it hadn't caught three different people.

A related pattern: tests under `tests/Feature/<Module>/` that share a `beforeEach` should put the `beforeEach` at the top of each file rather than in a shared module-level setup. Pest 4 doesn't have a `beforeEach()` that cascades across files in the same directory, and inventing one with `tests/Pest.php`'s `->in('Feature/Documents')` is a layer of indirection that's not worth saving four lines of boilerplate per file.

---

## 42.6  The audit regression suite

`tests/Feature/Audit/` is a special folder. Nine files, each one a regression suite for a specific tier of the V2 market-readiness audit that landed across 12 PRs (#44–#55) in May 2026. The audit closed 65 punch-list items; the tests under this folder encode the bar for "did the fix actually take" so the same bugs don't return six months later.

```
tests/Feature/Audit/
├── AuditChainTest.php
├── AuditChainBackfillTest.php
├── AuditChainNotificationTest.php
├── TierOneFixesTest.php
├── TierTwoCeoGatesTest.php
├── TierThreeSequenceRefsTest.php
├── TierThreeSupplementPermGatesTest.php
├── TierFourTransactionsRacesTest.php
└── TierFiveFiltersTest.php
```

The three at the top — `AuditChainTest`, `AuditChainBackfillTest`, `AuditChainNotificationTest` — cover the audit log hash chain itself and are described in Chapter 40. They predate the V2 wave and they remain the most-cited tests in the codebase because every conversation about "is the audit trail trustworthy" goes through them. Quick example from `AuditChainTest.php`:

```php
it('writes a tamper-evident hash chain over consecutive audit rows', function () {
    $user = User::factory()->create();

    for ($i = 1; $i <= 3; $i++) {
        (new WriteAuditLog([...]))->handle();
    }

    $rows = AuditLog::orderBy('chain_position')->get();

    expect($rows[0]->previous_hash)->toBeNull();
    expect($rows[1]->previous_hash)->toBe($rows[0]->row_hash);
    expect($rows[2]->previous_hash)->toBe($rows[1]->row_hash);
});

it('verify-chain command detects a tampered row', function () {
    // ... write three rows, then:
    AuditLog::where('chain_position', 2)->update(['action' => 'tampered']);

    $exit = Artisan::call('audit:verify-chain');
    expect($exit)->not->toBe(0);
});
```

The interesting one is the tamper test. We write the chain through the legitimate job, mutate one row directly via Eloquent (bypassing the chain logic), then assert that the artisan walker catches it. That single test is what gives the audit chain its meaning: it doesn't just produce hashes, the verifier actually detects when they no longer fit.

The six tier files are organised by the V2 audit's own tier numbering. Each one encodes one chunk of fixes.

**`TierOneFixesTest.php`** — the cosmetic-looking but functionally critical fixes from the first sweep. Includes:

- `Privacy/MyRequests` no longer crashes on the paginator-vs-array shape mismatch the Vue page expected.
- The DPA admin forms now post `summary` instead of `decision_summary`, matching the server validator.
- `Establishment/Positions/Show` exists and renders (it used to 404).
- `TicketService::search` works on SQLite (the `ilike` query was Postgres-only).
- `Conversation::otherParticipant` returns the right user (a broken `!==` comparator was matching nothing).

The Conversation test is two lines and worth quoting because it's the smallest possible regression — a single off-by-one operator that nobody noticed because the chat UI degraded silently:

```php
it('Conversation::otherParticipant returns the partner in a 1:1 chat', function () {
    $me = User::factory()->create();
    $them = User::factory()->create();

    $conv = Conversation::create(['is_group' => false]);
    $conv->participants()->attach([$me->id, $them->id]);

    $other = $conv->fresh('participants')->otherParticipant($me);

    expect($other->id)->toBe($them->id);
});
```

**`TierTwoCeoGatesTest.php`** — the audit found four places (Tickets, Complaints, WhistleblowerAdmin, Leave) where staff pickers and approval gates filtered by an allow-list of role strings that hard-coded `super_admin` and `hr_admin` but missed `ceo`. The test file walks each one and asserts the CEO is now visible / approval-capable. Half a dozen tests, all of the shape:

```php
$ceo = User::factory()->create(['role' => 'ceo', 'name' => 'CEO Person']);
$viewer = User::factory()->create(['role' => 'hr_admin']);

$this->actingAs($viewer)
    ->get('/tickets')
    ->assertOk()
    ->assertInertia(fn ($p) => $p
        ->component('Tickets/Index')
        ->where('staff', fn ($staff) =>
            collect($staff)->contains(fn ($s) => $s['name'] === 'CEO Person'))
    );
```

The `assertInertia` closure is the pattern for asserting shape on the props payload without serialising the whole thing. It's used everywhere a Feature test needs to inspect what reached the Vue page.

**`TierThreeSequenceRefsTest.php`** — closes the audit finding that several reference generators (off-boarding refs, loan refs, employee numbers, document refs) were using `count() + 1` or `MAX() + 1` queries, both of which race. The fix was to centralise on `App\Services\Finance\SequenceService::next($key)`, which takes a row lock on a dedicated `sequences` table. The tests assert sequential output across back-to-back calls:

```php
it('OffboardingService::initiate produces sequential OFF-{year}-NNNNN references', function () {
    // ... set up two employees, then:
    $a = $svc->initiate($emp1, ExitType::Resignation, ...);
    $b = $svc->initiate($emp2, ExitType::Resignation, ...);

    expect($a->reference)->toBe(sprintf('OFF-%04d-%05d', $year, 1));
    expect($b->reference)->toBe(sprintf('OFF-%04d-%05d', $year, 2));
});
```

The same shape repeats for `LoanService::apply` (LOAN-2026-00001), `UserController::nextEmployeeNo` (CIHRM-0001 — exercised via reflection because the method is private and the controller's auth chain would otherwise need a full HTTP setup), and `DocumentService::upload` (CIHRMS/DOC/2026/0001). These tests don't *prove* race-freedom — that needs real concurrency, which a single-process Feature test can't deliver — but they prove the contract: two calls, sequential output, no gaps, correct zero-padding. The race-freedom claim itself is enforced by code review against `SequenceService::next`'s row lock, and by `tests/Feature/Finance/FinanceSequenceUniquenessTest.php` and `tests/Feature/Finance/SequenceServiceTest.php`.

**`TierThreeSupplementPermGatesTest.php`** — closes four authorisation gaps caught in the final pass: learning enrolment progress updates, performance goal updates, AI employee-summary endpoint, and the governance incidents close/reopen routes. Each test asserts the 403 from a stranger and (where the legitimate flow is also worth pinning) the 200 / 302 from the owner. The file ends with two helpers, `makeEmployeeUser()` and `makeCourse()`, defined locally — the per-file helper pattern in action.

```php
it('forbids a non-owner without learning.manage from updating someone elses progress', function () {
    [$ownerUser, $ownerEmp] = makeEmployeeUser();
    // ... create enrolment owned by $ownerEmp
    [$strangerUser] = makeEmployeeUser();

    $this->actingAs($strangerUser)
        ->patch(route('learning.enrolments.progress', $enrolment), ['progress_pct' => 99])
        ->assertForbidden();

    expect($enrolment->fresh()->progress_pct)->toEqual(10);
});
```

The double assertion — "the request was forbidden" *and* "the value didn't change" — is the right shape for a permission test. A 403 alone doesn't tell you the controller didn't half-execute before throwing.

**`TierFourTransactionsRacesTest.php`** — four sites where a multi-step write was not wrapped in `DB::transaction()`, plus the conversation-pair race-condition test. The interesting one is `MessagingController::issuePin`: the test mocks `SmsDispatcher` to always throw, then asserts that even though the SMS dispatch fails *after* commit, the PIN row is still rotated. This is the inverse of what you'd assume — the test isn't asserting rollback, it's asserting that the SMS failure does *not* trigger rollback, because the user needs the PIN persisted so HR can resend.

The other three tests in this file do assert rollback:

- `LearningService::recordProgress` rolls back the progress bump if `completeEnrolment` throws (synthetic throw via a `saving` listener).
- `RecruitmentService::apply` deletes the orphan CV from disk when the Applicant insert throws.
- `Conversation::findOrCreateOneOnOne` returns the same row across back-to-back resolves for a pair (this is the conversation race fix — a `sharedLock` + re-check inside the transaction).

The file header is honest about what *isn't* covered:

> The headcount ceiling race (PositionService) needs real concurrency to exercise so it isn't covered here — the row-lock is asserted by code review.

That's the right kind of honesty for an audit regression file. Pretending the test covers race-freedom when it can't would be worse than leaving the gap and naming it.

**`TierFiveFiltersTest.php`** — the audit found three Vue filter inputs (Performance Contracts search, Attendance status/q, Leave pendingCount) that were declared in the page component but ignored by the controller, so the URL state never round-tripped. The tests assert the round trip end-to-end — set the query string, get the page, inspect the `filters.*` prop is what was sent in, and inspect that the paginated `data` array is actually filtered down to the matching rows.

```php
$this->actingAs($admin)
    ->get(route('performance.contracts.index', ['search' => 'alice']))
    ->assertOk()
    ->assertInertia(fn ($p) => $p
        ->component('Performance/Contracts/Index')
        ->has('contracts.data', 1)
        ->where('contracts.data.0.employee.employee_no', 'EMP-A001')
        ->where('filters.search', 'alice')
    );
```

This is the canonical shape for an Inertia controller test in this codebase: assert component, assert count on a known prop, assert one field on the first row, assert the filter input is echoed back. Four lines, four invariants.

---

## 42.7  Notable Feature test areas

The audit folder is the highest-density tour but most of the suite lives in domain folders. A non-exhaustive map of where the interesting tests are:

### Payroll math (8 files, ~80 tests)

`tests/Feature/Payroll/`. Eight files, the deepest mathematical coverage in the suite:

- `PayeCalculatorTest.php` — Ghana progressive PAYE brackets, edge cases at the band thresholds, married/single allowances.
- `SsnitCalculatorTest.php` — Tier 1 (13.5% employer / 5.5% employee), the correct base (gross less non-cash benefits), and the cap rules.
- `PayrollAttendanceGateTest.php` — the gate that prevents payroll being approved while there are unresolved attendance corrections for the same period. (Chapter 19 covers the business rule.)
- `PayrollLoanIntegrationTest.php` — loan deductions inserted as payroll lines, in the right order, with the right rounding.
- `PayrollRunFlowTest.php` — the end-to-end calculate → approve → disburse flow, including the 2FA-fresh requirement on approve.
- `PayrollOvertimeSupplementTest.php` — supplementary payroll runs (overtime-only, off-cycle) that don't re-process the regular salary line.
- `IppdExporterTest.php` — IPPD payroll export shape and totals.
- `GifmisJournalExporterTest.php` — GIFMIS journal export shape and the GL coding.

Payroll is the surface where a single bug costs the institute money, so the test density is deliberately higher than elsewhere.

### Audit chain (3 files, ~12 tests)

Already covered in §42.6. The chain tests are the spine of the audit story (Chapter 24, Chapter 40).

### Identity (2 files)

`tests/Feature/Identity/`:

- `IdentityVerificationTest.php` — the `VerifyEmployeeIdentity` job flow against a fake provider, including the retry and the verification ledger writes.
- `NiaOfficialProviderTest.php` — the real NIA HTTP provider's request shape (URL, headers, request body), asserted via `Http::fake()`. We don't hit the real NIA in tests; we assert we *would* hit them correctly.
- `IdentityExpiringTest.php` — the expiry-soon flagging logic for Ghana Card holders whose card is approaching its NIA-issued validity date.

The Identity surface is covered in Chapter 25.

### DPA / Privacy (4 files)

`tests/Feature/Privacy/`:

- `DataSubjectRequestServiceTest.php` — DSR lifecycle: submit → acknowledge → fulfil / reject, status transitions and notification fan-out.
- `ErasureServiceTest.php` — the erasure pipeline, with retention-override checks and the audit row that records the erasure.
- `PublicDpaPortalTest.php` — the unauthenticated submission portal (Chapter 26), including reCAPTCHA pass-through (faked) and the receipt token.
- `DataSubjectExportCsvTest.php` — the CSV export shape for a fulfilled access request.

### Whistleblower (2 files)

`tests/Feature/Whistleblower/`:

- `WhistleblowerSubmissionTest.php` — anonymous submission, tracking-token hash storage, status-check by token without authentication.
- `WhistleblowerInvestigationTest.php` — investigator assignment, case-note appending, status transitions, the audit log entries that get written along the way.

Chapter 27 covers the business rules.

### Finance F1–F5 (~58 files)

`tests/Feature/Finance/`. By file count this is the largest single folder in the suite — 58 files spanning Chart of Accounts (F1), Accounts Payable + Journal Engine (F2), Accounts Receivable (F3), Paystack gateway (F4 + F4R refunds), and Bank Reconciliation (F5). Each F-series shipped with its own permissions seeder, models, services, controllers, and a permissions-seed test that pins the slug bundle:

- `F2PermissionsSeedTest.php`
- `F3PermissionsSeedTest.php`
- `F4PermissionsSeedTest.php`
- `F4RPermissionsSeedTest.php`
- `F5PermissionsSeedTest.php`

If someone deletes a slug from a seeder without updating the controller that depends on it, the seed test catches it before the route test does. Chapter 20 walks through Finance end-to-end; this folder is the regression bar for the claims in that chapter.

### Documents (18 files)

`tests/Feature/Documents/`. The whole document lifecycle: upload, compose, annotate, route, act-on-route, withdraw, download (signed, restricted, plain), delete, share, update, end-to-end flow, plus the annotation-move policy. The `EndToEndFlowTest.php` is the integration test of last resort — it does upload → compose → annotate → route → approve → download in one test and is the slowest single test in the suite. It earns its runtime because the documents module touches PDF rendering, the annotation engine, RBAC scoping, audit, and downloads-as-signed-URLs all at once. Chapter 13 covers the module.

### Other notable areas

- `tests/Feature/Attendance/` (7 files) — overtime calculator, biometric webhook, geofence enforcement, attendance corrections, shift service, kiosk endpoint.
- `tests/Feature/Loans/` (2 files) — amortisation calculator across reducing-balance and flat methods.
- `tests/Feature/Governance/` (4 files) — policy workflow, certification reminders, incident reports.
- `tests/Feature/Reports/` (1 file) — the Auditor-General report pack assembly.
- `tests/Feature/Api/` and `tests/Feature/Api/V1/` (3 files) — the `/api/v1/*` surface health, scopes, and the OpenAPI spec drift check.
- `tests/Feature/RouteIntegrityTest.php` — walks every named route, asserts the controller and method exist, asserts the middleware stack matches a manifest. This test fails loudest of anything in the suite when someone forgets to register a route or middleware alias.

---

## 42.8  How to run

The everyday commands:

```bash
# Full suite, serial. ~2.5 minutes.
php artisan test

# Full suite, parallel. ~42 seconds on an 8-core laptop.
php artisan test --parallel

# Pest directly (skips Laravel's wrapper, slightly faster boot).
vendor/bin/pest

# One folder.
php artisan test tests/Feature/Payroll

# One file.
php artisan test tests/Feature/Audit/AuditChainTest.php

# One test by description fragment.
php artisan test --filter='tamper-evident hash chain'

# One test class by name.
php artisan test --filter=PayeCalculatorTest

# Stop on the first failure (saves time when iterating).
php artisan test --stop-on-failure

# Run only the audit folder, parallel.
php artisan test --parallel tests/Feature/Audit
```

A note on `--parallel`: Laravel's parallel runner uses `paratest` under the hood. Each worker gets its own SQLite `:memory:` database, runs migrations once at boot, and then executes a slice of the test suite. `RefreshDatabase` still applies inside each worker (every test is rolled back in a transaction). The speed-up scales close to linearly with cores up to about 8 workers; beyond that the migration boot cost starts to dominate.

The `--without-coverage` flag is not valid — Pest 4 + paratest doesn't recognise it as a top-level option. The correct flag is `--no-coverage`, but coverage is off by default in this codebase so neither is needed for an everyday run.

The `--filter=Documents/` slash trap is real and documented in §42.5. Use directory paths for directory scoping; use plain words or class names for `--filter`.

For long-running iterations on a single module, the right pattern is paratest scoped to the folder:

```bash
php artisan test --parallel tests/Feature/Finance
```

Two paratest workers on the Finance folder finishes in 6–8 seconds and gives a tight feedback loop.

---

## 42.9  CI

CI is GitHub Actions, single workflow at `.github/workflows/ci.yml`, triggered on push to `main` and on every PR against `main`. Matrix strategy on database driver:

```yaml
strategy:
  fail-fast: false
  matrix:
    db: [sqlite, pgsql]
```

Both legs run the full suite. The sqlite leg uses the in-repo file driver (against a fresh `database/database.sqlite`, not in-memory because the GitHub runner needs the file for the `migrate --seed` step), and the pgsql leg spins up a `postgres:16` service container with credentials `cihrms / cihrms / cihrms_test`. PHP 8.4, Node 22, composer 2.

The steps, in order:

1. Checkout.
2. Set up PHP 8.4 with the extension list the app needs (`bcmath`, `mbstring`, `pdo`, `pdo_sqlite`, `sqlite3`, `pdo_pgsql`, `pgsql`, `gd`, `intl`). `coverage: none` — we explicitly do not produce coverage in CI.
3. Set up Node 22 with npm cache.
4. Composer cache by `composer.lock` hash.
5. `composer install --prefer-dist --no-interaction --no-progress`.
6. `npm ci`.
7. Copy `.env.example`, generate key, touch `database/database.sqlite` if the matrix leg is sqlite.
8. `php artisan migrate --seed --force`.
9. `npm run build` — front-end build runs on CI to catch Vite / TS / Vue compile errors that wouldn't surface in a PHP-only test run.
10. `php artisan a11y:audit` — WCAG 2.1 AA static audit; fails on any `error` severity finding. The browser-based axe-core audit is a Phase 4 addition; this is the static linter that catches missing `aria-*` attributes, low-contrast pairings in the Tailwind config, and the like. See `docs/accessibility/` for the rule set.
11. `vendor/bin/pest --colors=always` — the test suite proper.

Honest call-outs:

- **No coverage gate.** We don't fail PRs on coverage delta. The argument is that a coverage gate optimises for "every line touched by *some* test", which is not the same thing as "every behaviour is covered by an *adequate* test". The audit regression folder is the better mechanism for that — when a bug ships, a test joins the suite that wouldn't exist if we'd chased coverage instead.
- **No mutation testing.** We've evaluated `infection` and not adopted it. The cost is a multi-minute CI step and a learning curve for the team; the benefit is a sharper "is this assertion actually meaningful" check that we currently get via code review. Open question; not closed.
- **No browser-driver tests in CI.** The Vue layer is exercised at the prop level via `assertInertia` and at the component level only by hand. See §42.10.
- **No performance regression gate.** Test runtime is asserted informally ("the suite should still finish in under a minute on a developer laptop"); CI runtime on the GitHub runner is ~2 minutes per matrix leg and we let it grow until someone notices.
- **`a11y:audit` is static-only.** It catches structural issues; it doesn't catch runtime issues (focus traps, dynamic ARIA, screen reader behaviour). The axe-core browser audit is the Phase 4 add-on for the runtime gaps.
- **Single PHP version.** PHP 8.4 in CI; the app targets `^8.3`. We do not currently matrix PHP 8.3 vs 8.4 in CI, on the theory that 8.4 is a strict superset for the features we use. This is a known small risk and would be cheap to add if needed.

The CI job takes ~5 minutes end-to-end per matrix leg on the standard GitHub runner: ~2 minutes for composer / npm install (mostly cached), ~30 seconds for the front-end build, ~5 seconds for the a11y audit, ~2 minutes for the Pest run (CI runs serial, not parallel — paratest under GitHub Actions adds enough boot overhead that the serial run is competitive).

---

## 42.10  What's covered, what isn't

A fair statement of what 973 tests do and don't cover.

**Covered well:**

- Every controller's happy path and its primary forbidden paths.
- Every Service's transactional contract — assertions for both the success branch and a synthetic rollback where the rollback semantics matter (`TierFourTransactionsRacesTest`).
- Every form request's validation rules, indirectly, via the controller test that posts to the route and asserts `assertSessionHasErrors` or `assertOk`.
- The audit hash chain: write, verify, tamper-detect, backfill, notify.
- The five named queues and their dispatch behaviour, via `sync` connection so the listener actually executes in the same test.
- RBAC matrix — every named permission has at least one test that asserts a holder can hit a route and a non-holder cannot. The `RouteIntegrityTest` is the catch-all that asserts the middleware stack itself.
- Payroll math against the Ghana statutory tables.
- Finance reference uniqueness and sequence behaviour (`SequenceServiceTest`, `FinanceSequenceUniquenessTest`, `TierThreeSequenceRefsTest`).
- Inertia prop shapes for the 128 page components — at least one assertion per page that the right component name reaches the response and the expected top-level props are present.

**Covered shallowly or by code review only:**

- **Race conditions under real concurrency.** Single-process Feature tests can't generate two truly-simultaneous requests. The locks in `SequenceService`, `PositionService::reserveHeadcount`, and `Conversation::findOrCreateOneOnOne` are asserted by code review against the row-lock SQL and by the back-to-back tests we *can* write. Real concurrency tests would need a separate harness (a small Go or Python script that hits the running app in parallel, plus a teardown that resets fixtures). Not built.
- **Front-end behaviour beyond prop shape.** A `assertInertia(fn ($p) => $p->component('Tickets/Index')->has('staff'))` tells you the right page got the right prop. It does not tell you the page rendered without a Vue error, that the staff dropdown filtered as the user typed, or that the modal opened on the right click. The Vue layer is currently exercised by hand against a local dev server.
- **Real third-party integrations.** NIA, Paystack, GIFMIS, IPPD, Zoho, the SMS providers — all are tested against fakes (`Http::fake`, `Storage::fake`, Mockery doubles). The request shape is asserted; the provider's response shape is mocked. The first time we hit a real production endpoint at scale, we'll learn things the fakes don't tell us.
- **Performance under load.** No load tests exist. The slowest single test in the suite is the documents end-to-end (~600 ms locally); the production envelope is asserted only by deployment experience.
- **Long-running background work.** Queue listeners run synchronously in tests (`QUEUE_CONNECTION=sync`). Behaviour under a backed-up queue worker, a worker crash mid-job, or a queue-table lock contention scenario is not covered.

**Not covered at all:**

- **Browser interaction.** No Cypress, no Playwright, no Dusk. Phase 4 plans Playwright for the top 20 user flows (login, create employee, run payroll, approve leave, file complaint, file whistleblower report, etc.).
- **Visual regression.** No Percy / Chromatic / Loki. The "Sovereign Precision" design system has tokens and component-level conventions but no automated snapshot harness.
- **Accessibility runtime.** `php artisan a11y:audit` is static; axe-core in a browser harness is the next add. The conformance statement at `docs/accessibility/` is honest about what's automated and what's reviewed by hand.
- **Security / pen-test automation.** No DAST, no automated CSP / CSRF probing. The middleware tests cover the framework-level cases (CSRF on POST routes, signature verification on webhook routes, etc.) but a dedicated security harness is Phase 4.
- **Load / performance.** No `k6`, no `locust`. Planned for Phase 4 as part of the Redis migration and Horizon roll-out — the load story matters more when the queue backend changes.

---

## 42.11  Forward — what changes in Phase 4

Three additions are on the Phase 4 list, in rough priority order:

**1. End-to-end browser tests (Playwright).** The candidate target is 20 flows. Each flow is one happy path through a major user journey, captured as a Playwright spec that boots a fresh database via the seeder and drives the real Vue app in a headless Chromium. The acceptance bar: the suite runs in CI on a separate workflow (not blocking PRs initially, blocking after a stabilisation period), and a failing flow opens a bug rather than reverts a PR. Twenty specs are enough to catch the regressions a prop-shape Feature test cannot — Vue render errors, broken event handlers, modals that don't open, search inputs that don't debounce — without paying for the maintenance burden of a 200-spec suite.

Playwright is the choice over Cypress for three reasons: better parallelism (workers are processes, not iframes), native multi-browser support (Chromium, Firefox, WebKit out of the box), and a smaller dependency surface. Laravel Dusk is also a candidate but its Chrome-only ChromeDriver story dates faster than Playwright's.

**2. axe-core browser audit.** The static audit at `php artisan a11y:audit` catches structural issues. The runtime issues — focus traps that release on the wrong element, dynamic ARIA that doesn't update when content changes, screen reader behaviour on the dashboard charts — need an in-browser audit. axe-core inside a Playwright spec is the standard pattern; we'd add one `a11y.spec.ts` per major page that asserts zero `error` findings.

**3. Load testing (k6).** Three scenarios initially: dashboard browsing (cheap reads, lots of users), payroll calculation (rare but expensive write), document compose-and-download (mixed read/write with file IO). Run against a Redis-backed staging environment, baseline the p50/p95/p99 latency, and add the numbers to the runbook. The case for load tests is weaker today than the case for browser tests — we have actual production deployments giving us latency telemetry — but it strengthens the moment we move to a multi-institute deployment model.

A fourth thing on the list, lower priority: **mutation testing**. `infection` against the Service layer, gated to the slowest 10% of tests so it runs in under five minutes, with a published mutation score in the README. This is the cheapest single thing we could do to sharpen the test suite's assertions, and it's been on the list for two months without being picked up.

---

## 42.12  Reading order for tests

A new engineer who wants to understand how the application thinks about correctness should read three files in this order:

1. **`tests/Feature/Audit/AuditChainTest.php`** — 51 lines. Three tests. Tells you the audit chain is the spine and how it's checked.
2. **`tests/Feature/RouteIntegrityTest.php`** — the manifest test. Tells you what routes exist and what middleware guards each one. If you want to understand the application's surface in one file, this is the file.
3. **`tests/Feature/Documents/EndToEndFlowTest.php`** — the longest single test. Walks the full documents lifecycle in one transaction. Tells you what a real integration test looks like in this codebase: explicit setup, narrative assertions through the flow, no shortcuts.

Then pick a module that interests you and read its folder. The Pest descriptions are written as sentences (`it('rotates the PIN even when the SMS dispatcher throws after commit', ...)`); reading the descriptions alone in order will give you the module's spec.
