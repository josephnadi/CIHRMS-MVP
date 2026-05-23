# Finance F4-R — Paystack Refund Operator Flow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an operator-driven Paystack refund flow for F4 payment intents: call Paystack `/refund`, reverse the F3 AR receipt via the existing `void()`, record refund audit on the intent, handle async `refund.processed` webhook.

**Architecture:** `RefundService` orchestrates a `DB::transaction` that calls `PaystackGatewayService::refundTransaction()` (new), then `ArReceiptService::void()` (unchanged), then updates `PaymentIntent` audit columns. `JournalPostingService` is unmodified — JE reversal flows transitively through `void()`. New `refund.processed` webhook handler stamps `refund_settled_at` when Paystack confirms async settlement.

**Tech Stack:** Laravel 13.7, PHP 8.3, Eloquent, Pest, Inertia + Vue 3. Same Paystack HTTP client chain as F4. No new tables — six nullable columns added to `payment_intents`.

**Spec reference:** [docs/superpowers/specs/2026-05-23-finance-f4r-paystack-refund-design.md](../specs/2026-05-23-finance-f4r-paystack-refund-design.md)

**Branch:** `feat/finance-f4r-paystack-refund` (off main `b0bb8d3`)

---

## File Structure

### New files

```
app/Services/Finance/
    RefundService.php

app/Http/Requests/Finance/
    StoreRefundRequest.php

app/Http/Controllers/Finance/
    RefundController.php

database/migrations/
    2026_06_05_000001_add_refund_columns_to_payment_intents.php

tests/Unit/Finance/
    EnumsF4RTest.php

tests/Feature/Finance/
    F4RMigrationTest.php
    F4RPermissionsSeedTest.php
    RefundServiceTest.php
    RefundEndpointTest.php
```

### Modified files

```
app/Enums/PaymentIntentStatus.php                    -- add Refunded case
app/Models/PaymentIntent.php                          -- add refund_* to $fillable + casts + scopeRefundable
app/Models/User.php                                   -- grant gateway.refund to finance_officer
app/Services/Finance/PaystackGatewayService.php       -- add refundTransaction()
app/Services/Finance/PaystackWebhookProcessor.php     -- add handleRefundProcessed() + match arm
database/seeders/RolePermissionSeeder.php             -- grant gateway.refund to finance_officer
routes/web.php                                        -- POST /finance/payment-intents/{intent}/refund
resources/js/Pages/Finance/PaymentIntents/Show.vue    -- DOES NOT EXIST in F4 (F4 only has Index.vue with focusIntent)
                                                       -- Add the Refund button to Index.vue's row UI instead
resources/js/Pages/Finance/PaymentIntents/Index.vue   -- Refund button + modal
resources/js/Pages/Finance/ArInvoices/Show.vue        -- "Refund Paystack payment" button when intent in success
tests/Feature/Finance/PaystackGatewayServiceTest.php  -- extend with refundTransaction() cases
tests/Feature/Finance/PaystackWebhookProcessorTest.php -- extend with refund.processed cases
```

### Responsibility boundaries

- **`PaystackGatewayService::refundTransaction()`** — pure HTTP wrapper. Pesewas conversion. No business logic.
- **`RefundService::refund()`** — orchestration. Validates state, transactional Paystack call + receipt void + intent update.
- **`PaystackWebhookProcessor::handleRefundProcessed()`** — pure handler. Locates intent by `refund_paystack_ref`, stamps `refund_settled_at`. Idempotent.
- **`RefundController::store()`** — thin: delegates to `RefundService`.

---

## Task 1: PaymentIntentStatus::Refunded enum case

**Files:**
- Modify: `app/Enums/PaymentIntentStatus.php`
- Test: `tests/Unit/Finance/EnumsF4RTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Finance/EnumsF4RTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\PaymentIntentStatus;

it('PaymentIntentStatus includes Refunded case for F4-R', function () {
    $values = array_map(fn ($c) => $c->value, PaymentIntentStatus::cases());
    expect($values)->toContain('refunded');
});

it('PaymentIntentStatus::Refunded has a non-empty label', function () {
    expect(PaymentIntentStatus::Refunded->label())->toBeString()->not->toBeEmpty();
});
```

- [ ] **Step 2: Run test — must FAIL**

```
php artisan test --filter=EnumsF4RTest
```

- [ ] **Step 3: Add the enum case**

Open `app/Enums/PaymentIntentStatus.php`. After `case Expired = 'expired';`, add:

```php
    case Refunded  = 'refunded';
```

And in the `match` inside `label()`, add (preserve alignment):

```php
            self::Refunded  => 'Refunded',
```

- [ ] **Step 4: Run test — must PASS**

```
php artisan test --filter=EnumsF4RTest
```

Expected: 2 tests pass.

- [ ] **Step 5: Commit**

