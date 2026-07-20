# Phase 1 — Automated Hubtel Bank Payouts & Closed Bank Loop — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Release an approved payout batch → money leaves via the Hubtel API → the transfer webhook settles the disbursement, posts the GL, and reconciles — replacing the manual GhIPSS export / bank-portal upload / statement download / manual match loop.

**Architecture:** A new `HubtelBankProvider` on the existing `DisbursementProvider` contract (additive; MoMo + GhIPSS untouched), a `PayoutBatch` maker-checker + threshold control layer that gates the existing "explicit dispatch" step, and a Hubtel webhook mirroring the Paystack wiring that flips `Sent→Settled/Failed`, posts the settlement GL, and reconciles.

**Tech Stack:** Laravel 13, PHP 8.3, Inertia v2, Vue 3, Tailwind v3, Pest 4. Postgres prod/dev, SQLite in-memory tests. HTTP via `Illuminate\Support\Facades\Http` (faked in tests with `Http::fake`).

## Global Constraints

- Additive only: do NOT modify `MtnMomoProvider`, `VodafoneCashProvider`, `AirtelTigoProvider`, or `GhIpssAchProvider`. Hubtel is a new channel `hubtel_bank`.
- Real money: no provider network call may happen before a `PayoutBatch` is **released** by a checker who is not the maker; batches at/above `config('finance.payouts.high_approval_threshold')` require the `payouts.release_high` permission.
- Idempotency: `HubtelBankProvider::send()` uses idempotency key `HUBTEL-{disbursement_id}`; the webhook is idempotent on the provider event id (unique column), mirroring `PaystackWebhookController`.
- Money is `decimal:2` everywhere; never float-compare money. Batch totals sum child `net_to_recipient`.
- New permission slugs `payouts.initiate`, `payouts.release`, `payouts.release_high` MUST be added to `App\Enums\Permission`, `App\Models\User::ROLE_PERMISSIONS`, AND `database/seeders/RolePermissionSeeder.php` (both catalog + role grants) — a pre-existing `PermissionEnumTest` fails otherwise.
- References via `App\Services\Finance\SequenceService::next(string $key): int`, format like `sprintf('POUT-%s-%04d', $year, $this->sequences->next("payout_batch:{$year}"))`.
- GL posting via `App\Services\Finance\PostingService::post(new PostingDocument(...))` with `PostingLine::debit/credit` — reuse the exact shape in `BatchDisbursementService::settle()`.
- Git: work happens in an isolated worktree (created via superpowers:using-git-worktrees at execution time); commit on the worktree branch (do NOT `git checkout -b`); conventional commits ending `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

---

## File Structure

- `app/Enums/DisbursementChannel.php` — *modify*: add `HubtelBank` case.
- `app/Enums/PayoutBatchStatus.php` — *create*.
- `app/Enums/Permission.php` — *modify*: 3 payout permission cases.
- `app/Models/User.php` — *modify*: grant payout perms to finance roles.
- `database/seeders/RolePermissionSeeder.php` — *modify*: catalog + grants.
- `config/disbursement.php` — *modify*: `providers.hubtel_bank` block.
- `config/services.php` — *modify*: `hubtel` block (webhook secret).
- `config/finance.php` — *modify or create key*: `payouts.high_approval_threshold`.
- `app/Exceptions/Finance/HubtelException.php`, `HubtelUnreachableException.php` — *create*.
- `app/Services/Disbursement/Providers/HubtelBankProvider.php` — *create*.
- `app/Providers/DisbursementServiceProvider.php` — *modify*: register Hubtel provider.
- `app/Models/PayoutBatch.php` + migration `*_create_payout_batches_table.php` — *create*.
- migration `*_add_payout_batch_id_to_disbursements_table.php` — *create*.
- `app/Services/Disbursement/PayoutBatchService.php` — *create*.
- `app/Services/Disbursement/PayoutReleaseService.php` — *create*.
- `app/Models/HubtelWebhookEvent.php` + migration — *create*.
- `app/Http/Middleware/VerifyHubtelSignature.php` — *create*; `bootstrap/app.php` — *modify* (alias).
- `app/Http/Controllers/Finance/HubtelWebhookController.php`, `app/Jobs/ProcessHubtelWebhook.php`, `app/Services/Finance/HubtelWebhookProcessor.php` — *create*; route in `routes/web.php`.
- `app/Listeners/MaterialiseDisbursements.php` — *modify*: create a `PayoutBatch` after materialising.
- `app/Services/Offboarding/OffboardingService.php` — *modify*: wrap settlement disbursement in a batch.
- `app/Console/Commands/RefreshHubtelDisbursementsCommand.php` + `routes/console.php` schedule — *create/modify*.
- `app/Http/Controllers/Finance/PayoutBatchController.php`, `resources/js/Pages/Finance/Payouts/{Index,Show}.vue`, `routes/web.php` — *create/modify*.
- Tests under `tests/Feature/Disbursement/` and `tests/Feature/Finance/`.

---

## Task 1: Hubtel channel, config, and provider

**Files:**
- Modify: `app/Enums/DisbursementChannel.php`
- Create: `config` keys in `config/disbursement.php` (`providers.hubtel_bank`) and `config/services.php` (`hubtel`)
- Create: `app/Exceptions/Finance/HubtelException.php`, `app/Exceptions/Finance/HubtelUnreachableException.php`
- Create: `app/Services/Disbursement/Providers/HubtelBankProvider.php`
- Modify: `app/Providers/DisbursementServiceProvider.php`
- Test: `tests/Feature/Disbursement/HubtelBankProviderTest.php`

**Interfaces:**
- Produces: `DisbursementChannel::HubtelBank = 'hubtel_bank'`; `HubtelBankProvider implements DisbursementProvider` with `send(Disbursement): DisbursementResult` and `refreshStatus(Disbursement): DisbursementResult`; registered under `hubtel_bank` in the provider registry.
- Consumes: `App\Services\Disbursement\Contracts\DisbursementProvider`, `App\Services\Disbursement\DisbursementResult` (`::sent($ref,$raw)`, `::settled(...)`, `::failed($reason,$raw)`), `App\Models\Disbursement` (fields `net_to_recipient`, `beneficiary_account`, `beneficiary_name`, `provider_reference`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Disbursement/HubtelBankProviderTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\DisbursementChannel;
use App\Enums\DisbursementStatus;
use App\Models\Disbursement;
use App\Services\Disbursement\Providers\HubtelBankProvider;
use Illuminate\Support\Facades\Http;

function hubtelProvider(): HubtelBankProvider
{
    return new HubtelBankProvider(
        baseUrl: 'https://payout.hubtel.test',
        clientId: 'cid',
        clientSecret: 'secret',
        merchantAccount: '12345',
        callbackUrl: 'https://app.test/webhooks/hubtel',
        timeoutSeconds: 5,
    );
}

function hubtelDisbursement(array $overrides = []): Disbursement
{
    return Disbursement::factory()->create(array_merge([
        'channel'             => DisbursementChannel::HubtelBank->value,
        'status'             => DisbursementStatus::Pending->value,
        'net_to_recipient'   => 1500.00,
        'beneficiary_account'=> '0551234567',
        'beneficiary_name'   => 'Ama Mensah',
    ], $overrides));
}

it('sends a transfer and returns Sent with the provider reference + idempotency key', function () {
    Http::fake([
        '*/transactions/*/send' => Http::response(['Data' => ['TransactionId' => 'HUB-TX-9']], 200),
    ]);

    $d = hubtelDisbursement();
    $result = hubtelProvider()->send($d);

    expect($result->status)->toBe(DisbursementStatus::Sent)
        ->and($result->providerReference)->toBe('HUB-TX-9');

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/send')
        && $req->hasHeader('Idempotency-Key', "HUBTEL-{$d->id}")
    );
});

it('returns Failed on a 4xx without throwing', function () {
    Http::fake(['*' => Http::response(['message' => 'invalid account'], 422)]);

    $result = hubtelProvider()->send(hubtelDisbursement());

    expect($result->status)->toBe(DisbursementStatus::Failed)
        ->and($result->failureReason)->toContain('422');
});

it('maps refreshStatus provider states to Settled/Failed/Sent', function () {
    $d = hubtelDisbursement(['provider_reference' => 'HUB-TX-9', 'status' => DisbursementStatus::Sent->value]);

    Http::fake(['*' => Http::response(['Data' => ['Status' => 'Paid']], 200)]);
    expect(hubtelProvider()->refreshStatus($d)->status)->toBe(DisbursementStatus::Settled);

    Http::fake(['*' => Http::response(['Data' => ['Status' => 'Failed']], 200)]);
    expect(hubtelProvider()->refreshStatus($d)->status)->toBe(DisbursementStatus::Failed);

    Http::fake(['*' => Http::response(['Data' => ['Status' => 'Pending']], 200)]);
    expect(hubtelProvider()->refreshStatus($d)->status)->toBe(DisbursementStatus::Sent);
});

it('rejects a disbursement with no beneficiary account', function () {
    $result = hubtelProvider()->send(hubtelDisbursement(['beneficiary_account' => null]));
    expect($result->status)->toBe(DisbursementStatus::Failed);
});
```

