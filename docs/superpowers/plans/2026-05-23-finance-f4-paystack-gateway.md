# Finance F4 — Paystack Gateway Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Paystack hosted-checkout payment gateway: finance officer generates a Paystack payment link for an AR invoice → customer pays → signed webhook auto-posts an `ArReceipt` via the existing F3 `ArReceiptService::record()` → balances update through F2's journal engine. No new accounting logic.

**Architecture:** 2 new tables (`payment_intents`, `paystack_webhook_events`), 1 enum, 3 services (`PaystackGatewayService`, `PaymentIntentService`, `PaystackWebhookProcessor`), 1 middleware (`VerifyPaystackSignature`), 2 controllers (auth + public webhook), 1 Inertia page. **`ArReceiptService::record()` is the SOLE entry point for AR receipts** — F4 routes the webhook through it instead of writing receipts directly. Idempotency at the storage layer via UNIQUE constraint on `paystack_webhook_events.paystack_event_id`.

**Tech Stack:** Laravel 13.7, PHP 8.3, Eloquent + SoftDeletes, Laravel `Http` client for Paystack REST API, queued jobs for async webhook processing, Pest. Paystack API: HMAC-SHA512 webhook signing, hosted checkout via `transaction/initialize`, GHS amounts in pesewas (×100).

**Spec reference:** [docs/superpowers/specs/2026-05-23-finance-f4-paystack-gateway-design.md](../specs/2026-05-23-finance-f4-paystack-gateway-design.md)

**Branch:** `feat/finance-f4-paystack` (off F3 head `5dfc084`; PR #12 has merged — rebase onto `origin/main` before PR)

---

## File Structure

### New files

```
app/Enums/
    PaymentIntentStatus.php

app/Models/
    PaymentIntent.php
    PaystackWebhookEvent.php

app/Services/Finance/
    PaystackGatewayService.php
    PaymentIntentService.php
    PaystackWebhookProcessor.php

app/Exceptions/Finance/
    PaystackException.php
    PaystackUnreachableException.php

app/Http/Middleware/
    VerifyPaystackSignature.php

app/Http/Requests/Finance/
    StorePaymentIntentRequest.php

app/Http/Resources/Finance/
    PaymentIntentResource.php

app/Http/Controllers/Finance/
    PaymentIntentController.php
    PaystackWebhookController.php

app/Jobs/
    ProcessPaystackWebhook.php

database/migrations/
    2026_05_23_100001_create_payment_intents.php
    2026_05_23_100002_create_paystack_webhook_events.php

database/factories/
    PaymentIntentFactory.php

resources/js/Pages/Finance/
    PaymentIntents/Index.vue

tests/Feature/Finance/
    PaymentIntentServiceTest.php
    PaystackWebhookProcessorTest.php
    PaystackWebhookEndpointTest.php
    PaymentIntentTest.php
    F4PermissionsSeedTest.php

tests/Unit/Finance/
    EnumsF4Test.php
```

### Modified files

```
app/Enums/JournalSourceType.php          -- (NO change for F4; ArReceipt case already exists from F3)
app/Models/User.php                       -- add 3 new perms to ROLE_PERMISSIONS
app/Services/Finance/FinanceHubService.php -- add gatewayHealth() check
bootstrap/app.php                         -- register 'paystack.signature' middleware alias
config/services.php                       -- add 'paystack' config block
database/seeders/RolePermissionSeeder.php -- add 3 new perms
routes/web.php                            -- payment-intents.* routes + public /webhooks/paystack
resources/js/Layouts/AuthenticatedLayout.vue -- "Payment Links" sidebar entry + icon palette
resources/js/Pages/Finance/Hub.vue        -- gateway health tile
resources/js/Pages/Finance/ArInvoices/Show.vue -- "Send Payment Link" button
.env.example                              -- document PAYSTACK_* keys
tests/Feature/Finance/FinanceHubTest.php  -- assert gatewayHealth key
```

### Responsibility boundaries

- **`PaystackGatewayService`** — thin HTTP wrapper. Knows about pesewas conversion. No business logic.
- **`PaymentIntentService`** — orchestration. Inputs validated, intent persisted, gateway called, intent finalized.
- **`PaystackWebhookProcessor`** — idempotent event handler. Single entry point: `process(PaystackWebhookEvent)`. Routes to `handleChargeSuccess()`; ignores other events.
- **`VerifyPaystackSignature`** — middleware. Reads raw body bytes, computes HMAC-SHA512, compares to `X-Paystack-Signature` header. Failure → 400.
- **`PaystackWebhookController`** — 2 lines: persist event row + dispatch queued job. Always returns 200 (Paystack retries on non-2xx).
- **`ProcessPaystackWebhook` job** — fetches the event row, delegates to `PaystackWebhookProcessor`.
- **`PaymentIntentController`** — thin: delegate to `PaymentIntentService`. Returns Inertia render or `back()`.

---

## Task 1: PaymentIntentStatus enum

**Files:**
- Create: `app/Enums/PaymentIntentStatus.php`
- Test: `tests/Unit/Finance/EnumsF4Test.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Finance/EnumsF4Test.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\PaymentIntentStatus;

it('PaymentIntentStatus exposes 6 cases', function () {
    $values = array_map(fn ($c) => $c->value, PaymentIntentStatus::cases());
    expect($values)->toEqualCanonicalizing([
        'created', 'pending', 'success', 'failed', 'abandoned', 'expired',
    ]);
});

it('all PaymentIntentStatus labels are non-empty', function () {
    foreach (PaymentIntentStatus::cases() as $case) {
        expect($case->label())->toBeString()->not->toBeEmpty();
    }
});
```

- [ ] **Step 2: Run test — must FAIL**

```
php artisan test --filter=EnumsF4Test
```

- [ ] **Step 3: Create the enum**

`app/Enums/PaymentIntentStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentIntentStatus: string
{
    case Created   = 'created';
    case Pending   = 'pending';
    case Success   = 'success';
    case Failed    = 'failed';
    case Abandoned = 'abandoned';
    case Expired   = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Created   => 'Created',
            self::Pending   => 'Pending',
            self::Success   => 'Success',
            self::Failed    => 'Failed',
            self::Abandoned => 'Abandoned',
            self::Expired   => 'Expired',
        };
    }
}
```

- [ ] **Step 4: Run test — must PASS**

```
php artisan test --filter=EnumsF4Test
```
Expected: 2 tests pass.

- [ ] **Step 5: Commit**

```
git add app/Enums/PaymentIntentStatus.php tests/Unit/Finance/EnumsF4Test.php
git commit -m "$(cat <<'EOF'
feat(finance): add PaymentIntentStatus enum (F4)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Migrations (payment_intents + paystack_webhook_events)

**Files:**
- Create: `database/migrations/2026_05_23_100001_create_payment_intents.php`
- Create: `database/migrations/2026_05_23_100002_create_paystack_webhook_events.php`
- Test: `tests/Feature/Finance/PaystackMigrationsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/PaystackMigrationsTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('creates the payment_intents table', function () {
    expect(Schema::hasTable('payment_intents'))->toBeTrue();
    expect(Schema::hasColumns('payment_intents', [
        'id', 'reference', 'customer_id', 'ar_invoice_id',
        'amount', 'currency', 'status',
        'paystack_reference', 'paystack_access_code', 'authorization_url', 'callback_url',
        'ar_receipt_id', 'narration', 'paid_at', 'expires_at', 'last_paystack_response',
        'created_by', 'created_at', 'updated_at', 'deleted_at',
    ]))->toBeTrue();
});

it('creates the paystack_webhook_events table', function () {
    expect(Schema::hasTable('paystack_webhook_events'))->toBeTrue();
    expect(Schema::hasColumns('paystack_webhook_events', [
        'id', 'paystack_event_id', 'event_type', 'paystack_reference',
        'payload', 'signature', 'payment_intent_id', 'ar_receipt_id',
        'processed_at', 'processing_error', 'received_at',
    ]))->toBeTrue();
});
```

- [ ] **Step 2: Run test — must FAIL**

```
php artisan test --filter=PaystackMigrationsTest
```

- [ ] **Step 3: Create `payment_intents` migration**

`database/migrations/2026_05_23_100001_create_payment_intents.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paystack payment intent. One row per "Send Payment Link" action. Links a
 * Paystack hosted-checkout transaction to a CIHRMS customer + AR invoice.
 *
 * Lifecycle:
 *   created → pending → success
 *                     → failed
 *                     → abandoned
 *                     → expired
 *
 * `paystack_reference` is the canonical key for webhook lookup (UNIQUE).
 * `ar_receipt_id` is set when the webhook posts the AR receipt, linking
 * the intent to its resulting receipt for audit. `ar_invoice_id` is
 * nullable to reserve forward-compat for customer-credit intents (not
 * used in F4 itself).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('ar_invoice_id')->nullable()->constrained('ar_invoices')->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->char('currency', 3)->default('GHS');
            $table->string('status', 20)->default('created')->index();
            $table->string('paystack_reference', 100)->nullable()->unique();
            $table->string('paystack_access_code', 100)->nullable();
            $table->string('authorization_url', 500)->nullable();
            $table->string('callback_url', 500)->nullable();
            $table->foreignId('ar_receipt_id')->nullable()->constrained('ar_receipts')->nullOnDelete();
            $table->string('narration', 500)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('last_paystack_response')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_intents');
    }
};
```

- [ ] **Step 4: Create `paystack_webhook_events` migration**

`database/migrations/2026_05_23_100002_create_paystack_webhook_events.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotent log of Paystack webhook deliveries.
 *
 * The UNIQUE on paystack_event_id (the data.id from the webhook payload)
 * is THE idempotency guard — a replayed delivery from Paystack collides
 * on INSERT and the controller short-circuits.
 *
 * The processor (PaystackWebhookProcessor) flips processed_at on success
 * and links payment_intent_id + ar_receipt_id when it produces a receipt.
 * processing_error captures async failure for the Payment Intents UI.
 *
 * No SoftDeletes — webhook events are immutable audit records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paystack_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('paystack_event_id', 100)->unique();
            $table->string('event_type', 100)->index();
            $table->string('paystack_reference', 100)->nullable()->index();
            $table->json('payload');
            $table->string('signature', 255);
            $table->foreignId('payment_intent_id')->nullable()->constrained('payment_intents')->nullOnDelete();
            $table->foreignId('ar_receipt_id')->nullable()->constrained('ar_receipts')->nullOnDelete();
            $table->timestamp('processed_at')->nullable()->index();
            $table->text('processing_error')->nullable();
            $table->timestamp('received_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paystack_webhook_events');
    }
};
```

- [ ] **Step 5: Run test — must PASS**

```
php artisan test --filter=PaystackMigrationsTest
```
Expected: 2 tests pass.

- [ ] **Step 6: Commit**

```
git add database/migrations/2026_05_23_100001_create_payment_intents.php database/migrations/2026_05_23_100002_create_paystack_webhook_events.php tests/Feature/Finance/PaystackMigrationsTest.php
git commit -m "$(cat <<'EOF'
feat(finance): F4 schema — payment_intents + paystack_webhook_events tables

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Models (PaymentIntent + PaystackWebhookEvent + factory)