```
git add app/Enums/PaymentIntentStatus.php tests/Unit/Finance/EnumsF4RTest.php
git commit -m "$(cat <<'EOF'
feat(finance): add PaymentIntentStatus::Refunded case (F4-R)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Migration — refund audit columns on payment_intents

**Files:**
- Create: `database/migrations/2026_06_05_000001_add_refund_columns_to_payment_intents.php`
- Test: `tests/Feature/Finance/F4RMigrationTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/F4RMigrationTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('adds refund audit columns to payment_intents', function () {
    expect(Schema::hasColumns('payment_intents', [
        'refunded_at', 'refund_amount', 'refund_reason',
        'refund_paystack_ref', 'refund_settled_at', 'refunded_by',
    ]))->toBeTrue();
});
```

- [ ] **Step 2: Run test — must FAIL**

```
php artisan test --filter=F4RMigrationTest
```

- [ ] **Step 3: Create the migration**

`database/migrations/2026_06_05_000001_add_refund_columns_to_payment_intents.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F4-R: refund audit columns. Set when an operator initiates a refund
 * (refunded_at / refund_amount / refund_reason / refund_paystack_ref /
 * refunded_by) and when Paystack confirms settlement via the
 * refund.processed webhook (refund_settled_at).
 *
 * One refund per intent in F4-R; partial / multiple refunds are
 * explicitly out of scope and would need their own table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_intents', function (Blueprint $table) {
            $table->timestamp('refunded_at')->nullable()->after('paid_at');
            $table->decimal('refund_amount', 18, 2)->nullable()->after('refunded_at');
            $table->string('refund_reason', 500)->nullable()->after('refund_amount');
            $table->string('refund_paystack_ref', 100)->nullable()->after('refund_reason');
            $table->timestamp('refund_settled_at')->nullable()->after('refund_paystack_ref');
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete()->after('refund_settled_at');

            $table->index('refund_paystack_ref');
        });
    }

    public function down(): void
    {
        Schema::table('payment_intents', function (Blueprint $table) {
            $table->dropForeign(['refunded_by']);
            $table->dropIndex(['refund_paystack_ref']);
            $table->dropColumn([
                'refunded_at', 'refund_amount', 'refund_reason',
                'refund_paystack_ref', 'refund_settled_at', 'refunded_by',
            ]);
        });
    }
};
```

- [ ] **Step 4: Run test — must PASS**

```
php artisan test --filter=F4RMigrationTest
```

Expected: 1 test passes.

- [ ] **Step 5: Commit**

```
git add database/migrations/2026_06_05_000001_add_refund_columns_to_payment_intents.php tests/Feature/Finance/F4RMigrationTest.php
git commit -m "$(cat <<'EOF'
feat(finance): F4-R schema — refund audit columns on payment_intents

Six nullable columns: refunded_at, refund_amount, refund_reason,
refund_paystack_ref (indexed — webhook lookup), refund_settled_at,
refunded_by.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: PaymentIntent model — fillable + casts + scopes

**Files:**
- Modify: `app/Models/PaymentIntent.php`
- Test: (covered by RefundServiceTest in Task 6)

- [ ] **Step 1: Add the new fields to `$fillable`**

Open `app/Models/PaymentIntent.php`. Append to the existing `$fillable` array:

```php
        'refunded_at', 'refund_amount', 'refund_reason',
        'refund_paystack_ref', 'refund_settled_at', 'refunded_by',
```

- [ ] **Step 2: Add casts for the new timestamp / decimal columns**

In the existing `casts()` method, append:

```php
            'refunded_at'       => 'datetime',
            'refund_amount'     => 'decimal:2',
            'refund_settled_at' => 'datetime',
```

- [ ] **Step 3: Add a `refunder` BelongsTo relation**

After the existing `creator()` relation method, add:

```php
    public function refunder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refunded_by');
    }
```

- [ ] **Step 4: Add `scopeRefundable` to the model**

After the existing `scopeStale()` method, add:

```php
    public function scopeRefundable(Builder $q): Builder
    {
        return $q->where('status', PaymentIntentStatus::Success->value)
                 ->whereNull('refunded_at');
    }
```

- [ ] **Step 5: Sanity-check tests still pass**

```
php artisan test --filter='F4ModelsTest|F4RMigrationTest'
```

Expected: all pass (F4 model tests should not regress).

- [ ] **Step 6: Commit**

```
git add app/Models/PaymentIntent.php
git commit -m "$(cat <<'EOF'
feat(finance): extend PaymentIntent for F4-R refund audit

Adds 6 columns to \$fillable, casts for the timestamp+decimal fields,
refunder() relation, scopeRefundable() that filters to success+not-yet-
refunded.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Grant gateway.refund to finance_officer

**Files:**
- Modify: `database/seeders/RolePermissionSeeder.php`
- Modify: `app/Models/User.php` (`ROLE_PERMISSIONS` constant)
- Test: `tests/Feature/Finance/F4RPermissionsSeedTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/F4RPermissionsSeedTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
});

it('grants gateway.refund to finance_officer', function () {
    $role = Role::where('slug', 'finance_officer')->firstOrFail();
    $slugs = $role->permissions()->pluck('slug')->all();

    expect($slugs)->toContain('gateway.refund');
});

it('auditor still does NOT have gateway.refund', function () {
    $role = Role::where('slug', 'auditor')->firstOrFail();
    $slugs = $role->permissions()->pluck('slug')->all();

    expect($slugs)->not->toContain('gateway.refund');
});

it('legacy ROLE_PERMISSIONS lock-step for finance_officer', function () {
    expect(User::ROLE_PERMISSIONS['finance_officer'])->toContain('gateway.refund');
});
```

- [ ] **Step 2: Run test — must FAIL**

```
php artisan test --filter=F4RPermissionsSeedTest
```

- [ ] **Step 3: Update `RolePermissionSeeder`**

Open `database/seeders/RolePermissionSeeder.php`. Find the `'finance_officer'` block and the existing F4 line:

```php
            // F4 — Paystack Gateway (no refund — super_admin only)
            'gateway.view', 'gateway.create',
