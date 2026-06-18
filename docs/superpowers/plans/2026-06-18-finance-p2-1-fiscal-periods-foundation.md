# Finance Phase 2 — P2-1: Fiscal Periods, Posting Guard & Immutability

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the ledger period-aware and tamper-resistant: a fiscal-year/period model, a closed-period posting guard at the single `JournalPostingService::post()` choke point that stamps each entry's `fiscal_period_id`, and immutability guards that seal posted journal content (reverse-only).

**Architecture:** New `fiscal_years`/`fiscal_periods` tables + models + a `FiscalCalendarService` that generates a year's 13 periods (12 calendar months + an Adjustment period). A `PeriodResolver` maps an entry date to its period (periods 1–12 only). `JournalPostingService::post()` resolves the period, throws `ClosedPeriodException` for Closed/Locked, and stamps `fiscal_period_id` for Open — while leaving posts with **no defined period unrestricted** (so the existing test suite is unaffected; production is covered by seeding the calendar). Model `booted()` guards block edits/deletes of posted ledger content.

**Tech Stack:** Laravel 13, PHP 8.3, Pest. Builds on the Phase 1 posting engine.

**This is P2-1 of Phase 2.** P2-2 (close/reopen/lock workflow + audit + UI) and P2-3 (subledger↔GL reconciliation) follow.

**Spec:** `docs/superpowers/specs/2026-06-18-finance-fiscal-periods-design.md`

---

### Task 1: FiscalPeriodStatus enum

**Files:**
- Create: `app/Enums/FiscalPeriodStatus.php`
- Test: `tests/Unit/Finance/FiscalPeriodStatusTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\FiscalPeriodStatus;

it('exposes open/closed/locked with labels', function () {
    expect(FiscalPeriodStatus::Open->value)->toBe('open')
        ->and(FiscalPeriodStatus::Closed->value)->toBe('closed')
        ->and(FiscalPeriodStatus::Locked->value)->toBe('locked')
        ->and(FiscalPeriodStatus::Open->label())->toBe('Open')
        ->and(FiscalPeriodStatus::Locked->label())->toBe('Locked');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Finance/FiscalPeriodStatusTest.php`
Expected: FAIL — enum missing.