**Files:**
- Create: `app/Models/PaymentIntent.php`
- Create: `app/Models/PaystackWebhookEvent.php`
- Create: `database/factories/PaymentIntentFactory.php`
- Test: `tests/Feature/Finance/F4ModelsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/F4ModelsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\PaymentIntentStatus;
use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\GlAccount;
use App\Models\PaymentIntent;
use App\Models\PaystackWebhookEvent;
use App\Models\User;

it('creates a payment intent and casts status enum + decimals + json', function () {
    $u = User::factory()->create();
    $c = Customer::factory()->create();

    $intent = PaymentIntent::create([
        'reference'   => 'PI-2026-000001',
        'customer_id' => $c->id,
        'amount'      => 250.50,
        'currency'    => 'GHS',
        'status'      => PaymentIntentStatus::Created->value,
        'created_by'  => $u->id,
        'last_paystack_response' => ['foo' => 'bar'],
    ]);

    expect($intent->status)->toBe(PaymentIntentStatus::Created);
    expect((float) $intent->amount)->toBe(250.50);
    expect($intent->last_paystack_response)->toBe(['foo' => 'bar']);
    expect($intent->customer->id)->toBe($c->id);
});

it('PaymentIntent.scopePending filters to status = pending', function () {
    $u = User::factory()->create();
    $c = Customer::factory()->create();

    PaymentIntent::create(['reference' => 'P1', 'customer_id' => $c->id, 'amount' => 1, 'status' => 'pending', 'created_by' => $u->id]);
    PaymentIntent::create(['reference' => 'P2', 'customer_id' => $c->id, 'amount' => 1, 'status' => 'success', 'created_by' => $u->id]);

    expect(PaymentIntent::pending()->pluck('reference')->all())->toBe(['P1']);
});

it('PaymentIntent.scopeStale returns pending intents with expires_at < now', function () {
    $u = User::factory()->create();
    $c = Customer::factory()->create();

    PaymentIntent::create(['reference' => 'old', 'customer_id' => $c->id, 'amount' => 1, 'status' => 'pending', 'expires_at' => now()->subHour(), 'created_by' => $u->id]);
    PaymentIntent::create(['reference' => 'fresh', 'customer_id' => $c->id, 'amount' => 1, 'status' => 'pending', 'expires_at' => now()->addHour(), 'created_by' => $u->id]);

    expect(PaymentIntent::stale()->pluck('reference')->all())->toBe(['old']);
});

it('PaystackWebhookEvent persists payload as JSON', function () {
    $event = PaystackWebhookEvent::create([
        'paystack_event_id'  => 'evt_test_001',
        'event_type'         => 'charge.success',
        'paystack_reference' => 'pst_ref_001',
        'payload'            => ['data' => ['amount' => 25050]],
        'signature'          => 'abc123',
    ]);

    expect($event->payload)->toBe(['data' => ['amount' => 25050]]);
    expect($event->event_type)->toBe('charge.success');
    expect($event->processed_at)->toBeNull();
});

it('PaystackWebhookEvent.paystack_event_id is unique', function () {
    PaystackWebhookEvent::create([
        'paystack_event_id' => 'evt_dup', 'event_type' => 'charge.success',
        'payload' => [], 'signature' => 'sig',
    ]);

    expect(fn () => PaystackWebhookEvent::create([
        'paystack_event_id' => 'evt_dup', 'event_type' => 'charge.success',
        'payload' => [], 'signature' => 'sig',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});
```

- [ ] **Step 2: Run test — must FAIL**

```
php artisan test --filter=F4ModelsTest
```

- [ ] **Step 3: Create `PaymentIntent` model**

`app/Models/PaymentIntent.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentIntentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentIntent extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'payment_intents';

    protected $fillable = [
        'reference', 'customer_id', 'ar_invoice_id', 'amount', 'currency', 'status',
        'paystack_reference', 'paystack_access_code', 'authorization_url', 'callback_url',
        'ar_receipt_id', 'narration', 'paid_at', 'expires_at', 'last_paystack_response',
        'created_by',
    ];

    protected $attributes = ['currency' => 'GHS', 'status' => 'created'];

    protected function casts(): array
    {
        return [
            'status'                 => PaymentIntentStatus::class,
            'amount'                 => 'decimal:2',
            'paid_at'                => 'datetime',
            'expires_at'             => 'datetime',
            'last_paystack_response' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ArInvoice::class, 'ar_invoice_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(ArReceipt::class, 'ar_receipt_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->where('status', PaymentIntentStatus::Pending->value);
    }

    public function scopeStale(Builder $q): Builder
    {
        return $q->where('status', PaymentIntentStatus::Pending->value)
                 ->whereNotNull('expires_at')
                 ->where('expires_at', '<', now());
    }
}
```

- [ ] **Step 4: Create `PaystackWebhookEvent` model**

`app/Models/PaystackWebhookEvent.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaystackWebhookEvent extends Model
{
    // Webhook log: append-only audit, no timestamps beyond received_at.
    public $timestamps = false;

    protected $table = 'paystack_webhook_events';

    protected $fillable = [
        'paystack_event_id', 'event_type', 'paystack_reference',
        'payload', 'signature',
        'payment_intent_id', 'ar_receipt_id',
        'processed_at', 'processing_error', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'payload'      => 'array',
            'processed_at' => 'datetime',
            'received_at'  => 'datetime',
        ];
    }

    public function paymentIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class);
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(ArReceipt::class, 'ar_receipt_id');
    }
}
```

- [ ] **Step 5: Create `PaymentIntentFactory`**

`database/factories/PaymentIntentFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentIntentStatus;
use App\Models\Customer;
use App\Models\PaymentIntent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PaymentIntent>
 */
class PaymentIntentFactory extends Factory
{
    protected $model = PaymentIntent::class;

    public function definition(): array
    {
        return [
            'reference'   => fake()->unique()->bothify('PI-2026-######'),
            'customer_id' => Customer::factory(),
            'amount'      => fake()->randomFloat(2, 10, 5000),
            'currency'    => 'GHS',
            'status'      => PaymentIntentStatus::Created->value,
            'created_by'  => User::factory(),
        ];
    }
}
```

- [ ] **Step 6: Run test — must PASS**

```
php artisan test --filter=F4ModelsTest
```
Expected: 5 tests pass.

- [ ] **Step 7: Commit**

```
git add app/Models/PaymentIntent.php app/Models/PaystackWebhookEvent.php database/factories/PaymentIntentFactory.php tests/Feature/Finance/F4ModelsTest.php
git commit -m "$(cat <<'EOF'
feat(finance): PaymentIntent + PaystackWebhookEvent models with scopes + factory

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: F4 Permissions (3 slugs)

**Files:**
- Modify: `database/seeders/RolePermissionSeeder.php`
- Modify: `app/Models/User.php` (only `ROLE_PERMISSIONS` constant)
- Test: `tests/Feature/Finance/F4PermissionsSeedTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/F4PermissionsSeedTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
});

it('seeds the 3 new F4 permission slugs', function () {
    foreach (['gateway.view', 'gateway.create', 'gateway.refund'] as $slug) {
        expect(Permission::where('slug', $slug)->exists())->toBeTrue("missing perm: {$slug}");
    }
});

it('grants gateway.view + gateway.create to finance_officer (NOT gateway.refund)', function () {
    $role = Role::where('slug', 'finance_officer')->firstOrFail();
    $slugs = $role->permissions()->pluck('slug')->all();

    expect($slugs)->toContain('gateway.view', 'gateway.create');
    expect($slugs)->not->toContain('gateway.refund');
});

it('grants gateway.view (view-only) to auditor', function () {
    $role = Role::where('slug', 'auditor')->firstOrFail();
    $slugs = $role->permissions()->pluck('slug')->all();

    expect($slugs)->toContain('gateway.view');
    expect($slugs)->not->toContain('gateway.create', 'gateway.refund');
});

it('legacy ROLE_PERMISSIONS lock-step for finance_officer', function () {
    expect(User::ROLE_PERMISSIONS['finance_officer'])->toContain('gateway.view', 'gateway.create');
    expect(User::ROLE_PERMISSIONS['finance_officer'])->not->toContain('gateway.refund');
});

it('super_admin gets gateway.refund via wildcard', function () {
    $u = User::factory()->create(['role' => 'super_admin']);
    expect($u->hasPermission('gateway.refund'))->toBeTrue();
});
```

- [ ] **Step 2: Run test — must FAIL**

```
php artisan test --filter=F4PermissionsSeedTest
```

- [ ] **Step 3: Add 3 new perms to `RolePermissionSeeder::PERMISSIONS`**

Open `database/seeders/RolePermissionSeeder.php`. Find the F3 block (search for `// ── F3: Finance — Accounts Receivable ──`). It ends with `'statements.view' => ['Finance', '...']`. Immediately AFTER that line, add:

```php
        // ── F4: Finance — Paystack Gateway ──
        'gateway.view'   => ['Finance', 'View payment intents and gateway events'],
        'gateway.create' => ['Finance', 'Generate Paystack payment links'],
        'gateway.refund' => ['Finance', 'Refund a processed Paystack payment'],
```

- [ ] **Step 4: Grant to `finance_officer` and `auditor` in `ROLE_PERMS`**

Find the `'finance_officer'` block. After the F3 finance slugs (the line ending with `'statements.view',`), add:

```php
            // F4 — Paystack Gateway (no refund — super_admin only)
            'gateway.view', 'gateway.create',
```

Find the `'auditor'` block. After the F3 view-only slugs, add:

```php
            // F4 — Read-only gateway oversight
            'gateway.view',
```

- [ ] **Step 5: Mirror in `User::ROLE_PERMISSIONS`**

Open `app/Models/User.php`. Find `public const ROLE_PERMISSIONS`. Append `'gateway.view', 'gateway.create'` to the `'finance_officer'` array and `'gateway.view'` to the `'auditor'` array (same comment markers as the seeder for traceability).

- [ ] **Step 6: Run test — must PASS**

```
php artisan test --filter=F4PermissionsSeedTest
```
Expected: 5 tests pass.

- [ ] **Step 7: Commit**

