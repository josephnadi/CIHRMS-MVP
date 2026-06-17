# Finance Universal Posting — Plan 2A: Actor Threading Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the GL posting engine accept an explicit actor so journal entries get a correct `created_by`/`posted_by` even in queued/webhook/console contexts — unblocking the payroll/disbursement/loan wirings (Plans 2B–2D) and fixing the existing bug where Paystack-webhook receipt JEs are posted with `posted_by = null`.

**Architecture:** Introduce a single `PostingActorResolver` (explicit actor → `auth()->id()` → configured system user → first super_admin). Thread an optional, backward-compatible `?User $actor = null` parameter through `JournalPostingService::post()` and `PostingService::post()`, both resolving the stamp via the resolver. Existing callers that omit the argument behave exactly as before when authenticated, and now fall back to the system user instead of `null` when not.

**Tech Stack:** Laravel 13, PHP 8.3, Pest. Follows existing Finance conventions; reuses the established `config('services.billing.system_user_id')` → super_admin system-user pattern already used by `FeesController`/`MemberFeesHandler`.

**This is Plan 2A of Plan 2 (Phase 1 wiring).** Later plans: 2B payroll accrual + integrated loan repayments + reversal; 2C disbursement settlement (credits the real payroll bank account); 2D loan disbursement. Member fees & Paystack already post to the GL (Plan 3 refactor candidates), so they are NOT wired here.

**Spec:** `docs/superpowers/specs/2026-06-16-finance-universal-posting-design.md`

---

### Task 1: PostingActorResolver

A single resolver for "who posted this JE": explicit actor first, then the authenticated user, then a configured system user, then the first super_admin. Returns the user id (or null only if the DB has literally no super_admin — an impossible state in a seeded system, but handled).

**Files:**
- Create: `app/Services/Finance/PostingActorResolver.php`
- Test: `tests/Feature/Finance/PostingActorResolverTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Finance\PostingActorResolver;

it('prefers an explicit actor over the authenticated user', function () {
    $auth = User::factory()->create();
    $explicit = User::factory()->create();
    $this->actingAs($auth);

    expect(app(PostingActorResolver::class)->resolveId($explicit))->toBe($explicit->id);
});

it('falls back to the authenticated user when no actor is given', function () {
    $auth = User::factory()->create();
    $this->actingAs($auth);

    expect(app(PostingActorResolver::class)->resolveId())->toBe($auth->id);
});

it('falls back to the configured system user when there is no auth and no actor', function () {
    $system = User::factory()->create(['role' => 'finance_officer']);
    config(['services.billing.system_user_id' => $system->id]);

    expect(app(PostingActorResolver::class)->resolveId())->toBe($system->id);
});

it('falls back to the first super_admin when no actor, no auth, and no valid configured system user', function () {
    config(['services.billing.system_user_id' => null]);
    $admin = User::factory()->create(['role' => 'super_admin']);
    User::factory()->create(['role' => 'super_admin']); // a later one — first should win

    expect(app(PostingActorResolver::class)->resolveId())->toBe($admin->id);
});

it('ignores a configured system user id that does not exist', function () {
    config(['services.billing.system_user_id' => 999999]);
    $admin = User::factory()->create(['role' => 'super_admin']);

    expect(app(PostingActorResolver::class)->resolveId())->toBe($admin->id);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/PostingActorResolverTest.php`
Expected: FAIL — `PostingActorResolver` does not exist.

- [ ] **Step 3: Write the resolver**

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\User;

/**
 * Resolves the user id to stamp on a journal entry's created_by/posted_by.
 * Order: explicit actor → authenticated user → configured system user →
 * first super_admin. Lets the posting engine work in queued/webhook/console
 * contexts where auth() is null, instead of stamping null.
 */
class PostingActorResolver
{
    public function resolveId(?User $actor = null): ?int
    {
        if ($actor !== null) {
            return $actor->id;
        }

        $authId = auth()->id();
        if ($authId !== null) {
            return $authId;
        }

        return $this->systemUserId();
    }