```

Replace that comment + line with:

```php
            // F4 — Paystack Gateway
            'gateway.view', 'gateway.create', 'gateway.refund',
```

- [ ] **Step 4: Mirror in `User::ROLE_PERMISSIONS`**

Open `app/Models/User.php`. Find the same F4 block under `'finance_officer'`:

```php
            // F4 — Paystack Gateway (no refund — super_admin only)
            'gateway.view', 'gateway.create',
```

Replace with:

```php
            // F4 — Paystack Gateway
            'gateway.view', 'gateway.create', 'gateway.refund',
```

- [ ] **Step 5: Run test — must PASS**

```
php artisan test --filter=F4RPermissionsSeedTest
```

Expected: 3 tests pass.

- [ ] **Step 6: Commit**

```
git add database/seeders/RolePermissionSeeder.php app/Models/User.php tests/Feature/Finance/F4RPermissionsSeedTest.php
git commit -m "$(cat <<'EOF'
feat(finance): grant gateway.refund to finance_officer (F4-R)

Closes the F4 deferral. auditor remains view-only.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: PaystackGatewayService::refundTransaction()

**Files:**
- Modify: `app/Services/Finance/PaystackGatewayService.php`
- Modify: `tests/Feature/Finance/PaystackGatewayServiceTest.php`

- [ ] **Step 1: Append failing tests to the existing test file**

Open `tests/Feature/Finance/PaystackGatewayServiceTest.php`. Append at the end of the file (after the closing of the last `it()` block):

```php
it('refundTransaction converts GHS to pesewas and returns refund data', function () {
    Http::fake([
        'api.paystack.co/refund' => Http::response([
            'status' => true,
            'data' => [
                'id'           => 99888777,
                'transaction'  => ['reference' => 'pst_ref_001'],
                'amount'       => 25050,
                'currency'     => 'GHS',
                'status'       => 'pending',
                'refunded_at'  => null,
                'merchant_note'=> 'Customer cancelled',
            ],
        ], 200),
    ]);

    $result = $this->svc->refundTransaction('pst_ref_001', 250.50, 'Customer cancelled');

    expect($result['id'])->toBe(99888777);
    expect($result['status'])->toBe('pending');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.paystack.co/refund'
            && $request['transaction']   === 'pst_ref_001'
            && $request['amount']        === 25050              // 250.50 GHS * 100
            && $request['merchant_note'] === 'Customer cancelled';
    });
});

it('refundTransaction throws PaystackException on non-2xx', function () {
    Http::fake([
        'api.paystack.co/refund' => Http::response([
            'status'  => false,
            'message' => 'Transaction not refundable',
        ], 422),
    ]);

    expect(fn () => $this->svc->refundTransaction('pst_bad', 100.0, 'no'))
        ->toThrow(PaystackException::class, 'not refundable');
});
```

- [ ] **Step 2: Run tests — must FAIL**

```
php artisan test --filter=PaystackGatewayServiceTest
```

Expected: 2 new failures (existing 4 still pass).

- [ ] **Step 3: Implement `refundTransaction()`**

Open `app/Services/Finance/PaystackGatewayService.php`. After the existing `verifyTransaction()` method, add:

```php
    /**
     * @return array  the parsed `data` field from Paystack's /refund response
     */
    public function refundTransaction(string $paystackRef, float $amountGhs, string $reason): array
    {
        $payload = [
            'transaction'   => $paystackRef,
            'amount'        => (int) round($amountGhs * 100),  // GHS → pesewas
            'merchant_note' => $reason,
        ];

        $response = $this->client()->post('/refund', $payload);

        return $this->parse($response, '/refund')['data'];
    }
```

- [ ] **Step 4: Run tests — must PASS**

```
php artisan test --filter=PaystackGatewayServiceTest
```

Expected: 6 tests pass (4 existing + 2 new).

- [ ] **Step 5: Commit**

```
git add app/Services/Finance/PaystackGatewayService.php tests/Feature/Finance/PaystackGatewayServiceTest.php
git commit -m "$(cat <<'EOF'
feat(finance): PaystackGatewayService::refundTransaction()

Mirrors initializeTransaction(): pesewas conversion + bearer auth +
PaystackException on non-2xx. F4-R uses this through RefundService.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: RefundService

**Files:**
- Create: `app/Services/Finance/RefundService.php`
- Test: `tests/Feature/Finance/RefundServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/RefundServiceTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\ArInvoiceStatus;
use App\Enums\ArReceiptStatus;
use App\Enums\PaymentIntentStatus;
use App\Models\ArInvoice;
use App\Models\ArReceipt;
use App\Models\Customer;
use App\Models\GlAccount;
use App\Models\OrgBankAccount;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Services\Finance\ArInvoiceService;
use App\Services\Finance\ArReceiptService;
use App\Services\Finance\RefundService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.paystack.url'        => 'https://api.paystack.co',
        'services.paystack.secret_key' => 'sk_test_secret',
    ]);

    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new OrgBankAccountSeeder())->run();

    $this->user     = User::factory()->create();
    $this->approver = User::factory()->create();
    $this->customer = Customer::create([
        'code' => 'CUS-R', 'name' => 'Ref', 'status' => 'active', 'email' => 'r@example.com',
    ]);

    // Approved invoice → record paid receipt with Paystack external_ref → wire up intent
    $income = GlAccount::where('code', '4100')->firstOrFail();
    $inv = app(ArInvoiceService::class)->create([
        'customer_id' => $this->customer->id, 'invoice_date' => '2026-05-23',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 250, 'gl_account_id' => $income->id]],
    ], $this->user);
    app(ArInvoiceService::class)->submit($inv);
    app(ArInvoiceService::class)->approve($inv->fresh(), $this->approver);
    $this->invoice = $inv->fresh();

    $bank = OrgBankAccount::forPurpose('receipts')->first()
        ?? OrgBankAccount::active()->first();

    $this->receipt = app(ArReceiptService::class)->record([
        'customer_id'         => $this->customer->id,
        'receipt_date'        => '2026-05-23',
        'amount'              => 250,
        'currency'            => 'GHS',
        'org_bank_account_id' => $bank->id,
        'external_ref'        => 'pst_refundtest',
        'allocations'         => [['ar_invoice_id' => $this->invoice->id, 'allocated_amount' => 250]],
    ], $this->user);

    $this->intent = PaymentIntent::create([
        'reference'          => 'PI-2026-R00001',
        'customer_id'        => $this->customer->id,
        'ar_invoice_id'      => $this->invoice->id,
        'amount'             => 250,
        'currency'           => 'GHS',
        'status'             => PaymentIntentStatus::Success->value,
        'paystack_reference' => 'pst_refundtest',
        'ar_receipt_id'      => $this->receipt->id,
        'paid_at'            => now(),
        'created_by'         => $this->user->id,
    ]);

    $this->svc = app(RefundService::class);
});