> Note: `Disbursement::factory()` must allow `payroll_run_id`/`payroll_line_id` to be null (settlement/standalone rows already do). If the factory requires them, pass `['payroll_run_id' => null, 'payroll_line_id' => null]` in `hubtelDisbursement()`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Disbursement/HubtelBankProviderTest.php`
Expected: FAIL — `Class "App\Services\Disbursement\Providers\HubtelBankProvider" not found`.

- [ ] **Step 3: Add the channel case**

In `app/Enums/DisbursementChannel.php`, add after `AirtelTigo`:

```php
    case HubtelBank    = 'hubtel_bank';         // Bank transfer via Hubtel payout API
```

If the enum has an `attractsELevy(): bool` method, return the same value as `GhipssAch` (bank transfer) for `HubtelBank` — locate the existing `match`/method and add the `HubtelBank` arm mirroring `GhipssAch`.

- [ ] **Step 4: Add the exceptions**

Create `app/Exceptions/Finance/HubtelException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Finance;

use RuntimeException;

class HubtelException extends RuntimeException {}
```

Create `app/Exceptions/Finance/HubtelUnreachableException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Finance;

class HubtelUnreachableException extends HubtelException {}
```

- [ ] **Step 5: Implement the provider** (mirrors `MtnMomoProvider` structure)

Create `app/Services/Disbursement/Providers/HubtelBankProvider.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Disbursement\Providers;

use App\Enums\DisbursementChannel;
use App\Models\Disbursement;
use App\Services\Disbursement\Contracts\DisbursementProvider;
use App\Services\Disbursement\DisbursementResult;
use Illuminate\Support\Facades\Http;

/**
 * Hubtel payout (bank transfer) provider.
 *
 * Hubtel exposes a synchronous "send" that accepts the transfer and returns a
 * TransactionId; final disposition arrives via the configured callback
 * (webhook). Idempotency-Key = `HUBTEL-{disbursement_id}` so retries are safe.
 */
class HubtelBankProvider implements DisbursementProvider
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $merchantAccount,
        private readonly string $callbackUrl,
        private readonly int    $timeoutSeconds = 15,
    ) {}

    public function channel(): string
    {
        return DisbursementChannel::HubtelBank->value;
    }

    public function send(Disbursement $d): DisbursementResult
    {
        if (empty($d->beneficiary_account)) {
            return DisbursementResult::failed('Hubtel: beneficiary bank account is missing.');
        }

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withBasicAuth($this->clientId, $this->clientSecret)
                ->withHeaders(['Idempotency-Key' => "HUBTEL-{$d->id}"])
                ->post("{$this->baseUrl}/transactions/{$this->merchantAccount}/send", [
                    'RecipientName'       => (string) $d->beneficiary_name,
                    'RecipientBankAccount'=> (string) $d->beneficiary_account,
                    'Amount'              => (float) $d->net_to_recipient,
                    'Description'         => "CIHRM payout #{$d->id}",
                    'ClientReference'     => "PAYOUT-{$d->id}",
                    'CallbackUrl'         => $this->callbackUrl,
                ]);
        } catch (\Throwable $e) {
            return DisbursementResult::failed("Hubtel transport error: {$e->getMessage()}");
        }

        if ($response->successful()) {
            $txId = (string) ($response->json('Data.TransactionId') ?? '');
            if ($txId !== '') {
                return DisbursementResult::sent($txId, $response->json() ?? []);
            }
        }

        return DisbursementResult::failed(
            "Hubtel HTTP {$response->status()}: " . substr($response->body(), 0, 200),
            ['body' => $response->json() ?? $response->body()],
        );
    }

    public function refreshStatus(Disbursement $d): DisbursementResult
    {
        if (! $d->provider_reference) {
            return DisbursementResult::failed('No provider reference to query.');
        }

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withBasicAuth($this->clientId, $this->clientSecret)
                ->get("{$this->baseUrl}/transactions/{$this->merchantAccount}/status/{$d->provider_reference}");
        } catch (\Throwable $e) {
            return DisbursementResult::failed("Hubtel status poll error: {$e->getMessage()}");
        }

        if (! $response->successful()) {
            return DisbursementResult::failed("Hubtel status HTTP {$response->status()}");
        }

        $status = (string) ($response->json('Data.Status') ?? '');

        return match (strtolower($status)) {
            'paid', 'success', 'successful' => DisbursementResult::settled((string) $d->provider_reference, $response->json() ?? []),
            'failed', 'declined', 'reversed' => DisbursementResult::failed("Hubtel reported {$status}", $response->json() ?? []),
            default => DisbursementResult::sent((string) $d->provider_reference, $response->json() ?? []),
        };
    }
}
```

- [ ] **Step 6: Add config**

In `config/disbursement.php`, inside the `'providers' => [ ... ]` array, add:

```php
        'hubtel_bank' => [
            'enabled'          => env('HUBTEL_PAYOUT_ENABLED', false),
            'base_url'         => env('HUBTEL_PAYOUT_BASE_URL', 'https://payout.hubtel.com'),
            'client_id'        => env('HUBTEL_CLIENT_ID'),
            'client_secret'    => env('HUBTEL_CLIENT_SECRET'),
            'merchant_account' => env('HUBTEL_MERCHANT_ACCOUNT'),
            'callback_url'     => env('HUBTEL_CALLBACK_URL'),
        ],
```

In `config/services.php`, add a top-level entry (near `paystack`):

```php
    'hubtel' => [
        'webhook_secret' => env('HUBTEL_WEBHOOK_SECRET'),
    ],
```

- [ ] **Step 7: Register the provider**

In `app/Providers/DisbursementServiceProvider.php`, add the import `use App\Services\Disbursement\Providers\HubtelBankProvider;` and, inside the `register()` closure before `return new BatchDisbursementService(...)`, add:

```php
            if (! empty($cfg['hubtel_bank']['enabled'])) {
                $providers[DisbursementChannel::HubtelBank->value] = new HubtelBankProvider(
                    baseUrl:         (string) $cfg['hubtel_bank']['base_url'],
                    clientId:        (string) $cfg['hubtel_bank']['client_id'],
                    clientSecret:    (string) $cfg['hubtel_bank']['client_secret'],
                    merchantAccount: (string) $cfg['hubtel_bank']['merchant_account'],
                    callbackUrl:     (string) $cfg['hubtel_bank']['callback_url'],
                );
            }