```
git add database/seeders/RolePermissionSeeder.php app/Models/User.php tests/Feature/Finance/F4PermissionsSeedTest.php
git commit -m "$(cat <<'EOF'
feat(finance): F4 permissions (gateway.view/create/refund)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: PaystackGatewayService + exceptions + config

**Files:**
- Create: `app/Exceptions/Finance/PaystackException.php`
- Create: `app/Exceptions/Finance/PaystackUnreachableException.php`
- Create: `app/Services/Finance/PaystackGatewayService.php`
- Modify: `config/services.php` — add 'paystack' block
- Modify: `.env.example` — document PAYSTACK_* keys
- Test: `tests/Feature/Finance/PaystackGatewayServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/PaystackGatewayServiceTest.php`:

```php
<?php

declare(strict_types=1);

use App\Exceptions\Finance\PaystackException;
use App\Services\Finance\PaystackGatewayService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.paystack.url'        => 'https://api.paystack.co',
        'services.paystack.secret_key' => 'sk_test_secret',
    ]);
    $this->svc = app(PaystackGatewayService::class);
});

it('initializeTransaction converts GHS to pesewas and returns authorization data', function () {
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/abc123',
                'access_code'       => 'ac_abc',
                'reference'         => 'pst_ref_001',
            ],
        ], 200),
    ]);

    $result = $this->svc->initializeTransaction([
        'email'     => 'cust@example.com',
        'amount'    => 250.50,         // GHS
        'reference' => 'PI-2026-000001',
    ]);

    expect($result['authorization_url'])->toBe('https://checkout.paystack.com/abc123');
    expect($result['reference'])->toBe('pst_ref_001');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.paystack.co/transaction/initialize'
            && $request['amount'] === 25050  // 250.50 GHS * 100 = 25050 pesewas
            && $request['email']  === 'cust@example.com'
            && $request->hasHeader('Authorization', 'Bearer sk_test_secret');
    });
});

it('initializeTransaction throws PaystackException on non-2xx', function () {
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response([
            'status'  => false,
            'message' => 'Invalid email',
        ], 422),
    ]);

    expect(fn () => $this->svc->initializeTransaction([
        'email' => 'bad', 'amount' => 100, 'reference' => 'X',
    ]))->toThrow(PaystackException::class, 'Invalid email');
});

it('verifyTransaction returns the full transaction object', function () {
    Http::fake([
        'api.paystack.co/transaction/verify/pst_ref_001' => Http::response([
            'status' => true,
            'data'   => [
                'status'    => 'success',
                'amount'    => 25050,
                'reference' => 'pst_ref_001',
                'paid_at'   => '2026-05-23T10:30:00Z',
                'channel'   => 'mobile_money',
            ],
        ], 200),
    ]);

    $tx = $this->svc->verifyTransaction('pst_ref_001');

    expect($tx['status'])->toBe('success');
    expect($tx['amount'])->toBe(25050);
});

it('verifyTransaction throws PaystackException when API status is false', function () {
    Http::fake([
        'api.paystack.co/transaction/verify/pst_ref_bad' => Http::response([
            'status' => false, 'message' => 'Transaction reference not found',
        ], 404),
    ]);

    expect(fn () => $this->svc->verifyTransaction('pst_ref_bad'))
        ->toThrow(PaystackException::class, 'reference not found');
});
```

- [ ] **Step 2: Run test — must FAIL**

```
php artisan test --filter=PaystackGatewayServiceTest
```

- [ ] **Step 3: Create exceptions**

`app/Exceptions/Finance/PaystackException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Finance;

use RuntimeException;

class PaystackException extends RuntimeException
{
}
```

`app/Exceptions/Finance/PaystackUnreachableException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Finance;

use RuntimeException;

class PaystackUnreachableException extends RuntimeException
{
}
```

- [ ] **Step 4: Update `config/services.php`**

Open `config/services.php`. Add the following at the end of the returned array (before the closing `];`):

```php
    'paystack' => [
        'url'                  => env('PAYSTACK_URL', 'https://api.paystack.co'),
        'public_key'           => env('PAYSTACK_PUBLIC_KEY'),
        'secret_key'           => env('PAYSTACK_SECRET_KEY'),
        'webhook_secret'       => env('PAYSTACK_WEBHOOK_SECRET'),
        'receipt_bank_purpose' => env('PAYSTACK_RECEIPT_BANK_PURPOSE', 'receipts'),
        'callback_default_url' => env('PAYSTACK_CALLBACK_DEFAULT_URL'),
    ],
```

- [ ] **Step 5: Append `.env.example` keys**

Append to `.env.example`:

```
# F4 — Paystack Gateway
PAYSTACK_URL=https://api.paystack.co
PAYSTACK_PUBLIC_KEY=
PAYSTACK_SECRET_KEY=
PAYSTACK_WEBHOOK_SECRET=
PAYSTACK_RECEIPT_BANK_PURPOSE=receipts
PAYSTACK_CALLBACK_DEFAULT_URL=
```

- [ ] **Step 6: Create `PaystackGatewayService`**

`app/Services/Finance/PaystackGatewayService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Exceptions\Finance\PaystackException;
use App\Exceptions\Finance\PaystackUnreachableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the Paystack REST API.
 *
 * Responsibilities:
 *   • Pesewas conversion (Paystack: amounts in minor units; CIHRMS: GHS)
 *   • Bearer-token authentication via config('services.paystack.secret_key')
 *   • Translates 4xx/5xx into PaystackException; connection failures into
 *     PaystackUnreachableException
 *
 * NO business logic — that lives in PaymentIntentService / WebhookProcessor.
 */
class PaystackGatewayService
{
    /**
     * @param  array{email:string, amount:float, reference:string, callback_url?:string, metadata?:array}  $data
     * @return array{authorization_url:string, access_code:string, reference:string}
     */
    public function initializeTransaction(array $data): array
    {
        $payload = [
            'email'     => $data['email'],
            'amount'    => (int) round($data['amount'] * 100),   // GHS → pesewas
            'reference' => $data['reference'],
        ];
        if (! empty($data['callback_url'])) {
            $payload['callback_url'] = $data['callback_url'];
        }
        if (! empty($data['metadata'])) {
            $payload['metadata'] = $data['metadata'];
        }

        $response = $this->client()->post('/transaction/initialize', $payload);

        return $this->parse($response, '/transaction/initialize')['data'];
    }

    /**
     * @return array  the parsed `data` field from Paystack's verify response
     */
    public function verifyTransaction(string $reference): array
    {
        $response = $this->client()->get("/transaction/verify/{$reference}");

        return $this->parse($response, "/transaction/verify/{$reference}")['data'];
    }

