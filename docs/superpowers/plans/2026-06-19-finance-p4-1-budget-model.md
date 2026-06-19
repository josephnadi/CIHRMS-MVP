# Finance Phase 4 — P4-1: Budget Model + Entry/Approval

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let finance enter and approve an annual budget — one budget per fiscal year holding an annual amount per GL account, with a draft → approved lifecycle and an admin UI — so P4-2 can report budget vs actuals.

**Architecture:** `budgets` (one per fiscal year, draft/approved) + `budget_lines` (annual_amount per GL account). A `BudgetService` owns the lifecycle (get-or-create the year's draft, upsert a line, approve, revert). A permission-gated admin page enters amounts per account and approves. Reuses `FiscalCalendarService` (P2-1) and the Enum→FormRequest→Service→Resource pattern.

**Tech Stack:** Laravel 13, Inertia + Vue 3, Pest.

## Global Constraints

- Enum → FormRequest → Service → Resource for new modules.
- DB-backed permissions; per-user JSON `permissions` column for test grants.
- Every form/date input carries an `aria-label` (the `AccessibilityAuditorTest` gate fails otherwise).
- `declare(strict_types=1)` on new PHP classes; `casts()` method form (Laravel 11+).

**This is P4-1 of Phase 4.** P4-2 (Budget vs Actuals report) and P4-3 (soft controls) follow.

**Spec:** `docs/superpowers/specs/2026-06-19-finance-budgeting-design.md`

---

### Task 1: Budget data model (enum + tables + models)

**Files:**
- Create: `app/Enums/BudgetStatus.php`
- Create: `database/migrations/2026_06_19_000001_create_budgets.php`
- Create: `database/migrations/2026_06_19_000002_create_budget_lines.php`
- Create: `app/Models/Budget.php`
- Create: `app/Models/BudgetLine.php`
- Test: `tests/Feature/Finance/BudgetModelTest.php`

**Interfaces:**
- Produces: `BudgetStatus::Draft|Approved` (string-backed, `->value` = 'draft'|'approved', `->label()`); `Budget` (fillable `fiscal_year_id,status,approved_by,approved_at`; `status` cast to `BudgetStatus`; `lines()` hasMany, `fiscalYear()`, `approver()` belongsTo); `BudgetLine` (fillable `budget_id,gl_account_id,annual_amount`; `annual_amount` cast `decimal:2`; `budget()`, `glAccount()` belongsTo).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\BudgetStatus;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\FiscalYear;
use App\Models\GlAccount;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(fn () => (new ChartOfAccountsSeeder())->run());

it('stores a budget with lines and casts status', function () {
    $fy = FiscalYear::create(['year' => 2026, 'status' => 'open', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
    $budget = Budget::create(['fiscal_year_id' => $fy->id, 'status' => 'draft']);
    $line = BudgetLine::create(['budget_id' => $budget->id, 'gl_account_id' => GlAccount::where('code', '5100')->value('id'), 'annual_amount' => 120000]);

    expect($budget->fresh()->status)->toBe(BudgetStatus::Draft)
        ->and((float) $line->fresh()->annual_amount)->toBe(120000.0)
        ->and($budget->lines()->count())->toBe(1)
        ->and($budget->fiscalYear->year)->toBe(2026)
        ->and(BudgetStatus::Approved->label())->toBe('Approved');
});

it('enforces one budget per fiscal year and one line per account', function () {
    $fy = FiscalYear::create(['year' => 2027, 'status' => 'open', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31']);
    Budget::create(['fiscal_year_id' => $fy->id, 'status' => 'draft']);
    expect(fn () => Budget::create(['fiscal_year_id' => $fy->id, 'status' => 'draft']))
        ->toThrow(Illuminate\Database\QueryException::class);

    $budget = Budget::where('fiscal_year_id', $fy->id)->first();
    $acc = GlAccount::where('code', '5100')->value('id');
    BudgetLine::create(['budget_id' => $budget->id, 'gl_account_id' => $acc, 'annual_amount' => 100]);
    expect(fn () => BudgetLine::create(['budget_id' => $budget->id, 'gl_account_id' => $acc, 'annual_amount' => 200]))
        ->toThrow(Illuminate\Database\QueryException::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/BudgetModelTest.php`
Expected: FAIL — enum/tables/models missing.

- [ ] **Step 3: Write the enum**

`app/Enums/BudgetStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum BudgetStatus: string
{
    case Draft    = 'draft';
    case Approved = 'approved';

    public function label(): string
    {
        return match ($this) {
            self::Draft    => 'Draft',
            self::Approved => 'Approved',
        };
    }
}
```

- [ ] **Step 4: Write the migrations**

`database/migrations/2026_06_19_000001_create_budgets.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->unique()->constrained('fiscal_years')->cascadeOnDelete();
            $table->string('status', 20)->default('draft')->index();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
```

`database/migrations/2026_06_19_000002_create_budget_lines.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained('budgets')->cascadeOnDelete();
            $table->foreignId('gl_account_id')->constrained('gl_accounts')->restrictOnDelete();
            $table->decimal('annual_amount', 18, 2)->default(0);
            $table->timestamps();
            $table->unique(['budget_id', 'gl_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_lines');
    }
};
```

- [ ] **Step 5: Write the models**

`app/Models/Budget.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BudgetStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Budget extends Model
{
    protected $table = 'budgets';

    protected $fillable = ['fiscal_year_id', 'status', 'approved_by', 'approved_at'];

    protected function casts(): array
    {
        return [
            'status'      => BudgetStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class, 'budget_id');
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
```

`app/Models/BudgetLine.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetLine extends Model
{
    protected $table = 'budget_lines';

    protected $fillable = ['budget_id', 'gl_account_id', 'annual_amount'];

    protected function casts(): array
    {
        return ['annual_amount' => 'decimal:2'];
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class, 'budget_id');
    }

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'gl_account_id');
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/BudgetModelTest.php`
Expected: PASS (both).

- [ ] **Step 7: Commit**

```bash
git add app/Enums/BudgetStatus.php database/migrations/2026_06_19_000001_create_budgets.php database/migrations/2026_06_19_000002_create_budget_lines.php app/Models/Budget.php app/Models/BudgetLine.php tests/Feature/Finance/BudgetModelTest.php
git commit -m "feat(finance): budget + budget_line model (one budget per fiscal year)"
```

---

### Task 2: BudgetService (lifecycle)

**Files:**
- Create: `app/Services/Finance/BudgetService.php`
- Test: `tests/Feature/Finance/BudgetServiceTest.php`

**Interfaces:**
- Consumes: `FiscalCalendarService::ensureYear(int): FiscalYear` (P2-1); `Budget`, `BudgetLine`, `BudgetStatus`, `GlAccount`, `User`.
- Produces: `BudgetService::forYear(int $year): Budget` (get-or-create draft); `setLine(Budget, GlAccount, float $annualAmount): BudgetLine` (upsert; throws `DomainException` if budget Approved); `approve(Budget, User): Budget` (throws if already approved); `revertToDraft(Budget): Budget`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\BudgetStatus;
use App\Models\GlAccount;
use App\Models\User;
use App\Services\Finance\BudgetService;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(fn () => (new ChartOfAccountsSeeder())->run());

it('gets or creates one draft budget per year and upserts lines', function () {
    $svc = app(BudgetService::class);
    $acc = GlAccount::where('code', '5100')->firstOrFail();

    $budget = $svc->forYear(2026);
    expect($budget->status)->toBe(BudgetStatus::Draft)
        ->and($svc->forYear(2026)->id)->toBe($budget->id); // get-or-create, no dup

    $svc->setLine($budget, $acc, 120000);
    $svc->setLine($budget->fresh(), $acc, 150000); // upsert
    expect($budget->lines()->count())->toBe(1)
        ->and((float) $budget->lines()->first()->annual_amount)->toBe(150000.0);
});

it('approves, blocks edits while approved, and reverts to draft', function () {
    $svc = app(BudgetService::class);
    $acc = GlAccount::where('code', '5100')->firstOrFail();
    $by = User::factory()->create();

    $budget = $svc->forYear(2026);
    $svc->setLine($budget, $acc, 100);
    $approved = $svc->approve($budget->fresh(), $by);

    expect($approved->status)->toBe(BudgetStatus::Approved)
        ->and($approved->approved_by)->toBe($by->id);

    // cannot edit while approved
    expect(fn () => $svc->setLine($approved, $acc, 200))->toThrow(DomainException::class);
    // cannot re-approve
    expect(fn () => $svc->approve($approved, $by))->toThrow(DomainException::class);

    $draft = $svc->revertToDraft($approved);
    expect($draft->status)->toBe(BudgetStatus::Draft)
        ->and($draft->approved_by)->toBeNull();
    $svc->setLine($draft, $acc, 200); // editable again
    expect((float) $draft->lines()->first()->annual_amount)->toBe(200.0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/BudgetServiceTest.php`
Expected: FAIL — service missing.

- [ ] **Step 3: Write the service**

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\BudgetStatus;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\GlAccount;
use App\Models\User;
use DomainException;

/**
 * Annual budget lifecycle: one budget per fiscal year, draft → approved.
 * A draft is editable; an approved budget is frozen until reverted to draft.
 */
class BudgetService
{
    public function __construct(private readonly FiscalCalendarService $calendar)
    {
    }

    /** Get-or-create the draft budget for a fiscal year (ensures the year exists). */
    public function forYear(int $year): Budget
    {
        $fiscalYear = $this->calendar->ensureYear($year);

        return Budget::firstOrCreate(
            ['fiscal_year_id' => $fiscalYear->id],
            ['status' => BudgetStatus::Draft->value],
        );
    }

    /** Upsert one account's annual budget. Only allowed while the budget is Draft. */
    public function setLine(Budget $budget, GlAccount $account, float $annualAmount): BudgetLine
    {
        if ($budget->status !== BudgetStatus::Draft) {
            throw new DomainException('Cannot edit an approved budget; revert it to draft first.');
        }

        return BudgetLine::updateOrCreate(
            ['budget_id' => $budget->id, 'gl_account_id' => $account->id],
            ['annual_amount' => round($annualAmount, 2)],
        );
    }

    public function approve(Budget $budget, User $by): Budget
    {
        if ($budget->status === BudgetStatus::Approved) {
            throw new DomainException('Budget is already approved.');
        }

        $budget->update([
            'status'      => BudgetStatus::Approved->value,
            'approved_by' => $by->id,
            'approved_at' => now(),
        ]);

        return $budget->fresh();
    }

    public function revertToDraft(Budget $budget): Budget
    {
        $budget->update([
            'status'      => BudgetStatus::Draft->value,
            'approved_by' => null,
            'approved_at' => null,
        ]);

        return $budget->fresh();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/BudgetServiceTest.php`
Expected: PASS (both).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Finance/BudgetService.php tests/Feature/Finance/BudgetServiceTest.php
git commit -m "feat(finance): BudgetService (forYear/setLine/approve/revertToDraft)"
```

---

### Task 3: finance.budget.manage permission

**Files:**
- Modify: `database/seeders/RolePermissionSeeder.php`
- Test: `tests/Feature/Finance/BudgetPermissionTest.php`

**Interfaces:**
- Produces: permission slug `finance.budget.manage`, granted to `finance_officer`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(fn () => (new RolePermissionSeeder())->run());

it('grants finance.budget.manage to finance_officer, not employee', function () {
    expect(User::factory()->create(['role' => 'finance_officer'])->hasPermission('finance.budget.manage'))->toBeTrue()
        ->and(User::factory()->create(['role' => 'employee'])->hasPermission('finance.budget.manage'))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/BudgetPermissionTest.php`
Expected: FAIL — permission absent.

- [ ] **Step 3: Declare + grant the permission**

In `database/seeders/RolePermissionSeeder.php`, inside `private const PERMISSIONS`, add (after the `finance.reports.view` entry):

```php
        'finance.budget.manage' => ['Finance', 'Create / edit / approve annual budgets'],
```

In the `'finance_officer' => [` role array, add (after `'finance.reports.view',`):

```php
            'finance.budget.manage',
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/BudgetPermissionTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/seeders/RolePermissionSeeder.php tests/Feature/Finance/BudgetPermissionTest.php
git commit -m "feat(finance): finance.budget.manage permission"
```

---

### Task 4: Budgets admin endpoints + Vue page

**Files:**
- Create: `app/Http/Requests/Finance/StoreBudgetLineRequest.php`
- Create: `app/Http/Controllers/Finance/BudgetController.php`
- Modify: `routes/web.php`
- Create: `resources/js/Pages/Finance/Budgets/Index.vue`
- Modify: `resources/js/Pages/Finance/Hub.vue`
- Test: `tests/Feature/Finance/BudgetEndpointTest.php`

**Interfaces:**
- Consumes: `BudgetService` (Task 2); `finance.budget.manage` (Task 3); `Budget`, `BudgetLine`, `GlAccount`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\BudgetStatus;
use App\Models\Budget;
use App\Models\GlAccount;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
});

it('finance_officer can view the budgets admin page', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u)->get('/finance/budgets?year=2026')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/Budgets/Index')->where('budget.status', 'draft'));
});

it('employee is forbidden from budgets', function () {
    $u = User::factory()->create(['role' => 'employee']);
    $this->actingAs($u)->get('/finance/budgets')->assertForbidden();
});

it('upserts a budget line, approves, blocks edits, then reverts', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $acc = GlAccount::where('code', '5100')->firstOrFail();

    $this->actingAs($u)->post('/finance/budgets/line', ['year' => 2026, 'gl_account_id' => $acc->id, 'annual_amount' => 120000])->assertRedirect();
    $budget = Budget::firstOrFail();
    expect((float) $budget->lines()->first()->annual_amount)->toBe(120000.0);

    $this->actingAs($u)->post('/finance/budgets/approve', ['year' => 2026])->assertRedirect();
    expect($budget->fresh()->status)->toBe(BudgetStatus::Approved);

    // editing an approved budget is rejected (validation error from the caught DomainException)
    $this->actingAs($u)->post('/finance/budgets/line', ['year' => 2026, 'gl_account_id' => $acc->id, 'annual_amount' => 1])
        ->assertSessionHasErrors();

    $this->actingAs($u)->post('/finance/budgets/revert', ['year' => 2026])->assertRedirect();
    expect($budget->fresh()->status)->toBe(BudgetStatus::Draft);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/BudgetEndpointTest.php`
Expected: FAIL — routes/controller/page missing.

- [ ] **Step 3: Write the FormRequest**

`app/Http/Requests/Finance/StoreBudgetLineRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class StoreBudgetLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('finance.budget.manage') === true;
    }

    public function rules(): array
    {
        return [
            'year'          => ['required', 'integer', 'min:2000', 'max:2100'],
            'gl_account_id' => ['required', 'integer', 'exists:gl_accounts,id'],
            'annual_amount' => ['required', 'numeric', 'min:0'],
        ];
    }
}
```

- [ ] **Step 4: Write the controller**

`app/Http/Controllers/Finance/BudgetController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreBudgetLineRequest;
use App\Models\Budget;
use App\Models\GlAccount;
use App\Services\Finance\BudgetService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BudgetController extends Controller
{
    public function __construct(private readonly BudgetService $budgets)
    {
    }

    public function index(Request $request): Response
    {
        $year   = (int) ($request->query('year') ?: now()->format('Y'));
        $budget = $this->budgets->forYear($year);
        $lines  = $budget->lines()->get()->keyBy('gl_account_id');

        return Inertia::render('Finance/Budgets/Index', [
            'activeModule' => 'finance-budgets',
            'year'         => $year,
            'budget'       => ['id' => $budget->id, 'status' => $budget->status->value, 'approved_at' => $budget->approved_at?->toDateString()],
            'accounts'     => GlAccount::active()->orderBy('code')->get(['id', 'code', 'name', 'type'])
                ->map(fn ($a) => [
                    'id'            => $a->id,
                    'code'          => $a->code,
                    'name'          => $a->name,
                    'type'          => $a->type->value,
                    'annual_amount' => (float) ($lines[$a->id]->annual_amount ?? 0),
                ]),
        ]);
    }

    public function storeLine(StoreBudgetLineRequest $request): RedirectResponse
    {
        $data    = $request->validated();
        $budget  = $this->budgets->forYear((int) $data['year']);
        $account = GlAccount::findOrFail($data['gl_account_id']);

        try {
            $this->budgets->setLine($budget, $account, (float) $data['annual_amount']);
        } catch (DomainException $e) {
            return back()->withErrors(['budget' => $e->getMessage()]);
        }

        return back()->with('success', 'Budget updated.');
    }

    public function approve(Request $request): RedirectResponse
    {
        $budget = $this->budgets->forYear((int) $request->input('year', now()->format('Y')));

        try {
            $this->budgets->approve($budget, $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['budget' => $e->getMessage()]);
        }

        return back()->with('success', 'Budget approved.');
    }

    public function revert(Request $request): RedirectResponse
    {
        $budget = $this->budgets->forYear((int) $request->input('year', now()->format('Y')));
        $this->budgets->revertToDraft($budget);

        return back()->with('success', 'Budget reverted to draft.');
    }
}
```

- [ ] **Step 5: Add the routes**

In `routes/web.php`, inside the `Route::prefix('finance')->name('finance.')->group(...)` block, add (e.g. after the reports group):

```php
        // Budgets (Phase 4) — entry/approval
        Route::middleware('permission:finance.budget.manage')->group(function () {
            Route::get('budgets',          [\App\Http\Controllers\Finance\BudgetController::class, 'index'])->name('budgets.index');
            Route::post('budgets/line',    [\App\Http\Controllers\Finance\BudgetController::class, 'storeLine'])->name('budgets.line');
            Route::post('budgets/approve', [\App\Http\Controllers\Finance\BudgetController::class, 'approve'])->name('budgets.approve');
            Route::post('budgets/revert',  [\App\Http\Controllers\Finance\BudgetController::class, 'revert'])->name('budgets.revert');
        });
```

- [ ] **Step 6: Write the Vue page**

`resources/js/Pages/Finance/Budgets/Index.vue`:

```vue
<script setup>
import { ref, reactive, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    year:     { type: Number, required: true },
    budget:   { type: Object, required: true },
    accounts: { type: Array,  default: () => [] },
});

const year = ref(props.year);
const isApproved = computed(() => props.budget.status === 'approved');
const draft = reactive(Object.fromEntries(props.accounts.map((a) => [a.id, a.annual_amount])));

const gotoYear = () => router.get(route('finance.budgets.index'), { year: year.value }, { preserveState: false });

const save = (account) => router.post(route('finance.budgets.line'),
    { year: props.year, gl_account_id: account.id, annual_amount: draft[account.id] },
    { preserveScroll: true });

const approve = () => router.post(route('finance.budgets.approve'), { year: props.year }, { preserveScroll: true });
const revert  = () => router.post(route('finance.budgets.revert'),  { year: props.year }, { preserveScroll: true });
</script>

<template>
    <Head title="Budgets" />

    <div class="p-6 max-w-4xl mx-auto">
        <header class="mb-6 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-black text-primary">Annual Budget</h1>
                <p class="text-on-surface-variant text-sm mt-1">
                    Fiscal year {{ year }} ·
                    <span :class="isApproved ? 'text-emerald-300' : 'text-amber-300'" class="font-bold">{{ budget.status }}</span>
                </p>
            </div>
            <div class="flex items-end gap-3">
                <label class="text-xs font-bold text-on-surface-variant">Year
                    <input type="number" v-model.number="year" aria-label="Fiscal year" @change="gotoYear"
                           class="mt-1 block w-24 rounded-lg bg-surface-container-lowest border-outline-variant/60 text-sm text-primary" />
                </label>
                <button v-if="!isApproved" @click="approve" class="rounded-lg bg-emerald-500/20 px-3 py-2 text-sm font-bold text-emerald-300">Approve</button>
                <button v-else @click="revert" class="rounded-lg border border-outline-variant/60 px-3 py-2 text-sm font-bold text-primary">Revert to draft</button>
            </div>
        </header>

        <p v-if="isApproved" class="mb-4 text-sm text-on-surface-variant">This budget is approved and read-only. Revert to draft to edit.</p>

        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
            <table class="w-full text-sm">
                <thead class="text-on-surface-variant text-[11px] uppercase border-b border-outline-variant/40">
                    <tr><th class="text-left p-3">Code</th><th class="text-left p-3">Account</th><th class="text-left p-3">Type</th><th class="text-right p-3">Annual budget</th><th class="p-3"></th></tr>
                </thead>
                <tbody class="divide-y divide-outline-variant/30">
                    <tr v-for="a in accounts" :key="a.id">
                        <td class="p-3 font-mono text-on-surface-variant">{{ a.code }}</td>
                        <td class="p-3 text-primary">{{ a.name }}</td>
                        <td class="p-3 text-on-surface-variant">{{ a.type }}</td>
                        <td class="p-3 text-right">
                            <input type="number" step="0.01" v-model.number="draft[a.id]" :disabled="isApproved"
                                   :aria-label="`Annual budget for ${a.code}`"
                                   class="w-32 text-right rounded-lg bg-surface-container border-outline-variant/60 text-sm text-primary disabled:opacity-50" />
                        </td>
                        <td class="p-3 text-right">
                            <button v-if="!isApproved" @click="save(a)" class="text-secondary text-xs font-bold hover:underline">Save</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
```

- [ ] **Step 7: Add a Budgets link to the Finance Hub**

In `resources/js/Pages/Finance/Hub.vue`, inside the `<div class="flex gap-2">` header block (after the Reports link), add:

```vue
                <Link :href="route('finance.budgets.index')"
                      class="inline-flex items-center gap-1.5 rounded-xl border border-outline-variant/60 bg-surface-container-lowest px-3 py-2 text-[12px] font-bold text-primary hover:border-secondary/40 transition-colors">
                    <span class="material-symbols-outlined text-[16px]">account_balance_wallet</span>
                    Budgets
                </Link>
```

(`Link` is already imported in `Hub.vue`.)

- [ ] **Step 8: Build + run the test**

Run: `npm run build`
Expected: succeeds, no Vue compile errors.

Run: `php artisan test tests/Feature/Finance/BudgetEndpointTest.php`
Expected: PASS (all three).

- [ ] **Step 9: Commit**

```bash
git add app/Http/Requests/Finance/StoreBudgetLineRequest.php app/Http/Controllers/Finance/BudgetController.php routes/web.php resources/js/Pages/Finance/Budgets/Index.vue resources/js/Pages/Finance/Hub.vue tests/Feature/Finance/BudgetEndpointTest.php
git commit -m "feat(finance): budgets admin page (enter per-account annual amounts + approve/revert)"
```

---

### Task 5: Regression gate

**Files:** none (verification only).

- [ ] **Step 1: Finance + dependent suites**

Run: `php artisan test tests/Feature/Finance tests/Unit/Finance`
Expected: PASS.

- [ ] **Step 2: Full app suite**

Run: `php artisan test`
Expected: PASS — accessibility green (the year + per-account inputs carry `aria-label`). Allow only the known `KioskRecentTest` time-of-day flake if it is the sole failure.

- [ ] **Step 3: Fresh seed sanity**

Run: `php artisan migrate:fresh --seed`
Expected: completes (the new tables migrate cleanly; no seeder references budgets).

- [ ] **Step 4: Mark the gate**

```bash
git commit --allow-empty -m "test(finance): P4-1 budget model + entry/approval regression gate green"
```

---

## Self-Review notes (for the implementer)

- **One budget per fiscal year** (`unique fiscal_year_id`); `forYear()` is get-or-create so the page/endpoints never need a separate "create budget" step.
- **Approved budgets are frozen** — `setLine` throws if not Draft; the controller catches the `DomainException` and surfaces it as a redirect-back validation error (the endpoint test asserts this). Revert to draft to edit.
- **All account types** are budgetable — the page lists every active GL account; un-entered accounts simply have no line (zero budget).
- **Accessibility**: the year input and each per-account amount input carry `aria-label` (the per-account one is dynamic: `Annual budget for {code}`).
- **P4-2** will add `BudgetVsActualsReport` reading these lines + `LedgerBalanceService::activity`; **P4-3** the soft controls.