```

- [ ] **Step 8: Run test to verify it passes**

Run: `php artisan test tests/Feature/Disbursement/HubtelBankProviderTest.php`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Enums/DisbursementChannel.php app/Services/Disbursement/Providers/HubtelBankProvider.php app/Exceptions/Finance/Hubtel*.php app/Providers/DisbursementServiceProvider.php config/disbursement.php config/services.php tests/Feature/Disbursement/HubtelBankProviderTest.php
git commit -m "feat(payouts): Hubtel bank-payout provider on the disbursement contract"
```

---

## Task 2: PayoutBatch model, status enum, migrations

**Files:**
- Create: `app/Enums/PayoutBatchStatus.php`
- Create: `app/Models/PayoutBatch.php`
- Create: `database/migrations/xxxx_create_payout_batches_table.php`
- Create: `database/migrations/xxxx_add_payout_batch_id_to_disbursements_table.php`
- Create: `database/factories/PayoutBatchFactory.php`
- Test: `tests/Feature/Disbursement/PayoutBatchModelTest.php`

**Interfaces:**
- Produces: `PayoutBatch` model with `disbursements()` hasMany, `reference`, `status` (`PayoutBatchStatus`), `total_amount`, `currency`, `created_by`, `released_by`, `released_at`, `requires_high_approval`, `approved_by`, morphable `source_type`/`source_id`. `PayoutBatchStatus` enum: `Draft, PendingRelease, Released, Completed, Failed, Cancelled` (values draft/pending_release/released/completed/failed/cancelled).
- Consumes: `App\Models\Disbursement` (adds `payout_batch_id`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Disbursement/PayoutBatchModelTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\PayoutBatchStatus;
use App\Models\Disbursement;
use App\Models\PayoutBatch;

it('creates a batch, links disbursements, and casts status', function () {
    $batch = PayoutBatch::factory()->create([
        'status'         => PayoutBatchStatus::PendingRelease->value,
        'total_amount'   => 5000.00,
        'currency'       => 'GHS',
    ]);

    Disbursement::factory()->count(2)->create(['payout_batch_id' => $batch->id]);

    expect($batch->status)->toBe(PayoutBatchStatus::PendingRelease)
        ->and($batch->disbursements)->toHaveCount(2)
        ->and((float) $batch->total_amount)->toBe(5000.00);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Disbursement/PayoutBatchModelTest.php`
Expected: FAIL — `Class "App\Models\PayoutBatch" not found`.

- [ ] **Step 3: Create the status enum**

Create `app/Enums/PayoutBatchStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum PayoutBatchStatus: string
{
    case Draft          = 'draft';
    case PendingRelease = 'pending_release';
    case Released       = 'released';
    case Completed      = 'completed';
    case Failed         = 'failed';
    case Cancelled      = 'cancelled';
}
```

- [ ] **Step 4: Create the migrations**

Create `database/migrations/xxxx_xx_xx_000001_create_payout_batches_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payout_batches', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->nullableMorphs('source'); // payroll_run / final_settlement / null
            $table->string('status')->default('pending_release')->index();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->string('currency', 3)->default('GHS');
            $table->boolean('requires_high_approval')->default(false);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_batches');
    }
};
```

Create `database/migrations/xxxx_xx_xx_000002_add_payout_batch_id_to_disbursements_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('disbursements', function (Blueprint $table) {
            $table->foreignId('payout_batch_id')->nullable()->after('id')
                ->constrained('payout_batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('disbursements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payout_batch_id');
        });
    }
};
```

- [ ] **Step 5: Create the model**

Create `app/Models/PayoutBatch.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PayoutBatchStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayoutBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference', 'source_type', 'source_id', 'status',
        'total_amount', 'currency', 'requires_high_approval',
        'created_by', 'released_by', 'approved_by', 'released_at',
    ];

    protected function casts(): array
    {
        return [
            'status'                 => PayoutBatchStatus::class,
            'total_amount'           => 'decimal:2',
            'requires_high_approval' => 'boolean',
            'released_at'            => 'datetime',
        ];
    }

    public function disbursements(): HasMany
    {
        return $this->hasMany(Disbursement::class);
    }

    public function maker(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
```

Add `payout_batch_id` to `app/Models/Disbursement.php` `$fillable` (append to the existing array).

- [ ] **Step 6: Create the factory**

Create `database/factories/PayoutBatchFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PayoutBatchStatus;
use App\Models\PayoutBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PayoutBatchFactory extends Factory
{
    protected $model = PayoutBatch::class;

    public function definition(): array
    {
        return [
            'reference'              => 'POUT-2026-' . fake()->unique()->numberBetween(1000, 9999),
            'status'                 => PayoutBatchStatus::PendingRelease->value,
            'total_amount'           => 0,
            'currency'               => 'GHS',
            'requires_high_approval' => false,
            'created_by'             => User::factory(),
        ];
    }
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test tests/Feature/Disbursement/PayoutBatchModelTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Enums/PayoutBatchStatus.php app/Models/PayoutBatch.php app/Models/Disbursement.php database/migrations/*payout_batch* database/factories/PayoutBatchFactory.php tests/Feature/Disbursement/PayoutBatchModelTest.php
git commit -m "feat(payouts): PayoutBatch model, status enum, migrations"
```

---

## Task 3: PayoutBatchService (wrap disbursements into a batch)

**Files:**
- Create: `app/Services/Disbursement/PayoutBatchService.php`
- Modify: `config/finance.php` (add `payouts.high_approval_threshold`)
- Test: `tests/Feature/Disbursement/PayoutBatchServiceTest.php`

**Interfaces:**
- Consumes: `App\Services\Finance\SequenceService` (`next(string): int`); `App\Models\{PayrollRun,FinalSettlement,Disbursement,PayoutBatch}`; `App\Enums\PayoutBatchStatus`.
- Produces: `PayoutBatchService::createForPayrollRun(PayrollRun $run, int $makerId): PayoutBatch` and `createForSettlement(FinalSettlement $s, int $makerId): PayoutBatch`. Wraps the run's/settlement's `Pending` disbursements (sets their `payout_batch_id`), computes `total_amount = sum(net_to_recipient)`, sets `requires_high_approval = total >= config('finance.payouts.high_approval_threshold')`, status `PendingRelease`, `created_by = makerId`, unique `reference`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Disbursement/PayoutBatchServiceTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\DisbursementStatus;
use App\Enums\PayoutBatchStatus;
use App\Models\Disbursement;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Disbursement\PayoutBatchService;

beforeEach(fn () => config()->set('finance.payouts.high_approval_threshold', 100000));

it('wraps a payroll run pending disbursements into a pending_release batch', function () {
    $maker = User::factory()->create();
    $run   = PayrollRun::factory()->create();

    Disbursement::factory()->count(3)->create([
        'payroll_run_id'   => $run->id,
        'status'           => DisbursementStatus::Pending->value,
        'net_to_recipient' => 2000.00,
    ]);

    $batch = app(PayoutBatchService::class)->createForPayrollRun($run, $maker->id);

    expect($batch->status)->toBe(PayoutBatchStatus::PendingRelease)
        ->and((float) $batch->total_amount)->toBe(6000.00)
        ->and($batch->requires_high_approval)->toBeFalse()
        ->and($batch->created_by)->toBe($maker->id)
        ->and($batch->disbursements()->count())->toBe(3);
});

it('flags requires_high_approval when total meets the threshold', function () {
    config()->set('finance.payouts.high_approval_threshold', 5000);
    $maker = User::factory()->create();
    $run   = PayrollRun::factory()->create();
    Disbursement::factory()->count(3)->create([
        'payroll_run_id' => $run->id, 'status' => DisbursementStatus::Pending->value, 'net_to_recipient' => 2000.00,
    ]);

    $batch = app(PayoutBatchService::class)->createForPayrollRun($run, $maker->id);

    expect($batch->requires_high_approval)->toBeTrue();
});
```

> Note: if `PayrollRun::factory()` requires related data, add the minimum the factory needs; the assertions only depend on the disbursements' amounts.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Disbursement/PayoutBatchServiceTest.php`
Expected: FAIL — `Class "App\Services\Disbursement\PayoutBatchService" not found`.

- [ ] **Step 3: Add config threshold**

In `config/finance.php`, add (or create the file's returned array key) a `payouts` block:

```php
    'payouts' => [
        // Batches whose total is >= this (GHS) require the higher approver
        // (payouts.release_high). 0 disables the high-approval tier.
        'high_approval_threshold' => (float) env('PAYOUT_HIGH_APPROVAL_THRESHOLD', 50000),
    ],
```

> If `config/finance.php` does not exist, create it returning `<?php return ['payouts' => [ ... ]];`.

- [ ] **Step 4: Implement the service**

Create `app/Services/Disbursement/PayoutBatchService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Disbursement;

use App\Enums\DisbursementStatus;
use App\Enums\PayoutBatchStatus;
use App\Models\Disbursement;
use App\Models\FinalSettlement;
use App\Models\PayoutBatch;
use App\Models\PayrollRun;
use App\Services\Finance\SequenceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PayoutBatchService
{
    public function __construct(private readonly SequenceService $sequences) {}

    public function createForPayrollRun(PayrollRun $run, int $makerId): PayoutBatch
    {
        return $this->wrap(
            Disbursement::query()->where('payroll_run_id', $run->id)
                ->where('status', DisbursementStatus::Pending->value)
                ->whereNull('payout_batch_id'),
            $makerId,
            PayrollRun::class,
            $run->id,
        );
    }

    public function createForSettlement(FinalSettlement $settlement, int $makerId): PayoutBatch
    {
        return $this->wrap(
            Disbursement::query()->where('final_settlement_id', $settlement->id)
                ->where('status', DisbursementStatus::Pending->value)
                ->whereNull('payout_batch_id'),
            $makerId,
            FinalSettlement::class,
            $settlement->id,
        );
    }

    private function wrap(\Illuminate\Database\Eloquent\Builder $pending, int $makerId, string $sourceType, int $sourceId): PayoutBatch
    {
        return DB::transaction(function () use ($pending, $makerId, $sourceType, $sourceId) {
            $rows      = $pending->get();
            $total     = (float) $rows->sum(fn ($d) => (float) $d->net_to_recipient);
            $threshold = (float) config('finance.payouts.high_approval_threshold', 0);

            $batch = PayoutBatch::create([
                'reference'              => $this->reference(),
                'source_type'            => $sourceType,
                'source_id'              => $sourceId,
                'status'                 => PayoutBatchStatus::PendingRelease->value,
                'total_amount'           => $total,
                'currency'               => 'GHS',
                'requires_high_approval' => $threshold > 0 && $total >= $threshold,
                'created_by'             => $makerId,
            ]);

            Disbursement::whereIn('id', $rows->pluck('id'))->update(['payout_batch_id' => $batch->id]);

            return $batch->fresh();
        });
    }

    private function reference(): string
    {
        $year = Carbon::now()->year;
        return sprintf('POUT-%s-%04d', $year, $this->sequences->next("payout_batch:{$year}"));
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/Disbursement/PayoutBatchServiceTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Disbursement/PayoutBatchService.php config/finance.php tests/Feature/Disbursement/PayoutBatchServiceTest.php
git commit -m "feat(payouts): PayoutBatchService wraps pending disbursements + threshold flag"
```

---

## Task 4: PayoutReleaseService + RBAC (maker-checker + threshold)

**Files:**
- Modify: `app/Enums/Permission.php`, `app/Models/User.php`, `database/seeders/RolePermissionSeeder.php`
- Create: `app/Services/Disbursement/PayoutReleaseService.php`
- Create: `app/Exceptions/Finance/PayoutAuthorizationException.php`
- Test: `tests/Feature/Disbursement/PayoutReleaseServiceTest.php`

**Interfaces:**
- Consumes: `App\Services\Disbursement\BatchDisbursementService::dispatchOne(Disbursement): string`; `PayoutBatch`; `User::hasPermission(string): bool`.
- Produces: `PayoutReleaseService::release(PayoutBatch $batch, User $releaser): array{sent:int,failed:int,skipped:int}`. Guards throw `PayoutAuthorizationException`: releaser lacks `payouts.release`; `requires_high_approval` and releaser lacks `payouts.release_high`; releaser id == `created_by` (maker≠checker). On pass: set `released_by`, `released_at`, `approved_by = releaser->id`, status `Released`; dispatch each child `Pending` disbursement via `dispatchOne`; batch stays `Released`. Re-release of a non-`PendingRelease` batch returns zeros (no-op).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Disbursement/PayoutReleaseServiceTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\DisbursementStatus;
use App\Enums\PayoutBatchStatus;
use App\Exceptions\Finance\PayoutAuthorizationException;
use App\Models\Disbursement;
use App\Models\PayoutBatch;
use App\Models\User;
use App\Services\Disbursement\PayoutReleaseService;

function releaser(array $perms): User
{
    // Test grant pattern: per-user JSON `permissions` column (see project_test_patterns).
    return User::factory()->create(['permissions' => $perms]);
}

function pendingBatch(User $maker, bool $high = false): PayoutBatch
{
    $batch = PayoutBatch::factory()->create([
        'created_by'             => $maker->id,
        'status'                 => PayoutBatchStatus::PendingRelease->value,
        'requires_high_approval' => $high,
    ]);
    Disbursement::factory()->count(2)->create([
        'payout_batch_id' => $batch->id,
        'channel'         => 'cash', // no provider → dispatchOne returns 'skipped', keeps test provider-free
        'status'          => DisbursementStatus::Pending->value,
    ]);
    return $batch;
}

it('blocks release by the maker (segregation of duties)', function () {
    $maker = releaser(['payouts.release']);
    $batch = pendingBatch($maker);

    app(PayoutReleaseService::class)->release($batch, $maker);
})->throws(PayoutAuthorizationException::class);

it('blocks release without payouts.release', function () {
    $maker    = releaser([]);
    $releaser = releaser([]);
    $batch    = pendingBatch($maker);

    app(PayoutReleaseService::class)->release($batch, $releaser);
})->throws(PayoutAuthorizationException::class);

it('blocks a high-value batch without payouts.release_high', function () {
    $maker    = releaser([]);
    $releaser = releaser(['payouts.release']); // lacks release_high
    $batch    = pendingBatch($maker, high: true);

    app(PayoutReleaseService::class)->release($batch, $releaser);
})->throws(PayoutAuthorizationException::class);

it('releases when a different authorized user acts', function () {
    $maker    = releaser([]);
    $releaser = releaser(['payouts.release']);
    $batch    = pendingBatch($maker);

    $result = app(PayoutReleaseService::class)->release($batch, $releaser);

    expect($batch->fresh()->status)->toBe(PayoutBatchStatus::Released)
        ->and($batch->fresh()->released_by)->toBe($releaser->id)
        ->and($result['sent'] + $result['skipped'])->toBe(2);
});

it('is a no-op when the batch is already released', function () {
    $maker    = releaser([]);
    $releaser = releaser(['payouts.release']);
    $batch    = pendingBatch($maker);
    app(PayoutReleaseService::class)->release($batch, $releaser);

    $again = app(PayoutReleaseService::class)->release($batch->fresh(), $releaser);
    expect($again)->toBe(['sent' => 0, 'failed' => 0, 'skipped' => 0]);
});
```

> Note: confirm the per-user test permission-grant mechanism against `project_test_patterns` / `User::hasPermission`. If the JSON `permissions` column isn't the mechanism, grant via whatever the existing tests use (e.g. a role with the slug). The `channel => 'cash'` keeps the release provider-free so this task doesn't depend on Hubtel HTTP.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Disbursement/PayoutReleaseServiceTest.php`
Expected: FAIL — service + permissions not defined.

- [ ] **Step 3: Add permissions (enum + role map + seeder)**

In `app/Enums/Permission.php` add:

```php
    case PayoutsInitiate    = 'payouts.initiate';
    case PayoutsRelease     = 'payouts.release';
    case PayoutsReleaseHigh = 'payouts.release_high';
```

In `app/Models/User.php` `ROLE_PERMISSIONS`, add `'payouts.initiate'` and `'payouts.release'` to the finance-officer role array, and `'payouts.release_high'` to the finance-manager/senior finance role if one exists (otherwise leave high-release to the ceo/super_admin wildcard). In `database/seeders/RolePermissionSeeder.php`, add all three slugs to the `PERMISSIONS` catalog and grant `payouts.initiate`/`payouts.release` to the finance role(s) in `ROLE_PERMS` (mirror the existing structure exactly).

- [ ] **Step 4: Create the authorization exception**

Create `app/Exceptions/Finance/PayoutAuthorizationException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Finance;

use RuntimeException;

class PayoutAuthorizationException extends RuntimeException {}
```

- [ ] **Step 5: Implement the release service**

Create `app/Services/Disbursement/PayoutReleaseService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Disbursement;

use App\Enums\DisbursementStatus;
use App\Enums\PayoutBatchStatus;
use App\Exceptions\Finance\PayoutAuthorizationException;
use App\Models\Disbursement;
use App\Models\PayoutBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PayoutReleaseService
{
    public function __construct(private readonly BatchDisbursementService $batch) {}

    /**
     * @return array{sent:int, failed:int, skipped:int}
     */
    public function release(PayoutBatch $batch, User $releaser): array
    {
        if ($batch->status !== PayoutBatchStatus::PendingRelease) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        }

        if (! $releaser->hasPermission('payouts.release')) {
            throw new PayoutAuthorizationException('You do not have permission to release payouts.');
        }
        if ($batch->requires_high_approval && ! $releaser->hasPermission('payouts.release_high')) {
            throw new PayoutAuthorizationException('This batch exceeds the threshold and requires a higher approver.');
        }
        if ((int) $batch->created_by === (int) $releaser->id) {
            throw new PayoutAuthorizationException('The maker of a batch cannot release it (segregation of duties).');
        }

        DB::transaction(function () use ($batch, $releaser) {
            $batch->update([
                'status'      => PayoutBatchStatus::Released->value,
                'released_by' => $releaser->id,
                'approved_by' => $releaser->id,
                'released_at' => now(),
            ]);
        });

        $totals = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($batch->disbursements()->where('status', DisbursementStatus::Pending->value)->get() as $d) {
            $totals[$this->batch->dispatchOne($d)]++;
        }

        return $totals;
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/Disbursement/PayoutReleaseServiceTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Enums/Permission.php app/Models/User.php database/seeders/RolePermissionSeeder.php app/Services/Disbursement/PayoutReleaseService.php app/Exceptions/Finance/PayoutAuthorizationException.php tests/Feature/Disbursement/PayoutReleaseServiceTest.php
git commit -m "feat(payouts): maker-checker + threshold release service and RBAC"
```

---

## Task 5: Hubtel webhook (settle → GL → reconcile)

**Files:**
- Create: `app/Models/HubtelWebhookEvent.php` + migration `xxxx_create_hubtel_webhook_events_table.php`
- Create: `app/Http/Middleware/VerifyHubtelSignature.php`; modify `bootstrap/app.php` (alias)
- Create: `app/Http/Controllers/Finance/HubtelWebhookController.php`, `app/Jobs/ProcessHubtelWebhook.php`, `app/Services/Finance/HubtelWebhookProcessor.php`
- Modify: `routes/web.php` (route)
- Modify: `app/Services/Disbursement/BatchDisbursementService.php` — extract a public `applyResult(Disbursement, DisbursementResult): void`
- Test: `tests/Feature/Finance/HubtelWebhookTest.php`

**Interfaces:**
- Consumes: `BatchDisbursementService` (new `applyResult`), `DisbursementResult`, `HubtelWebhookEvent`.
- Produces: `POST /webhooks/hubtel` named `webhooks.hubtel`, gated by `hubtel.signature`. Processor matches `provider_reference`, applies Settled/Failed, idempotent on event id.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Finance/HubtelWebhookTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\DisbursementStatus;
use App\Models\Disbursement;
use App\Models\HubtelWebhookEvent;

function hubtelSign(array $payload): array
{
    $secret = 'test-secret';
    config()->set('services.hubtel.webhook_secret', $secret);
    $body = json_encode($payload);
    return [$body, hash_hmac('sha256', $body, $secret)];
}

function sentDisbursement(string $ref): Disbursement
{
    return Disbursement::factory()->create([
        'channel'            => 'hubtel_bank',
        'status'             => DisbursementStatus::Sent->value,
        'provider_reference' => $ref,
        'gross_amount'       => 1000.00,
        'net_to_recipient'   => 1000.00,
        'final_settlement_id'=> null,
    ]);
}

it('settles a disbursement on a signed success webhook', function () {
    $d = sentDisbursement('HUB-TX-1');
    [$body, $sig] = hubtelSign(['Data' => ['TransactionId' => 'HUB-TX-1', 'Status' => 'Paid', 'ClientReference' => "PAYOUT-{$d->id}"]]);

    $this->call('POST', '/webhooks/hubtel', [], [], [], ['HTTP_X-Hubtel-Signature' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)
        ->assertOk();

    // process the queued job synchronously if needed; assert terminal state
    expect($d->fresh()->status)->toBe(DisbursementStatus::Settled);
});

it('fails a disbursement on a signed failure webhook', function () {
    $d = sentDisbursement('HUB-TX-2');
    [$body, $sig] = hubtelSign(['Data' => ['TransactionId' => 'HUB-TX-2', 'Status' => 'Failed', 'ClientReference' => "PAYOUT-{$d->id}"]]);

    $this->call('POST', '/webhooks/hubtel', [], [], [], ['HTTP_X-Hubtel-Signature' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();

    expect($d->fresh()->status)->toBe(DisbursementStatus::Failed);
});

it('rejects a bad signature', function () {
    $d = sentDisbursement('HUB-TX-3');
    config()->set('services.hubtel.webhook_secret', 'test-secret');
    $body = json_encode(['Data' => ['TransactionId' => 'HUB-TX-3', 'Status' => 'Paid']]);

    $this->call('POST', '/webhooks/hubtel', [], [], [], ['HTTP_X-Hubtel-Signature' => 'wrong', 'CONTENT_TYPE' => 'application/json'], $body)
        ->assertStatus(400);

    expect($d->fresh()->status)->toBe(DisbursementStatus::Sent);
});

it('is idempotent on a duplicate event', function () {
    $d = sentDisbursement('HUB-TX-4');
    [$body, $sig] = hubtelSign(['Data' => ['TransactionId' => 'HUB-TX-4', 'Status' => 'Paid', 'ClientReference' => "PAYOUT-{$d->id}"]]);

    $this->call('POST', '/webhooks/hubtel', [], [], [], ['HTTP_X-Hubtel-Signature' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
    $this->call('POST', '/webhooks/hubtel', [], [], [], ['HTTP_X-Hubtel-Signature' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();

    expect(HubtelWebhookEvent::where('hubtel_event_id', 'HUB-TX-4')->count())->toBe(1);
});
```

> Note: run tests with the `sync` queue (the suite default) so `ProcessHubtelWebhook::dispatch()` runs inline; if the suite uses a non-sync default, set `Queue::fake()` won't run it — instead force `config(['queue.default' => 'sync'])` in the test or dispatch synchronously in the controller as Paystack does. Match the Paystack test's approach.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Finance/HubtelWebhookTest.php`
Expected: FAIL — route/middleware/model not defined.

- [ ] **Step 3: Migration + model**

Create `database/migrations/xxxx_create_hubtel_webhook_events_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hubtel_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('hubtel_event_id')->unique();
            $table->string('client_reference')->nullable()->index();
            $table->string('status_text')->nullable();
            $table->json('payload');
            $table->string('signature')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hubtel_webhook_events');
    }
};
```

Create `app/Models/HubtelWebhookEvent.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HubtelWebhookEvent extends Model
{
    protected $fillable = [
        'hubtel_event_id', 'client_reference', 'status_text', 'payload', 'signature', 'processed_at',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'datetime'];
    }
}
```

- [ ] **Step 4: Signature middleware + alias** (mirror `VerifyPaystackSignature`, HMAC-SHA256)

Create `app/Http/Middleware/VerifyHubtelSignature.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyHubtelSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret    = (string) config('services.hubtel.webhook_secret');
        $signature = (string) $request->header('X-Hubtel-Signature', '');

        if ($secret === '' || $signature === '') {
            return response()->json(['error' => 'invalid_signature'], 400);
        }

        $computed = hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($computed, $signature)) {
            return response()->json(['error' => 'invalid_signature'], 400);
        }

        return $next($request);
    }
}
```

In `bootstrap/app.php`, in the middleware alias array (next to `'paystack.signature'`), add:

```php
            'hubtel.signature' => \App\Http\Middleware\VerifyHubtelSignature::class,