    private function client()
    {
        try {
            return Http::baseUrl(config('services.paystack.url'))
                ->withToken(config('services.paystack.secret_key'))
                ->acceptJson()
                ->asJson()
                ->timeout(15)
                ->retry(2, 250);
        } catch (ConnectionException $e) {
            throw new PaystackUnreachableException('Paystack unreachable: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array{status:bool, message?:string, data:array}
     */
    private function parse(\Illuminate\Http\Client\Response $response, string $endpoint): array
    {
        $body = $response->json();

        if (! $response->ok() || ! is_array($body) || ($body['status'] ?? false) !== true) {
            $message = $body['message'] ?? "Paystack {$endpoint} returned HTTP {$response->status()}";
            throw new PaystackException($message);
        }

        return $body;
    }
}
```

- [ ] **Step 7: Run test — must PASS**

```
php artisan test --filter=PaystackGatewayServiceTest
```
Expected: 4 tests pass.

- [ ] **Step 8: Commit**

```
git add app/Exceptions/Finance/PaystackException.php app/Exceptions/Finance/PaystackUnreachableException.php app/Services/Finance/PaystackGatewayService.php config/services.php .env.example tests/Feature/Finance/PaystackGatewayServiceTest.php
git commit -m "$(cat <<'EOF'
feat(finance): PaystackGatewayService — pesewas-aware HTTP wrapper

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: PaymentIntentService

**Files:**
- Create: `app/Services/Finance/PaymentIntentService.php`
- Test: `tests/Feature/Finance/PaymentIntentServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/PaymentIntentServiceTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\PaymentIntentStatus;
use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\GlAccount;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Services\Finance\ArInvoiceService;
use App\Services\Finance\PaymentIntentService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.paystack.url'        => 'https://api.paystack.co',
        'services.paystack.secret_key' => 'sk_test_secret',
    ]);

    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();

    $this->svc      = app(PaymentIntentService::class);
    $this->user     = User::factory()->create();
    $this->customer = Customer::create([
        'code' => 'CUS-P', 'name' => 'Pay', 'status' => 'active', 'email' => 'pay@example.com',
    ]);

    $income = GlAccount::where('code', '4100')->firstOrFail();
    $inv    = app(ArInvoiceService::class)->create([
        'customer_id' => $this->customer->id, 'invoice_date' => '2026-05-23',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 500, 'gl_account_id' => $income->id]],
    ], $this->user);
    app(ArInvoiceService::class)->submit($inv);
    $approver = User::factory()->create();
    app(ArInvoiceService::class)->approve($inv->fresh(), $approver);
    $this->invoice = $inv->fresh();
});

it('createForInvoice posts to Paystack and stores authorization_url', function () {
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/abc',
                'access_code'       => 'ac_abc',
                'reference'         => 'pst_001',
            ],
        ], 200),
    ]);

    $intent = $this->svc->createForInvoice($this->invoice, 500.0, $this->user);

    expect($intent->status)->toBe(PaymentIntentStatus::Pending);
    expect($intent->paystack_reference)->toBe('pst_001');
    expect($intent->authorization_url)->toBe('https://checkout.paystack.com/abc');
    expect((float) $intent->amount)->toBe(500.0);
    expect($intent->customer_id)->toBe($this->customer->id);
    expect($intent->ar_invoice_id)->toBe($this->invoice->id);
    expect($intent->expires_at)->not->toBeNull();
});

it('createForInvoice refuses if invoice status is not Approved or PartiallyPaid', function () {
    $income = GlAccount::where('code', '4100')->firstOrFail();
    $draft  = app(ArInvoiceService::class)->create([
        'customer_id' => $this->customer->id, 'invoice_date' => '2026-05-23',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'gl_account_id' => $income->id]],
    ], $this->user);

    expect(fn () => $this->svc->createForInvoice($draft->fresh(), 100.0, $this->user))
        ->toThrow(\DomainException::class, 'status');
});

it('createForInvoice refuses if amount exceeds outstanding', function () {
    Http::fake();  // Should not hit Paystack
    expect(fn () => $this->svc->createForInvoice($this->invoice, 1000.0, $this->user))
        ->toThrow(\DomainException::class, 'outstanding');
});

it('createForInvoice refuses if customer has no email', function () {
    $this->customer->update(['email' => null]);
    expect(fn () => $this->svc->createForInvoice($this->invoice->fresh(), 500.0, $this->user))
        ->toThrow(\DomainException::class, 'email');
});

it('expireStale flips pending+old intents to expired', function () {
    PaymentIntent::create([
        'reference' => 'OLD', 'customer_id' => $this->customer->id,
        'amount' => 100, 'status' => 'pending',
        'expires_at' => now()->subHours(2), 'created_by' => $this->user->id,
    ]);
    PaymentIntent::create([
        'reference' => 'FRESH', 'customer_id' => $this->customer->id,
        'amount' => 100, 'status' => 'pending',
        'expires_at' => now()->addHours(2), 'created_by' => $this->user->id,
    ]);

    $count = $this->svc->expireStale();

    expect($count)->toBe(1);
    expect(PaymentIntent::where('reference', 'OLD')->first()->status)->toBe(PaymentIntentStatus::Expired);
    expect(PaymentIntent::where('reference', 'FRESH')->first()->status)->toBe(PaymentIntentStatus::Pending);
});
```

- [ ] **Step 2: Run test — must FAIL**

```
php artisan test --filter=PaymentIntentServiceTest
```

- [ ] **Step 3: Create the service**

`app/Services/Finance/PaymentIntentService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\ArInvoiceStatus;
use App\Enums\PaymentIntentStatus;
use App\Models\ArInvoice;
use App\Models\PaymentIntent;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class PaymentIntentService
{
    public function __construct(private readonly PaystackGatewayService $gateway)
    {
    }

    public function createForInvoice(
        ArInvoice $invoice,
        float $amount,
        User $creator,
        ?string $callbackUrl = null,
    ): PaymentIntent {
        // Pre-validation (cheap, before any DB write or gateway call)
        if (! in_array($invoice->status, [ArInvoiceStatus::Approved, ArInvoiceStatus::PartiallyPaid], true)) {
            throw new DomainException(
                "Cannot create payment intent for invoice {$invoice->reference}: status is {$invoice->status->value}."
            );
        }
        if ($amount > $invoice->outstandingAmount() + 0.005) {
            throw new DomainException(sprintf(
                'Amount %.2f exceeds outstanding %.2f on invoice %s.',
                $amount, $invoice->outstandingAmount(), $invoice->reference,
            ));
        }
        $customer = $invoice->customer;
        if (empty($customer->email)) {
            throw new DomainException(
                "Customer {$customer->code} has no email address — required for Paystack."
            );
        }

        return DB::transaction(function () use ($invoice, $amount, $creator, $callbackUrl, $customer) {
            $intent = PaymentIntent::create([
                'reference'     => $this->nextReference(),
                'customer_id'   => $customer->id,
                'ar_invoice_id' => $invoice->id,
                'amount'        => $amount,
                'currency'      => 'GHS',
                'status'        => PaymentIntentStatus::Created->value,
                'callback_url'  => $callbackUrl ?? config('services.paystack.callback_default_url'),
                'created_by'    => $creator->id,
            ]);

            // Call Paystack — if this throws, the transaction rolls back.
            $paystackData = $this->gateway->initializeTransaction([
                'email'        => $customer->email,
                'amount'       => $amount,
                'reference'    => $intent->reference,
                'callback_url' => $intent->callback_url,
                'metadata'     => [
                    'cihrms_intent_id'    => $intent->id,
                    'cihrms_invoice_ref'  => $invoice->reference,
                    'cihrms_customer_code'=> $customer->code,
                ],
            ]);

            $intent->update([
                'status'                 => PaymentIntentStatus::Pending->value,
                'paystack_reference'     => $paystackData['reference'],
                'paystack_access_code'   => $paystackData['access_code'],
                'authorization_url'      => $paystackData['authorization_url'],
                'expires_at'             => now()->addHours(24),
                'last_paystack_response' => $paystackData,
            ]);

            return $intent->fresh();
        });
    }

    public function expireStale(): int
    {
        return PaymentIntent::stale()
            ->update(['status' => PaymentIntentStatus::Expired->value]);
    }

    private function nextReference(): string
    {
        $year = now()->format('Y');
        $count = PaymentIntent::query()->where('reference', 'like', "PI-{$year}-%")->count();
        return sprintf('PI-%s-%06d', $year, $count + 1);
    }
}
```

- [ ] **Step 4: Run test — must PASS**

```
php artisan test --filter=PaymentIntentServiceTest
```
Expected: 5 tests pass.

- [ ] **Step 5: Commit**

```
git add app/Services/Finance/PaymentIntentService.php tests/Feature/Finance/PaymentIntentServiceTest.php
git commit -m "$(cat <<'EOF'
feat(finance): PaymentIntentService — invoice validation + Paystack init in a transaction

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: PaystackWebhookProcessor

**Files:**
- Create: `app/Services/Finance/PaystackWebhookProcessor.php`
- Test: `tests/Feature/Finance/PaystackWebhookProcessorTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/PaystackWebhookProcessorTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\ArInvoiceStatus;
use App\Enums\PaymentIntentStatus;
use App\Models\ArInvoice;
use App\Models\ArReceipt;
use App\Models\Customer;
use App\Models\GlAccount;
use App\Models\OrgBankAccount;
use App\Models\PaymentIntent;
use App\Models\PaystackWebhookEvent;
use App\Models\User;
use App\Services\Finance\ArInvoiceService;
use App\Services\Finance\PaystackGatewayService;
use App\Services\Finance\PaystackWebhookProcessor;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.paystack.url'                  => 'https://api.paystack.co',
        'services.paystack.secret_key'           => 'sk_test_secret',
        'services.paystack.receipt_bank_purpose' => 'receipts',
    ]);

    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new OrgBankAccountSeeder())->run();

    // Ensure the "receipts"-purpose bank exists for tests
    $receiptsBank = OrgBankAccount::forPurpose('receipts')->first();
    if (! $receiptsBank) {
        $gl = GlAccount::where('code', '1110')->first() ?: GlAccount::create([
            'code' => '1110', 'name' => 'Bank - Receipts', 'type' => 'asset',
        ]);
        \App\Models\GlAccountBalance::firstOrCreate(['gl_account_id' => $gl->id], ['balance' => 0]);
        OrgBankAccount::create([
            'gl_account_id' => $gl->id, 'bank_name' => 'GTBank', 'account_name' => 'CIHRM Receipts',
            'account_number' => '7777777777', 'purpose' => 'receipts',
        ]);
    }

    $this->user     = User::factory()->create();
    $this->approver = User::factory()->create();
    $this->customer = Customer::create([
        'code' => 'CUS-W', 'name' => 'Web', 'status' => 'active', 'email' => 'web@example.com',
    ]);

    // Create + approve an invoice via the F3 service
    $income = GlAccount::where('code', '4100')->firstOrFail();
    $inv = app(ArInvoiceService::class)->create([
        'customer_id' => $this->customer->id, 'invoice_date' => '2026-05-23',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 300, 'gl_account_id' => $income->id]],
    ], $this->user);
    app(ArInvoiceService::class)->submit($inv);
    app(ArInvoiceService::class)->approve($inv->fresh(), $this->approver);
    $this->invoice = $inv->fresh();

    // Create a pending payment intent (without going through Paystack init)
    $this->intent = PaymentIntent::create([
        'reference'          => 'PI-2026-000001',
        'customer_id'        => $this->customer->id,
        'ar_invoice_id'      => $this->invoice->id,
        'amount'             => 300,
        'currency'           => 'GHS',
        'status'             => 'pending',
        'paystack_reference' => 'pst_webhook_001',
        'expires_at'         => now()->addHours(24),
        'created_by'         => $this->user->id,
    ]);

    $this->processor = app(PaystackWebhookProcessor::class);
});

function makeChargeSuccessEvent(string $eventId, string $paystackRef): PaystackWebhookEvent
{
    return PaystackWebhookEvent::create([
        'paystack_event_id'  => $eventId,
        'event_type'         => 'charge.success',
        'paystack_reference' => $paystackRef,
        'payload'            => [
            'event' => 'charge.success',
            'data'  => ['id' => 1, 'reference' => $paystackRef, 'status' => 'success', 'amount' => 30000],
        ],
        'signature'          => 'sig',
    ]);
}

it('charge.success with matching intent posts an ArReceipt and links everything', function () {
    Http::fake([
        'api.paystack.co/transaction/verify/pst_webhook_001' => Http::response([
            'status' => true,
            'data'   => ['status' => 'success', 'reference' => 'pst_webhook_001', 'amount' => 30000],
        ], 200),
    ]);

    $event = makeChargeSuccessEvent('evt_001', 'pst_webhook_001');
    $receipt = $this->processor->process($event);

    expect($receipt)->toBeInstanceOf(ArReceipt::class);
    expect($receipt->external_ref)->toBe('pst_webhook_001');
    expect((float) $receipt->amount)->toBe(300.0);
    expect($this->intent->fresh()->status)->toBe(PaymentIntentStatus::Success);
    expect($this->intent->fresh()->ar_receipt_id)->toBe($receipt->id);
    expect($event->fresh()->processed_at)->not->toBeNull();
    expect($event->fresh()->ar_receipt_id)->toBe($receipt->id);
    expect($this->invoice->fresh()->status)->toBe(ArInvoiceStatus::Paid);
});

it('re-processing the same event short-circuits (idempotent)', function () {
    Http::fake([
        'api.paystack.co/transaction/verify/pst_webhook_001' => Http::response([
            'status' => true,
            'data'   => ['status' => 'success', 'reference' => 'pst_webhook_001', 'amount' => 30000],
        ], 200),
    ]);

    $event = makeChargeSuccessEvent('evt_002', 'pst_webhook_001');
    $receipt1 = $this->processor->process($event);
    $receipt2 = $this->processor->process($event->fresh());

    expect($receipt2->id)->toBe($receipt1->id);
    expect(ArReceipt::count())->toBe(1);
});

it('charge.success with unknown paystack_reference records error and creates no receipt', function () {
    $event = makeChargeSuccessEvent('evt_003', 'pst_unknown_ref');
    $receipt = $this->processor->process($event);

    expect($receipt)->toBeNull();
    expect($event->fresh()->processing_error)->toContain('not found');
    expect(ArReceipt::count())->toBe(0);
});

it('charge.success with amount mismatch records error and creates no receipt', function () {
    Http::fake([
        'api.paystack.co/transaction/verify/pst_webhook_001' => Http::response([
            'status' => true,
            'data'   => ['status' => 'success', 'reference' => 'pst_webhook_001', 'amount' => 99999], // mismatch
        ], 200),
    ]);

    $event = makeChargeSuccessEvent('evt_004', 'pst_webhook_001');
    $receipt = $this->processor->process($event);

    expect($receipt)->toBeNull();
    expect($event->fresh()->processing_error)->toContain('amount');
    expect($this->intent->fresh()->status)->toBe(PaymentIntentStatus::Pending);
    expect(ArReceipt::count())->toBe(0);
});