- [ ] **Step 3: Write the enum**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum FiscalPeriodStatus: string
{
    case Open   = 'open';
    case Closed = 'closed';
    case Locked = 'locked';

    public function label(): string
    {
        return match ($this) {
            self::Open   => 'Open',
            self::Closed => 'Closed',
            self::Locked => 'Locked',
        };
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Finance/FiscalPeriodStatusTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Enums/FiscalPeriodStatus.php tests/Unit/Finance/FiscalPeriodStatusTest.php
git commit -m "feat(finance): FiscalPeriodStatus enum"
```

---

### Task 2: fiscal_years + fiscal_periods tables + models

**Files:**
- Create: `database/migrations/2026_06_18_000001_create_fiscal_years.php`
- Create: `database/migrations/2026_06_18_000002_create_fiscal_periods.php`
- Create: `app/Models/FiscalYear.php`
- Create: `app/Models/FiscalPeriod.php`
- Test: `tests/Feature/Finance/FiscalPeriodModelTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\FiscalPeriodStatus;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;

it('stores a year with periods and casts status', function () {
    $year = FiscalYear::create([
        'year' => 2026, 'status' => 'open',
        'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31',
    ]);

    $period = FiscalPeriod::create([
        'fiscal_year_id' => $year->id, 'period_no' => 1, 'name' => 'January 2026',
        'starts_on' => '2026-01-01', 'ends_on' => '2026-01-31', 'status' => 'open',
    ]);

    expect($period->fresh()->status)->toBe(FiscalPeriodStatus::Open)
        ->and($period->fiscalYear->year)->toBe(2026)
        ->and($year->periods()->count())->toBe(1);
});

it('enforces a unique (fiscal_year_id, period_no)', function () {
    $year = FiscalYear::create(['year' => 2027, 'status' => 'open', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31']);
    FiscalPeriod::create(['fiscal_year_id' => $year->id, 'period_no' => 1, 'name' => 'Jan', 'starts_on' => '2027-01-01', 'ends_on' => '2027-01-31', 'status' => 'open']);

    expect(fn () => FiscalPeriod::create(['fiscal_year_id' => $year->id, 'period_no' => 1, 'name' => 'Dup', 'starts_on' => '2027-01-01', 'ends_on' => '2027-01-31', 'status' => 'open']))
        ->toThrow(Illuminate\Database\QueryException::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/FiscalPeriodModelTest.php`
Expected: FAIL — tables/models missing.

- [ ] **Step 3: Write the fiscal_years migration**

`database/migrations/2026_06_18_000001_create_fiscal_years.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->unique();
            $table->string('status', 20)->default('open')->index();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_years');
    }
};
```

- [ ] **Step 4: Write the fiscal_periods migration**

`database/migrations/2026_06_18_000002_create_fiscal_periods.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->cascadeOnDelete();
            $table->unsignedTinyInteger('period_no'); // 1–12 months, 13 = Adjustment
            $table->string('name', 50);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('open')->index();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['fiscal_year_id', 'period_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_periods');
    }
};
```

- [ ] **Step 5: Write the FiscalYear model**

`app/Models/FiscalYear.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FiscalPeriodStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalYear extends Model
{
    protected $table = 'fiscal_years';

    protected $fillable = ['year', 'status', 'starts_on', 'ends_on'];

    protected function casts(): array
    {
        return [
            'year'      => 'integer',
            'status'    => FiscalPeriodStatus::class,
            'starts_on' => 'date',
            'ends_on'   => 'date',
        ];
    }

    public function periods(): HasMany
    {
        return $this->hasMany(FiscalPeriod::class, 'fiscal_year_id')->orderBy('period_no');
    }
}
```

- [ ] **Step 6: Write the FiscalPeriod model**

`app/Models/FiscalPeriod.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FiscalPeriodStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalPeriod extends Model
{
    protected $table = 'fiscal_periods';

    protected $fillable = [
        'fiscal_year_id', 'period_no', 'name', 'starts_on', 'ends_on',
        'status', 'closed_at', 'closed_by', 'locked_at', 'locked_by',
    ];

    protected function casts(): array
    {
        return [
            'period_no' => 'integer',
            'status'    => FiscalPeriodStatus::class,
            'starts_on' => 'date',
            'ends_on'   => 'date',
            'closed_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/FiscalPeriodModelTest.php`
Expected: PASS (both).

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_06_18_000001_create_fiscal_years.php database/migrations/2026_06_18_000002_create_fiscal_periods.php app/Models/FiscalYear.php app/Models/FiscalPeriod.php tests/Feature/Finance/FiscalPeriodModelTest.php
git commit -m "feat(finance): fiscal_years + fiscal_periods tables and models"
```

---

### Task 3: FiscalCalendarService + seeder

Generates a calendar year's 13 periods (12 months + Adjustment), idempotently. The seeder seeds the current + next year so production always has an Open calendar.

**Files:**
- Create: `app/Services/Finance/FiscalCalendarService.php`
- Create: `database/seeders/FiscalCalendarSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/Finance/FiscalCalendarServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Services\Finance\FiscalCalendarService;

it('generates 13 periods (12 months + adjustment) for a year, idempotently', function () {
    $svc = app(FiscalCalendarService::class);

    $year = $svc->ensureYear(2026);
    expect($year->periods()->count())->toBe(13);

    $jan = FiscalPeriod::where('fiscal_year_id', $year->id)->where('period_no', 1)->firstOrFail();
    expect($jan->name)->toBe('January 2026')
        ->and($jan->starts_on->toDateString())->toBe('2026-01-01')
        ->and($jan->ends_on->toDateString())->toBe('2026-01-31');

    $dec = FiscalPeriod::where('fiscal_year_id', $year->id)->where('period_no', 12)->firstOrFail();
    expect($dec->ends_on->toDateString())->toBe('2026-12-31');

    $adj = FiscalPeriod::where('fiscal_year_id', $year->id)->where('period_no', 13)->firstOrFail();
    expect($adj->name)->toBe('Adjustment 2026');

    // Idempotent — second call does not duplicate.
    $svc->ensureYear(2026);
    expect(FiscalPeriod::where('fiscal_year_id', $year->id)->count())->toBe(13)
        ->and(FiscalYear::where('year', 2026)->count())->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/FiscalCalendarServiceTest.php`
Expected: FAIL — service missing.

- [ ] **Step 3: Write the service**

`app/Services/Finance/FiscalCalendarService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\FiscalPeriodStatus;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use Carbon\CarbonImmutable;

class FiscalCalendarService
{
    /**
     * Idempotently create a calendar fiscal year (Jan–Dec) with 12 monthly
     * periods plus a period-13 "Adjustment" bucket. All periods default Open.
     */
    public function ensureYear(int $year): FiscalYear
    {
        $fiscalYear = FiscalYear::updateOrCreate(
            ['year' => $year],
            [
                'status'    => FiscalPeriodStatus::Open->value,
                'starts_on' => sprintf('%04d-01-01', $year),
                'ends_on'   => sprintf('%04d-12-31', $year),
            ],
        );

        for ($month = 1; $month <= 12; $month++) {
            $start = CarbonImmutable::create($year, $month, 1);
            $end   = $start->endOfMonth();

            FiscalPeriod::updateOrCreate(
                ['fiscal_year_id' => $fiscalYear->id, 'period_no' => $month],
                [
                    'name'      => $start->format('F Y'),
                    'starts_on' => $start->toDateString(),
                    'ends_on'   => $end->toDateString(),
                    'status'    => FiscalPeriodStatus::Open->value,
                ],
            );
        }

        // Period 13 — Adjustment. Dated at year-end as a sentinel; it is never
        // resolved by date (PeriodResolver only considers periods 1–12).
        FiscalPeriod::updateOrCreate(
            ['fiscal_year_id' => $fiscalYear->id, 'period_no' => 13],
            [
                'name'      => "Adjustment {$year}",
                'starts_on' => sprintf('%04d-12-31', $year),
                'ends_on'   => sprintf('%04d-12-31', $year),
                'status'    => FiscalPeriodStatus::Open->value,
            ],
        );

        return $fiscalYear->fresh();
    }
}
```

- [ ] **Step 4: Write the seeder**

`database/seeders/FiscalCalendarSeeder.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Finance\FiscalCalendarService;
use Illuminate\Database\Seeder;

class FiscalCalendarSeeder extends Seeder
{
    /**
     * Seed the current and next calendar years so production always has an
     * Open fiscal calendar for incoming postings. Idempotent.
     *
     * Uses a fixed anchor year so the seed is deterministic in CI; the current
     * year is derived from the database server clock at seed time via now().
     */
    public function run(): void
    {
        $svc = app(FiscalCalendarService::class);
        $current = (int) now()->format('Y');
        $svc->ensureYear($current);
        $svc->ensureYear($current + 1);
    }
}
```

- [ ] **Step 5: Register the seeder**

In `database/seeders/DatabaseSeeder.php`, add this call immediately after the `PostingAccountSeeder` call (it has no dependency on the chart, but grouping with finance seeders is clean):

```php
        $this->call(FiscalCalendarSeeder::class);
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/FiscalCalendarServiceTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Finance/FiscalCalendarService.php database/seeders/FiscalCalendarSeeder.php database/seeders/DatabaseSeeder.php tests/Feature/Finance/FiscalCalendarServiceTest.php
git commit -m "feat(finance): FiscalCalendarService + seeder (current + next year)"
```

---

### Task 4: PeriodResolver

**Files:**
- Create: `app/Services/Finance/PeriodResolver.php`
- Test: `tests/Feature/Finance/PeriodResolverTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\FiscalPeriod;
use App\Services\Finance\FiscalCalendarService;
use App\Services\Finance\PeriodResolver;
use Carbon\CarbonImmutable;

beforeEach(fn () => app(FiscalCalendarService::class)->ensureYear(2026));

it('resolves a date to its calendar-month period', function () {
    $period = app(PeriodResolver::class)->resolveForDate('2026-06-15');
    expect($period)->toBeInstanceOf(FiscalPeriod::class)
        ->and($period->period_no)->toBe(6)
        ->and($period->name)->toBe('June 2026');
});

it('resolves Dec 31 to December (period 12), never the adjustment period 13', function () {
    $period = app(PeriodResolver::class)->resolveForDate(CarbonImmutable::create(2026, 12, 31));
    expect($period->period_no)->toBe(12);
});

it('returns null when no period is defined for the date', function () {
    expect(app(PeriodResolver::class)->resolveForDate('2099-03-03'))->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/PeriodResolverTest.php`
Expected: FAIL — resolver missing.

- [ ] **Step 3: Write the resolver**

`app/Services/Finance/PeriodResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\FiscalPeriod;
use Carbon\CarbonImmutable;
use DateTimeInterface;

class PeriodResolver
{
    /**
     * Resolve a date to its monthly fiscal period (1–12). The Adjustment
     * period (13) is never resolved by date — it is targeted explicitly.
     * Returns null when no period is defined for the date.
     */
    public function resolveForDate(DateTimeInterface|string $date): ?FiscalPeriod
    {
        $day = ($date instanceof DateTimeInterface
            ? CarbonImmutable::instance($date)
            : CarbonImmutable::parse($date))->toDateString();

        return FiscalPeriod::query()
            ->where('period_no', '<=', 12)
            ->whereDate('starts_on', '<=', $day)
            ->whereDate('ends_on', '>=', $day)
            ->first();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/PeriodResolverTest.php`
Expected: PASS (all three).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Finance/PeriodResolver.php tests/Feature/Finance/PeriodResolverTest.php
git commit -m "feat(finance): PeriodResolver (date -> fiscal period)"
```

---

### Task 5: journal_entries.fiscal_period_id column + model wiring

**Files:**
- Create: `database/migrations/2026_06_18_000003_add_fiscal_period_id_to_journal_entries.php`
- Modify: `app/Models/JournalEntry.php`
- Test: `tests/Feature/Finance/JournalEntryFiscalPeriodColumnTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\JournalSourceType;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Finance\FiscalCalendarService;

it('persists fiscal_period_id and exposes the relation', function () {
    $year = app(FiscalCalendarService::class)->ensureYear(2026);
    $jun = FiscalPeriod::where('fiscal_year_id', $year->id)->where('period_no', 6)->firstOrFail();

    $entry = JournalEntry::create([
        'reference'      => 'JE-FP-1',
        'entry_date'     => '2026-06-15',
        'narration'      => 'test',
        'status'         => 'draft',
        'source_type'    => JournalSourceType::Manual->value,
        'source_id'      => null,
        'fiscal_period_id' => $jun->id,
        'created_by'     => User::factory()->create()->id,
    ]);

    expect($entry->fresh()->fiscal_period_id)->toBe($jun->id)
        ->and($entry->fiscalPeriod->period_no)->toBe(6);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/JournalEntryFiscalPeriodColumnTest.php`
Expected: FAIL — column/relation missing.

- [ ] **Step 3: Write the migration**

`database/migrations/2026_06_18_000003_add_fiscal_period_id_to_journal_entries.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignId('fiscal_period_id')->nullable()->after('entry_date')
                ->constrained('fiscal_periods')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fiscal_period_id');
        });
    }
};
```

- [ ] **Step 4: Wire the model**

In `app/Models/JournalEntry.php`: add `'fiscal_period_id'` to `$fillable` (after `'source_purpose'`), and add the relation:

```php
    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id');
    }
```

(`BelongsTo` is already imported; add `use App\Models\FiscalPeriod;`? No — same namespace `App\Models`, no import needed.)

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/JournalEntryFiscalPeriodColumnTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_18_000003_add_fiscal_period_id_to_journal_entries.php app/Models/JournalEntry.php tests/Feature/Finance/JournalEntryFiscalPeriodColumnTest.php
git commit -m "feat(finance): add fiscal_period_id to journal_entries"
```

---

### Task 6: ClosedPeriodException + the posting guard (the heart)

**Files:**
- Create: `app/Exceptions/Finance/ClosedPeriodException.php`
- Modify: `app/Services/Finance/JournalPostingService.php`
- Test: `tests/Feature/Finance/ClosedPeriodGuardTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\FiscalPeriodStatus;
use App\Enums\JournalEntryStatus;
use App\Enums\JournalSourceType;
use App\Exceptions\Finance\ClosedPeriodException;
use App\Models\FiscalPeriod;
use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Finance\FiscalCalendarService;
use App\Services\Finance\JournalPostingService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    $this->actingAs(User::factory()->create());
});

function draftEntryDated(string $date): JournalEntry
{
    $cash = GlAccount::where('code', '1010')->firstOrFail();
    $income = GlAccount::where('code', '4100')->firstOrFail();
    $je = JournalEntry::create([
        'reference' => 'JE-GUARD-' . uniqid(), 'entry_date' => $date, 'narration' => 'guard',
        'status' => JournalEntryStatus::Draft->value, 'source_type' => JournalSourceType::Manual->value,
        'source_id' => null, 'created_by' => auth()->id(),
    ]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 1, 'gl_account_id' => $cash->id, 'debit_amount' => 50, 'credit_amount' => 0]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 2, 'gl_account_id' => $income->id, 'debit_amount' => 0, 'credit_amount' => 50]);
    return $je->fresh('lines.glAccount');
}

it('posts and stamps fiscal_period_id when the period is open', function () {
    $year = app(FiscalCalendarService::class)->ensureYear(2026);
    $jun = FiscalPeriod::where('fiscal_year_id', $year->id)->where('period_no', 6)->firstOrFail();

    $posted = app(JournalPostingService::class)->post(draftEntryDated('2026-06-15'));

    expect($posted->status)->toBe(JournalEntryStatus::Posted)
        ->and($posted->fiscal_period_id)->toBe($jun->id);
});

it('blocks posting into a closed period', function () {
    $year = app(FiscalCalendarService::class)->ensureYear(2026);
    FiscalPeriod::where('fiscal_year_id', $year->id)->where('period_no', 6)
        ->update(['status' => FiscalPeriodStatus::Closed->value]);

    expect(fn () => app(JournalPostingService::class)->post(draftEntryDated('2026-06-15')))
        ->toThrow(ClosedPeriodException::class);
});

it('blocks posting into a locked period', function () {
    $year = app(FiscalCalendarService::class)->ensureYear(2026);
    FiscalPeriod::where('fiscal_year_id', $year->id)->where('period_no', 6)
        ->update(['status' => FiscalPeriodStatus::Locked->value]);

    expect(fn () => app(JournalPostingService::class)->post(draftEntryDated('2026-06-15')))
        ->toThrow(ClosedPeriodException::class);
});

it('allows posting when no fiscal period is defined for the date (no stamp)', function () {
    // No fiscal year seeded for 2099.
    $posted = app(JournalPostingService::class)->post(draftEntryDated('2099-03-03'));

    expect($posted->status)->toBe(JournalEntryStatus::Posted)
        ->and($posted->fiscal_period_id)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/ClosedPeriodGuardTest.php`
Expected: FAIL — `ClosedPeriodException` missing / no guard, so the closed/locked cases post successfully instead of throwing.

- [ ] **Step 3: Write the exception**

`app/Exceptions/Finance/ClosedPeriodException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Finance;

use DomainException;

class ClosedPeriodException extends DomainException
{
}
```

- [ ] **Step 4: Inject PeriodResolver + add the guard**

In `app/Services/Finance/JournalPostingService.php`:

(a) Add imports:

```php
use App\Enums\FiscalPeriodStatus;
use App\Exceptions\Finance\ClosedPeriodException;
```

(`PeriodResolver` is in the same namespace — no import needed.)

(b) Add `PeriodResolver` to the constructor (it currently injects `SequenceService $sequences, PostingActorResolver $actors`):

```php
    public function __construct(
        private readonly SequenceService $sequences,
        private readonly PostingActorResolver $actors,
        private readonly PeriodResolver $periods,
    ) {
    }
```

(c) Inside `post()`'s `DB::transaction(function () use ($entry, $actor) {` closure, as the FIRST statements (before the balance loop), add the guard + stamp:

```php
            $period = $this->periods->resolveForDate($entry->entry_date);
            if ($period !== null && $period->status !== FiscalPeriodStatus::Open) {
                throw new ClosedPeriodException(
                    "Cannot post entry {$entry->reference}: fiscal period {$period->name} is {$period->status->value}."
                );
            }
            if ($period !== null) {
                $entry->fiscal_period_id = $period->id;
            }
```

(The existing `$entry->save()` later in the closure persists `fiscal_period_id`.)

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/ClosedPeriodGuardTest.php`
Expected: PASS (all four).

- [ ] **Step 6: Confirm no regression in the broad finance suite**

Run: `php artisan test tests/Feature/Finance/PostingServiceTest.php tests/Feature/Finance/JournalPostingActorTest.php`
Expected: PASS — these don't seed a fiscal calendar, so the guard finds no period and allows posting unchanged (no stamp).

- [ ] **Step 7: Commit**

```bash
git add app/Exceptions/Finance/ClosedPeriodException.php app/Services/Finance/JournalPostingService.php tests/Feature/Finance/ClosedPeriodGuardTest.php
git commit -m "feat(finance): closed-period posting guard + fiscal_period stamping"
```

---

### Task 7: Journal immutability guards

**Files:**
- Modify: `app/Models/JournalLine.php`
- Modify: `app/Models/JournalEntry.php`
- Test: `tests/Feature/Finance/JournalImmutabilityTest.php`

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
    $this->actingAs(User::factory()->create());
});

function postedEntry(): JournalEntry
{
    $cash = GlAccount::where('code', '1010')->firstOrFail();
    $income = GlAccount::where('code', '4100')->firstOrFail();
    $je = JournalEntry::create([
        'reference' => 'JE-IMM-' . uniqid(), 'entry_date' => '2026-06-15', 'narration' => 'imm',
        'status' => JournalEntryStatus::Draft->value, 'source_type' => JournalSourceType::Manual->value,
        'source_id' => null, 'created_by' => auth()->id(),
    ]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 1, 'gl_account_id' => $cash->id, 'debit_amount' => 50, 'credit_amount' => 0]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 2, 'gl_account_id' => $income->id, 'debit_amount' => 0, 'credit_amount' => 50]);
    return app(JournalPostingService::class)->post($je->fresh('lines.glAccount'));
}

it('blocks updating a line on a posted entry', function () {
    $entry = postedEntry();
    $line = $entry->lines()->first();
    expect(fn () => $line->update(['narration' => 'tampered']))->toThrow(DomainException::class);
});

it('blocks deleting a line on a posted entry', function () {
    $entry = postedEntry();
    $line = $entry->lines()->first();
    expect(fn () => $line->delete())->toThrow(DomainException::class);
});

it('blocks deleting a posted entry', function () {
    $entry = postedEntry();
    expect(fn () => $entry->delete())->toThrow(DomainException::class);
});

it('still allows editing a draft entry and its lines', function () {
    $cash = GlAccount::where('code', '1010')->firstOrFail();
    $je = JournalEntry::create([
        'reference' => 'JE-DRAFT-OK', 'entry_date' => '2026-06-15', 'narration' => 'draft',
        'status' => JournalEntryStatus::Draft->value, 'source_type' => JournalSourceType::Manual->value,
        'source_id' => null, 'created_by' => auth()->id(),
    ]);
    $line = JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 1, 'gl_account_id' => $cash->id, 'debit_amount' => 50, 'credit_amount' => 0]);

    $line->update(['debit_amount' => 75]);   // allowed on a draft
    expect((float) $line->fresh()->debit_amount)->toBe(75.0);

    $je->delete();                            // draft delete allowed
    expect(JournalEntry::find($je->id))->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/JournalImmutabilityTest.php`
Expected: FAIL — no guards, so the update/delete cases succeed instead of throwing.

- [ ] **Step 3: Add the JournalLine guards**

In `app/Models/JournalLine.php`, the `booted()` method already registers a `saving` guard. Add `updating` and `deleting` guards inside `booted()`:

```php
        static::updating(function (self $line) {
            if ($line->entry && $line->entry->status !== \App\Enums\JournalEntryStatus::Draft) {
                throw new DomainException('Cannot modify a journal line on a posted entry; reverse the entry instead.');
            }
        });

        static::deleting(function (self $line) {
            if ($line->entry && $line->entry->status !== \App\Enums\JournalEntryStatus::Draft) {
                throw new DomainException('Cannot delete a journal line on a posted entry; reverse the entry instead.');
            }
        });
```

(`$line->entry` is the existing `belongsTo(JournalEntry::class, 'journal_entry_id')` relation. `DomainException` is already imported in this model.)

- [ ] **Step 4: Add the JournalEntry guard**

In `app/Models/JournalEntry.php`, add a `booted()` method (the model currently has none):

```php
    protected static function booted(): void
    {
        static::deleting(function (self $entry) {
            if (in_array($entry->status, [JournalEntryStatus::Posted, JournalEntryStatus::Reversed], true)) {
                throw new \DomainException('Cannot delete a posted or reversed journal entry; reverse it instead.');
            }
        });
    }
```

(`JournalEntryStatus` is already imported.)

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/JournalImmutabilityTest.php`
Expected: PASS (all four).

- [ ] **Step 6: Confirm reversal still works (it creates a NEW draft entry + lines, then posts; it does not edit posted lines)**

Run: `php artisan test tests/Feature/Finance/PostingServiceTest.php tests/Feature/Finance/JournalPostingActorTest.php`
Expected: PASS — the reversal path marks the original `Reversed` (a header status update, not a line edit or entry delete) and posts a fresh reversal entry; neither guard fires.

- [ ] **Step 7: Commit**

```bash
git add app/Models/JournalLine.php app/Models/JournalEntry.php tests/Feature/Finance/JournalImmutabilityTest.php
git commit -m "feat(finance): journal immutability guards (seal posted entries + lines)"
```

---

### Task 8: Regression gate

**Files:** none (verification only).

- [ ] **Step 1: Full finance + dependent suites**

Run: `php artisan test tests/Feature/Finance tests/Unit/Finance tests/Feature/Disbursement tests/Feature/Payroll tests/Feature/Loans`
Expected: PASS — the guard allows posting when no period is defined (existing tests seed no calendar), immutability fires only on posted entries, and reversal is unaffected. If a test that posts AND seeds a fiscal calendar now hits the guard unexpectedly, investigate (it should only block Closed/Locked periods).

- [ ] **Step 2: Full app suite + fresh seed**

Run: `php artisan test`
Expected: PASS (allowing the known time-of-day `KioskRecentTest` flake if it is the only failure).

Run: `php artisan migrate:fresh --seed`
Expected: completes; verify the calendar seeded:

Run: `php artisan tinker --execute="echo App\Models\FiscalPeriod::count();"`
Expected: prints `26` (current year + next year × 13 periods).

- [ ] **Step 3: Mark the gate**

```bash
git commit --allow-empty -m "test(finance): P2-1 fiscal-periods foundation regression gate green"
```

---

## Self-Review notes (for the implementer)

- **The "no period → allow" rule is load-bearing for keeping the suite green.** The guard only throws when a period EXISTS and is Closed/Locked. Existing tests seed no calendar, so they post freely and `fiscal_period_id` is null — that's expected.
- **Period 13 (Adjustment) is never date-resolved** — `PeriodResolver` filters `period_no <= 12`. A Dec 31 entry resolves to December (period 12).
- **Immutability allows the legitimate transitions.** `post()` flips Draft→Posted (header update, not a line edit) and `reverse()` flips Posted→Reversed (header update) + creates a NEW draft reversal entry — neither trips the line/entry guards. The guards only block editing/deleting *content* of already-posted entries.
- **The guard runs inside the post transaction**, so a blocked post rolls back the whole business event (e.g. a payroll approval dated into a closed period fails wholesale) — matching the spec's atomicity requirement.
- **Audit recording of close/reopen/blocked-posting is P2-2**, not here — P2-1 just enforces the guard and throws.