```

- [ ] **Step 5: Extract `applyResult` on BatchDisbursementService**

In `app/Services/Disbursement/BatchDisbursementService.php`, add a public method reusing the exact update+settle block already in `dispatchOne`/`reconcileOne`:

```php
    /** Apply a provider/webhook result to a disbursement (status + settlement GL). */
    public function applyResult(Disbursement $d, DisbursementResult $result): void
    {
        DB::transaction(function () use ($d, $result) {
            $d->update([
                'status'            => $result->status->value,
                'provider_response' => $result->raw,
                'settled_at'        => $result->status === DisbursementStatus::Settled ? now() : $d->settled_at,
                'failed_at'         => $result->status === DisbursementStatus::Failed ? now() : $d->failed_at,
                'failure_reason'    => $result->failureReason,
            ]);

            if ($result->status === DisbursementStatus::Settled) {
                $this->settle($d);
            }
        });
    }
```

(Leave `dispatchOne`/`reconcileOne` as-is, or optionally refactor them to call `applyResult` — not required.)

- [ ] **Step 6: Processor, job, controller, route**

Create `app/Services/Finance/HubtelWebhookProcessor.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\Disbursement;
use App\Models\HubtelWebhookEvent;
use App\Services\Disbursement\BatchDisbursementService;
use App\Services\Disbursement\DisbursementResult;
use Illuminate\Support\Facades\Log;