it('non-charge-success events are recorded as no-op', function () {
    $event = PaystackWebhookEvent::create([
        'paystack_event_id' => 'evt_005', 'event_type' => 'charge.failed',
        'paystack_reference' => 'pst_webhook_001',
        'payload' => ['event' => 'charge.failed', 'data' => []],
        'signature' => 'sig',
    ]);

    $result = $this->processor->process($event);

    expect($result)->toBeNull();
    expect($event->fresh()->processed_at)->not->toBeNull();
    expect($event->fresh()->processing_error)->toContain('no-op');
});
```

- [ ] **Step 2: Run test — must FAIL**

```
php artisan test --filter=PaystackWebhookProcessorTest
```

- [ ] **Step 3: Create the processor**

`app/Services/Finance/PaystackWebhookProcessor.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\ArReceiptStatus;
use App\Enums\PaymentIntentStatus;
use App\Models\ArReceipt;
use App\Models\OrgBankAccount;
use App\Models\PaymentIntent;
use App\Models\PaystackWebhookEvent;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaystackWebhookProcessor
{
    public function __construct(
        private readonly PaystackGatewayService $gateway,
        private readonly ArReceiptService $receipts,
    ) {
    }

    public function process(PaystackWebhookEvent $event): ?ArReceipt
    {
        // Idempotency: if already processed, short-circuit and return prior receipt (if any).
        if ($event->processed_at !== null) {
            return $event->receipt;
        }

        try {
            return match ($event->event_type) {
                'charge.success' => $this->handleChargeSuccess($event),
                default          => $this->markNoOp($event),
            };
        } catch (Throwable $e) {
            // Persistent processing error — record and re-throw so the queue retries.
            $event->update(['processing_error' => $e->getMessage()]);
            Log::error('Paystack webhook processing failed', [
                'event_id' => $event->id, 'paystack_event_id' => $event->paystack_event_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function handleChargeSuccess(PaystackWebhookEvent $event): ?ArReceipt
    {
        return DB::transaction(function () use ($event) {
            $intent = PaymentIntent::where('paystack_reference', $event->paystack_reference)
                ->lockForUpdate()
                ->first();

            if (! $intent) {
                $event->update([
                    'processed_at'     => now(),
                    'processing_error' => "PaymentIntent for paystack_reference '{$event->paystack_reference}' not found",
                ]);
                return null;
            }

            // If already succeeded, return the existing receipt (extra-defense against re-fires).
            if ($intent->status === PaymentIntentStatus::Success) {
                $event->update([
                    'processed_at'      => now(),
                    'payment_intent_id' => $intent->id,
                    'ar_receipt_id'     => $intent->ar_receipt_id,
                ]);
                return $intent->receipt;
            }

            // Refuse for terminal non-success states (failed/abandoned/expired).
            if ($intent->status !== PaymentIntentStatus::Pending) {
                $event->update([
                    'processed_at'      => now(),
                    'payment_intent_id' => $intent->id,
                    'processing_error'  => "PaymentIntent {$intent->reference} status is {$intent->status->value}; cannot post receipt.",
                ]);
                return null;
            }

            // Belt-and-braces: verify the transaction directly with Paystack.
            $verified = $this->gateway->verifyTransaction($event->paystack_reference);

            $expectedPesewas = (int) round((float) $intent->amount * 100);
            if (($verified['status'] ?? null) !== 'success') {
                $event->update([
                    'processed_at'      => now(),
                    'payment_intent_id' => $intent->id,
                    'processing_error'  => "Paystack verify returned status '{$verified['status']}' for {$event->paystack_reference}",
                ]);
                return null;
            }
            if ((int) ($verified['amount'] ?? 0) !== $expectedPesewas) {
                $event->update([
                    'processed_at'      => now(),
                    'payment_intent_id' => $intent->id,
                    'processing_error'  => sprintf(
                        'Amount mismatch on %s: intent expects %d pesewas, Paystack reports %d',
                        $event->paystack_reference,
                        $expectedPesewas,
                        (int) ($verified['amount'] ?? 0),
                    ),
                ]);
                return null;
            }

            // Resolve receiving bank account (purpose = receipts).
            $bank = OrgBankAccount::forPurpose(config('services.paystack.receipt_bank_purpose'))
                ->active()
                ->first();

            if (! $bank) {
                throw new DomainException(
                    'No active org_bank_account with purpose='
                    . config('services.paystack.receipt_bank_purpose')
                    . '. Configure one before processing Paystack receipts.'
                );
            }

            // Defense-in-depth: if a receipt with this external_ref already exists, link and exit.
            $existing = ArReceipt::where('external_ref', $event->paystack_reference)->first();
            if ($existing) {
                $intent->update([
                    'status'        => PaymentIntentStatus::Success->value,
                    'paid_at'       => now(),
                    'ar_receipt_id' => $existing->id,
                ]);
                $event->update([
                    'processed_at'      => now(),
                    'payment_intent_id' => $intent->id,
                    'ar_receipt_id'     => $existing->id,
                ]);
                return $existing;
            }

            // Post the AR receipt via the F3 service.
            $receipt = $this->receipts->record([
                'customer_id'         => $intent->customer_id,
                'receipt_date'        => now()->format('Y-m-d'),
                'amount'              => (float) $intent->amount,
                'currency'            => $intent->currency,
                'org_bank_account_id' => $bank->id,
                'external_ref'        => $event->paystack_reference,
                'narration'           => "Paystack — {$intent->reference}",
                'allocations'         => [[
                    'ar_invoice_id'    => $intent->ar_invoice_id,
                    'allocated_amount' => (float) $intent->amount,
                ]],
            ], $intent->creator);

            $intent->update([
                'status'        => PaymentIntentStatus::Success->value,
                'paid_at'       => now(),
                'ar_receipt_id' => $receipt->id,
            ]);

            $event->update([
                'processed_at'      => now(),
                'payment_intent_id' => $intent->id,
                'ar_receipt_id'     => $receipt->id,
            ]);

            return $receipt;
        });
    }

    private function markNoOp(PaystackWebhookEvent $event): null
    {
        $event->update([
            'processed_at'     => now(),
            'processing_error' => "no-op for event_type {$event->event_type}",
        ]);
        return null;
    }
}
```

- [ ] **Step 4: Run test — must PASS**

```
php artisan test --filter=PaystackWebhookProcessorTest
```
Expected: 5 tests pass.

- [ ] **Step 5: Commit**

```
git add app/Services/Finance/PaystackWebhookProcessor.php tests/Feature/Finance/PaystackWebhookProcessorTest.php
git commit -m "$(cat <<'EOF'
feat(finance): PaystackWebhookProcessor — idempotent charge.success → ArReceipt

Verify-on-receive (belt-and-braces) against Paystack API before posting the receipt.
Routes through F3's ArReceiptService::record() — single mutator preserved. Amount
mismatch / unknown reference / non-pending intent all recorded as processing_error
without creating receipts. Wrapped in DB::transaction with lockForUpdate on intent.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: VerifyPaystackSignature middleware + register alias

**Files:**
- Create: `app/Http/Middleware/VerifyPaystackSignature.php`
- Modify: `bootstrap/app.php` — register `'paystack.signature'` alias
- Test: `tests/Feature/Finance/VerifyPaystackSignatureTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/VerifyPaystackSignatureTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config(['services.paystack.webhook_secret' => 'whsec_test_value']);

    Route::post('/_test/paystack-sig', fn () => response('ok', 200))
        ->middleware('paystack.signature');
});

function paystackSig(string $body, string $secret): string
{
    return hash_hmac('sha512', $body, $secret);
}

it('passes when signature matches HMAC-SHA512 of body', function () {
    $body = json_encode(['event' => 'charge.success', 'data' => ['id' => 1]]);
    $sig  = paystackSig($body, 'whsec_test_value');

    $this->withHeaders(['X-Paystack-Signature' => $sig])
        ->call('POST', '/_test/paystack-sig', [], [], [], [], $body)
        ->assertOk();
});

it('rejects when signature is missing', function () {
    $this->call('POST', '/_test/paystack-sig', [], [], [], [], '{"x":1}')
        ->assertStatus(400);
});

it('rejects when signature is wrong', function () {
    $this->withHeaders(['X-Paystack-Signature' => 'totally-bogus-sig'])
        ->call('POST', '/_test/paystack-sig', [], [], [], [], '{"x":1}')
        ->assertStatus(400);
});

it('uses constant-time comparison (hash_equals)', function () {
    // Sanity test: a near-correct signature is rejected.
    $body = '{"x":1}';
    $good = paystackSig($body, 'whsec_test_value');
    $bad  = substr($good, 0, -1) . 'X';

    $this->withHeaders(['X-Paystack-Signature' => $bad])
        ->call('POST', '/_test/paystack-sig', [], [], [], [], $body)
        ->assertStatus(400);
});
```

- [ ] **Step 2: Run test — must FAIL**

```
php artisan test --filter=VerifyPaystackSignatureTest
```

- [ ] **Step 3: Create the middleware**

`app/Http/Middleware/VerifyPaystackSignature.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies Paystack webhook signatures via HMAC-SHA512.
 *
 * Paystack signs each webhook with the merchant's webhook secret and sends
 * the result in the `X-Paystack-Signature` header. We compute the same
 * HMAC over the raw request body and compare with `hash_equals` (constant-
 * time) to prevent timing attacks.
 *
 * Reads RAW body bytes via Request::getContent() — must happen BEFORE
 * Laravel parses JSON, so this middleware should be applied at the route
 * level (not after web middleware that mutates the request).
 */
class VerifyPaystackSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret    = (string) config('services.paystack.webhook_secret');
        $signature = (string) $request->header('X-Paystack-Signature', '');

        if ($secret === '' || $signature === '') {
            return response()->json(['error' => 'invalid_signature'], 400);
        }

        $computed = hash_hmac('sha512', $request->getContent(), $secret);

        if (! hash_equals($computed, $signature)) {
            return response()->json(['error' => 'invalid_signature'], 400);
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Register the middleware alias**

Open `bootstrap/app.php`. Find the existing `->withMiddleware(function (Middleware $middleware) { ... })` block. Inside, find the `alias([...])` call (where existing aliases like `'permission' => ...` are registered). Add:

```php
'paystack.signature' => \App\Http\Middleware\VerifyPaystackSignature::class,
```

- [ ] **Step 5: Run test — must PASS**

```
php artisan test --filter=VerifyPaystackSignatureTest
```
Expected: 4 tests pass.

- [ ] **Step 6: Commit**

```
git add app/Http/Middleware/VerifyPaystackSignature.php bootstrap/app.php tests/Feature/Finance/VerifyPaystackSignatureTest.php
git commit -m "$(cat <<'EOF'
feat(finance): VerifyPaystackSignature middleware (HMAC-SHA512, constant-time)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: ProcessPaystackWebhook job + webhook controller + route

**Files:**
- Create: `app/Jobs/ProcessPaystackWebhook.php`
- Create: `app/Http/Controllers/Finance/PaystackWebhookController.php`
- Modify: `routes/web.php` — public `/webhooks/paystack` route OUTSIDE the auth group
- Test: `tests/Feature/Finance/PaystackWebhookEndpointTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/PaystackWebhookEndpointTest.php`:

```php
<?php

declare(strict_types=1);

use App\Jobs\ProcessPaystackWebhook;
use App\Models\PaystackWebhookEvent;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['services.paystack.webhook_secret' => 'whsec_test_value']);
});

function signedPayload(array $payload, string $secret): array
{
    $body = json_encode($payload);
    return [$body, hash_hmac('sha512', $body, $secret)];
}

it('valid signature persists event row and dispatches job', function () {
    Queue::fake();

    $payload = [
        'event' => 'charge.success',
        'data' => [
            'id' => 12345,
            'reference' => 'pst_endpoint_001',
            'status' => 'success',
            'amount' => 50000,
        ],
    ];
    [$body, $sig] = signedPayload($payload, 'whsec_test_value');

    $this->call('POST', '/webhooks/paystack', [], [], [],
        ['HTTP_X-Paystack-Signature' => $sig, 'CONTENT_TYPE' => 'application/json'],
        $body
    )->assertOk();

    expect(PaystackWebhookEvent::count())->toBe(1);
    $event = PaystackWebhookEvent::first();
    expect($event->paystack_event_id)->toBe('12345');
    expect($event->event_type)->toBe('charge.success');
    expect($event->paystack_reference)->toBe('pst_endpoint_001');

    Queue::assertPushed(ProcessPaystackWebhook::class);
});

it('invalid signature returns 400 and creates no event row', function () {
    $payload = ['event' => 'charge.success', 'data' => ['id' => 99]];
    $body = json_encode($payload);

    $this->call('POST', '/webhooks/paystack', [], [], [],
        ['HTTP_X-Paystack-Signature' => 'bad-sig', 'CONTENT_TYPE' => 'application/json'],
        $body
    )->assertStatus(400);

    expect(PaystackWebhookEvent::count())->toBe(0);
});

it('missing signature header returns 400', function () {
    $payload = ['event' => 'charge.success', 'data' => ['id' => 99]];
    $body = json_encode($payload);

    $this->call('POST', '/webhooks/paystack', [], [], [],
        ['CONTENT_TYPE' => 'application/json'],
        $body
    )->assertStatus(400);
});

it('replayed payload is idempotent (one event row only)', function () {
    Queue::fake();

    $payload = [
        'event' => 'charge.success',
        'data' => ['id' => 7777, 'reference' => 'pst_replay_001', 'status' => 'success', 'amount' => 10000],
    ];
    [$body, $sig] = signedPayload($payload, 'whsec_test_value');

    $this->call('POST', '/webhooks/paystack', [], [], [],
        ['HTTP_X-Paystack-Signature' => $sig, 'CONTENT_TYPE' => 'application/json'],
        $body
    )->assertOk();

    // Second delivery of the same event
    $this->call('POST', '/webhooks/paystack', [], [], [],
        ['HTTP_X-Paystack-Signature' => $sig, 'CONTENT_TYPE' => 'application/json'],
        $body
    )->assertOk();

    expect(PaystackWebhookEvent::count())->toBe(1);
});

it('webhook route is public — no auth redirect', function () {
    // No session, no auth — should NOT redirect to login
    $payload = ['event' => 'charge.success', 'data' => ['id' => 1]];
    [$body, $sig] = signedPayload($payload, 'whsec_test_value');

    $response = $this->call('POST', '/webhooks/paystack', [], [], [],
        ['HTTP_X-Paystack-Signature' => $sig, 'CONTENT_TYPE' => 'application/json'],
        $body
    );

    expect($response->status())->not->toBe(302); // not a redirect
});
```

- [ ] **Step 2: Run test — must FAIL**

```
php artisan test --filter=PaystackWebhookEndpointTest
```

- [ ] **Step 3: Create `ProcessPaystackWebhook` job**

`app/Jobs/ProcessPaystackWebhook.php`:

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PaystackWebhookEvent;
use App\Services\Finance\PaystackWebhookProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessPaystackWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(public readonly int $eventId)
    {
    }

    public function handle(PaystackWebhookProcessor $processor): void
    {
        $event = PaystackWebhookEvent::find($this->eventId);
        if (! $event) {
            return;
        }
        $processor->process($event);
    }
}
```

- [ ] **Step 4: Create `PaystackWebhookController`**

`app/Http/Controllers/Finance/PaystackWebhookController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPaystackWebhook;
use App\Models\PaystackWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaystackWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->json()->all();

        $paystackEventId = (string) (data_get($payload, 'data.id') ?? '');
        if ($paystackEventId === '') {
            // Paystack always sends `data.id`; missing it = malformed but signed payload.
            return response()->json(['status' => 'ignored', 'reason' => 'missing data.id'], 200);
        }

        // Idempotent at the storage layer — duplicate paystack_event_id collides on the UNIQUE.
        try {
            $event = PaystackWebhookEvent::create([
                'paystack_event_id'  => $paystackEventId,
                'event_type'         => (string) data_get($payload, 'event'),
                'paystack_reference' => (string) data_get($payload, 'data.reference'),
                'payload'            => $payload,
                'signature'          => (string) $request->header('X-Paystack-Signature'),
            ]);
            ProcessPaystackWebhook::dispatch($event->id);
        } catch (Throwable $e) {
            // Likely a UNIQUE-constraint violation on paystack_event_id — replay. Safe to ignore.
            Log::info('Paystack webhook replay or insert error (safely ignored)', [
                'paystack_event_id' => $paystackEventId,
                'error'             => $e->getMessage(),
            ]);
        }

        // ALWAYS return 200 — Paystack retries on non-2xx, and we've persisted the event
        // (or already had it). The queued processor handles the rest asynchronously.
        return response()->json(['status' => 'received'], 200);
    }
}
```

- [ ] **Step 5: Add the webhook route**

Open `routes/web.php`. Find a location OUTSIDE the `Route::middleware(['auth', 'audit'])->group(...)` block — near the top of the file, or where other public routes live (e.g. near `/webhooks/biometric` if present, or after the homepage route). Add:

```php
// F4 — Paystack webhook (public, signature-verified)
Route::post('/webhooks/paystack', [\App\Http\Controllers\Finance\PaystackWebhookController::class, 'handle'])
    ->middleware(['paystack.signature', 'throttle:120,1'])
    ->name('webhooks.paystack');
```

CRITICAL: this route must be OUTSIDE the `auth` group. Paystack does not authenticate via session — it authenticates via HMAC signature.

- [ ] **Step 6: Run test — must PASS**

```
php artisan test --filter=PaystackWebhookEndpointTest
```
Expected: 5 tests pass.

- [ ] **Step 7: Commit**

```
git add app/Jobs/ProcessPaystackWebhook.php app/Http/Controllers/Finance/PaystackWebhookController.php routes/web.php tests/Feature/Finance/PaystackWebhookEndpointTest.php
git commit -m "$(cat <<'EOF'
feat(finance): public Paystack webhook endpoint + queued processor job

Controller persists the event row and dispatches ProcessPaystackWebhook job.
Always returns 200 — Paystack retries on non-2xx, and the queue handles the
async work. Idempotency at storage layer via paystack_event_id UNIQUE.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 10: Payment Intent endpoints (operator side)

**Files:**
- Create: `app/Http/Requests/Finance/StorePaymentIntentRequest.php`
- Create: `app/Http/Resources/Finance/PaymentIntentResource.php`
- Create: `app/Http/Controllers/Finance/PaymentIntentController.php`
- Create: `resources/js/Pages/Finance/PaymentIntents/Index.vue` (minimal stub — Task 11 expands)
- Modify: `routes/web.php` — payment-intents.* routes inside the `finance.` prefix group
- Test: `tests/Feature/Finance/PaymentIntentTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/PaymentIntentTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\GlAccount;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Services\Finance\ArInvoiceService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
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

    $this->customer = Customer::create([
        'code' => 'CUS-PI', 'name' => 'PI Test', 'status' => 'active', 'email' => 'pi@example.com',
    ]);
});

it('finance_officer can list payment intents', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u)->get('/finance/payment-intents')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/PaymentIntents/Index'));
});

it('auditor can list (gateway.view) but not create (gateway.create)', function () {
    $u = User::factory()->create(['role' => 'auditor']);
    $this->actingAs($u)->get('/finance/payment-intents')->assertOk();
    $this->actingAs($u)->post('/finance/payment-intents', [
        'ar_invoice_id' => 1, 'amount' => 100,
    ])->assertForbidden();
});

it('employee gets 403 on listing', function () {
    $u = User::factory()->create(['role' => 'employee']);
    $this->actingAs($u)->get('/finance/payment-intents')->assertForbidden();
});

it('finance_officer creates a payment intent (Paystack mocked)', function () {
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/xyz',
                'access_code'       => 'ac_xyz',
                'reference'         => 'pst_endpoint_create',
            ],
        ], 200),
    ]);

    $u = User::factory()->create(['role' => 'finance_officer']);
    $income = GlAccount::where('code', '4100')->firstOrFail();

    $inv = app(ArInvoiceService::class)->create([
        'customer_id' => $this->customer->id, 'invoice_date' => '2026-05-23',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 250, 'gl_account_id' => $income->id]],
    ], $u);
    app(ArInvoiceService::class)->submit($inv);
    $approver = User::factory()->create(['role' => 'finance_officer']);
    app(ArInvoiceService::class)->approve($inv->fresh(), $approver);

    $this->actingAs($u)->post('/finance/payment-intents', [
        'ar_invoice_id' => $inv->id,
        'amount'        => 250,
    ])->assertRedirect();

    expect(PaymentIntent::count())->toBe(1);
    expect(PaymentIntent::first()->ar_invoice_id)->toBe($inv->id);
});
```

- [ ] **Step 2: Run test — must FAIL**

```
php artisan test --filter=PaymentIntentTest
```

- [ ] **Step 3: Create `StorePaymentIntentRequest`**

`app/Http/Requests/Finance/StorePaymentIntentRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentIntentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('gateway.create') === true;
    }

    public function rules(): array
    {
        return [
            'ar_invoice_id' => ['required', 'integer', 'exists:ar_invoices,id'],
            'amount'        => ['required', 'numeric', 'min:0.01'],
            'callback_url'  => ['nullable', 'url', 'max:500'],
            'narration'     => ['nullable', 'string', 'max:500'],
        ];
    }
}
```

- [ ] **Step 4: Create `PaymentIntentResource`**

`app/Http/Resources/Finance/PaymentIntentResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Finance;

use App\Models\PaymentIntent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PaymentIntent */
class PaymentIntentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'reference'           => $this->reference,
            'status'              => ['value' => $this->status->value, 'label' => $this->status->label()],
            'amount'              => (float) $this->amount,
            'currency'            => $this->currency,
            'paystack_reference'  => $this->paystack_reference,
            'authorization_url'   => $this->authorization_url,
            'narration'           => $this->narration,
            'paid_at'             => $this->paid_at?->format('Y-m-d H:i'),
            'expires_at'          => $this->expires_at?->format('Y-m-d H:i'),
            'ar_receipt_id'       => $this->ar_receipt_id,
            'customer'            => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id, 'code' => $this->customer->code, 'name' => $this->customer->name,
            ]),
            'invoice'             => $this->whenLoaded('invoice', fn () => $this->invoice ? [
                'id' => $this->invoice->id, 'reference' => $this->invoice->reference,
            ] : null),
            'created_at'          => $this->created_at?->format('Y-m-d H:i'),
        ];
    }
}
```

- [ ] **Step 5: Create `PaymentIntentController`**

`app/Http/Controllers/Finance/PaymentIntentController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StorePaymentIntentRequest;
use App\Http\Resources\Finance\PaymentIntentResource;
use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\PaymentIntent;
use App\Services\Finance\PaymentIntentService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PaymentIntentController extends Controller
{
    public function __construct(private readonly PaymentIntentService $service)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $request->only(['status', 'customer_id']);

        $q = PaymentIntent::query()->with(['customer:id,code,name', 'invoice:id,reference']);
        if (! empty($filters['status']))      $q->where('status', $filters['status']);
        if (! empty($filters['customer_id'])) $q->where('customer_id', $filters['customer_id']);

        $intents = $q->orderByDesc('created_at')->paginate(50)->withQueryString();

        return Inertia::render('Finance/PaymentIntents/Index', [
            'activeModule' => 'finance-payment-intents',
            'intents'      => PaymentIntentResource::collection($intents),
            'filters'      => $filters,
            'customers'    => Customer::active()->orderBy('name')->get(['id','code','name','email']),
            'openInvoices' => ArInvoice::open()
                ->with('customer:id,code,name')
                ->orderBy('invoice_date')
                ->get(['id','reference','customer_id','customer_invoice_no','total','amount_received','invoice_date']),
        ]);
    }

    public function show(PaymentIntent $paymentIntent): Response
    {
        $paymentIntent->load(['customer', 'invoice', 'receipt']);

        return Inertia::render('Finance/PaymentIntents/Index', [
            'activeModule' => 'finance-payment-intents',
            'focusIntent'  => (new PaymentIntentResource($paymentIntent))->resolve(),
        ]);
    }

    public function store(StorePaymentIntentRequest $request): RedirectResponse
    {
        $invoice = ArInvoice::findOrFail($request->validated('ar_invoice_id'));

        try {
            $this->service->createForInvoice(
                $invoice,
                (float) $request->validated('amount'),
                $request->user(),
                $request->validated('callback_url'),
            );
        } catch (DomainException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        return back()->with('success', 'Payment link generated.');
    }
}
```

- [ ] **Step 6: Add routes**

In `routes/web.php`, inside the `Route::prefix('finance')->name('finance.')->group(...)` block, AFTER the F3 statements routes, add:

```php
        // F4 — Payment Intents (Paystack gateway)
        Route::middleware('permission:gateway.view')->group(function () {
            Route::get('payment-intents',                       [\App\Http\Controllers\Finance\PaymentIntentController::class, 'index'])->name('payment-intents.index');
            Route::get('payment-intents/{paymentIntent}',       [\App\Http\Controllers\Finance\PaymentIntentController::class, 'show'])->name('payment-intents.show');
        });
        Route::middleware(['permission:gateway.create', '2fa:fresh'])->group(function () {
            Route::post('payment-intents',                      [\App\Http\Controllers\Finance\PaymentIntentController::class, 'store'])->name('payment-intents.store');
        });
```

- [ ] **Step 7: Create Vue stub**

`resources/js/Pages/Finance/PaymentIntents/Index.vue`:

```vue
<script setup>
// Stub — Task 11 replaces with the real Payment Intents page.
defineProps({
    intents:      { type: Object, default: () => ({ data: [] }) },
    filters:      { type: Object, default: () => ({}) },
    customers:    { type: Array,  default: () => [] },
    openInvoices: { type: Array,  default: () => [] },
    focusIntent:  { type: [Object, null], default: null },
});
</script>

<template>
    <div>Payment Intents (stub)</div>
</template>
```

- [ ] **Step 8: Run test — must PASS**

```
php artisan test --filter=PaymentIntentTest
```
Expected: 4 tests pass.

> NOTE: the `2fa:fresh` middleware on POST may behave variably in tests depending on the existing CIHRMS test fixture. If the test for "finance_officer creates a payment intent" fails because of 2FA, follow the same pattern as F3 — accept the deferral and add a code comment. The test as written should pass when 2FA is satisfied via the default test setup.

- [ ] **Step 9: Commit**

```
git add app/Http/Requests/Finance/StorePaymentIntentRequest.php app/Http/Resources/Finance/PaymentIntentResource.php app/Http/Controllers/Finance/PaymentIntentController.php resources/js/Pages/Finance/PaymentIntents/Index.vue routes/web.php tests/Feature/Finance/PaymentIntentTest.php
git commit -m "$(cat <<'EOF'
feat(finance): payment-intents CRUD endpoints + 2fa:fresh on create

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 11: Payment Intents Inertia page + AR Invoice "Send Payment Link" button

**Files:**
- Replace stub: `resources/js/Pages/Finance/PaymentIntents/Index.vue`
- Modify: `resources/js/Pages/Finance/ArInvoices/Show.vue` — add "Send Payment Link" action

- [ ] **Step 1: Replace `PaymentIntents/Index.vue` with the real page**

Full source — replaces the Task 10 stub entirely:

```vue
<script setup>
import { ref, computed, watch } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import EmptyState from '@/Components/EmptyState.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    intents:      { type: Object, required: true },
    filters:      { type: Object, default: () => ({}) },
    customers:    { type: Array,  default: () => [] },
    openInvoices: { type: Array,  default: () => [] },
    focusIntent:  { type: [Object, null], default: null },
});