it('refund() calls Paystack, voids the receipt, and stamps the intent', function () {
    Http::fake([
        'api.paystack.co/refund' => Http::response([
            'status' => true,
            'data' => ['id' => 555, 'status' => 'pending', 'amount' => 25000],
        ], 200),
    ]);

    $intent = $this->svc->refund($this->intent, $this->user, 'Customer requested cancellation');

    expect($intent->status)->toBe(PaymentIntentStatus::Refunded);
    expect($intent->refunded_at)->not->toBeNull();
    expect($intent->refund_paystack_ref)->toBe('555');
    expect($intent->refund_reason)->toBe('Customer requested cancellation');
    expect((float) $intent->refund_amount)->toBe(250.0);
    expect($intent->refunded_by)->toBe($this->user->id);
    expect($intent->refund_settled_at)->toBeNull(); // webhook hasn't arrived yet

    // Receipt voided + invoice amount_received reset
    expect($this->receipt->fresh()->status)->toBe(ArReceiptStatus::Voided);
    expect((float) $this->invoice->fresh()->amount_received)->toBe(0.0);
});

it('refund() refuses an intent that is not in Success status', function () {
    $this->intent->update(['status' => PaymentIntentStatus::Pending->value]);

    expect(fn () => $this->svc->refund($this->intent->fresh(), $this->user, 'try'))
        ->toThrow(\DomainException::class, 'status');
});

it('refund() refuses an already-refunded intent', function () {
    Http::fake([
        'api.paystack.co/refund' => Http::response([
            'status' => true, 'data' => ['id' => 555, 'status' => 'pending'],
        ], 200),
    ]);

    $this->svc->refund($this->intent, $this->user, 'first');

    expect(fn () => $this->svc->refund($this->intent->fresh(), $this->user, 'second'))
        ->toThrow(\DomainException::class, 'already refunded');
});

it('refund() refuses an intent with no linked receipt', function () {
    $this->intent->update(['ar_receipt_id' => null]);

    expect(fn () => $this->svc->refund($this->intent->fresh(), $this->user, 'try'))
        ->toThrow(\DomainException::class, 'no linked AR receipt');
});

it('refund() Paystack failure leaves the receipt + intent untouched', function () {
    Http::fake([
        'api.paystack.co/refund' => Http::response([
            'status'  => false,
            'message' => 'Transaction not refundable',
        ], 422),
    ]);

    expect(fn () => $this->svc->refund($this->intent, $this->user, 'try'))
        ->toThrow(\App\Exceptions\Finance\PaystackException::class);

    expect($this->intent->fresh()->refunded_at)->toBeNull();
    expect($this->intent->fresh()->status)->toBe(PaymentIntentStatus::Success);
    expect($this->receipt->fresh()->status)->toBe(ArReceiptStatus::Processed);
});
```

- [ ] **Step 2: Run test — must FAIL**

```
php artisan test --filter=RefundServiceTest
```

- [ ] **Step 3: Create the service**

`app/Services/Finance/RefundService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\PaymentIntentStatus;
use App\Models\PaymentIntent;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class RefundService
{
    public function __construct(
        private readonly PaystackGatewayService $gateway,
        private readonly ArReceiptService $receipts,
    ) {
    }

    public function refund(PaymentIntent $intent, User $user, string $reason): PaymentIntent
    {
        if ($intent->status !== PaymentIntentStatus::Success) {
            throw new DomainException(
                "Cannot refund intent {$intent->reference}: status is {$intent->status->value}."
            );
        }
        if ($intent->refunded_at !== null) {
            throw new DomainException("Intent {$intent->reference} is already refunded.");
        }
        if ($intent->ar_receipt_id === null) {
            throw new DomainException("Intent {$intent->reference} has no linked AR receipt to reverse.");
        }

        return DB::transaction(function () use ($intent, $user, $reason) {
            // 1. Call Paystack — if this throws, the transaction rolls back
            //    and the receipt + intent stay untouched.
            $response = $this->gateway->refundTransaction(
                $intent->paystack_reference,
                (float) $intent->amount,
                $reason,
            );

            // 2. Reverse the AR receipt via the existing F3 service.
            $this->receipts->void(
                $intent->receipt,
                $user,
                "Paystack refund: {$reason}",
            );

            // 3. Stamp the audit fields on the intent.
            $intent->update([
                'status'              => PaymentIntentStatus::Refunded->value,
                'refunded_at'         => now(),
                'refund_amount'       => $intent->amount,
                'refund_reason'       => $reason,
                'refund_paystack_ref' => (string) ($response['id'] ?? ''),
                'refunded_by'         => $user->id,
            ]);

            return $intent->fresh('receipt');
        });
    }
}
```

- [ ] **Step 4: Run test — must PASS**

```
php artisan test --filter=RefundServiceTest
```

Expected: 5 tests pass.

- [ ] **Step 5: Commit**

```
git add app/Services/Finance/RefundService.php tests/Feature/Finance/RefundServiceTest.php
git commit -m "$(cat <<'EOF'
feat(finance): RefundService — validate → Paystack /refund → AR receipt void