class HubtelWebhookProcessor
{
    public function __construct(private readonly BatchDisbursementService $batch) {}

    public function process(HubtelWebhookEvent $event): void
    {
        if ($event->processed_at !== null) {
            return;
        }

        $data      = data_get($event->payload, 'Data', []);
        $clientRef = (string) ($data['ClientReference'] ?? '');
        $txId      = (string) ($data['TransactionId'] ?? '');
        $status    = strtolower((string) ($data['Status'] ?? ''));

        // ClientReference is "PAYOUT-{disbursement_id}"
        $disbursementId = (int) str_replace('PAYOUT-', '', $clientRef);
        $d = Disbursement::find($disbursementId)
            ?? Disbursement::where('provider_reference', $txId)->first();

        if (! $d) {
            Log::info('Hubtel webhook: no matching disbursement', ['ref' => $clientRef, 'tx' => $txId]);
            $event->update(['processed_at' => now()]);
            return;
        }

        $result = match ($status) {
            'paid', 'success', 'successful' => DisbursementResult::settled($txId ?: (string) $d->provider_reference, (array) $event->payload),
            'failed', 'declined', 'reversed' => DisbursementResult::failed("Hubtel reported {$status}", (array) $event->payload),
            default => null,
        };

        if ($result !== null) {
            $this->batch->applyResult($d, $result);
        }

        $event->update(['processed_at' => now()]);
    }
}
```

Create `app/Jobs/ProcessHubtelWebhook.php` (mirror `ProcessPaystackWebhook`):

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\HubtelWebhookEvent;
use App\Services\Finance\HubtelWebhookProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessHubtelWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(public readonly int $eventId) {}

    public function handle(HubtelWebhookProcessor $processor): void
    {
        $event = HubtelWebhookEvent::find($this->eventId);
        if (! $event) {
            return;
        }
        $processor->process($event);
    }
}
```