const page = usePage();
const canCreate = computed(() => {
    const perms = page.props?.auth?.permissions ?? [];
    const list = Array.isArray(perms) ? perms : (typeof perms === 'function' ? perms() : []);
    return list.includes('gateway.create');
});

const rows = computed(() => props.intents.data ?? props.intents ?? []);

const statusFilter = ref(props.filters.status ?? '');
const apply = () => router.get(route('finance.payment-intents.index'), {
    status: statusFilter.value || undefined,
}, { preserveState: true, replace: true });

const cedi = (v) => 'GHS ' + (Number(v) || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const panelOpen = ref(false);
const form = useForm({
    customer_id:   null,
    ar_invoice_id: null,
    amount:        0,
    narration:     '',
});

const candidates = computed(() => props.openInvoices.filter(inv => inv.customer_id === form.customer_id));

watch(() => form.customer_id, () => {
    form.ar_invoice_id = null;
    form.amount        = 0;
});

watch(() => form.ar_invoice_id, () => {
    const inv = props.openInvoices.find(x => x.id === form.ar_invoice_id);
    if (inv) form.amount = Number(inv.total) - Number(inv.amount_received);
});

const openNew = () => {
    form.reset();
    panelOpen.value = true;
};

const submit = () => form.post(route('finance.payment-intents.store'), {
    onSuccess: () => { panelOpen.value = false; form.reset(); },
});

const copyLink = (url) => {
    navigator.clipboard?.writeText(url);
};

const statusColor = (val) => ({
    created:   'text-on-surface-variant bg-surface-container border-outline-variant',
    pending:   'text-amber-700 bg-amber-50 border-amber-100',
    success:   'text-emerald-700 bg-emerald-50 border-emerald-100',
    failed:    'text-rose-700 bg-rose-50 border-rose-100',
    abandoned: 'text-slate-700 bg-slate-100 border-slate-200',
    expired:   'text-slate-500 bg-slate-50 border-slate-100',
}[val] ?? 'text-on-surface-variant');
</script>

<template>
    <Head title="Payment Links" />

    <div class="space-y-6 animate-reveal-up">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">FINANCE — PAYMENT GATEWAY</p>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Payment Links</h1>
                <p class="mt-1 text-[13px] font-medium text-on-surface-variant">{{ rows.length }} intents · Paystack hosted checkout.</p>
            </div>
            <PrimaryButton v-if="canCreate" @click="openNew">
                <span class="material-symbols-outlined text-[16px] mr-1">link</span>Send Payment Link
            </PrimaryButton>
        </div>

        <div class="flex flex-wrap gap-2 items-center">
            <button v-for="t in [
                { v: '',           label: 'All' },
                { v: 'pending',    label: 'Pending' },
                { v: 'success',    label: 'Success' },
                { v: 'failed',     label: 'Failed' },
                { v: 'abandoned',  label: 'Abandoned' },
                { v: 'expired',    label: 'Expired' },
            ]" :key="t.v" @click="statusFilter = t.v; apply();"
                :class="['px-3 py-1.5 rounded-full text-[11px] font-bold border transition-colors',
                    statusFilter === t.v ? 'bg-primary text-on-primary border-primary'
                                         : 'bg-surface-container-lowest text-on-surface-variant border-outline-variant hover:border-secondary/40']">
                {{ t.label }}
            </button>
        </div>

        <div v-if="rows.length" class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
            <table class="w-full text-[12px]">
                <thead class="bg-surface-container border-b border-outline-variant/40">
                    <tr class="text-left">
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Reference</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Customer</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Invoice</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider text-right">Amount</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Status</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="intent in rows" :key="intent.id" class="border-t border-outline-variant/30">
                        <td class="px-4 py-2 font-mono font-bold text-primary">{{ intent.reference }}</td>
                        <td class="px-4 py-2 text-on-surface">{{ intent.customer?.code }}</td>
                        <td class="px-4 py-2 text-on-surface-variant">{{ intent.invoice?.reference ?? '—' }}</td>
                        <td class="px-4 py-2 text-right font-mono text-primary">{{ cedi(intent.amount) }}</td>
                        <td class="px-4 py-2">
                            <span class="rounded-full px-2 py-0.5 text-[9px] font-black uppercase border" :class="statusColor(intent.status.value)">{{ intent.status.label }}</span>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <button v-if="intent.authorization_url && intent.status.value === 'pending'"
                                    @click="copyLink(intent.authorization_url)"
                                    class="text-[11px] font-bold text-secondary hover:underline">Copy link</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-else icon="link" title="No payment links yet" description="Generate a payment link for an approved or partially-paid AR invoice." />

        <SlidePanel :open="panelOpen" @close="panelOpen = false" title="Generate Payment Link">
            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <InputLabel for="customer_id" value="Customer" />
                    <select id="customer_id" v-model="form.customer_id" aria-label="Customer"
                            class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]">
                        <option :value="null">—</option>
                        <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.code }} — {{ c.name }}</option>
                    </select>
                </div>
                <div>
                    <InputLabel for="ar_invoice_id" value="Invoice" />
                    <select id="ar_invoice_id" v-model="form.ar_invoice_id" aria-label="Invoice"
                            class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]">
                        <option :value="null">—</option>
                        <option v-for="inv in candidates" :key="inv.id" :value="inv.id">
                            {{ inv.reference }} · {{ cedi(Number(inv.total) - Number(inv.amount_received)) }} outstanding
                        </option>
                    </select>
                    <InputError :message="form.errors.ar_invoice_id" />
                </div>
                <div>
                    <InputLabel for="amount" value="Amount (GHS)" />
                    <input id="amount" v-model.number="form.amount" type="number" step="0.01" min="0.01" aria-label="Amount"
                           class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]" />
                    <InputError :message="form.errors.amount" />
                </div>
                <div>
                    <InputLabel for="narration" value="Narration (optional)" />
                    <input id="narration" v-model="form.narration" type="text" aria-label="Narration"
                           class="mt-1 block w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]" />
                </div>
                <div class="pt-2 flex justify-end gap-2">
                    <button type="button" @click="panelOpen = false" class="rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-bold text-on-surface-variant">Cancel</button>
                    <PrimaryButton type="submit" :disabled="form.processing || !form.ar_invoice_id">Generate</PrimaryButton>
                </div>
            </form>
        </SlidePanel>
    </div>