Single DB::transaction wraps gateway call + receipt void + intent
update. Paystack failure rolls everything back (receipt remains
Processed, intent remains Success). JournalPostingService is reached
transitively through ArReceiptService::void()::reverse() — unmodified.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: PaystackWebhookProcessor — handle refund.processed

**Files:**
- Modify: `app/Services/Finance/PaystackWebhookProcessor.php`
- Modify: `tests/Feature/Finance/PaystackWebhookProcessorTest.php`

- [ ] **Step 1: Append failing tests to the existing test file**

Open `tests/Feature/Finance/PaystackWebhookProcessorTest.php`. After the last existing `it(...)` block, append:

```php
it('refund.processed event stamps refund_settled_at on the matching intent', function () {
    // First refund the intent via the service so refund_paystack_ref is populated.
    Http::fake([
        'api.paystack.co/refund' => Http::response([
            'status' => true, 'data' => ['id' => 7777, 'status' => 'pending'],
        ], 200),
    ]);
    app(\App\Services\Finance\RefundService::class)
        ->refund($this->intent, $this->user, 'test reason');

    // Then deliver the webhook.
    $event = PaystackWebhookEvent::create([
        'paystack_event_id'  => 'evt_rp_001',
        'event_type'         => 'refund.processed',
        'paystack_reference' => 'pst_webhook_001',
        'payload'            => ['event' => 'refund.processed', 'data' => ['id' => 7777]],
        'signature'          => 'sig',
    ]);

    $result = $this->processor->process($event);

    expect($result)->toBeNull();
    expect($event->fresh()->processed_at)->not->toBeNull();
    expect($this->intent->fresh()->refund_settled_at)->not->toBeNull();
    expect($event->fresh()->payment_intent_id)->toBe($this->intent->id);
});

it('refund.processed event with unknown refund_paystack_ref records error', function () {
    $event = PaystackWebhookEvent::create([
        'paystack_event_id'  => 'evt_rp_002',
        'event_type'         => 'refund.processed',
        'paystack_reference' => 'pst_webhook_001',
        'payload'            => ['event' => 'refund.processed', 'data' => ['id' => 99999]],
        'signature'          => 'sig',
    ]);

    $result = $this->processor->process($event);

    expect($result)->toBeNull();
    expect($event->fresh()->processing_error)->toContain('not found');
});

it('refund.processed event is idempotent (re-processing does not move refund_settled_at)', function () {
    Http::fake([
        'api.paystack.co/refund' => Http::response([
            'status' => true, 'data' => ['id' => 4444, 'status' => 'pending'],
        ], 200),
    ]);
    app(\App\Services\Finance\RefundService::class)
        ->refund($this->intent, $this->user, 'idempotency test');

    $event1 = PaystackWebhookEvent::create([
        'paystack_event_id'  => 'evt_rp_003a',
        'event_type'         => 'refund.processed',
        'paystack_reference' => 'pst_webhook_001',
        'payload'            => ['event' => 'refund.processed', 'data' => ['id' => 4444]],
        'signature'          => 'sig',
    ]);
    $this->processor->process($event1);
    $firstSettled = $this->intent->fresh()->refund_settled_at->toDateTimeString();

    // Second event row with same data.id — different paystack_event_id so it isn't
    // caught at the storage layer. Processor must still no-op the settlement column.
    $event2 = PaystackWebhookEvent::create([
        'paystack_event_id'  => 'evt_rp_003b',
        'event_type'         => 'refund.processed',
        'paystack_reference' => 'pst_webhook_001',
        'payload'            => ['event' => 'refund.processed', 'data' => ['id' => 4444]],
        'signature'          => 'sig',
    ]);
    travel(2)->seconds();
    $this->processor->process($event2);

    expect($this->intent->fresh()->refund_settled_at->toDateTimeString())->toBe($firstSettled);
});
```

- [ ] **Step 2: Run tests — must FAIL**

```
php artisan test --filter=PaystackWebhookProcessorTest
```

Expected: 3 new failures (5 existing still pass).

- [ ] **Step 3: Update the `process()` match expression**

Open `app/Services/Finance/PaystackWebhookProcessor.php`. Find the `match ($event->event_type) { ... }` inside `process()` and change it to:

```php
            return match ($event->event_type) {
                'charge.success'   => $this->handleChargeSuccess($event),
                'refund.processed' => $this->handleRefundProcessed($event),
                default            => $this->markNoOp($event),
            };
```