Create `app/Http/Controllers/Finance/HubtelWebhookController.php` (mirror `PaystackWebhookController` check-then-create):

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessHubtelWebhook;
use App\Models\HubtelWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class HubtelWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->json()->all();
        $eventId = (string) (data_get($payload, 'Data.TransactionId') ?? '');
        if ($eventId === '') {
            return response()->json(['status' => 'ignored', 'reason' => 'missing TransactionId'], 200);
        }

        $existing = HubtelWebhookEvent::where('hubtel_event_id', $eventId)->first();
        if ($existing === null) {
            try {
                $event = HubtelWebhookEvent::create([
                    'hubtel_event_id'  => $eventId,
                    'client_reference' => (string) data_get($payload, 'Data.ClientReference'),
                    'status_text'      => (string) data_get($payload, 'Data.Status'),
                    'payload'          => $payload,
                    'signature'        => (string) $request->header('X-Hubtel-Signature'),
                ]);
                ProcessHubtelWebhook::dispatch($event->id);
            } catch (Throwable $e) {
                Log::info('Hubtel webhook insert race (safely ignored)', ['id' => $eventId, 'error' => $e->getMessage()]);
            }
        }

        return response()->json(['status' => 'received'], 200);
    }
}
```

In `routes/web.php`, next to the Paystack webhook route, add:

```php
    Route::post('/webhooks/hubtel', [\App\Http\Controllers\Finance\HubtelWebhookController::class, 'handle'])
        ->middleware(['hubtel.signature', 'throttle:120,1'])
        ->name('webhooks.hubtel');
