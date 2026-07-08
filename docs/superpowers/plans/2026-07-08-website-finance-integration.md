# Website Finance Integration — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ingest verified fee collections from `cihrm_website` into the `cihrms-mvp` general ledger nightly, keyed off a mirrored member list, so the financial statements are produced from real revenue.

**Architecture:** `cihrms-mvp` is a pull client. A `WebsiteFeedClient` interface abstracts the website's read API so the entire mvp side is built and tested against a fake feed before the real endpoint exists. Collections are staged idempotently, mapped to GL accounts via a configurable table, and posted per-payment cash-basis via the existing `PostingService`. A reconciliation dashboard proves the ledger ties to the feed.

**Tech Stack:** Laravel 13, Pest 4, Vue 3 + Inertia, PostgreSQL (prod) / SQLite in-memory (test). Reuses `PostingService` / `PostingDocument` / `PostingLine`.

**Spec:** `docs/superpowers/specs/2026-07-08-website-finance-integration-design.md`

## Global Constraints

- GHS only — any other currency parks the row as `error`, never posts.
- One-way pull: mvp reads the website; never writes to it. No website schema changes.
- Idempotency: every collection is unique on `(source, external_ref)`; posting is idempotent on `(JournalSourceType::WebsiteCollection, external_collections.id, 'collection')`.
- Fail-soft: one bad row parks (`unmapped`/`error`/`flagged`) and never aborts the batch.
- Website is the source of truth for members; mvp mirrors, keyed by `external_user_id` (website `users.id`). mvp never edits mirrored fields.
- Posting is per-payment, cash-basis: `DR clearing / CR income`; member/student subscriptions → `CR 2400` (Deferred Income). **No recognition schedule in v1** (deferred income release is P2, gated on the separate D work).
- All GL posting goes through `PostingService::post(PostingDocument, ?User)` — never write `JournalEntry`/`JournalLine` directly.
- New refs (if any) use `SequenceService::next()` — never `count()+1`.
- Ignore the Pest `$this` and Eloquent magic-method linter hints — they are false positives in this codebase.

## Account reference (verified present in the chart of accounts)

| code | name | type | use |
|---|---|---|---|
| 1130 | Cash in transit (`bank.cash_in_transit` slug) | asset | fallback clearing |
| 1131 | Website Collections Clearing | asset | **added in Task 1**, default clearing debited on receipt |
| 2400 | Subscription in Advance (Deferred Income) | liability | subscriptions credit here |
| 4110 | Subscription - Members | income | member subscription (deferred → recognised to here later) |
| 4120 | PCP Fees & Subscription - Students | income | student subscription |
| 4100 | Member fee income (`member_fee.income` slug) | income | induction / building levy / generic member fees |
| 4600 | (loan interest income; example of an existing 46xx) | income | — |

Exam / tuition / conference / transcript income accounts are resolved by code in the Task 1 seeder; if a code is absent, the seeder creates it under the 4000 income parent (shown in Task 1).

## File structure

**Create (mvp):**
- `database/migrations/2026_07_09_000001_create_fee_gl_mappings.php`
- `database/migrations/2026_07_09_000002_create_external_collections.php`
- `database/migrations/2026_07_09_000003_create_sync_state.php`
- `database/migrations/2026_07_09_000004_add_external_user_id_to_members.php`
- `app/Models/FeeGlMapping.php`, `app/Models/ExternalCollection.php`, `app/Models/SyncState.php`
- `database/seeders/FeeGlMappingSeeder.php`
- `app/Services/Website/WebsiteFeedClient.php` (interface)
- `app/Services/Website/HttpWebsiteFeedClient.php`
- `app/Services/Website/MemberMirrorService.php`
- `app/Services/Website/CollectionIngestionService.php`
- `app/Services/Website/WebsiteSyncService.php`
- `app/Console/Commands/SyncWebsiteCollections.php`
- `app/Http/Controllers/Finance/CollectionReconciliationController.php`
- `resources/js/Pages/Finance/Reconciliation/Index.vue`
- `tests/Feature/Website/*` (per task)
- `tests/Support/FakeWebsiteFeedClient.php`

**Modify (mvp):**
- `app/Enums/JournalSourceType.php` (add `WebsiteCollection`)
- `tests/Unit/Finance/EnumsF2Test.php` (extend the enum list)
- `database/seeders/DatabaseSeeder.php` (register `FeeGlMappingSeeder`)
- `config/services.php` (website feed url + token)
- `routes/web.php` (reconciliation route), `routes/console.php` or `app/Console/Kernel` scheduling

**Companion (separate repo `cihrm_website`) — described in the appendix, not executed here:** the read API endpoints.

---

## Task 1: Fee → GL mapping table, model, and seeder

**Files:**
- Create: `database/migrations/2026_07_09_000001_create_fee_gl_mappings.php`
- Create: `app/Models/FeeGlMapping.php`
- Create: `database/seeders/FeeGlMappingSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/Website/FeeGlMappingTest.php`

**Interfaces:**
- Produces: `FeeGlMapping::forCode(string $feeCode): ?FeeGlMapping` (null when unmapped/inactive). Columns: `fee_code`, `label`, `income_gl_account_id`, `clearing_gl_account_id`, `is_deferred`, `recognition_months`, `deferred_gl_account_id`, `is_active`.
- Produces: seeded row for every canonical `fee_code` + a `1131 Website Collections Clearing` GL account.

- [ ] **Step 1: Write the failing test**

```php
// tests/Feature/Website/FeeGlMappingTest.php
<?php
declare(strict_types=1);

use App\Models\FeeGlMapping;
use App\Models\GlAccount;

beforeEach(function () {
    (new \Database\Seeders\CihrmChartOfAccountsSeeder())->run();
    (new \Database\Seeders\FeeGlMappingSeeder())->run();
});

it('seeds a clearing account and a mapping per fee code', function () {
    expect(GlAccount::where('code', '1131')->exists())->toBeTrue();

    $sub = FeeGlMapping::forCode('member.subscription');
    expect($sub)->not->toBeNull()
        ->and($sub->is_deferred)->toBeTrue()
        ->and((int) $sub->recognition_months)->toBe(12)
        ->and($sub->deferredAccount->code)->toBe('2400')
        ->and($sub->clearingAccount->code)->toBe('1131');

    $exam = FeeGlMapping::forCode('exam');
    expect($exam)->not->toBeNull()
        ->and($exam->is_deferred)->toBeFalse()
        ->and($exam->incomeAccount->type)->toBe('income');
});

it('returns null for an unknown or inactive fee code', function () {
    expect(FeeGlMapping::forCode('does.not.exist'))->toBeNull();

    FeeGlMapping::forCode('exam')->update(['is_active' => false]);
    expect(FeeGlMapping::forCode('exam'))->toBeNull();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/Website/FeeGlMappingTest.php`
Expected: FAIL — class `FeeGlMapping` / seeder not found.