- [ ] **Step 4: Add the `handleRefundProcessed()` method**

In the same file, after the existing `handleChargeSuccess()` method, add:

```php
    private function handleRefundProcessed(PaystackWebhookEvent $event): null
    {
        $refundId = (string) (data_get($event->payload, 'data.id') ?? '');
        if ($refundId === '') {
            $event->update([
                'processed_at'     => now(),
                'processing_error' => 'refund.processed missing data.id',
            ]);
            return null;
        }

        return DB::transaction(function () use ($event, $refundId) {
            $intent = PaymentIntent::where('refund_paystack_ref', $refundId)
                ->lockForUpdate()
                ->first();

            if (! $intent) {
                $event->update([
                    'processed_at'     => now(),
                    'processing_error' => "PaymentIntent for refund_paystack_ref '{$refundId}' not found",
                ]);
                return null;
            }

            if ($intent->refund_settled_at === null) {
                $intent->update(['refund_settled_at' => now()]);
            }

            $event->update([
                'processed_at'      => now(),
                'payment_intent_id' => $intent->id,
            ]);

            return null;
        });
    }
```

- [ ] **Step 5: Run tests — must PASS**

```
php artisan test --filter=PaystackWebhookProcessorTest
```

Expected: 8 tests pass (5 existing + 3 new).

- [ ] **Step 6: Commit**

```
git add app/Services/Finance/PaystackWebhookProcessor.php tests/Feature/Finance/PaystackWebhookProcessorTest.php
git commit -m "$(cat <<'EOF'
feat(finance): PaystackWebhookProcessor handles refund.processed (F4-R)

Stamps refund_settled_at when Paystack confirms async settlement.
Idempotent: subsequent events for the same refund don't move the
timestamp. Unknown refund_paystack_ref records processing_error.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: StoreRefundRequest + RefundController + route

**Files:**
- Create: `app/Http/Requests/Finance/StoreRefundRequest.php`
- Create: `app/Http/Controllers/Finance/RefundController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Finance/RefundEndpointTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/RefundEndpointTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\PaymentIntentStatus;
use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\GlAccount;
use App\Models\OrgBankAccount;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Services\Finance\ArInvoiceService;
use App\Services\Finance\ArReceiptService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.paystack.url'        => 'https://api.paystack.co',
        'services.paystack.secret_key' => 'sk_test_secret',
    ]);

    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new OrgBankAccountSeeder())->run();

    $this->customer = Customer::create([
        'code' => 'CUS-RE', 'name' => 'Refund Endpoint', 'status' => 'active', 'email' => 're@example.com',
    ]);
});

function refund2faFresh(User $user): User
{
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    app(TwoFactorService::class)->markFresh($user);
    return $user;
}

function makeRefundableIntent(Customer $customer, User $creator, User $approver): PaymentIntent
{
    $income = GlAccount::where('code', '4100')->firstOrFail();
    $inv = app(ArInvoiceService::class)->create([
        'customer_id' => $customer->id, 'invoice_date' => '2026-05-23',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'gl_account_id' => $income->id]],
    ], $creator);
    app(ArInvoiceService::class)->submit($inv);
    app(ArInvoiceService::class)->approve($inv->fresh(), $approver);

    $bank = OrgBankAccount::forPurpose('receipts')->first()
        ?? OrgBankAccount::active()->first();

    $receipt = app(ArReceiptService::class)->record([
        'customer_id'         => $customer->id,
        'receipt_date'        => '2026-05-23',
        'amount'              => 100,
        'currency'            => 'GHS',
        'org_bank_account_id' => $bank->id,
        'external_ref'        => 'pst_endpoint_refund',
        'allocations'         => [['ar_invoice_id' => $inv->id, 'allocated_amount' => 100]],
    ], $creator);

    return PaymentIntent::create([
        'reference'          => 'PI-2026-RE0001',
        'customer_id'        => $customer->id,
        'ar_invoice_id'      => $inv->id,
        'amount'             => 100,
        'currency'           => 'GHS',
        'status'             => PaymentIntentStatus::Success->value,
        'paystack_reference' => 'pst_endpoint_refund',
        'ar_receipt_id'      => $receipt->id,
        'paid_at'            => now(),
        'created_by'         => $creator->id,
    ]);
}

it('finance_officer with fresh 2FA can refund a payment intent', function () {
    Http::fake([
        'api.paystack.co/refund' => Http::response([
            'status' => true, 'data' => ['id' => 8888, 'status' => 'pending'],
        ], 200),
    ]);

    $creator  = User::factory()->create(['role' => 'finance_officer']);
    $approver = User::factory()->create(['role' => 'finance_officer']);
    $intent = makeRefundableIntent($this->customer, $creator, $approver);

    $this->actingAs(refund2faFresh($creator))
        ->post("/finance/payment-intents/{$intent->id}/refund", [
            'reason' => 'Customer wants money back',
        ])
        ->assertRedirect();

    expect($intent->fresh()->status)->toBe(PaymentIntentStatus::Refunded);
});

it('finance_officer WITHOUT fresh 2FA is bounced (302) before reaching the service', function () {
    $creator  = User::factory()->create(['role' => 'finance_officer']);
    $approver = User::factory()->create(['role' => 'finance_officer']);
    $intent = makeRefundableIntent($this->customer, $creator, $approver);

    $this->actingAs($creator)
        ->post("/finance/payment-intents/{$intent->id}/refund", [
            'reason' => 'Customer wants money back',
        ])
        ->assertStatus(302);

    expect($intent->fresh()->status)->toBe(PaymentIntentStatus::Success);
});