```

> Match the Paystack route's group/prefix placement (it lives under a `webhooks` prefix or at web root as `/paystack` — mirror exactly where Paystack's is registered).

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test tests/Feature/Finance/HubtelWebhookTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Models/HubtelWebhookEvent.php database/migrations/*hubtel_webhook* app/Http/Middleware/VerifyHubtelSignature.php bootstrap/app.php app/Http/Controllers/Finance/HubtelWebhookController.php app/Jobs/ProcessHubtelWebhook.php app/Services/Finance/HubtelWebhookProcessor.php app/Services/Disbursement/BatchDisbursementService.php routes/web.php tests/Feature/Finance/HubtelWebhookTest.php
git commit -m "feat(payouts): Hubtel webhook settles disbursement, posts GL, idempotent"
```

---

## Task 6: Wire payroll + settlement to batches; scheduled refresh fallback

**Files:**
- Modify: `app/Listeners/MaterialiseDisbursements.php`
- Modify: `app/Services/Offboarding/OffboardingService.php`
- Create: `app/Console/Commands/RefreshHubtelDisbursementsCommand.php`; modify `routes/console.php`
- Test: `tests/Feature/Disbursement/PayoutWiringTest.php`

**Interfaces:**
- Consumes: `PayoutBatchService`, `BatchDisbursementService::reconcileOne`, `DisbursementStatus`.
- Produces: on `PayrollRunApproved`, disbursements are materialised AND wrapped into a `PendingRelease` `PayoutBatch` (nothing auto-sends). Settlement disbursements likewise get a batch. A `payouts:refresh-hubtel` command refreshes stale `Sent` `hubtel_bank` rows.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Disbursement/PayoutWiringTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\DisbursementStatus;
use App\Enums\PayoutBatchStatus;
use App\Events\PayrollRunApproved;
use App\Listeners\MaterialiseDisbursements;
use App\Models\PayoutBatch;
use App\Models\PayrollRun;

it('materialises disbursements into a pending_release batch on payroll approval (no auto-send)', function () {
    $run = PayrollRun::factory()->create();
    // create at least one calculated line so materialise() produces a disbursement
    // (mirror how existing MaterialiseDisbursements tests set up a run + lines)

    app(MaterialiseDisbursements::class)->handle(new PayrollRunApproved($run));

    $batch = PayoutBatch::where('source_type', PayrollRun::class)->where('source_id', $run->id)->first();

    expect($batch)->not->toBeNull()
        ->and($batch->status)->toBe(PayoutBatchStatus::PendingRelease)
        ->and($batch->disbursements()->where('status', DisbursementStatus::Pending->value)->count())
            ->toBeGreaterThan(0);
    // nothing was sent — all rows still Pending
    expect($batch->disbursements()->where('status', DisbursementStatus::Sent->value)->count())->toBe(0);
});
```

> Note: reuse the exact run+lines setup from the existing `MaterialiseDisbursements`/`BatchDisbursementService` tests (search `tests/` for `materialise`). The batch's maker for a system event is the run's approver — use `$run->approved_by` if present, else a system user id; see Step 3.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Disbursement/PayoutWiringTest.php`
Expected: FAIL — no batch created.

- [ ] **Step 3: Wire the listener**

In `app/Listeners/MaterialiseDisbursements.php`, inject `PayoutBatchService` and, after `$this->batch->materialise($event->run)`, create the batch. Use the run's approver as maker (`$event->run->approved_by`) — fall back to the run's creator if approver is null:

```php
    public function __construct(
        private readonly BatchDisbursementService $batch,
        private readonly \App\Services\Disbursement\PayoutBatchService $batches,
    ) {}

    public function handle(PayrollRunApproved $event): void
    {
        $this->batch->materialise($event->run);

        $makerId = (int) ($event->run->approved_by ?? $event->run->created_by ?? 0);
        if ($makerId > 0) {
            $this->batches->createForPayrollRun($event->run, $makerId);
        }
    }
```

> Confirm the `PayrollRun` field names for approver/creator (`approved_by`, `created_by`, or similar) by reading the model; use the ones that exist. The maker must be a real user id so maker≠checker can be enforced at release.

- [ ] **Step 4: Wire settlements**

In `app/Services/Offboarding/OffboardingService.php`, where `createForSettlement($settlement->fresh())` is called, after it returns the disbursement, create a batch via `PayoutBatchService::createForSettlement($settlement, $makerId)` using the settlement's initiator/approver id. (Inject `PayoutBatchService` into the service constructor following the existing DI pattern.)

- [ ] **Step 5: Scheduled refresh command**

Create `app/Console/Commands/RefreshHubtelDisbursementsCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DisbursementChannel;
use App\Enums\DisbursementStatus;
use App\Models\Disbursement;
use App\Services\Disbursement\BatchDisbursementService;
use Illuminate\Console\Command;

class RefreshHubtelDisbursementsCommand extends Command
{
    protected $signature = 'payouts:refresh-hubtel {--minutes=15}';
    protected $description = 'Poll Hubtel for the status of Sent bank disbursements the webhook has not settled yet.';

    public function handle(BatchDisbursementService $batch): int
    {
        $stale = Disbursement::query()
            ->where('channel', DisbursementChannel::HubtelBank->value)
            ->where('status', DisbursementStatus::Sent->value)
            ->where('sent_at', '<=', now()->subMinutes((int) $this->option('minutes')))
            ->get();

        $touched = 0;
        foreach ($stale as $d) {
            if ($batch->reconcileOne($d)) {
                $touched++;
            }
        }

        $this->info("Refreshed {$touched} Hubtel disbursement(s).");
        return self::SUCCESS;
    }
}
```

In `routes/console.php`, add a schedule (mirror the existing Paystack-expiry schedule):

```php
Schedule::command('payouts:refresh-hubtel')->everyFifteenMinutes()->withoutOverlapping();
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/Disbursement/PayoutWiringTest.php`
Expected: PASS.

- [ ] **Step 7: Run the disbursement + payroll suites (behavioural change guard)**

Run: `php artisan test tests/Feature/Disbursement tests/Feature/Payroll`
Expected: PASS. If a pre-existing test asserted that approval auto-sends disbursements, update it to assert the new batch/pending_release behaviour (the send now happens on release). Fix only tests whose expectation the spec deliberately changed.

- [ ] **Step 8: Commit**

```bash
git add app/Listeners/MaterialiseDisbursements.php app/Services/Offboarding/OffboardingService.php app/Console/Commands/RefreshHubtelDisbursementsCommand.php routes/console.php tests/Feature/Disbursement/PayoutWiringTest.php
git commit -m "feat(payouts): wire payroll + settlements to payout batches; scheduled Hubtel refresh"
```

---

## Task 7: Payouts UI (list, detail, maker create + checker release)

**Files:**
- Create: `app/Http/Controllers/Finance/PayoutBatchController.php`
- Create: `app/Http/Resources/Finance/PayoutBatchResource.php`
- Create: `resources/js/Pages/Finance/Payouts/Index.vue`, `resources/js/Pages/Finance/Payouts/Show.vue`
- Modify: `routes/web.php` (routes), `resources/js/Layouts/AuthenticatedLayout.vue` (nav)
- Test: `tests/Feature/Finance/PayoutBatchPageTest.php`

**Interfaces:**
- Consumes: `PayoutReleaseService::release`, `PayoutBatch`, permissions `payouts.initiate`/`payouts.release`.
- Produces: routes `finance.payouts.index` (list), `finance.payouts.show`, `finance.payouts.release` (POST); Inertia pages `Finance/Payouts/{Index,Show}`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Finance/PayoutBatchPageTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\PayoutBatchStatus;
use App\Models\PayoutBatch;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('renders the payouts list for a permissioned user', function () {
    $user = User::factory()->create(['permissions' => ['payouts.initiate', 'payouts.release']]);
    PayoutBatch::factory()->count(2)->create();

    $this->actingAs($user)->get(route('finance.payouts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Finance/Payouts/Index')->has('batches'));
});