</template>
```

- [ ] **Step 2: Add "Send Payment Link" button to AR Invoice Show**

Open `resources/js/Pages/Finance/ArInvoices/Show.vue`. Add a new `canCreatePayment` computed near the existing permission gates:

```js
const canCreatePayment = computed(() => {
    const perms = page.props?.auth?.permissions ?? [];
    const list = Array.isArray(perms) ? perms : (typeof perms === 'function' ? perms() : []);
    return list.includes('gateway.create');
});

const sendPaymentLink = () => {
    if (! invoice.value && ! props.invoice) return;
    const inv = props.invoice;
    router.post(route('finance.payment-intents.store'), {
        ar_invoice_id: inv.id,
        amount:        inv.outstanding,
    });
};
```

Then find the existing action button area (where Approve / Cancel / Write Off buttons live) and add ONE more button, gated by `canCreatePayment` AND invoice status in `approved`/`partially_paid`:

```vue
<button v-if="canCreatePayment && ['approved','partially_paid'].includes(invoice.status.value)"
        @click="sendPaymentLink"
        class="rounded-xl border border-secondary/40 bg-secondary/5 px-3 py-2 text-[12px] font-bold text-secondary hover:bg-secondary/10">
    <span class="material-symbols-outlined text-[14px] mr-1">link</span>Send Payment Link