    private function systemUserId(): ?int
    {
        $configured = config('services.billing.system_user_id');
        if ($configured !== null && User::whereKey($configured)->exists()) {
            return (int) $configured;
        }

        return User::where('role', 'super_admin')->orderBy('id')->value('id');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/PostingActorResolverTest.php`
Expected: PASS (all five).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Finance/PostingActorResolver.php tests/Feature/Finance/PostingActorResolverTest.php
git commit -m "feat(finance): PostingActorResolver for explicit/system posting actor"
```

---

### Task 2: Thread actor through JournalPostingService

Add a backward-compatible `?User $actor = null` to `post()`; resolve `posted_by` via `PostingActorResolver`. Pass the reversal actor (`$by`) through `reverse()`'s internal `post()` call so reversals are stamped too. Existing callers that omit the argument keep working (authenticated tests are unchanged; previously-null contexts now get the system user).

**Files:**
- Modify: `app/Services/Finance/JournalPostingService.php`
- Test: `tests/Feature/Finance/JournalPostingActorTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\JournalEntryStatus;
use App\Enums\JournalSourceType;
use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Finance\JournalPostingService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
});

function draftJe(): JournalEntry
{
    $cash = GlAccount::where('code', '1010')->firstOrFail();   // asset
    $income = GlAccount::where('code', '4100')->firstOrFail(); // income

    $je = JournalEntry::create([
        'reference'   => 'JE-ACTOR-' . uniqid(),
        'entry_date'  => '2026-06-17',
        'narration'   => 'actor test',
        'status'      => JournalEntryStatus::Draft->value,
        'source_type' => JournalSourceType::Manual->value,
        'source_id'   => null,
        'created_by'  => User::factory()->create()->id,
    ]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 1, 'gl_account_id' => $cash->id,   'debit_amount' => 100, 'credit_amount' => 0]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 2, 'gl_account_id' => $income->id, 'debit_amount' => 0,   'credit_amount' => 100]);

    return $je->fresh('lines.glAccount');
}

it('stamps posted_by from an explicit actor even with no auth', function () {
    $actor = User::factory()->create();

    $posted = app(JournalPostingService::class)->post(draftJe(), $actor);

    expect($posted->posted_by)->toBe($actor->id);
});

it('stamps posted_by from the authenticated user when no actor is passed (backward compatible)', function () {
    $auth = User::factory()->create();
    $this->actingAs($auth);

    $posted = app(JournalPostingService::class)->post(draftJe());

    expect($posted->posted_by)->toBe($auth->id);
});

it('falls back to the system super_admin instead of null when there is no auth and no actor', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);

    $posted = app(JournalPostingService::class)->post(draftJe());

    expect($posted->posted_by)->toBe($admin->id);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/JournalPostingActorTest.php`
Expected: FAIL — the third case stamps `null` (current behavior) / `post()` has no `$actor` parameter.

- [ ] **Step 3: Inject the resolver into the constructor**

In `app/Services/Finance/JournalPostingService.php`, change the constructor (currently `public function __construct(private readonly SequenceService $sequences)`) to also take the resolver:

```php
    public function __construct(
        private readonly SequenceService $sequences,
        private readonly PostingActorResolver $actors,
    ) {
    }
```

No new imports are needed: `App\Models\User` is already imported (used by `reverse()`'s `User $by`), and `PostingActorResolver` is in the same namespace `App\Services\Finance`.

- [ ] **Step 4: Thread the actor into post()**

Change the `post()` signature and the `posted_by` line. The method header becomes:

```php
    public function post(JournalEntry $entry, ?User $actor = null): JournalEntry
```

and inside the `DB::transaction` closure, replace:

```php
            $entry->posted_by = auth()->id();
```

with:

```php
            $entry->posted_by = $this->actors->resolveId($actor);
```

(The closure already uses `$entry`; add `$actor` to the `use (...)` of the closure: change `use ($entry)` to `use ($entry, $actor)`.)

- [ ] **Step 5: Thread the reversal actor through reverse()**

In `reverse(JournalEntry $entry, User $by, string $reason)`, the internal call currently reads `$postedReversal = $this->post($reversal);`. Change it to pass `$by`:

```php
            $postedReversal = $this->post($reversal, $by);
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/JournalPostingActorTest.php`
Expected: PASS (all three).

- [ ] **Step 7: Confirm existing callers still pass (backward compatibility)**

Run: `php artisan test tests/Feature/Finance --filter="Journal|ApPayment|ArInvoice|ArReceipt|VendorInvoice|BankAdjustment|PostingService"`
Expected: PASS — existing callers omit `$actor`; authenticated tests resolve to the same auth id as before.

- [ ] **Step 8: Commit**

```bash
git add app/Services/Finance/JournalPostingService.php tests/Feature/Finance/JournalPostingActorTest.php
git commit -m "feat(finance): thread explicit actor through JournalPostingService::post"
```

---

### Task 3: Thread actor through PostingService

Add the same backward-compatible `?User $actor = null` to `PostingService::post()`; use the resolver for the draft JE's `created_by`, and pass the actor down to `JournalPostingService::post()` so `posted_by` matches.

**Files:**
- Modify: `app/Services/Finance/PostingService.php`
- Test: `tests/Feature/Finance/PostingServiceActorTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\JournalSourceType;
use App\Models\User;
use App\Services\Finance\Posting\PostingDocument;
use App\Services\Finance\Posting\PostingLine;
use App\Services\Finance\PostingService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\PostingAccountSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new PostingAccountSeeder())->run();
});

function actorDoc(): PostingDocument
{
    return new PostingDocument(
        sourceType: JournalSourceType::Payroll,
        sourceId: 501,
        purpose: 'accrual',
        date: '2026-06-17',
        narration: 'actor doc',
        lines: [
            PostingLine::debit(slug: 'payroll.salary_expense', amount: 100.0),
            PostingLine::credit(slug: 'payroll.net_pay_payable', amount: 100.0),
        ],
    );
}

it('stamps created_by and posted_by from an explicit actor with no auth', function () {
    $actor = User::factory()->create();

    $entry = app(PostingService::class)->post(actorDoc(), $actor);

    expect($entry->created_by)->toBe($actor->id)
        ->and($entry->posted_by)->toBe($actor->id);
});

it('falls back to the system super_admin instead of null when no auth and no actor', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);

    $entry = app(PostingService::class)->post(actorDoc());

    expect($entry->created_by)->toBe($admin->id)
        ->and($entry->posted_by)->toBe($admin->id);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/PostingServiceActorTest.php`
Expected: FAIL — `post()` has no `$actor` parameter / `created_by` and `posted_by` are null without auth.

- [ ] **Step 3: Inject the resolver**

In `app/Services/Finance/PostingService.php`, add `PostingActorResolver` to the constructor (it currently injects `JournalPostingService $journal, AccountResolver $resolver, SequenceService $sequences`):

```php
    public function __construct(
        private readonly JournalPostingService $journal,
        private readonly AccountResolver $resolver,
        private readonly SequenceService $sequences,
        private readonly PostingActorResolver $actors,
    ) {
    }
```

No new imports are needed: `App\Models\User` is already imported (used by `reverseFor()`'s `User $by`), and `PostingActorResolver` is in the same namespace.

- [ ] **Step 4: Thread the actor through post()**

Change the method header:

```php
    public function post(PostingDocument $doc, ?User $actor = null): JournalEntry
```

Inside the `DB::transaction` closure, the `JournalEntry::create([...])` currently sets `'created_by' => auth()->id(),`. Replace it with:

```php
                    'created_by'     => $this->actors->resolveId($actor),
```

Change the closure's `use (...)` to capture `$actor`: it currently reads `function () use ($doc)` — change to `function () use ($doc, $actor)`.

Finally, the closure returns `return $this->journal->post($entry->fresh('lines.glAccount'));` — pass the actor through so `posted_by` matches:

```php
                return $this->journal->post($entry->fresh('lines.glAccount'), $actor);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/PostingServiceActorTest.php`
Expected: PASS (both).

- [ ] **Step 6: Confirm the engine suite still passes**

Run: `php artisan test tests/Feature/Finance/PostingServiceTest.php tests/Feature/Finance/PostingServiceActorTest.php`
Expected: PASS — the existing 7 engine tests (which act as an authenticated user) still resolve to that user; the 2 new actor tests pass.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Finance/PostingService.php tests/Feature/Finance/PostingServiceActorTest.php
git commit -m "feat(finance): thread explicit actor through PostingService::post"
```

---

### Task 4: Regression gate

Confirm the actor threading is fully backward compatible and the whole Finance suite is green.

**Files:** none (verification only).

- [ ] **Step 1: Full Finance suite**

Run: `php artisan test tests/Feature/Finance tests/Unit/Finance`
Expected: PASS — all existing tests plus the new actor tests. No existing test should regress, because every existing caller of `post()` omits `$actor` and runs as an authenticated user, resolving to the same id as before.

- [ ] **Step 2: Commit (empty, marks the gate)**

```bash
git commit --allow-empty -m "test(finance): actor-threading regression gate green"
```

---

## Self-Review notes (for the implementer)

- **Backward compatibility is the core invariant.** `?User $actor = null` defaults preserve every existing call site. The only behavioral change for existing callers is in a genuinely actor-less context (queue/webhook), where `posted_by` now resolves to the system user instead of `null` — a strict improvement.
- **Do NOT change `PostingDocument`.** The actor is a parameter of `post()`, not part of the document value object — keeps the VO a pure description of the entry.
- **Plan 2B** will call `PostingService::post($doc, $approver)` from `PayrollService::approve()`; **2C** will call it with the controller user (or system fallback) from disbursement settlement; **2D** with `$disburser` from `LoanService::disburse()`. All rely on this task's `$actor` parameter existing.
- The `config('services.billing.system_user_id')` key is the same one `FeesController`/`MemberFeesHandler` already use; the resolver degrades gracefully if it is unset or points to a missing user.