it('auditor gets 403 on refund', function () {
    $creator  = User::factory()->create(['role' => 'finance_officer']);
    $approver = User::factory()->create(['role' => 'finance_officer']);
    $intent = makeRefundableIntent($this->customer, $creator, $approver);

    $auditor = User::factory()->create(['role' => 'auditor']);
    $this->actingAs(refund2faFresh($auditor))
        ->post("/finance/payment-intents/{$intent->id}/refund", [
            'reason' => 'snooping',
        ])
        ->assertForbidden();
});

it('rejects a missing or too-short reason', function () {
    $creator  = User::factory()->create(['role' => 'finance_officer']);
    $approver = User::factory()->create(['role' => 'finance_officer']);
    $intent = makeRefundableIntent($this->customer, $creator, $approver);

    $this->actingAs(refund2faFresh($creator))
        ->post("/finance/payment-intents/{$intent->id}/refund", ['reason' => 'no'])
        ->assertSessionHasErrors('reason');
});
```

- [ ] **Step 2: Run test — must FAIL**

```
php artisan test --filter=RefundEndpointTest
```

- [ ] **Step 3: Create the request**

`app/Http/Requests/Finance/StoreRefundRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class StoreRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('gateway.refund') === true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
```

- [ ] **Step 4: Create the controller**

`app/Http/Controllers/Finance/RefundController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Exceptions\Finance\PaystackException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreRefundRequest;
use App\Models\PaymentIntent;
use App\Services\Finance\RefundService;
use DomainException;
use Illuminate\Http\RedirectResponse;

class RefundController extends Controller
{
    public function __construct(private readonly RefundService $refunds)
    {
    }

    public function store(StoreRefundRequest $request, PaymentIntent $paymentIntent): RedirectResponse
    {
        try {
            $this->refunds->refund($paymentIntent, $request->user(), $request->validated('reason'));
        } catch (DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        } catch (PaystackException $e) {
            return back()->withErrors(['reason' => 'Paystack: ' . $e->getMessage()]);
        }

        return back()->with('success', 'Refund initiated. Settlement confirmation will arrive via webhook.');
    }
}
```

- [ ] **Step 5: Add the route**

Open `routes/web.php`. Find the F4 payment-intents block:

```php
        Route::middleware(['permission:gateway.create', '2fa:fresh'])->group(function () {
            Route::post('payment-intents',                      [\App\Http\Controllers\Finance\PaymentIntentController::class, 'store'])->name('payment-intents.store');
        });
```

Immediately AFTER it (still inside the `finance.` prefix group), add:

```php
        Route::middleware(['permission:gateway.refund', '2fa:fresh'])->group(function () {
            Route::post('payment-intents/{paymentIntent}/refund', [\App\Http\Controllers\Finance\RefundController::class, 'store'])->name('payment-intents.refund');
        });
```

- [ ] **Step 6: Run test — must PASS**

```
php artisan test --filter=RefundEndpointTest
```

Expected: 4 tests pass.

- [ ] **Step 7: Commit**

```
git add app/Http/Requests/Finance/StoreRefundRequest.php app/Http/Controllers/Finance/RefundController.php routes/web.php tests/Feature/Finance/RefundEndpointTest.php
git commit -m "$(cat <<'EOF'
feat(finance): F4-R refund endpoint — gateway.refund + 2fa:fresh

POST /finance/payment-intents/{intent}/refund. Reason required
(5..500 chars). auditor 403; finance_officer with stale 2FA bounced
to the 2FA flow.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: Inertia UI — Refund button on PaymentIntents Index + AR Invoice Show

**Files:**
- Modify: `resources/js/Pages/Finance/PaymentIntents/Index.vue`
- Modify: `resources/js/Pages/Finance/ArInvoices/Show.vue`

> NOTE: F4 only ships an Index page for Payment Intents (no separate Show page; clicking an intent surfaces it via `focusIntent` query). F4-R puts the Refund button directly on the Index row when status is `success` and the user has `gateway.refund`. Same row also shows a refund audit badge once status flips to `refunded`.

- [ ] **Step 1: Update `PaymentIntents/Index.vue`**

A. Add a `canRefund` computed near the existing `canCreate`:

Open `resources/js/Pages/Finance/PaymentIntents/Index.vue`. After the existing `const canCreate = computed(() => {...})`, add:

```js
const canRefund = computed(() => {
    const perms = page.props?.auth?.permissions ?? [];
    const list = Array.isArray(perms) ? perms : (typeof perms === 'function' ? perms() : []);
    return list.includes('gateway.refund');
});
```

B. Add a `useForm` for the refund modal AFTER the existing `useForm({...})` block:

```js
const refundModal = ref(null);
const refundForm = useForm({ reason: '' });

const openRefund = (intent) => { refundModal.value = intent; refundForm.reset(); };

const submitRefund = () => {
    if (! refundModal.value) return;
    refundForm.post(route('finance.payment-intents.refund', refundModal.value.id), {
        preserveScroll: true,
        onSuccess: () => { refundModal.value = null; refundForm.reset(); },
    });
};
```

C. Extend the row's action column. Find the existing action `<td>` (the one with the "Copy link" button) and replace it with:

```vue
                        <td class="px-4 py-2 text-right space-x-2">
                            <button v-if="intent.authorization_url && intent.status.value === 'pending'"
                                    @click="copyLink(intent.authorization_url)"
                                    class="text-[11px] font-bold text-secondary hover:underline">Copy link</button>
                            <button v-if="canRefund && intent.status.value === 'success'"
                                    @click="openRefund(intent)"
                                    class="text-[11px] font-bold text-rose-700 hover:underline">Refund</button>
                            <span v-if="intent.status.value === 'refunded'"
                                  class="text-[10px] font-bold text-violet-700">Refunded</span>
                        </td>
```

D. Add the refund modal at the bottom of the template, after the existing `<SlidePanel>` block (just before the closing `</template>` tag):

```vue
        <div v-if="refundModal" class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center">
            <div class="bg-surface-container-lowest rounded-2xl p-6 w-full max-w-md">
                <h3 class="text-[14px] font-black text-primary mb-1">Refund Payment Link</h3>
                <p class="text-[11px] text-on-surface-variant mb-4">{{ refundModal.reference }} · {{ cedi(refundModal.amount) }}</p>
                <p class="text-[11px] text-amber-700 bg-amber-50 border border-amber-200 rounded-lg p-2 mb-3">
                    This calls Paystack /refund and immediately voids the linked AR receipt. Cannot be undone.
                </p>
                <form @submit.prevent="submitRefund" class="space-y-3">
                    <div>
                        <label class="block text-[11px] font-bold text-on-surface-variant mb-1">Reason (visible to Paystack support)</label>
                        <textarea v-model="refundForm.reason" rows="3"
                                  class="block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]"></textarea>
                        <InputError :message="refundForm.errors.reason" />
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="refundModal = null"
                                class="rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-bold text-on-surface-variant">Cancel</button>
                        <button type="submit" :disabled="refundForm.processing"
                                class="rounded-xl bg-rose-700 text-white px-3 py-2 text-[12px] font-bold disabled:opacity-50">Confirm refund</button>
                    </div>
                </form>
            </div>
        </div>
```

- [ ] **Step 2: Update `ArInvoices/Show.vue`**

A. Add a `canRefundPayment` computed near the existing `canCreatePayment`:

Open `resources/js/Pages/Finance/ArInvoices/Show.vue`. After the existing `const canCreatePayment = computed(...)` block, add:

```js
const canRefundPayment = computed(() => {
    const perms = page.props?.auth?.permissions ?? [];
    const list = Array.isArray(perms) ? perms : (typeof perms === 'function' ? perms() : []);
    return list.includes('gateway.refund');
});
```

B. Find the "Send Payment Link" button in the template. Immediately after that button, add a sibling that links to the Payment Intents page so the operator can refund from there:

```vue
                    <Link v-if="canRefundPayment && invoice.status.value === 'paid'"
                          :href="route('finance.payment-intents.index')"
                          class="rounded-xl border border-rose-300 bg-rose-50 px-3 py-2 text-[12px] font-bold text-rose-700 hover:bg-rose-100">
                        <span class="material-symbols-outlined text-[14px] mr-1 align-text-bottom">undo</span>Refund Paystack payment
                    </Link>
```

- [ ] **Step 3: Build + Finance suite**

```
npm run build
php artisan test --filter=Finance
```

Build must compile cleanly; full Finance suite must remain green.

- [ ] **Step 4: Commit**

```
git add resources/js/Pages/Finance/PaymentIntents/Index.vue resources/js/Pages/Finance/ArInvoices/Show.vue
git commit -m "$(cat <<'EOF'
feat(finance): F4-R UI — Refund button on Payment Links index + AR Invoice cross-link

Refund modal with reason textarea + warning banner about Paystack call
+ receipt void. Cross-link from a Paid AR Invoice page to the Payment
Links page, where the actual refund is initiated.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 10: Acceptance smoke

- [ ] **Step 1: Run the full Finance + F4-R suite**

```
php artisan test --filter='Finance|EnumsF4R|F4R'
```

Expected: ~25 new tests (across F4-R) + all existing finance tests pass. Total ~280+ green.

- [ ] **Step 2: Run the full Pest suite**

```
php artisan test 2>&1 | tail -3
```

Expected: green; no regressions outside Finance.

- [ ] **Step 3: Build smoke**

```
npm run build 2>&1 | tail -3
```

Expected: clean build, no Vue/TS errors.

- [ ] **Step 4: Smoke a fresh migration + seed**

```
php artisan migrate:fresh --seed --force 2>&1 | tail -5
```

Expected: completes through all seeders. Verifies the new migration doesn't break the seeder chain.

---

## Done criteria

F4-R is complete when:

1. All 10 tasks are checked off.
2. All Pest tests pass (~25 new + existing).
3. A `finance_officer` with fresh 2FA can click Refund on a Paystack-paid intent → enters a reason → sees the intent flip to `Refunded` → linked AR receipt status flips to `Voided` → invoice `amount_received` decreases → reversal JE visible at `journal.show`.
4. A `refund.processed` webhook (test fixture with valid HMAC against the new event handler) stamps `refund_settled_at` on the matching intent.
5. `JournalPostingService` is unmodified (`git diff` against main shows no change).
6. `ArReceiptService::void()` is unmodified (F4-R calls it; doesn't change it).
7. `gateway.refund` is granted explicitly to `finance_officer` in both the DB seed and `User::ROLE_PERMISSIONS`; `auditor` still does not have it.
8. F4-R refund-related endpoints all gate on `2fa:fresh` from day one.