</button>
```

- [ ] **Step 3: Build + verify**

```
npm run build
php artisan test --filter=Finance
```
Build must compile cleanly; full Finance suite must remain green.

- [ ] **Step 4: Commit**

```
git add resources/js/Pages/Finance/PaymentIntents/Index.vue resources/js/Pages/Finance/ArInvoices/Show.vue
git commit -m "$(cat <<'EOF'
feat(finance): Payment Intents Index page + Send Payment Link button on AR Invoice Show

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 12: Hub gateway health + Sidebar + Acceptance smoke

**Files:**
- Modify: `app/Services/Finance/FinanceHubService.php` — add `gatewayHealth()` method
- Modify: `resources/js/Pages/Finance/Hub.vue` — render gateway-health tile
- Modify: `resources/js/Layouts/AuthenticatedLayout.vue` — "Payment Links" sidebar entry + icon palette
- Modify: `tests/Feature/Finance/FinanceHubTest.php` — assert `gatewayHealth` key

### Step 1: Extend FinanceHubTest

Open `tests/Feature/Finance/FinanceHubTest.php`. Add ONE new test at the end (don't remove the existing ones):

```php
it('hub returns gatewayHealth key', function () {
    $finance = User::factory()->create(['role' => 'finance_officer']);

    $this->actingAs($finance)
        ->get('/finance')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Finance/Hub')
            ->has('gatewayHealth')
            ->has('gatewayHealth.status')
            ->has('gatewayHealth.message')
        );
});

it('gatewayHealth is missing_bank when no receipts-purpose bank exists', function () {
    config(['services.paystack.receipt_bank_purpose' => 'receipts']);
    \App\Models\OrgBankAccount::query()->forPurpose('receipts')->delete();

    $finance = User::factory()->create(['role' => 'finance_officer']);

    \Illuminate\Support\Facades\Cache::flush();

    $this->actingAs($finance)
        ->get('/finance')
        ->assertInertia(fn ($p) => $p->where('gatewayHealth.status', 'missing_bank'));
});
```

### Step 2: Run test — must FAIL on the new key

```
php artisan test --filter=FinanceHubTest
```

### Step 3: Update `FinanceHubService`

Open `app/Services/Finance/FinanceHubService.php`.

**A.** Add a new private method:

```php
    private function gatewayHealth(): array
    {
        $purpose = config('services.paystack.receipt_bank_purpose', 'receipts');

        $hasReceiptsBank = \App\Models\OrgBankAccount::query()
            ->where('purpose', $purpose)
            ->where('is_active', true)
            ->exists();

        if (! $hasReceiptsBank) {
            return [
                'status'  => 'missing_bank',
                'message' => "No active org bank account with purpose '{$purpose}'. Paystack receipts will fail.",
            ];
        }

        return ['status' => 'ok', 'message' => null];
    }
```

**B.** Update `build()` to include the key. After the existing `agingBuckets` line, add:

```php
            'gatewayHealth'       => $this->gatewayHealth(),
```

### Step 4: Update `Hub.vue`

Open `resources/js/Pages/Finance/Hub.vue`.

**A.** Add the new prop:

```js
gatewayHealth: { type: Object, default: () => ({ status: 'ok', message: null }) },
```

**B.** Add a small warning banner near the top of the template (after the main heading, before the KPI strip), shown only when `status === 'missing_bank'`:

```vue
<div v-if="gatewayHealth.status === 'missing_bank'"
     class="rounded-xl border border-amber-200 bg-amber-50 p-4 flex items-start gap-3">
    <span class="material-symbols-outlined text-[20px] text-amber-700">warning</span>
    <div>
        <p class="text-[12px] font-black text-amber-900">Gateway not configured</p>
        <p class="text-[11px] text-amber-800 mt-0.5">{{ gatewayHealth.message }}</p>
    </div>
</div>
```

### Step 5: Update sidebar in `AuthenticatedLayout.vue`

**A.** Find `SIDEBAR_ICON_COLORS` and add:

```js
'finance-payment-intents': '#3949ab',
```

**B.** Find the non-admin branch's Finance section. Update its `if (...)` guard to also include `gateway.view`:

```js
if (can('finance.hub') || can('accounts.view') || can('bank_accounts.view') ||
    can('vendors.view') || can('ap_invoices.view') || can('journal.view') ||
    can('customers.view') || can('ar_invoices.view') || can('statements.view') ||
    can('gateway.view')) {
```

Inside the section's `items` array, AFTER the F3 entries (Customers, AR Invoices, AR Receipts, Statements), add:

```js
                { label: 'Payment Links', route: 'finance.payment-intents.index', module: 'finance-payment-intents', icon: 'link', visible: can('gateway.view') },
```

### Step 6: Run tests + build

```
php artisan test --filter=FinanceHubTest
npm run build
php artisan test --filter=Finance
```
All must pass.

### Step 7: Acceptance smoke

```
php artisan test 2>&1 | tail -3
```
Expected: full Pest suite passing.

```
php artisan migrate:fresh --seed 2>&1 | tail -5
```
Expected: completes for F4 portions (pre-existing `departments_name_unique` Postgres bug is acknowledged and out of scope).

```
php artisan tinker --execute="echo App\\Models\\PaymentIntent::count() . ' / ' . App\\Models\\PaystackWebhookEvent::count();"
```
Expected: `0 / 0` (no seeded payment intents — operator-created).

### Step 8: Commit

```
git add app/Services/Finance/FinanceHubService.php resources/js/Pages/Finance/Hub.vue resources/js/Layouts/AuthenticatedLayout.vue tests/Feature/Finance/FinanceHubTest.php
git commit -m "$(cat <<'EOF'
feat(finance): F4 hub gateway health + Payment Links sidebar entry

gatewayHealth() warns when no active org bank account has purpose='receipts',
which would cause Paystack webhooks to fail. Hub renders an amber banner.
Sidebar gets Payment Links entry gated by gateway.view.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Done criteria

F4 is complete when:

1. All 12 tasks are checked off.
2. All Pest tests under `tests/Feature/Finance/` and `tests/Unit/Finance/` pass (~195 total: F1 49 + F2 80 + F3 36 + F4 ~30).
3. A `finance_officer` can complete the full flow: open an approved AR invoice → click "Send Payment Link" → get a Paystack `authorization_url` → simulate a `charge.success` webhook (Pest test fixture with valid HMAC) → see the AR receipt posted, invoice flipped to Paid, intent status flipped to `success`.
4. Idempotency contract test passes: replaying the same `charge.success` event produces only one `ArReceipt`.
5. Signature verification rejects spoofed webhooks with HTTP 400 + no DB writes.
6. Hub warns when no `receipts`-purpose org bank account exists.
7. `2fa:fresh` is on `POST /finance/payment-intents` from day one (F3 forward-fix applied).
8. `gateway.refund` permission exists but is super_admin-only (refund implementation deferred).
9. `JournalPostingService` is unmodified.
10. `ArReceiptService::record()` is the sole entry point for AR receipts — F4 routes through it, no parallel receipt path.