it('403s the list for a user without payout permissions', function () {
    $user = User::factory()->create(['permissions' => []]);
    $this->actingAs($user)->get(route('finance.payouts.index'))->assertForbidden();
});

it('blocks the maker from releasing via the endpoint', function () {
    $maker = User::factory()->create(['permissions' => ['payouts.release']]);
    $batch = PayoutBatch::factory()->create(['created_by' => $maker->id, 'status' => PayoutBatchStatus::PendingRelease->value]);

    $this->actingAs($maker)->post(route('finance.payouts.release', $batch))
        ->assertForbidden(); // PayoutAuthorizationException → 403
});
```

> Confirm the permission-grant test mechanism matches Task 4. Map `PayoutAuthorizationException` to a 403 (see Step 4).

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Finance/PayoutBatchPageTest.php`
Expected: FAIL — routes not defined.

- [ ] **Step 3: Controller + resource**

Create `app/Http/Resources/Finance/PayoutBatchResource.php` returning `id`, `reference`, `status` (value), `total_amount`, `currency`, `requires_high_approval`, `created_by`, `released_by`, `released_at?->format('Y-m-d H:i')`, `disbursements_count`, `created_at?->format('Y-m-d H:i')`.

Create `app/Http/Controllers/Finance/PayoutBatchController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Resources\Finance\PayoutBatchResource;
use App\Models\PayoutBatch;
use App\Services\Disbursement\PayoutReleaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PayoutBatchController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Finance/Payouts/Index', [
            'activeModule' => 'finance-payouts',
            'batches'      => PayoutBatchResource::collection(
                PayoutBatch::query()->withCount('disbursements')->latest()->paginate(25)
            ),
        ]);
    }

    public function show(PayoutBatch $payout): Response
    {
        $payout->load('disbursements');
        return Inertia::render('Finance/Payouts/Show', [
            'activeModule' => 'finance-payouts',
            'batch'        => new PayoutBatchResource($payout),
            'rows'         => $payout->disbursements->map(fn ($d) => [
                'id' => $d->id, 'beneficiary_name' => $d->beneficiary_name,
                'beneficiary_account' => $d->beneficiary_account,
                'net_to_recipient' => $d->net_to_recipient, 'status' => $d->status->value,
                'channel' => $d->channel->value, 'failure_reason' => $d->failure_reason,
            ]),
        ]);
    }

    public function release(Request $request, PayoutBatch $payout, PayoutReleaseService $releaser): RedirectResponse
    {
        $releaser->release($payout, $request->user());
        return back()->with('success', 'Payout batch released.');
    }
}
```

- [ ] **Step 4: Map the authorization exception to 403**

In `bootstrap/app.php` `->withExceptions(...)`, render `PayoutAuthorizationException` as 403:

```php
        $exceptions->render(function (\App\Exceptions\Finance\PayoutAuthorizationException $e) {
            abort(403, $e->getMessage());
        });
```

- [ ] **Step 5: Routes + nav**

In `routes/web.php`, inside the authenticated group, add (gate index/show on `payouts.initiate`, release on `payouts.release`):

```php
    Route::get('finance/payouts', [\App\Http\Controllers\Finance\PayoutBatchController::class, 'index'])
        ->name('finance.payouts.index')->middleware('permission:payouts.initiate');
    Route::get('finance/payouts/{payout}', [\App\Http\Controllers\Finance\PayoutBatchController::class, 'show'])
        ->name('finance.payouts.show')->middleware('permission:payouts.initiate');
    Route::post('finance/payouts/{payout}/release', [\App\Http\Controllers\Finance\PayoutBatchController::class, 'release'])
        ->name('finance.payouts.release')->middleware('permission:payouts.release');
```

In `resources/js/Layouts/AuthenticatedLayout.vue`, add a nav child under the Finance group: `{ label: 'Payouts', route: 'finance.payouts.index', module: 'finance-payouts', icon: 'send_money', visible: can('payouts.initiate') }`.

- [ ] **Step 6: Vue pages**

Create `resources/js/Pages/Finance/Payouts/Index.vue` — a table of batches (reference, status badge, total via a `money()` helper, disbursement count, created date) linking to Show. Create `resources/js/Pages/Finance/Payouts/Show.vue` — batch header + a "Release" button (visible when status is `pending_release` and `can('payouts.release')`) that posts to `finance.payouts.release` via `useForm`, closing only on success and rendering `form.errors`/flash; a table of rows (beneficiary, account, amount, status, failure reason). Add explicit `aria-label`s to any icon-only controls (WCAG merge-gate). Declare `activeModule` in `defineProps`. Reuse `StatCard`/table chrome and the `money()` helper pattern from other finance pages.

- [ ] **Step 7: Build + run tests**

Run: `npx vite build` (expected: clean) then `php artisan test tests/Feature/Finance/PayoutBatchPageTest.php` (expected: PASS).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Finance/PayoutBatchController.php app/Http/Resources/Finance/PayoutBatchResource.php resources/js/Pages/Finance/Payouts/ routes/web.php resources/js/Layouts/AuthenticatedLayout.vue bootstrap/app.php tests/Feature/Finance/PayoutBatchPageTest.php
git commit -m "feat(payouts): payouts list/detail UI with maker-checker release"
```

---

## Task 8: Full-suite regression + integrate

**Files:** none (verification + git)

- [ ] **Step 1: Build + full suite**

Run: `npx vite build` then `php artisan test`.
Expected: PASS (previous total + the new payout/webhook tests). The accessibility WCAG gate and `PermissionEnumTest` must both pass (aria-labels present; the 3 payout slugs catalogued in the seeder). Fix any feature-caused failure; report (don't "fix") pre-existing unrelated failures.

- [ ] **Step 2: Merge with a merge commit**

```bash
git checkout main
git merge --no-ff <feature-branch> -m "Merge: automated Hubtel bank payouts + closed bank loop"
```

- [ ] **Step 3: Push**

```bash
git push origin main
```

---

## Self-Review Notes

- **Spec coverage:** HubtelBankProvider (T1), PayoutBatch model/enum/migrations (T2), batch creation + threshold (T3), maker-checker + threshold release + RBAC (T4), webhook settle→GL→reconcile + idempotency + signature (T5), payroll/settlement wiring + scheduled fallback (T6), UI with maker-checker (T7), regression + integrate (T8). Slices 1a/1b/1c are explicitly out of scope (spec), reusing PayoutBatch + HubtelBankProvider unchanged.
- **Type/name consistency:** `DisbursementChannel::HubtelBank='hubtel_bank'`, `PayoutBatchStatus` values, permission slugs `payouts.initiate|release|release_high`, `applyResult(Disbursement,DisbursementResult)`, `ClientReference = "PAYOUT-{id}"`, and `provider_reference` are used identically across provider, webhook, services, and tests.
- **Verification points flagged inline for the implementer:** Disbursement factory nullable payroll fields; per-user test permission mechanism vs `project_test_patterns`; PayrollRun approver/creator field names; exact location/prefix of the Paystack webhook route to mirror; whether `config/finance.php` exists; the queue default for synchronous webhook processing in tests; existing payroll tests that assumed auto-send.
- **Real-money safety:** no provider call before release; maker≠checker; threshold→release_high; idempotency key + webhook idempotency; GL settlement reuses the audited `settle()` path.