- [ ] **Step 3: Write the migration**

```php
// database/migrations/2026_07_09_000001_create_fee_gl_mappings.php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_gl_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('fee_code')->unique();
            $table->string('label');
            $table->foreignId('income_gl_account_id')->constrained('gl_accounts')->restrictOnDelete();
            $table->foreignId('clearing_gl_account_id')->constrained('gl_accounts')->restrictOnDelete();
            $table->boolean('is_deferred')->default(false);
            $table->unsignedSmallInteger('recognition_months')->nullable();
            $table->foreignId('deferred_gl_account_id')->nullable()->constrained('gl_accounts')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_gl_mappings');
    }
};
```

- [ ] **Step 4: Write the model**

```php
// app/Models/FeeGlMapping.php
<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeGlMapping extends Model
{
    protected $fillable = [
        'fee_code', 'label', 'income_gl_account_id', 'clearing_gl_account_id',
        'is_deferred', 'recognition_months', 'deferred_gl_account_id', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_deferred' => 'bool', 'is_active' => 'bool', 'recognition_months' => 'int'];
    }

    public static function forCode(string $feeCode): ?self
    {
        return static::where('fee_code', $feeCode)->where('is_active', true)->first();
    }

    public function incomeAccount(): BelongsTo   { return $this->belongsTo(GlAccount::class, 'income_gl_account_id'); }
    public function clearingAccount(): BelongsTo { return $this->belongsTo(GlAccount::class, 'clearing_gl_account_id'); }
    public function deferredAccount(): BelongsTo { return $this->belongsTo(GlAccount::class, 'deferred_gl_account_id'); }
}
```

- [ ] **Step 5: Write the seeder**

```php
// database/seeders/FeeGlMappingSeeder.php
<?php
declare(strict_types=1);

namespace Database\Seeders;

use App\Models\FeeGlMapping;
use App\Models\GlAccount;
use Illuminate\Database\Seeder;

class FeeGlMappingSeeder extends Seeder
{
    public function run(): void
    {
        // Clearing account money lands in on receipt; bank reconciliation later
        // matches it to actual settlements.
        $clearing = GlAccount::firstOrCreate(
            ['code' => '1131'],
            ['name' => 'Website Collections Clearing', 'type' => 'asset', 'parent_code' => '1000', 'statement_section' => 'current'],
        );

        // Ensure income accounts exist (create under the 4000 parent if absent).
        $income = fn (string $code, string $name) => GlAccount::firstOrCreate(
            ['code' => $code],
            ['name' => $name, 'type' => 'income', 'parent_code' => '4000', 'statement_section' => 'operating'],
        );

        $deferred = GlAccount::where('code', '2400')->firstOrFail();

        // fee_code => [label, income code, deferred?, months]
        $map = [
            'member.subscription'   => ['Member subscription',        '4110', true,  12],
            'member.induction'      => ['Member induction fee',       '4100', false, null],
            'member.building_levy'  => ['Building levy',              '4100', false, null],
            'member.combined'       => ['Member combined fee',        '4100', false, null],
            'student.subscription'  => ['Student subscription',       '4120', true,  12],
            'student.tuition'       => ['Student tuition',            '4130', false, null],
            'student.exemption'     => ['Student exemption fee',      '4140', false, null],
            'student.combined'      => ['Student combined fee',       '4120', false, null],
            'exam'                  => ['Examination fee',            '4150', false, null],
            'conference'            => ['Conference fee',             '4160', false, null],
            'exhibitor'             => ['Exhibitor package',          '4160', false, null],
            'transcript'            => ['Transcript fee',             '4170', false, null],
            'premium'               => ['Premium fee',               '4180', false, null],
        ];

        foreach ($map as $code => [$label, $incomeCode, $isDeferred, $months]) {
            FeeGlMapping::updateOrCreate(
                ['fee_code' => $code],
                [
                    'label'                  => $label,
                    'income_gl_account_id'   => $income($incomeCode, $label.' income')->id,
                    'clearing_gl_account_id' => $clearing->id,
                    'is_deferred'            => $isDeferred,
                    'recognition_months'     => $months,
                    'deferred_gl_account_id' => $isDeferred ? $deferred->id : null,
                    'is_active'              => true,
                ],
            );
        }
    }
}
```

- [ ] **Step 6: Register the seeder** in `database/seeders/DatabaseSeeder.php` — add `$this->call(FeeGlMappingSeeder::class);` after the chart-of-accounts seeders (verify `CihrmChartOfAccountsSeeder` runs before it; if `DatabaseSeeder` doesn't call the CoA seeder, add both in order).

- [ ] **Step 7: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Website/FeeGlMappingTest.php`
Expected: PASS (2 tests).

> Note: verify `GlAccount`'s real column names first with `head -40 app/Models/GlAccount.php` and the create-gl-accounts migration. If the column is `account_type` not `type`, or there is no `parent_code`, adjust the seeder/model assertions to match — do not invent columns.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_07_09_000001_create_fee_gl_mappings.php app/Models/FeeGlMapping.php database/seeders/FeeGlMappingSeeder.php database/seeders/DatabaseSeeder.php tests/Feature/Website/FeeGlMappingTest.php
git commit -m "feat(website-sync): fee_code → GL mapping table + seeder"
```

---

## Task 2: `external_collections` staging table + model

**Files:**
- Create: `database/migrations/2026_07_09_000002_create_external_collections.php`
- Create: `app/Models/ExternalCollection.php`
- Test: `tests/Feature/Website/ExternalCollectionTest.php`

**Interfaces:**
- Produces: `ExternalCollection` with status constants `STATUS_POSTED='posted'`, `STATUS_UNMAPPED='unmapped'`, `STATUS_ERROR='error'`, `STATUS_FLAGGED='flagged'`; unique `(source, external_ref)`; `payload` cast to array.

- [ ] **Step 1: Write the failing test**

```php
// tests/Feature/Website/ExternalCollectionTest.php
<?php
declare(strict_types=1);

use App\Models\ExternalCollection;
use Illuminate\Database\QueryException;

it('persists a staged collection and casts payload', function () {
    $c = ExternalCollection::create([
        'source' => 'member_fee_payment', 'source_id' => 10, 'external_ref' => 'TXN-1',
        'fee_code' => 'member.subscription', 'amount' => 350, 'currency' => 'GHS',
        'paid_at' => now(), 'payload' => ['a' => 1], 'status' => ExternalCollection::STATUS_POSTED,
    ]);
    expect($c->payload)->toBe(['a' => 1])->and($c->status)->toBe('posted');
});

it('rejects a duplicate (source, external_ref)', function () {
    $row = fn () => ExternalCollection::create([
        'source' => 'member_fee_payment', 'source_id' => 10, 'external_ref' => 'TXN-1',
        'fee_code' => 'member.subscription', 'amount' => 350, 'currency' => 'GHS',
        'paid_at' => now(), 'status' => ExternalCollection::STATUS_POSTED,
    ]);
    $row();
    expect($row)->toThrow(QueryException::class);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/Website/ExternalCollectionTest.php`
Expected: FAIL — model not found.

- [ ] **Step 3: Write the migration**

```php
// database/migrations/2026_07_09_000002_create_external_collections.php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_collections', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->unsignedBigInteger('source_id');
            $table->string('external_ref');
            $table->unsignedBigInteger('external_user_id')->nullable();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('fee_code');
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('GHS');
            $table->timestamp('paid_at');
            $table->string('method')->nullable();
            $table->string('gateway_ref')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 16)->default('posted');
            $table->string('status_note')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();

            $table->unique(['source', 'external_ref']);
            $table->index(['status', 'fee_code']);
            $table->index('paid_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_collections');
    }
};
```

- [ ] **Step 4: Write the model**

```php
// app/Models/ExternalCollection.php
<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalCollection extends Model
{
    public const STATUS_POSTED   = 'posted';
    public const STATUS_UNMAPPED = 'unmapped';
    public const STATUS_ERROR    = 'error';
    public const STATUS_FLAGGED  = 'flagged';

    protected $fillable = [
        'source', 'source_id', 'external_ref', 'external_user_id', 'member_id',
        'fee_code', 'amount', 'currency', 'paid_at', 'method', 'gateway_ref',
        'payload', 'status', 'status_note', 'journal_entry_id',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'amount' => 'decimal:2', 'paid_at' => 'datetime'];
    }

    public function member(): BelongsTo       { return $this->belongsTo(Member::class); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Website/ExternalCollectionTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_09_000002_create_external_collections.php app/Models/ExternalCollection.php tests/Feature/Website/ExternalCollectionTest.php
git commit -m "feat(website-sync): external_collections idempotent staging table"
```

---

## Task 3: Member mirror (external_user_id + MemberMirrorService)

**Files:**
- Create: `database/migrations/2026_07_09_000004_add_external_user_id_to_members.php`
- Create: `app/Services/Website/MemberMirrorService.php`
- Modify: `app/Models/Member.php` (add `external_user_id` to `$fillable`)
- Test: `tests/Feature/Website/MemberMirrorServiceTest.php`

**Interfaces:**
- Consumes: `Member`, `Customer` models.
- Produces: `MemberMirrorService::upsert(array $record): Member` — matches on `external_user_id`, creates/updates the mirror + a linked AR `Customer`. `MemberMirrorService::genericCustomer(): Customer` — the "Website Collections" catch-all.

- [ ] **Step 1: Write the failing test**

```php
// tests/Feature/Website/MemberMirrorServiceTest.php
<?php
declare(strict_types=1);

use App\Models\Member;
use App\Services\Website\MemberMirrorService;

it('creates a member mirror keyed by external_user_id', function () {
    $svc = app(MemberMirrorService::class);
    $m = $svc->upsert([
        'external_user_id' => 4821, 'member_number' => 'CIHRM/2021/00456', 'student_number' => null,
        'user_type' => 'member', 'class' => 'full', 'status' => 'active',
        'name' => 'Ama Mensah', 'email' => 'ama@example.com', 'phone' => '0244000000',
    ]);
    expect((int) $m->external_user_id)->toBe(4821)
        ->and($m->customer_id)->not->toBeNull();
});

it('updates an existing mirror instead of duplicating', function () {
    $svc = app(MemberMirrorService::class);
    $svc->upsert(['external_user_id' => 4821, 'name' => 'Old', 'email' => 'a@x.com', 'user_type' => 'member', 'class' => 'full', 'status' => 'active']);
    $svc->upsert(['external_user_id' => 4821, 'name' => 'New', 'email' => 'a@x.com', 'user_type' => 'member', 'class' => 'full', 'status' => 'active']);
    expect(Member::where('external_user_id', 4821)->count())->toBe(1)
        ->and(Member::where('external_user_id', 4821)->first()->name)->toBe('New');
});

it('provides a single generic Website Collections customer', function () {
    $svc = app(MemberMirrorService::class);
    $a = $svc->genericCustomer();
    $b = $svc->genericCustomer();
    expect($a->id)->toBe($b->id);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/Website/MemberMirrorServiceTest.php`
Expected: FAIL — service/column missing.

- [ ] **Step 3: Write the migration**

```php
// database/migrations/2026_07_09_000004_add_external_user_id_to_members.php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->unsignedBigInteger('external_user_id')->nullable()->unique()->after('id');
            $table->string('student_no')->nullable()->after('member_no');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['external_user_id', 'student_no']);
        });
    }
};
```

- [ ] **Step 4: Add `external_user_id` and `student_no` to `Member::$fillable`** in `app/Models/Member.php`.

- [ ] **Step 5: Write the service**

```php
// app/Services/Website/MemberMirrorService.php
<?php
declare(strict_types=1);

namespace App\Services\Website;

use App\Models\Customer;
use App\Models\Member;

class MemberMirrorService
{
    /** Upsert a member mirror from a website feed record, keyed by external_user_id. */
    public function upsert(array $r): Member
    {
        $member = Member::firstOrNew(['external_user_id' => $r['external_user_id']]);

        $member->fill([
            'member_no'  => $r['member_number'] ?? $member->member_no ?? ('WEB-'.$r['external_user_id']),
            'student_no' => $r['student_number'] ?? $member->student_no,
            'class'      => $r['class']  ?? $member->class,
            'status'     => $r['status'] ?? $member->status,
            'name'       => $r['name']   ?? $member->name,
            'email'      => $r['email']  ?? $member->email,
            'phone'      => $r['phone']  ?? $member->phone,
        ]);

        if (! $member->customer_id) {
            $member->customer_id = Customer::create([
                'name'  => $member->name ?? 'Member '.$r['external_user_id'],
                'email' => $member->email,
                'phone' => $member->phone,
            ])->id;
        }

        $member->save();

        return $member;
    }

    /** The catch-all customer for collections with no matched member. */
    public function genericCustomer(): Customer
    {
        return Customer::firstOrCreate(
            ['name' => 'Website Collections'],
            ['email' => null, 'phone' => null],
        );
    }
}
```

> Before writing, run `head -60 app/Models/Member.php` and `head -40 app/Models/Customer.php` to confirm `member_no`, `class`, `status`, `customer_id` and `Customer`'s creatable columns. Adjust field names to the real schema — the `MemberClass`/`MemberStatus` enums may require enum values (e.g. `'full'`) that match the casts.

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Website/MemberMirrorServiceTest.php`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_09_000004_add_external_user_id_to_members.php app/Models/Member.php app/Services/Website/MemberMirrorService.php tests/Feature/Website/MemberMirrorServiceTest.php
git commit -m "feat(website-sync): member mirror keyed by external_user_id"
```

---

## Task 4: Collection ingestion + GL posting (the heart)

**Files:**
- Modify: `app/Enums/JournalSourceType.php`, `tests/Unit/Finance/EnumsF2Test.php`
- Create: `app/Services/Website/CollectionIngestionService.php`
- Test: `tests/Feature/Website/CollectionIngestionServiceTest.php`

**Interfaces:**
- Consumes: `FeeGlMapping::forCode()`, `PostingService::post()`, `ExternalCollection`, `Member` (direct lookup by `external_user_id`).
- Produces: `CollectionIngestionService::ingest(array $record): ExternalCollection` — idempotent by `(source, external_ref)`; posts `DR clearing / CR income` (or `CR 2400` if deferred) via `JournalSourceType::WebsiteCollection`, purpose `'collection'`, source_id = the `ExternalCollection` id; parks `unmapped`/`error` fail-soft; returns the staged row.

- [ ] **Step 1: Add the enum case.** In `app/Enums/JournalSourceType.php` add `case WebsiteCollection = 'website_collection';` and its label `self::WebsiteCollection => 'Website Collection',`.

- [ ] **Step 2: Extend the enum snapshot test.** In `tests/Unit/Finance/EnumsF2Test.php`, add `'website_collection'` to the `toEqualCanonicalizing([...])` array in the "JournalSourceType exposes ..." test (mirrors how `back_pay` was added).

- [ ] **Step 3: Write the failing test**

```php
// tests/Feature/Website/CollectionIngestionServiceTest.php
<?php
declare(strict_types=1);

use App\Enums\JournalSourceType;
use App\Models\ExternalCollection;
use App\Models\JournalEntry;
use App\Services\Website\CollectionIngestionService;

beforeEach(function () {
    (new \Database\Seeders\CihrmChartOfAccountsSeeder())->run();
    (new \Database\Seeders\GlAccountBalanceSeeder())->run();
    (new \Database\Seeders\PostingAccountSeeder())->run();
    (new \Database\Seeders\FeeGlMappingSeeder())->run();
});

function wsRecord(array $over = []): array {
    return array_merge([
        'source' => 'payment_record', 'source_id' => 7, 'external_ref' => 'PR-7',
        'external_user_id' => null, 'payer_name' => 'X', 'payer_email' => null, 'payer_phone' => null,
        'fee_code' => 'exam', 'amount' => 200.00, 'currency' => 'GHS',
        'paid_at' => '2026-07-05T10:00:00Z', 'method' => 'cash', 'gateway_ref' => null, 'meta' => [],
    ], $over);
}

it('posts a non-deferred collection DR clearing / CR income', function () {
    $c = app(CollectionIngestionService::class)->ingest(wsRecord());

    expect($c->status)->toBe(ExternalCollection::STATUS_POSTED)
        ->and($c->journal_entry_id)->not->toBeNull();

    $entry = JournalEntry::with('lines')->find($c->journal_entry_id);
    $dr = round((float) $entry->lines->sum('debit_amount'), 2);
    $cr = round((float) $entry->lines->sum('credit_amount'), 2);
    expect($dr)->toBe(200.00)->and($cr)->toBe(200.00)
        ->and($entry->source_type)->toBe(JournalSourceType::WebsiteCollection->value);
});

it('credits deferred income 2400 for a subscription', function () {
    $c = app(CollectionIngestionService::class)->ingest(wsRecord([
        'source' => 'member_fee_payment', 'external_ref' => 'TXN-9', 'fee_code' => 'member.subscription', 'amount' => 350,
    ]));
    $entry = JournalEntry::with('lines.glAccount')->find($c->journal_entry_id);
    $creditedCodes = $entry->lines->where('credit_amount', '>', 0)->pluck('glAccount.code');
    expect($creditedCodes)->toContain('2400');
});

it('is idempotent on (source, external_ref)', function () {
    $svc = app(CollectionIngestionService::class);
    $a = $svc->ingest(wsRecord());
    $b = $svc->ingest(wsRecord());
    expect($b->id)->toBe($a->id)
        ->and(ExternalCollection::where('external_ref', 'PR-7')->count())->toBe(1)
        ->and(JournalEntry::where('source_type', JournalSourceType::WebsiteCollection->value)->count())->toBe(1);
});

it('parks an unmapped fee code without posting', function () {
    $c = app(CollectionIngestionService::class)->ingest(wsRecord(['fee_code' => 'mystery.fee', 'external_ref' => 'PR-8']));
    expect($c->status)->toBe(ExternalCollection::STATUS_UNMAPPED)
        ->and($c->journal_entry_id)->toBeNull();
});

it('parks a non-GHS collection as error', function () {
    $c = app(CollectionIngestionService::class)->ingest(wsRecord(['currency' => 'USD', 'external_ref' => 'PR-9']));
    expect($c->status)->toBe(ExternalCollection::STATUS_ERROR);
});
```

- [ ] **Step 4: Run it to verify it fails**

Run: `php artisan test tests/Feature/Website/CollectionIngestionServiceTest.php`
Expected: FAIL — service not found.

- [ ] **Step 5: Write the service**

```php
// app/Services/Website/CollectionIngestionService.php
<?php
declare(strict_types=1);

namespace App\Services\Website;

use App\Enums\JournalSourceType;
use App\Models\ExternalCollection;
use App\Models\FeeGlMapping;
use App\Models\Member;
use App\Services\Finance\PostingService;
use App\Services\Finance\Posting\PostingDocument;
use App\Services\Finance\Posting\PostingLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CollectionIngestionService
{
    // Cash-basis posting hits clearing + income only (no customer on the JE), so
    // the member is resolved by a direct lookup for drill-down; member_id stays
    // null when unmatched. The AR-side generic-customer fallback lives in
    // MemberMirrorService for a future AR-based posting model.
    public function __construct(
        private readonly PostingService $posting,
    ) {}

    /** Stage + post one normalized collection. Idempotent, fail-soft. */
    public function ingest(array $r): ExternalCollection
    {
        $existing = ExternalCollection::where('source', $r['source'])
            ->where('external_ref', $r['external_ref'])->first();
        if ($existing && $existing->status === ExternalCollection::STATUS_POSTED) {
            return $existing;
        }

        $memberId = $r['external_user_id']
            ? Member::where('external_user_id', $r['external_user_id'])->value('id')
            : null;

        $collection = $existing ?? new ExternalCollection();
        $collection->fill([
            'source' => $r['source'], 'source_id' => $r['source_id'], 'external_ref' => $r['external_ref'],
            'external_user_id' => $r['external_user_id'] ?? null, 'member_id' => $memberId,
            'fee_code' => $r['fee_code'], 'amount' => $r['amount'], 'currency' => $r['currency'],
            'paid_at' => CarbonImmutable::parse($r['paid_at']), 'method' => $r['method'] ?? null,
            'gateway_ref' => $r['gateway_ref'] ?? null, 'payload' => $r,
        ]);

        if (strtoupper((string) $r['currency']) !== 'GHS') {
            return $this->park($collection, ExternalCollection::STATUS_ERROR, "Unsupported currency {$r['currency']}");
        }

        $mapping = FeeGlMapping::forCode($r['fee_code']);
        if (! $mapping) {
            return $this->park($collection, ExternalCollection::STATUS_UNMAPPED, "No GL mapping for {$r['fee_code']}");
        }

        return DB::transaction(function () use ($collection, $mapping, $r) {
            $collection->status = ExternalCollection::STATUS_POSTED;
            $collection->status_note = null;
            $collection->save(); // need the id for the posting source

            $creditAccountId = $mapping->is_deferred
                ? $mapping->deferred_gl_account_id
                : $mapping->income_gl_account_id;

            $doc = new PostingDocument(
                sourceType: JournalSourceType::WebsiteCollection,
                sourceId: $collection->id,
                purpose: 'collection',
                date: CarbonImmutable::parse($r['paid_at'])->toDateString(),
                narration: "Website collection {$r['external_ref']} ({$r['fee_code']})",
                lines: [
                    PostingLine::debit(amount: (float) $r['amount'], accountId: $mapping->clearing_gl_account_id, narration: 'Collections clearing'),
                    PostingLine::credit(amount: (float) $r['amount'], accountId: $creditAccountId, narration: $mapping->label),
                ],
            );

            $entry = $this->posting->post($doc);
            $collection->journal_entry_id = $entry->id;
            $collection->save();

            return $collection;
        });
    }

    private function park(ExternalCollection $c, string $status, string $note): ExternalCollection
    {
        $c->status = $status;
        $c->status_note = $note;
        $c->journal_entry_id = null;
        $c->save();

        return $c;
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Website/CollectionIngestionServiceTest.php tests/Unit/Finance/EnumsF2Test.php`
Expected: PASS (5 + existing enum tests).

- [ ] **Step 7: Commit**

```bash
git add app/Enums/JournalSourceType.php tests/Unit/Finance/EnumsF2Test.php app/Services/Website/CollectionIngestionService.php tests/Feature/Website/CollectionIngestionServiceTest.php
git commit -m "feat(website-sync): collection ingestion + cash-basis GL posting"
```

---

## Task 5: Feed client interface, HTTP implementation, and fake

**Files:**
- Create: `app/Services/Website/WebsiteFeedClient.php` (interface)
- Create: `app/Services/Website/HttpWebsiteFeedClient.php`
- Create: `tests/Support/FakeWebsiteFeedClient.php`
- Modify: `config/services.php`
- Test: `tests/Feature/Website/HttpWebsiteFeedClientTest.php`

**Interfaces:**
- Produces: `WebsiteFeedClient` with `members(?string $since, ?int $cursor, int $limit = 200): array` and `collections(?string $since, ?int $cursor, int $limit = 200): array`, each returning `['data' => array<array>, 'next_cursor' => ?int]`.

- [ ] **Step 1: Write the failing test**

```php
// tests/Feature/Website/HttpWebsiteFeedClientTest.php
<?php
declare(strict_types=1);

use App\Services\Website\HttpWebsiteFeedClient;
use Illuminate\Support\Facades\Http;

it('calls the collections endpoint with token + params and parses the page', function () {
    config()->set('services.cihrm_website.url', 'https://site.test');
    config()->set('services.cihrm_website.token', 'secret-token');

    Http::fake(['site.test/api/finance-sync/collections*' => Http::response([
        'data' => [['source' => 'exam', 'external_ref' => 'PR-1']],
        'next_cursor' => 42,
    ])]);

    $page = app(HttpWebsiteFeedClient::class)->collections(since: '2026-07-01T00:00:00Z', cursor: null, limit: 200);

    expect($page['data'])->toHaveCount(1)->and($page['next_cursor'])->toBe(42);
    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/api/finance-sync/collections')
        && $req->hasHeader('Authorization', 'Bearer secret-token')
        && str_contains($req->url(), 'since=2026-07-01'));
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/Website/HttpWebsiteFeedClientTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the interface**

```php
// app/Services/Website/WebsiteFeedClient.php
<?php
declare(strict_types=1);

namespace App\Services\Website;

interface WebsiteFeedClient
{
    /** @return array{data: array<int, array>, next_cursor: ?int} */
    public function members(?string $since, ?int $cursor, int $limit = 200): array;

    /** @return array{data: array<int, array>, next_cursor: ?int} */
    public function collections(?string $since, ?int $cursor, int $limit = 200): array;
}
```

- [ ] **Step 4: Write the HTTP implementation**

```php
// app/Services/Website/HttpWebsiteFeedClient.php
<?php
declare(strict_types=1);

namespace App\Services\Website;

use Illuminate\Support\Facades\Http;

class HttpWebsiteFeedClient implements WebsiteFeedClient
{
    public function members(?string $since, ?int $cursor, int $limit = 200): array
    {
        return $this->get('members', $since, $cursor, $limit);
    }

    public function collections(?string $since, ?int $cursor, int $limit = 200): array
    {
        return $this->get('collections', $since, $cursor, $limit);
    }

    private function get(string $path, ?string $since, ?int $cursor, int $limit): array
    {
        $base  = rtrim((string) config('services.cihrm_website.url'), '/');
        $token = (string) config('services.cihrm_website.token');

        $resp = Http::withToken($token)->acceptJson()->timeout(30)
            ->get("{$base}/api/finance-sync/{$path}", array_filter([
                'since' => $since, 'cursor' => $cursor, 'limit' => $limit,
            ], fn ($v) => $v !== null))
            ->throw()->json();

        return ['data' => $resp['data'] ?? [], 'next_cursor' => $resp['next_cursor'] ?? null];
    }
}
```

- [ ] **Step 5: Add config** in `config/services.php`:

```php
'cihrm_website' => [
    'url'   => env('WEBSITE_SYNC_URL'),
    'token' => env('WEBSITE_SYNC_TOKEN'),
],
```

- [ ] **Step 6: Write the fake** (used by Task 6):

```php
// tests/Support/FakeWebsiteFeedClient.php
<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Services\Website\WebsiteFeedClient;

class FakeWebsiteFeedClient implements WebsiteFeedClient
{
    /** @param array<int,array> $memberPages @param array<int,array> $collectionPages */
    public function __construct(
        public array $members = [],
        public array $collections = [],
    ) {}

    public function members(?string $since, ?int $cursor, int $limit = 200): array
    {
        return ['data' => $this->members, 'next_cursor' => null];
    }

    public function collections(?string $since, ?int $cursor, int $limit = 200): array
    {
        return ['data' => $this->collections, 'next_cursor' => null];
    }
}
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Website/HttpWebsiteFeedClientTest.php`
Expected: PASS (1 test).

- [ ] **Step 8: Commit**

```bash
git add app/Services/Website/WebsiteFeedClient.php app/Services/Website/HttpWebsiteFeedClient.php tests/Support/FakeWebsiteFeedClient.php config/services.php tests/Feature/Website/HttpWebsiteFeedClientTest.php
git commit -m "feat(website-sync): feed client interface + HTTP impl + fake"
```

---

## Task 6: WebsiteSyncService orchestrator + watermark

**Files:**
- Create: `database/migrations/2026_07_09_000003_create_sync_state.php`
- Create: `app/Models/SyncState.php`
- Create: `app/Services/Website/WebsiteSyncService.php`
- Test: `tests/Feature/Website/WebsiteSyncServiceTest.php`

**Interfaces:**
- Consumes: `WebsiteFeedClient`, `MemberMirrorService`, `CollectionIngestionService`.
- Produces: `WebsiteSyncService::sync(): array` returning a report `['members' => int, 'pulled' => int, 'posted' => int, 'unmapped' => int, 'error' => int, 'flagged' => int, 'skipped' => int]`; advances per-feed watermarks in `sync_state`.

- [ ] **Step 1: Write the failing test**

```php
// tests/Feature/Website/WebsiteSyncServiceTest.php
<?php
declare(strict_types=1);

use App\Models\ExternalCollection;
use App\Models\Member;
use App\Services\Website\WebsiteFeedClient;
use App\Services\Website\WebsiteSyncService;
use Tests\Support\FakeWebsiteFeedClient;

beforeEach(function () {
    (new \Database\Seeders\CihrmChartOfAccountsSeeder())->run();
    (new \Database\Seeders\GlAccountBalanceSeeder())->run();
    (new \Database\Seeders\PostingAccountSeeder())->run();
    (new \Database\Seeders\FeeGlMappingSeeder())->run();
});

it('mirrors members and posts collections, returning a report', function () {
    $fake = new FakeWebsiteFeedClient(
        members: [[
            'external_user_id' => 4821, 'member_number' => 'M-1', 'student_number' => null,
            'user_type' => 'member', 'class' => 'full', 'status' => 'active',
            'name' => 'Ama', 'email' => 'a@x.com', 'phone' => '024',
        ]],
        collections: [
            ['source' => 'member_fee_payment', 'source_id' => 1, 'external_ref' => 'TXN-1', 'external_user_id' => 4821,
             'payer_name' => 'Ama', 'fee_code' => 'member.subscription', 'amount' => 350, 'currency' => 'GHS',
             'paid_at' => '2026-07-05T10:00:00Z', 'method' => 'momo', 'gateway_ref' => 'h1', 'meta' => []],
            ['source' => 'payment_record', 'source_id' => 2, 'external_ref' => 'PR-2', 'external_user_id' => null,
             'payer_name' => 'Y', 'fee_code' => 'mystery', 'amount' => 50, 'currency' => 'GHS',
             'paid_at' => '2026-07-05T11:00:00Z', 'method' => 'cash', 'gateway_ref' => null, 'meta' => []],
        ],
    );
    app()->instance(WebsiteFeedClient::class, $fake);

    $report = app(WebsiteSyncService::class)->sync();

    expect($report['members'])->toBe(1)
        ->and($report['posted'])->toBe(1)
        ->and($report['unmapped'])->toBe(1)
        ->and(Member::where('external_user_id', 4821)->exists())->toBeTrue()
        ->and(ExternalCollection::where('status', 'posted')->count())->toBe(1);
});

it('does not double-post on a second sync', function () {
    $fake = new FakeWebsiteFeedClient(collections: [
        ['source' => 'payment_record', 'source_id' => 2, 'external_ref' => 'PR-2', 'external_user_id' => null,
         'payer_name' => 'Y', 'fee_code' => 'exam', 'amount' => 200, 'currency' => 'GHS',
         'paid_at' => '2026-07-05T11:00:00Z', 'method' => 'cash', 'gateway_ref' => null, 'meta' => []],
    ]);
    app()->instance(WebsiteFeedClient::class, $fake);

    app(WebsiteSyncService::class)->sync();
    app(WebsiteSyncService::class)->sync();

    expect(ExternalCollection::where('external_ref', 'PR-2')->count())->toBe(1);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/Website/WebsiteSyncServiceTest.php`
Expected: FAIL — service not found.

- [ ] **Step 3: Write the migration**

```php
// database/migrations/2026_07_09_000003_create_sync_state.php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_state', function (Blueprint $table) {
            $table->id();
            $table->string('feed')->unique();          // 'members' | 'collections'
            $table->string('watermark')->nullable();   // ISO8601 of last processed row
            $table->unsignedBigInteger('last_cursor')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_state');
    }
};
```

- [ ] **Step 4: Write the model**

```php
// app/Models/SyncState.php
<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncState extends Model
{
    protected $table = 'sync_state';
    protected $fillable = ['feed', 'watermark', 'last_cursor', 'last_run_at'];
    protected function casts(): array { return ['last_run_at' => 'datetime']; }

    public static function for(string $feed): self
    {
        return static::firstOrCreate(['feed' => $feed]);
    }
}
```

- [ ] **Step 5: Write the orchestrator**

```php
// app/Services/Website/WebsiteSyncService.php
<?php
declare(strict_types=1);

namespace App\Services\Website;

use App\Models\ExternalCollection;
use App\Models\SyncState;

class WebsiteSyncService
{
    public function __construct(
        private readonly WebsiteFeedClient $client,
        private readonly MemberMirrorService $mirror,
        private readonly CollectionIngestionService $ingestion,
    ) {}

    public function sync(): array
    {
        $report = ['members' => 0, 'pulled' => 0, 'posted' => 0, 'unmapped' => 0, 'error' => 0, 'flagged' => 0, 'skipped' => 0];

        // 1. Members
        $mState = SyncState::for('members');
        $cursor = null;
        do {
            $page = $this->client->members($mState->watermark, $cursor, 200);
            foreach ($page['data'] as $rec) {
                $this->mirror->upsert($rec);
                $report['members']++;
            }
            $cursor = $page['next_cursor'];
        } while ($cursor !== null);
        $mState->update(['last_run_at' => now()]);

        // 2. Collections
        $cState = SyncState::for('collections');
        $cursor = null;
        do {
            $page = $this->client->collections($cState->watermark, $cursor, 200);
            foreach ($page['data'] as $rec) {
                $report['pulled']++;
                $before = ExternalCollection::where('source', $rec['source'])
                    ->where('external_ref', $rec['external_ref'])
                    ->where('status', ExternalCollection::STATUS_POSTED)->exists();

                $c = $this->ingestion->ingest($rec);

                if ($before) { $report['skipped']++; continue; }
                match ($c->status) {
                    ExternalCollection::STATUS_POSTED   => $report['posted']++,
                    ExternalCollection::STATUS_UNMAPPED => $report['unmapped']++,
                    ExternalCollection::STATUS_ERROR    => $report['error']++,
                    ExternalCollection::STATUS_FLAGGED  => $report['flagged']++,
                    default => null,
                };
            }
            $cursor = $page['next_cursor'];
        } while ($cursor !== null);
        $cState->update(['last_run_at' => now()]);

        return $report;
    }
}
```

> Watermark advancement is intentionally conservative in v1: `since` stays null and idempotency (`external_collections` uniqueness) prevents re-posting, so a full re-pull is safe and simple. A later optimization can set `watermark` to the max `paid_at` once volume warrants it — leave a `// TODO(perf)` only if you implement the naive version; do not leave the watermark write unimplemented silently.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Website/WebsiteSyncServiceTest.php`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_09_000003_create_sync_state.php app/Models/SyncState.php app/Services/Website/WebsiteSyncService.php tests/Feature/Website/WebsiteSyncServiceTest.php
git commit -m "feat(website-sync): sync orchestrator + watermark state"
```

---

## Task 7: Scheduled console command

**Files:**
- Create: `app/Console/Commands/SyncWebsiteCollections.php`
- Modify: `routes/console.php` (bind the real client + schedule)
- Test: `tests/Feature/Website/SyncCommandTest.php`

**Interfaces:**
- Consumes: `WebsiteSyncService`.
- Produces: artisan command `sync:website-collections`.

- [ ] **Step 1: Write the failing test**

```php
// tests/Feature/Website/SyncCommandTest.php
<?php
declare(strict_types=1);

use App\Services\Website\WebsiteFeedClient;
use Tests\Support\FakeWebsiteFeedClient;

beforeEach(function () {
    (new \Database\Seeders\CihrmChartOfAccountsSeeder())->run();
    (new \Database\Seeders\GlAccountBalanceSeeder())->run();
    (new \Database\Seeders\PostingAccountSeeder())->run();
    (new \Database\Seeders\FeeGlMappingSeeder())->run();
});

it('runs the sync and reports counts', function () {
    app()->instance(WebsiteFeedClient::class, new FakeWebsiteFeedClient(collections: [
        ['source' => 'payment_record', 'source_id' => 2, 'external_ref' => 'PR-2', 'external_user_id' => null,
         'payer_name' => 'Y', 'fee_code' => 'exam', 'amount' => 200, 'currency' => 'GHS',
         'paid_at' => '2026-07-05T11:00:00Z', 'method' => 'cash', 'gateway_ref' => null, 'meta' => []],
    ]));

    $this->artisan('sync:website-collections')
        ->assertExitCode(0)
        ->expectsOutputToContain('posted: 1');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/Website/SyncCommandTest.php`
Expected: FAIL — command not registered.

- [ ] **Step 3: Write the command**

```php
// app/Console/Commands/SyncWebsiteCollections.php
<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Website\WebsiteSyncService;
use Illuminate\Console\Command;

class SyncWebsiteCollections extends Command
{
    protected $signature = 'sync:website-collections';
    protected $description = 'Pull verified fee collections from cihrm_website and post them to the GL.';

    public function handle(WebsiteSyncService $sync): int
    {
        $r = $sync->sync();
        $this->info(sprintf(
            'Website sync complete — members: %d, pulled: %d, posted: %d, unmapped: %d, error: %d, flagged: %d, skipped: %d',
            $r['members'], $r['pulled'], $r['posted'], $r['unmapped'], $r['error'], $r['flagged'], $r['skipped'],
        ));

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Bind the real client + schedule** in `routes/console.php`:

```php
use App\Services\Website\HttpWebsiteFeedClient;
use App\Services\Website\WebsiteFeedClient;
use Illuminate\Support\Facades\Schedule;

app()->bindIf(WebsiteFeedClient::class, HttpWebsiteFeedClient::class);

Schedule::command('sync:website-collections')->dailyAt('01:30')->withoutOverlapping();
```

> `bindIf` (not `bind`) so tests that pre-bind a `FakeWebsiteFeedClient` win. Verify whether this project schedules in `routes/console.php` or `app/Console/Kernel.php` (`ls app/Console/Kernel.php`) and place the `Schedule` call accordingly.

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Website/SyncCommandTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/SyncWebsiteCollections.php routes/console.php tests/Feature/Website/SyncCommandTest.php
git commit -m "feat(website-sync): scheduled sync:website-collections command"
```

---

## Task 8: Reconciliation dashboard

**Files:**
- Create: `app/Http/Controllers/Finance/CollectionReconciliationController.php`
- Create: `resources/js/Pages/Finance/Reconciliation/Index.vue`
- Modify: `routes/web.php`
- Test: `tests/Feature/Website/ReconciliationDashboardTest.php`

**Interfaces:**
- Consumes: `ExternalCollection`.
- Produces: route `finance.reconciliation` → Inertia page `Finance/Reconciliation/Index` with `summary` (per fee_code: collected, posted, and unresolved counts) and `unresolved` (the worklist of unmapped/error/flagged rows).

- [ ] **Step 1: Write the failing test**

```php
// tests/Feature/Website/ReconciliationDashboardTest.php
<?php
declare(strict_types=1);

use App\Models\ExternalCollection;
use App\Models\User;

it('renders the reconciliation dashboard with summary + unresolved worklist', function () {
    ExternalCollection::create(['source' => 's', 'source_id' => 1, 'external_ref' => 'A', 'fee_code' => 'exam',
        'amount' => 200, 'currency' => 'GHS', 'paid_at' => now(), 'status' => 'posted']);
    ExternalCollection::create(['source' => 's', 'source_id' => 2, 'external_ref' => 'B', 'fee_code' => 'mystery',
        'amount' => 50, 'currency' => 'GHS', 'paid_at' => now(), 'status' => 'unmapped', 'status_note' => 'no map']);

    $user = User::factory()->create(['role' => 'finance_officer', 'permissions' => ['finance.reports']]);

    $this->actingAs($user)->get(route('finance.reconciliation'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/Reconciliation/Index')
            ->has('summary')
            ->has('unresolved', 1));
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/Website/ReconciliationDashboardTest.php`
Expected: FAIL — route/controller missing.

- [ ] **Step 3: Write the controller**

```php
// app/Http/Controllers/Finance/CollectionReconciliationController.php
<?php
declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\ExternalCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CollectionReconciliationController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->hasPermission('finance.reports'), 403);

        $summary = ExternalCollection::query()
            ->select('fee_code',
                DB::raw("sum(amount) as collected"),
                DB::raw("sum(case when status = 'posted' then amount else 0 end) as posted"),
                DB::raw("sum(case when status <> 'posted' then 1 else 0 end) as unresolved_count"))
            ->groupBy('fee_code')->orderBy('fee_code')->get();

        $unresolved = ExternalCollection::query()
            ->whereIn('status', [ExternalCollection::STATUS_UNMAPPED, ExternalCollection::STATUS_ERROR, ExternalCollection::STATUS_FLAGGED])
            ->latest('paid_at')->limit(200)
            ->get(['id', 'source', 'external_ref', 'fee_code', 'amount', 'status', 'status_note', 'paid_at']);

        return Inertia::render('Finance/Reconciliation/Index', [
            'summary'      => $summary,
            'unresolved'   => $unresolved,
            'activeModule' => 'finance',
        ]);
    }
}
```

- [ ] **Step 4: Add the route** in `routes/web.php` (inside the authenticated group, near other finance report routes):

```php
Route::get('/finance/reconciliation', [\App\Http\Controllers\Finance\CollectionReconciliationController::class, 'index'])
    ->middleware('permission:finance.reports')->name('finance.reconciliation');
```

- [ ] **Step 5: Write the Vue page** — a stat/summary table + an "unresolved" worklist. Follow the existing Finance report page conventions (see `resources/js/Pages/Finance/Reports/FinancialActivities.vue` for the layout idiom):

```vue
<script setup>
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
defineOptions({ layout: AuthenticatedLayout });
defineProps({ summary: { type: Array, default: () => [] }, unresolved: { type: Array, default: () => [] }, activeModule: String });
const ghs = (v) => 'GHS ' + (Number(v) || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
</script>

<template>
    <Head title="Collections Reconciliation" />
    <div class="p-6 max-w-5xl mx-auto space-y-6">
        <header>
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">Finance</p>
            <h1 class="text-2xl font-black text-primary">Collections Reconciliation</h1>
            <p class="text-sm text-on-surface-variant mt-1">Website fee collections ingested into the ledger — collected vs posted, and anything unresolved.</p>
        </header>

        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
            <table class="w-full text-sm">
                <thead class="text-on-surface-variant text-[10px] uppercase bg-surface-container-low/20"><tr>
                    <th class="text-left p-3">Fee code</th><th class="text-right p-3">Collected</th>
                    <th class="text-right p-3">Posted</th><th class="text-right p-3">Unresolved</th>
                </tr></thead>
                <tbody class="divide-y divide-outline-variant/30">
                    <tr v-for="s in summary" :key="s.fee_code">
                        <td class="p-3 font-bold text-primary">{{ s.fee_code }}</td>
                        <td class="p-3 text-right tabular-nums">{{ ghs(s.collected) }}</td>
                        <td class="p-3 text-right tabular-nums">{{ ghs(s.posted) }}</td>
                        <td class="p-3 text-right" :class="Number(s.unresolved_count) ? 'text-rose-600 font-bold' : 'text-on-surface-variant'">{{ s.unresolved_count }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="unresolved.length" class="rounded-2xl border border-rose-300/60 bg-rose-50/40 overflow-hidden">
            <div class="px-5 py-3 border-b border-rose-200/60"><h2 class="text-sm font-black uppercase tracking-wide text-rose-700">Unresolved — accountant worklist</h2></div>
            <table class="w-full text-sm">
                <thead class="text-on-surface-variant text-[10px] uppercase"><tr>
                    <th class="text-left p-3">Ref</th><th class="text-left p-3">Fee code</th><th class="text-right p-3">Amount</th>
                    <th class="text-left p-3">Status</th><th class="text-left p-3">Note</th>
                </tr></thead>
                <tbody class="divide-y divide-outline-variant/30">
                    <tr v-for="u in unresolved" :key="u.id">
                        <td class="p-3 font-mono text-xs">{{ u.external_ref }}</td>
                        <td class="p-3">{{ u.fee_code }}</td>
                        <td class="p-3 text-right tabular-nums">{{ ghs(u.amount) }}</td>
                        <td class="p-3 capitalize">{{ u.status }}</td>
                        <td class="p-3 text-xs text-on-surface-variant">{{ u.status_note }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
```

- [ ] **Step 6: Build the frontend + run the test**

Run: `npx vite build && php artisan test tests/Feature/Website/ReconciliationDashboardTest.php`
Expected: build OK; PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Finance/CollectionReconciliationController.php resources/js/Pages/Finance/Reconciliation/Index.vue routes/web.php public/build tests/Feature/Website/ReconciliationDashboardTest.php
git commit -m "feat(website-sync): collections reconciliation dashboard"
```

> `public/build` is gitignored; the `git add public/build` will no-op — that's expected, drop it from the command if it warns.

---

## Task 9: Full-suite gate

- [ ] **Step 1: Run the whole suite**

Run: `php artisan test`
Expected: all green (previous baseline 1496 + the new Website tests). Fix any regression before finishing.

- [ ] **Step 2: Add a route-integrity check** — confirm `finance.reconciliation` binds (it is covered by `RouteIntegrityTest` if present; run `php artisan test tests/Feature/RouteIntegrityTest.php`).

- [ ] **Step 3: Final commit if anything changed.**

---

## Appendix — Companion plan: website read API (repo `cihrm_website`, executed separately)

This runs in the live `cihrm_website` Laravel 11 repo, not here. It is intentionally deferred to its own execution because it cannot be tested from this repo and touches a production app. It is additive only (no schema/behaviour change).

- **A1.** Add a `finance-sync` Sanctum token/ability for an mvp service account (a seeder or an artisan one-off that prints the token once).
- **A2.** `routes/api.php`: `Route::middleware('auth:sanctum')->prefix('finance-sync')->group(...)` with `GET members` and `GET collections`.
- **A3.** `MembersFeedController` — query `members` + `students` joined to `users`, emit the normalized member record (Component 1 of the spec), cursor-paginated by `users.id`, filtered by `updated_at >= since`.
- **A4.** `CollectionsFeedController` + a `CollectionNormalizer` that UNIONs the settled rows of each surface into the normalized shape:
  - `member_fee_payments` where `status='completed' AND payment_verified=true` → `member.{fee_type}` (`combined` → `member.combined`).
  - `student_payments` where `status='completed' AND payment_verified=true` → `student.{fee_type}`.
  - `payment_records` where `status='approved'` → `{fee_type}` (`exam_fee`→`exam`, else `student.{fee_type}`), `external_ref='PR-'+id`.
  - `conference_event_registrations` / `exhibitor_registrations` / `transcript_applications` where `payment_status='paid'` → `conference`/`exhibitor`/`transcript`.
  - `premium_fees` / generic `payments` per their paid flag → `premium`.
- **A5.** Contract tests in that repo: only settled rows; stable `external_ref`; token required; pagination complete.
- **A6.** In mvp, set `WEBSITE_SYNC_URL` + `WEBSITE_SYNC_TOKEN` and run `php artisan sync:website-collections` against one real day; confirm the reconciliation dashboard ties out.

The mvp tests pin the exact contract (`FakeWebsiteFeedClient` records), so A3–A4 must emit that shape verbatim.

---

## P2 (later, separate plan) — deferred income recognition

Once the D work (`RevenueRecognitionService` + `revenue_recognition_schedules`) exists, extend `CollectionIngestionService` so a deferred posting also creates a recognition schedule (`recognition_months`, `start_date = paid_at`), and schedule the monthly recognition run. Until then, subscriptions correctly sit in `2400` and are recognised manually.
