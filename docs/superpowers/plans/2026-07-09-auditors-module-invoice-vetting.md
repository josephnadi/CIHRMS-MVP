# Auditors Module — Invoice Vetting + Auditor Hub — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let any authorized department submit an incoming purchase invoice (scan/upload) that an auditor vets, the CEO approves, and Finance codes + posts — promoting it into a real `VendorInvoice` — all reachable from a new Auditor Hub.

**Architecture:** A new `IncomingInvoice` intake entity (separate from the accounting `VendorInvoice`) carries a workflow state machine driven exclusively by `IncomingInvoiceService`. On the final `post` transition the service calls the existing `VendorInvoiceService::create()` to mint the accounting invoice and its GL accrual, then links the two. Follows the repo's Enum → FormRequest → Service → Event → Resource → Controller (Inertia) convention.

**Tech Stack:** Laravel 13.7 (PHP 8.3), Inertia.js v2 + Vue 3, Tailwind v3, Pest tests. Private `local` disk for attachments.

## Global Constraints

- All new Finance references MUST use `SequenceService::next('incoming_invoice')` — never `count()+1`.
- State transitions live ONLY in `IncomingInvoiceService`; each guards its source state and throws `DomainException` on an illegal transition (mirror `VendorInvoiceService`).
- Dual control: the vetting auditor MUST NOT be the submitter (`created_by`).
- New permission slugs MUST be added in THREE synced places: `App\Enums\Permission`, `Database\Seeders\RolePermissionSeeder` (`PERMISSIONS` + `ROLE_PERMS`), and `App\Models\User::ROLE_PERMISSIONS`.
- `super_admin` and `ceo` keep their wildcard (`null`) mapping — do not add explicit slugs to them.
- Money columns: `decimal(18,2)`. Currency: `char(3)` default `'GHS'`.
- Attachments stored via `$request->file(...)->store('incoming-invoices', 'local')` (private disk); never the public symlink.
- Feature tests are Pest function-style; `RefreshDatabase` is auto-applied in `tests/Feature`. Seed RBAC + chart of accounts in `beforeEach` (see Task 3).

---

## File Structure

**Create:**
- `app/Enums/IncomingInvoiceStatus.php` — the 6-state enum.
- `database/migrations/2026_07_09_000001_create_incoming_invoices.php` — header table.
- `database/migrations/2026_07_09_000002_create_incoming_invoice_attachments.php`
- `database/migrations/2026_07_09_000003_create_incoming_invoice_events.php`
- `app/Models/IncomingInvoice.php`, `IncomingInvoiceAttachment.php`, `IncomingInvoiceEvent.php`
- `database/factories/IncomingInvoiceFactory.php`
- `app/Events/IncomingInvoiceSubmitted.php`, `IncomingInvoiceVetted.php`, `IncomingInvoiceApproved.php`, `IncomingInvoiceReturned.php`, `IncomingInvoicePosted.php`
- `app/Services/Finance/IncomingInvoiceService.php` — all lifecycle logic.
- `app/Http/Requests/Finance/StoreIncomingInvoiceRequest.php`, `UpdateIncomingInvoiceRequest.php`, `VetIncomingInvoiceRequest.php`, `ReturnIncomingInvoiceRequest.php`, `PostIncomingInvoiceRequest.php`
- `app/Http/Resources/Finance/IncomingInvoiceResource.php`, `IncomingInvoiceEventResource.php`
- `app/Http/Controllers/AuditorController.php` — Hub landing.
- `app/Http/Controllers/Finance/IncomingInvoiceController.php` — intake CRUD + transitions.
- `resources/js/Pages/Auditor/Hub.vue`, `Auditor/IncomingInvoices/Index.vue`, `Create.vue`, `Show.vue`
- `tests/Feature/Auditor/IncomingInvoicePermissionsSeedTest.php`, `IncomingInvoiceMigrationsTest.php`, `IncomingInvoiceServiceTest.php`, `IncomingInvoicePostTest.php`, `IncomingInvoiceEndpointTest.php`, `AuditorHubTest.php`

**Modify:**
- `app/Enums/Permission.php` — add 6 cases.
- `database/seeders/RolePermissionSeeder.php` — add to `PERMISSIONS` + `ROLE_PERMS`.
- `app/Models/User.php` — mirror slugs into `ROLE_PERMISSIONS`.
- `routes/web.php` — add auditor + incoming-invoice route groups.
- `resources/js/Layouts/AuthenticatedLayout.vue` — add the "Auditor" nav group.

---

## Task 1: Permissions & RBAC wiring

**Files:**
- Modify: `app/Enums/Permission.php`
- Modify: `database/seeders/RolePermissionSeeder.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/Auditor/IncomingInvoicePermissionsSeedTest.php`

**Interfaces:**
- Produces slugs used everywhere downstream: `incoming_invoices.view`, `incoming_invoices.submit`, `incoming_invoices.vet`, `incoming_invoices.approve`, `incoming_invoices.post`, `auditor.hub`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Auditor/IncomingInvoicePermissionsSeedTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(fn () => (new RolePermissionSeeder())->run());

it('grants auditor vetting + hub, not submit', function () {
    $u = User::factory()->create(['role' => 'auditor']);
    expect($u->hasPermission('incoming_invoices.view'))->toBeTrue();
    expect($u->hasPermission('incoming_invoices.vet'))->toBeTrue();
    expect($u->hasPermission('auditor.hub'))->toBeTrue();
    expect($u->hasPermission('incoming_invoices.submit'))->toBeFalse();
    expect($u->hasPermission('incoming_invoices.approve'))->toBeFalse();
});

it('grants finance_officer submit + post', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    expect($u->hasPermission('incoming_invoices.submit'))->toBeTrue();
    expect($u->hasPermission('incoming_invoices.post'))->toBeTrue();
    expect($u->hasPermission('incoming_invoices.vet'))->toBeFalse();
});

it('grants dept_head submit but not vet/post', function () {
    $u = User::factory()->create(['role' => 'dept_head']);
    expect($u->hasPermission('incoming_invoices.submit'))->toBeTrue();
    expect($u->hasPermission('incoming_invoices.vet'))->toBeFalse();
    expect($u->hasPermission('incoming_invoices.post'))->toBeFalse();
});

it('ceo (wildcard) can approve', function () {
    $u = User::factory()->create(['role' => 'ceo']);
    expect($u->hasPermission('incoming_invoices.approve'))->toBeTrue();
});

it('plain employee has none', function () {
    $u = User::factory()->create(['role' => 'employee']);
    expect($u->hasPermission('incoming_invoices.view'))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Auditor/IncomingInvoicePermissionsSeedTest.php`
Expected: FAIL (permissions not granted / roles lack slugs).

- [ ] **Step 3: Add the six cases to `app/Enums/Permission.php`**

Find the audit case (`case AuditView = 'audit.view';`) and add directly beneath it:

```php
    case AuditorHub              = 'auditor.hub';
    case IncomingInvoicesView    = 'incoming_invoices.view';
    case IncomingInvoicesSubmit  = 'incoming_invoices.submit';
    case IncomingInvoicesVet     = 'incoming_invoices.vet';
    case IncomingInvoicesApprove = 'incoming_invoices.approve';
    case IncomingInvoicesPost    = 'incoming_invoices.post';
```

- [ ] **Step 4: Register the slugs in `RolePermissionSeeder::PERMISSIONS`**

In `database/seeders/RolePermissionSeeder.php`, inside the `PERMISSIONS` array, after the `'audit.view'` line add:

```php
        // Auditors — invoice vetting + hub
        'auditor.hub'                => ['Auditors', 'Access the Auditor Hub landing page'],
        'incoming_invoices.view'     => ['Auditors', 'View incoming invoices submitted for vetting'],
        'incoming_invoices.submit'   => ['Auditors', 'Create / submit / resubmit incoming invoices for vetting'],
        'incoming_invoices.vet'      => ['Auditors', 'Vet (accept/return) submitted invoices'],
        'incoming_invoices.approve'  => ['Auditors', 'CEO approval / return of vetted invoices'],
        'incoming_invoices.post'     => ['Auditors', 'Finance: code + post an approved invoice to the GL'],
```

- [ ] **Step 5: Grant the slugs to roles in `RolePermissionSeeder::ROLE_PERMS`**

In the same file, append these slugs to the listed roles' arrays (add each line inside the matching role's `[...]`):

- To `'dept_head' => [ ... ]`, `'manager' => [ ... ]`, `'hr_admin' => [ ... ]`:
```php
            'auditor.hub', 'incoming_invoices.view', 'incoming_invoices.submit',
```
- To `'finance_officer' => [ ... ]`:
```php
            'incoming_invoices.view', 'incoming_invoices.submit', 'incoming_invoices.post',
```
- To `'auditor' => [ ... ]`:
```php
            'auditor.hub', 'incoming_invoices.view', 'incoming_invoices.vet',
```

Leave `'super_admin' => null` and `'ceo' => null` untouched (wildcard already covers `incoming_invoices.approve`).

- [ ] **Step 6: Mirror the grants into `app/Models/User.php` `ROLE_PERMISSIONS`**

Apply the identical additions to the same role keys in the `const ROLE_PERMISSIONS` array in `User.php` (the `'dept_head'`, `'manager'`, `'hr_admin'`, `'finance_officer'`, `'auditor'` entries). Use the exact same slug strings as Step 5.

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test tests/Feature/Auditor/IncomingInvoicePermissionsSeedTest.php`
Expected: PASS (5 passing).

- [ ] **Step 8: Commit**

```bash
git add app/Enums/Permission.php database/seeders/RolePermissionSeeder.php app/Models/User.php tests/Feature/Auditor/IncomingInvoicePermissionsSeedTest.php
git commit -m "feat(auditor): add incoming-invoice + hub permissions"
```

---

## Task 2: Status enum, migrations, models, factory

**Files:**
- Create: `app/Enums/IncomingInvoiceStatus.php`
- Create: `database/migrations/2026_07_09_000001_create_incoming_invoices.php`
- Create: `database/migrations/2026_07_09_000002_create_incoming_invoice_attachments.php`
- Create: `database/migrations/2026_07_09_000003_create_incoming_invoice_events.php`
- Create: `app/Models/IncomingInvoice.php`, `IncomingInvoiceAttachment.php`, `IncomingInvoiceEvent.php`
- Create: `database/factories/IncomingInvoiceFactory.php`
- Test: `tests/Feature/Auditor/IncomingInvoiceMigrationsTest.php`

**Interfaces:**
- Produces enum `IncomingInvoiceStatus` cases: `Draft, Submitted, Vetted, Approved, Posted, Returned` (string values `draft|submitted|vetted|approved|posted|returned`) with `label()`.
- Produces model `IncomingInvoice` (fillable + `status` cast + relations `attachments()`, `events()`, `department()`, `submitter()`, `vendorInvoice()`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Auditor/IncomingInvoiceMigrationsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\IncomingInvoiceStatus;
use App\Models\IncomingInvoice;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

it('creates the three tables', function () {
    expect(Schema::hasTable('incoming_invoices'))->toBeTrue();
    expect(Schema::hasTable('incoming_invoice_attachments'))->toBeTrue();
    expect(Schema::hasTable('incoming_invoice_events'))->toBeTrue();
    expect(Schema::hasColumns('incoming_invoices', [
        'reference', 'status', 'department_id', 'vendor_name', 'amount',
        'submitted_by', 'vetted_by', 'approved_by', 'returned_by',
        'posted_by', 'vendor_invoice_id', 'created_by',
    ]))->toBeTrue();
});

it('casts status to the enum and defaults to draft', function () {
    $u = User::factory()->create();
    $inv = IncomingInvoice::create([
        'reference'   => 'INV-TEST-1',
        'vendor_name' => 'Acme Co',
        'invoice_date'=> '2026-07-09',
        'amount'      => 1200.50,
        'description' => 'Toner cartridges',
        'created_by'  => $u->id,
    ]);
    expect($inv->status)->toBe(IncomingInvoiceStatus::Draft);
    expect((float) $inv->amount)->toBe(1200.50);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Auditor/IncomingInvoiceMigrationsTest.php`
Expected: FAIL ("Class IncomingInvoice not found" / no table).

- [ ] **Step 3: Create the enum**

`app/Enums/IncomingInvoiceStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum IncomingInvoiceStatus: string
{
    case Draft     = 'draft';
    case Submitted = 'submitted';
    case Vetted    = 'vetted';
    case Approved  = 'approved';
    case Posted    = 'posted';
    case Returned  = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Draft     => 'Draft',
            self::Submitted => 'Submitted for Vetting',
            self::Vetted    => 'Vetted — Pending CEO',
            self::Approved  => 'Approved',
            self::Posted    => 'Posted to GL',
            self::Returned  => 'Returned',
        };
    }
}
```

- [ ] **Step 4: Create the header migration**

`database/migrations/2026_07_09_000001_create_incoming_invoices.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incoming purchase invoice intake. Departmental submitters scan/upload; an
 * auditor vets; the CEO approves; Finance codes + posts (promoting to a
 * VendorInvoice). Lifecycle:
 *   draft → submitted → vetted → approved → posted
 *   (submitted|vetted|approved) → returned → submitted (resubmit)
 * Separate from vendor_invoices so departments never touch the GL artifact
 * directly. vendor_invoice_id links to the promoted accounting invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incoming_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->string('status', 30)->default('draft')->index();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('vendor_name', 200);
            $table->string('vendor_invoice_no', 100)->nullable();
            $table->date('invoice_date');
            $table->char('currency', 3)->default('GHS');
            $table->decimal('amount', 18, 2)->default(0);
            $table->text('description')->nullable();

            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('vetted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('vetted_at')->nullable();
            $table->text('vetting_notes')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('vendor_invoice_id')->nullable()->constrained('vendor_invoices')->restrictOnDelete();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('invoice_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_invoices');
    }
};
```

- [ ] **Step 5: Create the attachments migration**

`database/migrations/2026_07_09_000002_create_incoming_invoice_attachments.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incoming_invoice_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incoming_invoice_id')->constrained('incoming_invoices')->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_invoice_attachments');
    }
};
```

- [ ] **Step 6: Create the events migration**

`database/migrations/2026_07_09_000003_create_incoming_invoice_events.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Append-only audit trail of every state transition. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incoming_invoice_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incoming_invoice_id')->constrained('incoming_invoices')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_invoice_events');
    }
};
```

- [ ] **Step 7: Create the three models**

`app/Models/IncomingInvoice.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IncomingInvoiceStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class IncomingInvoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'reference', 'status', 'department_id', 'vendor_name', 'vendor_invoice_no',
        'invoice_date', 'currency', 'amount', 'description',
        'submitted_by', 'submitted_at', 'vetted_by', 'vetted_at', 'vetting_notes',
        'approved_by', 'approved_at', 'returned_by', 'returned_at', 'return_reason',
        'posted_by', 'posted_at', 'vendor_invoice_id', 'created_by',
    ];

    protected $attributes = ['status' => 'draft', 'currency' => 'GHS', 'amount' => 0];

    protected function casts(): array
    {
        return [
            'status'       => IncomingInvoiceStatus::class,
            'invoice_date' => 'date',
            'amount'       => 'decimal:2',
            'submitted_at' => 'datetime',
            'vetted_at'    => 'datetime',
            'approved_at'  => 'datetime',
            'returned_at'  => 'datetime',
            'posted_at'    => 'datetime',
        ];
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(IncomingInvoiceAttachment::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(IncomingInvoiceEvent::class)->orderByDesc('id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function vendorInvoice(): BelongsTo
    {
        return $this->belongsTo(VendorInvoice::class);
    }
}
```

`app/Models/IncomingInvoiceAttachment.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncomingInvoiceAttachment extends Model
{
    protected $fillable = [
        'incoming_invoice_id', 'path', 'original_name', 'mime', 'size', 'uploaded_by',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(IncomingInvoice::class, 'incoming_invoice_id');
    }
}
```

`app/Models/IncomingInvoiceEvent.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncomingInvoiceEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'incoming_invoice_id', 'actor_id', 'action', 'from_status', 'to_status', 'comment', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
```

- [ ] **Step 8: Create the factory**

`database/factories/IncomingInvoiceFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\IncomingInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class IncomingInvoiceFactory extends Factory
{
    protected $model = IncomingInvoice::class;

    public function definition(): array
    {
        return [
            'reference'    => 'INV-' . fake()->unique()->numerify('######'),
            'status'       => 'draft',
            'vendor_name'  => fake()->company(),
            'vendor_invoice_no' => fake()->bothify('BILL-####'),
            'invoice_date' => '2026-07-09',
            'currency'     => 'GHS',
            'amount'       => fake()->randomFloat(2, 50, 5000),
            'description'  => fake()->sentence(),
            'created_by'   => User::factory(),
        ];
    }
}
```

- [ ] **Step 9: Run test to verify it passes**

Run: `php artisan test tests/Feature/Auditor/IncomingInvoiceMigrationsTest.php`
Expected: PASS (2 passing).

- [ ] **Step 10: Commit**

```bash
git add app/Enums/IncomingInvoiceStatus.php database/migrations/2026_07_09_00000*_*.php app/Models/IncomingInvoice*.php database/factories/IncomingInvoiceFactory.php tests/Feature/Auditor/IncomingInvoiceMigrationsTest.php
git commit -m "feat(auditor): incoming-invoice schema, models, factory"
```

---

## Task 3: Service — create / update / submit (+ events)

**Files:**
- Create: `app/Services/Finance/IncomingInvoiceService.php`
- Create: `app/Events/IncomingInvoiceSubmitted.php`, `IncomingInvoiceVetted.php`, `IncomingInvoiceApproved.php`, `IncomingInvoiceReturned.php`, `IncomingInvoicePosted.php`
- Test: `tests/Feature/Auditor/IncomingInvoiceServiceTest.php`

**Interfaces:**
- Consumes: `SequenceService::next(string)` (constructor-injected), `IncomingInvoiceStatus`.
- Produces (used by Tasks 4–8):
  - `create(array $data, User $creator): IncomingInvoice` — status `draft`; derives `department_id` from `$creator->employee?->department_id`; creates attachment rows from `$data['attachments']` (each `['path','original_name','mime','size']`); records a `created` event.
  - `update(IncomingInvoice $inv, array $data, User $actor): IncomingInvoice` — only when status is `draft` or `returned`, else `DomainException`.
  - `submit(IncomingInvoice $inv, User $actor): IncomingInvoice` — from `draft` or `returned` → `submitted`; sets `submitted_by/at`; dispatches `IncomingInvoiceSubmitted`; records event.
  - protected `recordEvent(IncomingInvoice, ?User, string $action, ?string $from, ?string $to, ?string $comment)`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Auditor/IncomingInvoiceServiceTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\IncomingInvoiceStatus;
use App\Events\IncomingInvoiceSubmitted;
use App\Models\IncomingInvoice;
use App\Models\User;
use App\Services\Finance\IncomingInvoiceService;
use Illuminate\Support\Facades\Event;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    $this->service = app(IncomingInvoiceService::class);
});

function makeData(array $overrides = []): array
{
    return array_merge([
        'vendor_name'  => 'Acme Co',
        'vendor_invoice_no' => 'BILL-1',
        'invoice_date' => '2026-07-09',
        'amount'       => 1500,
        'description'  => 'Toner',
    ], $overrides);
}

it('creates a draft with a sequenced reference and a created event', function () {
    $u = User::factory()->create(['role' => 'dept_head']);
    $inv = $this->service->create(makeData(), $u);

    expect($inv->status)->toBe(IncomingInvoiceStatus::Draft);
    expect($inv->reference)->toStartWith('INV-');
    expect($inv->created_by)->toBe($u->id);
    expect($inv->events()->where('action', 'created')->exists())->toBeTrue();
});

it('records attachments passed in create', function () {
    $u = User::factory()->create(['role' => 'dept_head']);
    $inv = $this->service->create(makeData(['attachments' => [
        ['path' => 'incoming-invoices/a.pdf', 'original_name' => 'a.pdf', 'mime' => 'application/pdf', 'size' => 10],
    ]]), $u);

    expect($inv->attachments()->count())->toBe(1);
});

it('submit moves draft to submitted and fires the event', function () {
    Event::fake([IncomingInvoiceSubmitted::class]);
    $u = User::factory()->create(['role' => 'dept_head']);
    $inv = $this->service->create(makeData(), $u);

    $this->service->submit($inv, $u);

    expect($inv->fresh()->status)->toBe(IncomingInvoiceStatus::Submitted);
    expect($inv->fresh()->submitted_by)->toBe($u->id);
    Event::assertDispatched(IncomingInvoiceSubmitted::class);
});

it('update refuses a submitted invoice', function () {
    $u = User::factory()->create(['role' => 'dept_head']);
    $inv = $this->service->create(makeData(), $u);
    $this->service->submit($inv, $u);

    $this->service->update($inv->fresh(), makeData(['amount' => 99]), $u);
})->throws(DomainException::class);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Auditor/IncomingInvoiceServiceTest.php`
Expected: FAIL ("Target class [IncomingInvoiceService] does not exist").

- [ ] **Step 3: Create the five event classes**

Each event is the same shape as `VendorInvoiceCreated`. Create all five, substituting the class name. Example `app/Events/IncomingInvoiceSubmitted.php`:

```php
<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\IncomingInvoice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IncomingInvoiceSubmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly IncomingInvoice $invoice)
    {
    }
}
```

Repeat verbatim for `IncomingInvoiceVetted`, `IncomingInvoiceApproved`, `IncomingInvoiceReturned`, `IncomingInvoicePosted` (only the class name changes).

- [ ] **Step 4: Create the service with create/update/submit**

`app/Services/Finance/IncomingInvoiceService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\IncomingInvoiceStatus;
use App\Events\IncomingInvoiceSubmitted;
use App\Models\IncomingInvoice;
use App\Models\IncomingInvoiceEvent;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class IncomingInvoiceService
{
    public function __construct(private readonly SequenceService $sequences)
    {
    }

    public function create(array $data, User $creator): IncomingInvoice
    {
        return DB::transaction(function () use ($data, $creator) {
            $inv = IncomingInvoice::create([
                'reference'         => $this->nextReference(),
                'status'            => IncomingInvoiceStatus::Draft->value,
                'department_id'     => $creator->employee?->department_id,
                'vendor_name'       => $data['vendor_name'],
                'vendor_invoice_no' => $data['vendor_invoice_no'] ?? null,
                'invoice_date'      => $data['invoice_date'],
                'currency'          => $data['currency'] ?? 'GHS',
                'amount'            => $data['amount'],
                'description'       => $data['description'] ?? null,
                'created_by'        => $creator->id,
            ]);

            foreach ($data['attachments'] ?? [] as $a) {
                $inv->attachments()->create([
                    'path'          => $a['path'],
                    'original_name' => $a['original_name'],
                    'mime'          => $a['mime'] ?? null,
                    'size'          => $a['size'] ?? 0,
                    'uploaded_by'   => $creator->id,
                ]);
            }

            $this->recordEvent($inv, $creator, 'created', null, IncomingInvoiceStatus::Draft->value);

            return $inv->fresh(['attachments', 'events']);
        });
    }

    public function update(IncomingInvoice $inv, array $data, User $actor): IncomingInvoice
    {
        if (! in_array($inv->status, [IncomingInvoiceStatus::Draft, IncomingInvoiceStatus::Returned], true)) {
            throw new DomainException('Only draft or returned invoices can be edited.');
        }

        $inv->update([
            'vendor_name'       => $data['vendor_name'],
            'vendor_invoice_no' => $data['vendor_invoice_no'] ?? null,
            'invoice_date'      => $data['invoice_date'],
            'currency'          => $data['currency'] ?? $inv->currency,
            'amount'            => $data['amount'],
            'description'       => $data['description'] ?? null,
        ]);

        foreach ($data['attachments'] ?? [] as $a) {
            $inv->attachments()->create([
                'path'          => $a['path'],
                'original_name' => $a['original_name'],
                'mime'          => $a['mime'] ?? null,
                'size'          => $a['size'] ?? 0,
                'uploaded_by'   => $actor->id,
            ]);
        }

        return $inv->fresh(['attachments', 'events']);
    }

    public function submit(IncomingInvoice $inv, User $actor): IncomingInvoice
    {
        if (! in_array($inv->status, [IncomingInvoiceStatus::Draft, IncomingInvoiceStatus::Returned], true)) {
            throw new DomainException('Only draft or returned invoices can be submitted.');
        }

        $from = $inv->status->value;
        $inv->update([
            'status'       => IncomingInvoiceStatus::Submitted->value,
            'submitted_by' => $actor->id,
            'submitted_at' => now(),
        ]);

        $this->recordEvent($inv, $actor, 'submitted', $from, IncomingInvoiceStatus::Submitted->value);
        IncomingInvoiceSubmitted::dispatch($inv->fresh());

        return $inv->fresh();
    }

    protected function recordEvent(IncomingInvoice $inv, ?User $actor, string $action, ?string $from, ?string $to, ?string $comment = null): void
    {
        IncomingInvoiceEvent::create([
            'incoming_invoice_id' => $inv->id,
            'actor_id'            => $actor?->id,
            'action'              => $action,
            'from_status'         => $from,
            'to_status'           => $to,
            'comment'             => $comment,
            'created_at'          => now(),
        ]);
    }

    protected function nextReference(): string
    {
        $n = $this->sequences->next('incoming_invoice');
        return 'INV-' . now()->format('Y') . '-' . str_pad((string) $n, 5, '0', STR_PAD_LEFT);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/Auditor/IncomingInvoiceServiceTest.php`
Expected: PASS (4 passing).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Finance/IncomingInvoiceService.php app/Events/IncomingInvoice*.php tests/Feature/Auditor/IncomingInvoiceServiceTest.php
git commit -m "feat(auditor): incoming-invoice service create/update/submit + events"
```

---

## Task 4: Service — vetAccept / vetReturn (dual control)

**Files:**
- Modify: `app/Services/Finance/IncomingInvoiceService.php`
- Test: `tests/Feature/Auditor/IncomingInvoiceServiceTest.php` (append)

**Interfaces:**
- Produces:
  - `vetAccept(IncomingInvoice $inv, User $auditor, ?string $notes = null): IncomingInvoice` — from `submitted` → `vetted`; throws `DomainException` if `$auditor->id === $inv->created_by` (dual control) or status ≠ submitted; sets `vetted_by/at`, `vetting_notes`; dispatches `IncomingInvoiceVetted`.
  - `vetReturn(IncomingInvoice $inv, User $auditor, string $reason): IncomingInvoice` — from `submitted` → `returned`; sets `returned_by/at`, `return_reason`; dispatches `IncomingInvoiceReturned`.

- [ ] **Step 1: Write the failing test (append)**

Append to `tests/Feature/Auditor/IncomingInvoiceServiceTest.php`:

```php
it('auditor vets a submitted invoice', function () {
    $sub = User::factory()->create(['role' => 'dept_head']);
    $aud = User::factory()->create(['role' => 'auditor']);
    $inv = $this->service->create(makeData(), $sub);
    $this->service->submit($inv, $sub);

    $this->service->vetAccept($inv->fresh(), $aud, 'looks good');

    expect($inv->fresh()->status)->toBe(IncomingInvoiceStatus::Vetted);
    expect($inv->fresh()->vetted_by)->toBe($aud->id);
});

it('blocks the submitter from vetting their own invoice (dual control)', function () {
    $sub = User::factory()->create(['role' => 'finance_officer']);
    $inv = $this->service->create(makeData(), $sub);
    $this->service->submit($inv, $sub);

    $this->service->vetAccept($inv->fresh(), $sub);
})->throws(DomainException::class);

it('vetReturn sends it back to returned with a reason', function () {
    $sub = User::factory()->create(['role' => 'dept_head']);
    $aud = User::factory()->create(['role' => 'auditor']);
    $inv = $this->service->create(makeData(), $sub);
    $this->service->submit($inv, $sub);

    $this->service->vetReturn($inv->fresh(), $aud, 'missing receipt');

    expect($inv->fresh()->status)->toBe(IncomingInvoiceStatus::Returned);
    expect($inv->fresh()->return_reason)->toBe('missing receipt');
});

it('can resubmit a returned invoice', function () {
    $sub = User::factory()->create(['role' => 'dept_head']);
    $aud = User::factory()->create(['role' => 'auditor']);
    $inv = $this->service->create(makeData(), $sub);
    $this->service->submit($inv, $sub);
    $this->service->vetReturn($inv->fresh(), $aud, 'fix it');

    $this->service->submit($inv->fresh(), $sub);
    expect($inv->fresh()->status)->toBe(IncomingInvoiceStatus::Submitted);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Auditor/IncomingInvoiceServiceTest.php`
Expected: FAIL ("Call to undefined method ...vetAccept()").

- [ ] **Step 3: Add the methods**

Add to `IncomingInvoiceService` (after `submit`), and add the two `use App\Events\IncomingInvoiceVetted;` / `IncomingInvoiceReturned;` imports at the top:

```php
    public function vetAccept(IncomingInvoice $inv, User $auditor, ?string $notes = null): IncomingInvoice
    {
        if ($inv->status !== IncomingInvoiceStatus::Submitted) {
            throw new DomainException('Only submitted invoices can be vetted.');
        }
        if ($auditor->id === $inv->created_by) {
            throw new DomainException('Dual-control violation: the submitter cannot vet their own invoice.');
        }

        $inv->update([
            'status'        => IncomingInvoiceStatus::Vetted->value,
            'vetted_by'     => $auditor->id,
            'vetted_at'     => now(),
            'vetting_notes' => $notes,
        ]);

        $this->recordEvent($inv, $auditor, 'vetted', IncomingInvoiceStatus::Submitted->value, IncomingInvoiceStatus::Vetted->value, $notes);
        IncomingInvoiceVetted::dispatch($inv->fresh());

        return $inv->fresh();
    }

    public function vetReturn(IncomingInvoice $inv, User $auditor, string $reason): IncomingInvoice
    {
        if ($inv->status !== IncomingInvoiceStatus::Submitted) {
            throw new DomainException('Only submitted invoices can be returned by the auditor.');
        }

        return $this->markReturned($inv, $auditor, $reason, IncomingInvoiceStatus::Submitted->value);
    }

    protected function markReturned(IncomingInvoice $inv, User $actor, string $reason, string $from): IncomingInvoice
    {
        $inv->update([
            'status'        => IncomingInvoiceStatus::Returned->value,
            'returned_by'   => $actor->id,
            'returned_at'   => now(),
            'return_reason' => $reason,
        ]);

        $this->recordEvent($inv, $actor, 'returned', $from, IncomingInvoiceStatus::Returned->value, $reason);
        IncomingInvoiceReturned::dispatch($inv->fresh());

        return $inv->fresh();
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Auditor/IncomingInvoiceServiceTest.php`
Expected: PASS (8 passing).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Finance/IncomingInvoiceService.php tests/Feature/Auditor/IncomingInvoiceServiceTest.php
git commit -m "feat(auditor): auditor vet accept/return with dual control"
```

---

## Task 5: Service — ceoApprove / ceoReturn

**Files:**
- Modify: `app/Services/Finance/IncomingInvoiceService.php`
- Test: `tests/Feature/Auditor/IncomingInvoiceServiceTest.php` (append)

**Interfaces:**
- Produces:
  - `ceoApprove(IncomingInvoice $inv, User $ceo): IncomingInvoice` — from `vetted` → `approved`; sets `approved_by/at`; dispatches `IncomingInvoiceApproved`.
  - `ceoReturn(IncomingInvoice $inv, User $ceo, string $reason): IncomingInvoice` — from `vetted` → `returned` (reuses `markReturned`).

- [ ] **Step 1: Write the failing test (append)**

```php
it('ceo approves a vetted invoice', function () {
    $sub = User::factory()->create(['role' => 'dept_head']);
    $aud = User::factory()->create(['role' => 'auditor']);
    $ceo = User::factory()->create(['role' => 'ceo']);
    $inv = $this->service->create(makeData(), $sub);
    $this->service->submit($inv, $sub);
    $this->service->vetAccept($inv->fresh(), $aud);

    $this->service->ceoApprove($inv->fresh(), $ceo);

    expect($inv->fresh()->status)->toBe(IncomingInvoiceStatus::Approved);
    expect($inv->fresh()->approved_by)->toBe($ceo->id);
});

it('ceo cannot approve an un-vetted invoice', function () {
    $sub = User::factory()->create(['role' => 'dept_head']);
    $ceo = User::factory()->create(['role' => 'ceo']);
    $inv = $this->service->create(makeData(), $sub);
    $this->service->submit($inv, $sub);

    $this->service->ceoApprove($inv->fresh(), $ceo);
})->throws(DomainException::class);

it('ceoReturn sends a vetted invoice back', function () {
    $sub = User::factory()->create(['role' => 'dept_head']);
    $aud = User::factory()->create(['role' => 'auditor']);
    $ceo = User::factory()->create(['role' => 'ceo']);
    $inv = $this->service->create(makeData(), $sub);
    $this->service->submit($inv, $sub);
    $this->service->vetAccept($inv->fresh(), $aud);

    $this->service->ceoReturn($inv->fresh(), $ceo, 'over budget');
    expect($inv->fresh()->status)->toBe(IncomingInvoiceStatus::Returned);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Auditor/IncomingInvoiceServiceTest.php`
Expected: FAIL ("undefined method ceoApprove").

- [ ] **Step 3: Add the methods**

Add `use App\Events\IncomingInvoiceApproved;` at top, then to the service:

```php
    public function ceoApprove(IncomingInvoice $inv, User $ceo): IncomingInvoice
    {
        if ($inv->status !== IncomingInvoiceStatus::Vetted) {
            throw new DomainException('Only vetted invoices can be approved.');
        }

        $inv->update([
            'status'      => IncomingInvoiceStatus::Approved->value,
            'approved_by' => $ceo->id,
            'approved_at' => now(),
        ]);

        $this->recordEvent($inv, $ceo, 'approved', IncomingInvoiceStatus::Vetted->value, IncomingInvoiceStatus::Approved->value);
        IncomingInvoiceApproved::dispatch($inv->fresh());

        return $inv->fresh();
    }

    public function ceoReturn(IncomingInvoice $inv, User $ceo, string $reason): IncomingInvoice
    {
        if ($inv->status !== IncomingInvoiceStatus::Vetted) {
            throw new DomainException('Only vetted invoices can be returned by the CEO.');
        }

        return $this->markReturned($inv, $ceo, $reason, IncomingInvoiceStatus::Vetted->value);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Auditor/IncomingInvoiceServiceTest.php`
Expected: PASS (11 passing).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Finance/IncomingInvoiceService.php tests/Feature/Auditor/IncomingInvoiceServiceTest.php
git commit -m "feat(auditor): CEO approve/return transitions"
```

---

## Task 6: Service — post() promotes to VendorInvoice

**Files:**
- Modify: `app/Services/Finance/IncomingInvoiceService.php`
- Test: `tests/Feature/Auditor/IncomingInvoicePostTest.php`

**Interfaces:**
- Consumes: existing `VendorInvoiceService::create(array $data, User $creator): VendorInvoice` (inject via constructor). Its `$data` needs `vendor_id`, `invoice_date`, optional `vendor_invoice_no`, and `lines` (each `description, quantity, unit_price, tax_rate?, gl_account_id`).
- Produces:
  - `post(IncomingInvoice $inv, array $data, User $poster): IncomingInvoice` — from `approved` → `posted`. Builds the vendor-invoice payload from `$data['vendor_id']` + `$data['lines']`, calls `VendorInvoiceService::create`, sets `vendor_invoice_id`, `posted_by/at`; dispatches `IncomingInvoicePosted`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Auditor/IncomingInvoicePostTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\IncomingInvoiceStatus;
use App\Models\GlAccount;
use App\Models\IncomingInvoice;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Finance\IncomingInvoiceService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    $this->service = app(IncomingInvoiceService::class);
    $this->expense = GlAccount::where('code', '5200')->firstOrFail();
    $this->vendor  = Vendor::create(['code' => 'VEN-P', 'name' => 'Poster', 'status' => 'active']);
});

it('posting an approved invoice promotes it to a VendorInvoice', function () {
    $sub = User::factory()->create(['role' => 'dept_head']);
    $aud = User::factory()->create(['role' => 'auditor']);
    $ceo = User::factory()->create(['role' => 'ceo']);
    $fin = User::factory()->create(['role' => 'finance_officer']);

    $inv = $this->service->create([
        'vendor_name' => 'Poster', 'vendor_invoice_no' => 'BILL-9',
        'invoice_date' => '2026-07-09', 'amount' => 100, 'description' => 'Stuff',
    ], $sub);
    $this->service->submit($inv, $sub);
    $this->service->vetAccept($inv->fresh(), $aud);
    $this->service->ceoApprove($inv->fresh(), $ceo);

    $this->service->post($inv->fresh(), [
        'vendor_id' => $this->vendor->id,
        'lines' => [[
            'description' => 'Stuff', 'quantity' => 1, 'unit_price' => 100,
            'gl_account_id' => $this->expense->id,
        ]],
    ], $fin);

    $inv->refresh();
    expect($inv->status)->toBe(IncomingInvoiceStatus::Posted);
    expect($inv->vendor_invoice_id)->not->toBeNull();
    expect($inv->posted_by)->toBe($fin->id);
});

it('cannot post an invoice that is not approved', function () {
    $sub = User::factory()->create(['role' => 'dept_head']);
    $fin = User::factory()->create(['role' => 'finance_officer']);
    $inv = $this->service->create([
        'vendor_name' => 'Poster', 'invoice_date' => '2026-07-09', 'amount' => 100,
    ], $sub);

    $this->service->post($inv->fresh(), [
        'vendor_id' => $this->vendor->id,
        'lines' => [['description' => 'x', 'quantity' => 1, 'unit_price' => 100, 'gl_account_id' => $this->expense->id]],
    ], $fin);
})->throws(DomainException::class);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Auditor/IncomingInvoicePostTest.php`
Expected: FAIL ("undefined method post").

- [ ] **Step 3: Inject VendorInvoiceService and add post()**

Update the constructor and add the method. New constructor:

```php
    public function __construct(
        private readonly SequenceService $sequences,
        private readonly VendorInvoiceService $vendorInvoices,
    ) {
    }
```

Add `use App\Events\IncomingInvoicePosted;` at top, then:

```php
    public function post(IncomingInvoice $inv, array $data, User $poster): IncomingInvoice
    {
        if ($inv->status !== IncomingInvoiceStatus::Approved) {
            throw new DomainException('Only CEO-approved invoices can be posted.');
        }

        return DB::transaction(function () use ($inv, $data, $poster) {
            $vendorInvoice = $this->vendorInvoices->create([
                'vendor_id'         => $data['vendor_id'],
                'vendor_invoice_no' => $inv->vendor_invoice_no,
                'invoice_date'      => $inv->invoice_date->format('Y-m-d'),
                'currency'          => $inv->currency,
                'notes'             => 'Promoted from incoming invoice ' . $inv->reference,
                'lines'             => $data['lines'],
            ], $poster);

            $inv->update([
                'status'            => IncomingInvoiceStatus::Posted->value,
                'posted_by'         => $poster->id,
                'posted_at'         => now(),
                'vendor_invoice_id' => $vendorInvoice->id,
            ]);

            $this->recordEvent($inv, $poster, 'posted', IncomingInvoiceStatus::Approved->value, IncomingInvoiceStatus::Posted->value, "VendorInvoice #{$vendorInvoice->id}");
            IncomingInvoicePosted::dispatch($inv->fresh());

            return $inv->fresh();
        });
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Auditor/IncomingInvoicePostTest.php`
Expected: PASS (2 passing).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Finance/IncomingInvoiceService.php tests/Feature/Auditor/IncomingInvoicePostTest.php
git commit -m "feat(auditor): post() promotes approved intake to VendorInvoice"
```

---

## Task 7: FormRequests + Resources

**Files:**
- Create: `app/Http/Requests/Finance/StoreIncomingInvoiceRequest.php`, `UpdateIncomingInvoiceRequest.php`, `VetIncomingInvoiceRequest.php`, `ReturnIncomingInvoiceRequest.php`, `PostIncomingInvoiceRequest.php`
- Create: `app/Http/Resources/Finance/IncomingInvoiceResource.php`, `IncomingInvoiceEventResource.php`
- Test: covered by Task 8's endpoint test (these are exercised through HTTP).

**Interfaces:**
- Produces validated payloads consumed by the controller in Task 8. `StoreIncomingInvoiceRequest` validates `vendor_name, vendor_invoice_no?, invoice_date, currency?, amount, description?, attachments[]` (each `file`, pdf/jpg/png, ≤10 MB). `PostIncomingInvoiceRequest` validates `vendor_id` + `lines[]` exactly like `StoreVendorInvoiceRequest`.

- [ ] **Step 1: Create `StoreIncomingInvoiceRequest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class StoreIncomingInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('incoming_invoices.submit') === true;
    }

    public function rules(): array
    {
        return [
            'vendor_name'       => ['required', 'string', 'max:200'],
            'vendor_invoice_no' => ['nullable', 'string', 'max:100'],
            'invoice_date'      => ['required', 'date'],
            'currency'          => ['sometimes', 'string', 'size:3'],
            'amount'            => ['required', 'numeric', 'min:0'],
            'description'       => ['nullable', 'string', 'max:2000'],
            'attachments'       => ['sometimes', 'array', 'max:10'],
            'attachments.*'     => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }
}
```

- [ ] **Step 2: Create `UpdateIncomingInvoiceRequest.php`**

Identical to Store but `authorize()` allows the owner to edit their own draft/returned:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class UpdateIncomingInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('incoming_invoices.submit') === true;
    }

    public function rules(): array
    {
        return [
            'vendor_name'       => ['required', 'string', 'max:200'],
            'vendor_invoice_no' => ['nullable', 'string', 'max:100'],
            'invoice_date'      => ['required', 'date'],
            'currency'          => ['sometimes', 'string', 'size:3'],
            'amount'            => ['required', 'numeric', 'min:0'],
            'description'       => ['nullable', 'string', 'max:2000'],
            'attachments'       => ['sometimes', 'array', 'max:10'],
            'attachments.*'     => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }
}
```

- [ ] **Step 3: Create `VetIncomingInvoiceRequest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class VetIncomingInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('incoming_invoices.vet') === true;
    }

    public function rules(): array
    {
        return ['notes' => ['nullable', 'string', 'max:2000']];
    }
}
```

- [ ] **Step 4: Create `ReturnIncomingInvoiceRequest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class ReturnIncomingInvoiceRequest extends FormRequest
{
    // Both auditor (vet) and CEO (approve) may return; the controller picks the
    // transition by current status. Either permission authorizes the request.
    public function authorize(): bool
    {
        $u = $this->user();
        return $u !== null && ($u->hasPermission('incoming_invoices.vet') || $u->hasPermission('incoming_invoices.approve'));
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:2000']];
    }
}
```

- [ ] **Step 5: Create `PostIncomingInvoiceRequest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class PostIncomingInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('incoming_invoices.post') === true;
    }

    public function rules(): array
    {
        return [
            'vendor_id'             => ['required', 'integer', 'exists:vendors,id'],
            'lines'                 => ['required', 'array', 'min:1'],
            'lines.*.description'   => ['required', 'string', 'max:500'],
            'lines.*.quantity'      => ['required', 'numeric', 'min:0.001'],
            'lines.*.unit_price'    => ['required', 'numeric', 'min:0'],
            'lines.*.tax_rate'      => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'lines.*.gl_account_id' => ['required', 'integer', 'exists:gl_accounts,id'],
        ];
    }
}
```

- [ ] **Step 6: Create `IncomingInvoiceEventResource.php`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Finance;

use App\Models\IncomingInvoiceEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin IncomingInvoiceEvent */
class IncomingInvoiceEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'action'      => $this->action,
            'from_status' => $this->from_status,
            'to_status'   => $this->to_status,
            'comment'     => $this->comment,
            'actor'       => $this->whenLoaded('actor', fn () => [
                'id' => $this->actor?->id, 'name' => $this->actor?->name,
            ]),
            'created_at'  => $this->created_at?->format('Y-m-d H:i'),
        ];
    }
}
```

- [ ] **Step 7: Create `IncomingInvoiceResource.php`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Finance;

use App\Models\IncomingInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin IncomingInvoice */
class IncomingInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'reference'         => $this->reference,
            'status'            => ['value' => $this->status->value, 'label' => $this->status->label()],
            'vendor_name'       => $this->vendor_name,
            'vendor_invoice_no' => $this->vendor_invoice_no,
            'invoice_date'      => $this->invoice_date?->format('Y-m-d'),
            'currency'          => $this->currency,
            'amount'            => (float) $this->amount,
            'description'       => $this->description,
            'department'        => $this->whenLoaded('department', fn () => [
                'id' => $this->department?->id, 'name' => $this->department?->name,
            ]),
            'vetting_notes'     => $this->vetting_notes,
            'return_reason'     => $this->return_reason,
            'vendor_invoice_id' => $this->vendor_invoice_id,
            'submitted_at'      => $this->submitted_at?->format('Y-m-d H:i'),
            'vetted_at'         => $this->vetted_at?->format('Y-m-d H:i'),
            'approved_at'       => $this->approved_at?->format('Y-m-d H:i'),
            'posted_at'         => $this->posted_at?->format('Y-m-d H:i'),
            'created_by'        => $this->created_by,
            'attachments'       => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($a) => [
                'id' => $a->id, 'original_name' => $a->original_name, 'mime' => $a->mime, 'size' => $a->size,
            ])),
            'events'            => IncomingInvoiceEventResource::collection($this->whenLoaded('events')),
        ];
    }
}
```

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/Finance/*IncomingInvoice*.php app/Http/Resources/Finance/IncomingInvoice*.php
git commit -m "feat(auditor): incoming-invoice form requests + resources"
```

---

## Task 8: Controllers + routes + endpoint tests

**Files:**
- Create: `app/Http/Controllers/Finance/IncomingInvoiceController.php`
- Create: `app/Http/Controllers/AuditorController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Auditor/IncomingInvoiceEndpointTest.php`, `tests/Feature/Auditor/AuditorHubTest.php`

**Interfaces:**
- Consumes: `IncomingInvoiceService` (all methods), the Task 7 requests/resources.
- Produces route names: `auditor.hub`, `incoming-invoices.{index,show,store,update,submit,download,vet,vet-return,approve,ceo-return,post}`.

- [ ] **Step 1: Write the failing endpoint test**

Create `tests/Feature/Auditor/IncomingInvoiceEndpointTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\IncomingInvoiceStatus;
use App\Models\GlAccount;
use App\Models\IncomingInvoice;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    Storage::fake('local');
    $this->expense = GlAccount::where('code', '5200')->firstOrFail();
    $this->vendor  = Vendor::create(['code' => 'VEN-E', 'name' => 'E', 'status' => 'active']);
});

it('dept_head can list, employee cannot', function () {
    $this->actingAs(User::factory()->create(['role' => 'dept_head']))
        ->get('/auditor/incoming-invoices')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Auditor/IncomingInvoices/Index'));

    $this->actingAs(User::factory()->create(['role' => 'employee']))
        ->get('/auditor/incoming-invoices')->assertForbidden();
});

it('stores an invoice with an attachment', function () {
    $u = User::factory()->create(['role' => 'dept_head']);
    $this->actingAs($u)->post('/auditor/incoming-invoices', [
        'vendor_name' => 'Acme', 'invoice_date' => '2026-07-09', 'amount' => 250,
        'description' => 'Paper',
        'attachments' => [UploadedFile::fake()->create('bill.pdf', 40, 'application/pdf')],
    ])->assertRedirect();

    $inv = IncomingInvoice::latest()->first();
    expect($inv->attachments()->count())->toBe(1);
    expect($inv->status)->toBe(IncomingInvoiceStatus::Draft);
});

it('walks the full lifecycle over HTTP', function () {
    $sub = User::factory()->create(['role' => 'dept_head']);
    $aud = User::factory()->create(['role' => 'auditor']);
    $ceo = User::factory()->create(['role' => 'ceo']);
    $fin = User::factory()->create(['role' => 'finance_officer']);

    $this->actingAs($sub)->post('/auditor/incoming-invoices', [
        'vendor_name' => 'Acme', 'invoice_date' => '2026-07-09', 'amount' => 100, 'description' => 'x',
    ]);
    $inv = IncomingInvoice::latest()->first();

    $this->actingAs($sub)->post("/auditor/incoming-invoices/{$inv->id}/submit")->assertRedirect();
    $this->actingAs($aud)->post("/auditor/incoming-invoices/{$inv->id}/vet")->assertRedirect();
    expect($inv->fresh()->status)->toBe(IncomingInvoiceStatus::Vetted);

    $this->actingAs($ceo)->post("/auditor/incoming-invoices/{$inv->id}/approve")->assertRedirect();
    expect($inv->fresh()->status)->toBe(IncomingInvoiceStatus::Approved);

    $this->actingAs($fin)->post("/auditor/incoming-invoices/{$inv->id}/post", [
        'vendor_id' => $this->vendor->id,
        'lines' => [['description' => 'x', 'quantity' => 1, 'unit_price' => 100, 'gl_account_id' => $this->expense->id]],
    ])->assertRedirect();
    expect($inv->fresh()->status)->toBe(IncomingInvoiceStatus::Posted);
    expect($inv->fresh()->vendor_invoice_id)->not->toBeNull();
});

it('auditor return requires a reason', function () {
    $sub = User::factory()->create(['role' => 'dept_head']);
    $aud = User::factory()->create(['role' => 'auditor']);
    $this->actingAs($sub)->post('/auditor/incoming-invoices', [
        'vendor_name' => 'Acme', 'invoice_date' => '2026-07-09', 'amount' => 100,
    ]);
    $inv = IncomingInvoice::latest()->first();
    $this->actingAs($sub)->post("/auditor/incoming-invoices/{$inv->id}/submit");

    $this->actingAs($aud)->post("/auditor/incoming-invoices/{$inv->id}/vet-return", [])
        ->assertSessionHasErrors(['reason']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Auditor/IncomingInvoiceEndpointTest.php`
Expected: FAIL (404 / no route / no component).

- [ ] **Step 3: Create `IncomingInvoiceController.php`**

`app/Http/Controllers/Finance/IncomingInvoiceController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Enums\IncomingInvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\PostIncomingInvoiceRequest;
use App\Http\Requests\Finance\ReturnIncomingInvoiceRequest;
use App\Http\Requests\Finance\StoreIncomingInvoiceRequest;
use App\Http\Requests\Finance\UpdateIncomingInvoiceRequest;
use App\Http\Requests\Finance\VetIncomingInvoiceRequest;
use App\Http\Resources\Finance\IncomingInvoiceResource;
use App\Models\GlAccount;
use App\Models\IncomingInvoice;
use App\Models\Vendor;
use App\Services\Finance\IncomingInvoiceService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncomingInvoiceController extends Controller
{
    public function __construct(private readonly IncomingInvoiceService $service)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $request->only(['status', 'search']);
        $q = IncomingInvoice::query()->with('department:id,name');
        if (! empty($filters['status'])) $q->where('status', $filters['status']);
        if (! empty($filters['search'])) $q->where('vendor_name', 'like', '%'.$filters['search'].'%');

        $invoices = $q->orderByDesc('created_at')->paginate(50)->withQueryString();

        return Inertia::render('Auditor/IncomingInvoices/Index', [
            'activeModule' => 'auditor-incoming-invoices',
            'invoices'     => IncomingInvoiceResource::collection($invoices),
            'filters'      => $filters,
            'statuses'     => collect(IncomingInvoiceStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Auditor/IncomingInvoices/Create', [
            'activeModule' => 'auditor-incoming-invoices',
        ]);
    }

    public function show(IncomingInvoice $incomingInvoice, Request $request): Response
    {
        abort_unless($request->user()?->hasPermission('incoming_invoices.view'), 403);
        $incomingInvoice->load(['department', 'attachments', 'events.actor']);

        return Inertia::render('Auditor/IncomingInvoices/Show', [
            'activeModule'    => 'auditor-incoming-invoices',
            'invoice'         => (new IncomingInvoiceResource($incomingInvoice))->resolve(),
            'vendors'         => Vendor::active()->orderBy('name')->get(['id', 'code', 'name']),
            'expenseAccounts' => GlAccount::ofType('expense')->active()->orderBy('code')->get(['id', 'code', 'name']),
            'can'             => [
                'vet'     => $request->user()->hasPermission('incoming_invoices.vet'),
                'approve' => $request->user()->hasPermission('incoming_invoices.approve'),
                'post'    => $request->user()->hasPermission('incoming_invoices.post'),
                'submit'  => $request->user()->hasPermission('incoming_invoices.submit'),
            ],
        ]);
    }

    public function store(StoreIncomingInvoiceRequest $request): RedirectResponse
    {
        $this->service->create($this->withAttachments($request), $request->user());
        return redirect()->route('auditor.incoming-invoices.index')->with('success', 'Invoice submitted to intake.');
    }

    public function update(UpdateIncomingInvoiceRequest $request, IncomingInvoice $incomingInvoice): RedirectResponse
    {
        try {
            $this->service->update($incomingInvoice, $this->withAttachments($request), $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
        return back()->with('success', 'Invoice updated.');
    }

    public function submit(IncomingInvoice $incomingInvoice, Request $request): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('incoming_invoices.submit'), 403);
        try {
            $this->service->submit($incomingInvoice, $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
        return back()->with('success', 'Invoice submitted for vetting.');
    }

    public function vet(VetIncomingInvoiceRequest $request, IncomingInvoice $incomingInvoice): RedirectResponse
    {
        try {
            $this->service->vetAccept($incomingInvoice, $request->user(), $request->validated('notes'));
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
        return back()->with('success', 'Invoice vetted — sent to CEO.');
    }

    public function vetReturn(ReturnIncomingInvoiceRequest $request, IncomingInvoice $incomingInvoice): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('incoming_invoices.vet'), 403);
        try {
            $this->service->vetReturn($incomingInvoice, $request->user(), $request->validated('reason'));
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
        return back()->with('success', 'Invoice returned to submitter.');
    }

    public function approve(IncomingInvoice $incomingInvoice, Request $request): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('incoming_invoices.approve'), 403);
        try {
            $this->service->ceoApprove($incomingInvoice, $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
        return back()->with('success', 'Invoice approved.');
    }

    public function ceoReturn(ReturnIncomingInvoiceRequest $request, IncomingInvoice $incomingInvoice): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('incoming_invoices.approve'), 403);
        try {
            $this->service->ceoReturn($incomingInvoice, $request->user(), $request->validated('reason'));
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
        return back()->with('success', 'Invoice returned to submitter.');
    }

    public function post(PostIncomingInvoiceRequest $request, IncomingInvoice $incomingInvoice): RedirectResponse
    {
        try {
            $this->service->post($incomingInvoice, $request->validated(), $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
        return back()->with('success', 'Invoice posted — vendor invoice + accrual created.');
    }

    public function download(IncomingInvoice $incomingInvoice, int $attachment, Request $request): StreamedResponse
    {
        abort_unless($request->user()?->hasPermission('incoming_invoices.view'), 403);
        $file = $incomingInvoice->attachments()->findOrFail($attachment);
        abort_unless(Storage::disk('local')->exists($file->path), 404);
        return Storage::disk('local')->download($file->path, $file->original_name);
    }

    /** Store uploaded files to the private disk and fold their metadata into the payload. */
    private function withAttachments(Request $request): array
    {
        $data = $request->validated();
        $data['attachments'] = [];
        foreach ($request->file('attachments', []) as $file) {
            $data['attachments'][] = [
                'path'          => $file->store('incoming-invoices', 'local'),
                'original_name' => $file->getClientOriginalName(),
                'mime'          => $file->getMimeType(),
                'size'          => $file->getSize(),
            ];
        }
        return $data;
    }
}
```

- [ ] **Step 4: Create `AuditorController.php`**

`app/Http/Controllers/AuditorController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\IncomingInvoiceStatus;
use App\Models\IncomingInvoice;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditorController extends Controller
{
    public function hub(Request $request): Response
    {
        $counts = IncomingInvoice::query()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return Inertia::render('Auditor/Hub', [
            'activeModule' => 'auditor',
            'stats' => [
                'pending_vetting' => (int) ($counts[IncomingInvoiceStatus::Submitted->value] ?? 0),
                'pending_ceo'     => (int) ($counts[IncomingInvoiceStatus::Vetted->value] ?? 0),
                'approved'        => (int) ($counts[IncomingInvoiceStatus::Approved->value] ?? 0),
                'returned'        => (int) ($counts[IncomingInvoiceStatus::Returned->value] ?? 0),
            ],
            'links' => [
                'assets'  => $request->user()->hasPermission('assets.view'),
                'reports' => $request->user()->hasPermission('reports.view') || $request->user()->hasPermission('audit.view'),
                'audit'   => $request->user()->hasPermission('audit.view'),
            ],
        ]);
    }
}
```

- [ ] **Step 5: Register routes in `routes/web.php`**

Insert this block immediately before the final `require __DIR__.'/auth.php';` line:

```php
// ── Auditors: hub + incoming-invoice vetting ────────────────────────────────
Route::middleware(['auth', 'verified'])->prefix('auditor')->name('auditor.')->group(function () {
    Route::get('/', [\App\Http\Controllers\AuditorController::class, 'hub'])
        ->middleware('permission:auditor.hub')->name('hub');

    Route::middleware('permission:incoming_invoices.view')->group(function () {
        Route::get('incoming-invoices',                 [\App\Http\Controllers\Finance\IncomingInvoiceController::class, 'index'])->name('incoming-invoices.index');
        Route::get('incoming-invoices/create',          [\App\Http\Controllers\Finance\IncomingInvoiceController::class, 'create'])->name('incoming-invoices.create');
        Route::get('incoming-invoices/{incomingInvoice}', [\App\Http\Controllers\Finance\IncomingInvoiceController::class, 'show'])->name('incoming-invoices.show');
        Route::get('incoming-invoices/{incomingInvoice}/attachments/{attachment}', [\App\Http\Controllers\Finance\IncomingInvoiceController::class, 'download'])->name('incoming-invoices.download');
    });
    Route::middleware('permission:incoming_invoices.submit')->group(function () {
        Route::post('incoming-invoices',                        [\App\Http\Controllers\Finance\IncomingInvoiceController::class, 'store'])->name('incoming-invoices.store');
        Route::patch('incoming-invoices/{incomingInvoice}',     [\App\Http\Controllers\Finance\IncomingInvoiceController::class, 'update'])->name('incoming-invoices.update');
        Route::post('incoming-invoices/{incomingInvoice}/submit',[\App\Http\Controllers\Finance\IncomingInvoiceController::class, 'submit'])->name('incoming-invoices.submit');
    });
    Route::middleware('permission:incoming_invoices.vet')->group(function () {
        Route::post('incoming-invoices/{incomingInvoice}/vet',        [\App\Http\Controllers\Finance\IncomingInvoiceController::class, 'vet'])->name('incoming-invoices.vet');
        Route::post('incoming-invoices/{incomingInvoice}/vet-return', [\App\Http\Controllers\Finance\IncomingInvoiceController::class, 'vetReturn'])->name('incoming-invoices.vet-return');
    });
    Route::middleware('permission:incoming_invoices.approve')->group(function () {
        Route::post('incoming-invoices/{incomingInvoice}/approve',    [\App\Http\Controllers\Finance\IncomingInvoiceController::class, 'approve'])->name('incoming-invoices.approve');
        Route::post('incoming-invoices/{incomingInvoice}/ceo-return', [\App\Http\Controllers\Finance\IncomingInvoiceController::class, 'ceoReturn'])->name('incoming-invoices.ceo-return');
    });
    Route::middleware('permission:incoming_invoices.post')->group(function () {
        Route::post('incoming-invoices/{incomingInvoice}/post', [\App\Http\Controllers\Finance\IncomingInvoiceController::class, 'post'])->name('incoming-invoices.post');
    });
});
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/Auditor/IncomingInvoiceEndpointTest.php`
Expected: PASS (4 passing). (The Index/Show/Create Vue components resolve in tests via `assertInertia` on component name only — the `.vue` files land in Task 9; component-name assertions pass without a built asset.)

- [ ] **Step 7: Write the Auditor Hub test**

Create `tests/Feature/Auditor/AuditorHubTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(fn () => (new RolePermissionSeeder())->run());

it('auditor can open the hub', function () {
    $this->actingAs(User::factory()->create(['role' => 'auditor']))
        ->get('/auditor')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Auditor/Hub'));
});

it('plain employee cannot open the hub', function () {
    $this->actingAs(User::factory()->create(['role' => 'employee']))
        ->get('/auditor')->assertForbidden();
});
```

- [ ] **Step 8: Run the hub test**

Run: `php artisan test tests/Feature/Auditor/AuditorHubTest.php`
Expected: PASS (2 passing).

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/Finance/IncomingInvoiceController.php app/Http/Controllers/AuditorController.php routes/web.php tests/Feature/Auditor/IncomingInvoiceEndpointTest.php tests/Feature/Auditor/AuditorHubTest.php
git commit -m "feat(auditor): controllers, routes, endpoint + hub tests"
```

---

## Task 9: Vue pages + navigation

**Files:**
- Create: `resources/js/Pages/Auditor/Hub.vue`, `Auditor/IncomingInvoices/Index.vue`, `Create.vue`, `Show.vue`
- Modify: `resources/js/Layouts/AuthenticatedLayout.vue`

**Interfaces:**
- Consumes props from Task 8 controllers: Hub `{stats, links}`; Index `{invoices, filters, statuses}`; Show `{invoice, vendors, expenseAccounts, can}`.

- [ ] **Step 1: Add the nav group**

In `resources/js/Layouts/AuthenticatedLayout.vue`, insert this item into the nav array immediately after the `Finance` expandable group (after its closing `},` around line 135):

```js
                    {
                        label: 'Auditor', icon: 'verified_user', expandable: true,
                        visible: can('auditor.hub') || can('incoming_invoices.view'),
                        children: [
                            { label: 'Hub',              route: 'auditor.hub',                    module: 'auditor',                     icon: 'verified_user', visible: can('auditor.hub') },
                            { label: 'Incoming Invoices',route: 'auditor.incoming-invoices.index', module: 'auditor-incoming-invoices',  icon: 'request_page',  visible: can('incoming_invoices.view') },
                        ],
                    },
```

- [ ] **Step 2: Create `Hub.vue`**

`resources/js/Pages/Auditor/Hub.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    stats: { type: Object, required: true },
    links: { type: Object, required: true },
});
</script>

<template>
    <Head title="Auditor Hub" />
    <AuthenticatedLayout>
        <div class="p-6 space-y-6">
            <h1 class="text-2xl font-semibold">Auditor Hub</h1>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <Link :href="route('auditor.incoming-invoices.index', { status: 'submitted' })" class="rounded-xl border p-4 hover:shadow">
                    <div class="text-3xl font-bold">{{ stats.pending_vetting }}</div>
                    <div class="text-sm text-gray-500">Pending vetting</div>
                </Link>
                <Link :href="route('auditor.incoming-invoices.index', { status: 'vetted' })" class="rounded-xl border p-4 hover:shadow">
                    <div class="text-3xl font-bold">{{ stats.pending_ceo }}</div>
                    <div class="text-sm text-gray-500">Awaiting CEO</div>
                </Link>
                <Link :href="route('auditor.incoming-invoices.index', { status: 'approved' })" class="rounded-xl border p-4 hover:shadow">
                    <div class="text-3xl font-bold">{{ stats.approved }}</div>
                    <div class="text-sm text-gray-500">Awaiting posting</div>
                </Link>
                <Link :href="route('auditor.incoming-invoices.index', { status: 'returned' })" class="rounded-xl border p-4 hover:shadow">
                    <div class="text-3xl font-bold">{{ stats.returned }}</div>
                    <div class="text-sm text-gray-500">Returned</div>
                </Link>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <Link v-if="links.assets" :href="route('assets.index')" class="rounded-xl border p-4 hover:shadow">
                    <div class="font-medium">Assets Oversight</div>
                    <div class="text-sm text-gray-500">Review the asset registry</div>
                </Link>
                <Link v-if="links.reports" :href="route('reports.auditor-general')" class="rounded-xl border p-4 hover:shadow">
                    <div class="font-medium">Audit Report Packs</div>
                    <div class="text-sm text-gray-500">Downloadable auditor reports</div>
                </Link>
                <Link v-if="links.audit" :href="route('audit.index')" class="rounded-xl border p-4 hover:shadow">
                    <div class="font-medium">Audit Log</div>
                    <div class="text-sm text-gray-500">System activity trail</div>
                </Link>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

Note: before finalizing, confirm the three link route names exist by running `php artisan route:list --name=assets.index` and `--name=reports.auditor-general` and `--name=audit.index`. If any differs, use the actual name (or wrap that one card in `v-if="false"` if no route exists). Do not invent route names.

- [ ] **Step 3: Create `IncomingInvoices/Index.vue`**

`resources/js/Pages/Auditor/IncomingInvoices/Index.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    invoices: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    statuses: { type: Array, default: () => [] },
});

const status = ref(props.filters.status ?? '');
const search = ref(props.filters.search ?? '');

function applyFilters() {
    router.get(route('auditor.incoming-invoices.index'), { status: status.value, search: search.value }, { preserveState: true, replace: true });
}
</script>

<template>
    <Head title="Incoming Invoices" />
    <AuthenticatedLayout>
        <div class="p-6 space-y-4">
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-semibold">Incoming Invoices</h1>
                <Link :href="route('auditor.incoming-invoices.create')" class="rounded-lg bg-blue-600 text-white px-4 py-2 text-sm">New invoice</Link>
            </div>

            <div class="flex gap-3">
                <select v-model="status" @change="applyFilters" class="rounded-lg border-gray-300 text-sm">
                    <option value="">All statuses</option>
                    <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                </select>
                <input v-model="search" @keyup.enter="applyFilters" placeholder="Search vendor…" class="rounded-lg border-gray-300 text-sm" />
            </div>

            <table class="w-full text-sm">
                <thead class="text-left text-gray-500 border-b">
                    <tr><th class="py-2">Reference</th><th>Vendor</th><th>Amount</th><th>Status</th><th>Date</th></tr>
                </thead>
                <tbody>
                    <tr v-for="inv in invoices.data" :key="inv.id" class="border-b hover:bg-gray-50">
                        <td class="py-2">
                            <Link :href="route('auditor.incoming-invoices.show', inv.id)" class="text-blue-600">{{ inv.reference }}</Link>
                        </td>
                        <td>{{ inv.vendor_name }}</td>
                        <td>{{ inv.currency }} {{ inv.amount.toFixed(2) }}</td>
                        <td>{{ inv.status.label }}</td>
                        <td>{{ inv.invoice_date }}</td>
                    </tr>
                    <tr v-if="!invoices.data.length"><td colspan="5" class="py-6 text-center text-gray-400">No invoices.</td></tr>
                </tbody>
            </table>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 4: Create `IncomingInvoices/Create.vue`**

`resources/js/Pages/Auditor/IncomingInvoices/Create.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    vendor_name: '',
    vendor_invoice_no: '',
    invoice_date: '',
    currency: 'GHS',
    amount: '',
    description: '',
    attachments: [],
});

function submit() {
    form.post(route('auditor.incoming-invoices.store'), { forceFormData: true });
}
</script>

<template>
    <Head title="New Incoming Invoice" />
    <AuthenticatedLayout>
        <form @submit.prevent="submit" class="p-6 max-w-xl space-y-4">
            <h1 class="text-2xl font-semibold">New Incoming Invoice</h1>

            <div>
                <label class="block text-sm">Vendor name</label>
                <input v-model="form.vendor_name" class="w-full rounded-lg border-gray-300" />
                <div v-if="form.errors.vendor_name" class="text-red-600 text-xs">{{ form.errors.vendor_name }}</div>
            </div>
            <div>
                <label class="block text-sm">Vendor invoice #</label>
                <input v-model="form.vendor_invoice_no" class="w-full rounded-lg border-gray-300" />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm">Invoice date</label>
                    <input type="date" v-model="form.invoice_date" class="w-full rounded-lg border-gray-300" />
                    <div v-if="form.errors.invoice_date" class="text-red-600 text-xs">{{ form.errors.invoice_date }}</div>
                </div>
                <div>
                    <label class="block text-sm">Amount</label>
                    <input type="number" step="0.01" v-model="form.amount" class="w-full rounded-lg border-gray-300" />
                    <div v-if="form.errors.amount" class="text-red-600 text-xs">{{ form.errors.amount }}</div>
                </div>
            </div>
            <div>
                <label class="block text-sm">Description</label>
                <textarea v-model="form.description" class="w-full rounded-lg border-gray-300"></textarea>
            </div>
            <div>
                <label class="block text-sm">Attachments (scan/upload)</label>
                <input type="file" multiple accept=".pdf,.jpg,.jpeg,.png" @input="form.attachments = Array.from($event.target.files)" />
                <div v-if="form.errors['attachments.0']" class="text-red-600 text-xs">{{ form.errors['attachments.0'] }}</div>
            </div>

            <button :disabled="form.processing" class="rounded-lg bg-blue-600 text-white px-4 py-2">Submit to intake</button>
        </form>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 5: Create `IncomingInvoices/Show.vue`**

`resources/js/Pages/Auditor/IncomingInvoices/Show.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    invoice: { type: Object, required: true },
    vendors: { type: Array, default: () => [] },
    expenseAccounts: { type: Array, default: () => [] },
    can: { type: Object, required: true },
});

const s = props.invoice.status.value;
const isOwner = true; // submitter actions are gated server-side; UI shows when relevant

const vetForm = useForm({ notes: '' });
const returnForm = useForm({ reason: '' });
const postForm = useForm({ vendor_id: '', lines: [{ description: '', quantity: 1, unit_price: props.invoice.amount, tax_rate: 0, gl_account_id: '' }] });

function act(name) { router.post(route(name, props.invoice.id)); }
</script>

<template>
    <Head :title="invoice.reference" />
    <AuthenticatedLayout>
        <div class="p-6 max-w-3xl space-y-6">
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-semibold">{{ invoice.reference }}</h1>
                <span class="rounded-full bg-gray-100 px-3 py-1 text-sm">{{ invoice.status.label }}</span>
            </div>

            <dl class="grid grid-cols-2 gap-2 text-sm">
                <dt class="text-gray-500">Vendor</dt><dd>{{ invoice.vendor_name }}</dd>
                <dt class="text-gray-500">Amount</dt><dd>{{ invoice.currency }} {{ invoice.amount.toFixed(2) }}</dd>
                <dt class="text-gray-500">Date</dt><dd>{{ invoice.invoice_date }}</dd>
                <dt class="text-gray-500">Description</dt><dd>{{ invoice.description }}</dd>
            </dl>

            <div v-if="invoice.attachments?.length">
                <h2 class="font-medium">Attachments</h2>
                <ul class="text-sm list-disc pl-5">
                    <li v-for="a in invoice.attachments" :key="a.id">
                        <a :href="route('auditor.incoming-invoices.download', [invoice.id, a.id])" class="text-blue-600">{{ a.original_name }}</a>
                    </li>
                </ul>
            </div>

            <div v-if="invoice.return_reason && s === 'returned'" class="rounded-lg bg-amber-50 p-3 text-sm">
                <strong>Returned:</strong> {{ invoice.return_reason }}
            </div>

            <!-- Submitter -->
            <div v-if="can.submit && (s === 'draft' || s === 'returned')" class="flex gap-2">
                <button @click="act('auditor.incoming-invoices.submit')" class="rounded-lg bg-blue-600 text-white px-4 py-2">Submit for vetting</button>
            </div>

            <!-- Auditor -->
            <div v-if="can.vet && s === 'submitted'" class="space-y-2 border-t pt-4">
                <textarea v-model="vetForm.notes" placeholder="Vetting notes (optional)" class="w-full rounded-lg border-gray-300"></textarea>
                <div class="flex gap-2">
                    <button @click="vetForm.post(route('auditor.incoming-invoices.vet', invoice.id))" class="rounded-lg bg-green-600 text-white px-4 py-2">Accept & send to CEO</button>
                    <button @click="returnForm.post(route('auditor.incoming-invoices.vet-return', invoice.id))" class="rounded-lg bg-red-600 text-white px-4 py-2">Return</button>
                </div>
                <input v-model="returnForm.reason" placeholder="Return reason" class="w-full rounded-lg border-gray-300" />
                <div v-if="returnForm.errors.reason" class="text-red-600 text-xs">{{ returnForm.errors.reason }}</div>
            </div>

            <!-- CEO -->
            <div v-if="can.approve && s === 'vetted'" class="space-y-2 border-t pt-4">
                <div class="flex gap-2">
                    <button @click="act('auditor.incoming-invoices.approve')" class="rounded-lg bg-green-600 text-white px-4 py-2">Approve</button>
                    <button @click="returnForm.post(route('auditor.incoming-invoices.ceo-return', invoice.id))" class="rounded-lg bg-red-600 text-white px-4 py-2">Return</button>
                </div>
                <input v-model="returnForm.reason" placeholder="Return reason" class="w-full rounded-lg border-gray-300" />
                <div v-if="returnForm.errors.reason" class="text-red-600 text-xs">{{ returnForm.errors.reason }}</div>
            </div>

            <!-- Finance posting -->
            <div v-if="can.post && s === 'approved'" class="space-y-2 border-t pt-4">
                <h2 class="font-medium">Post to ledger</h2>
                <select v-model="postForm.vendor_id" class="w-full rounded-lg border-gray-300">
                    <option value="">Select vendor…</option>
                    <option v-for="v in vendors" :key="v.id" :value="v.id">{{ v.code }} — {{ v.name }}</option>
                </select>
                <div v-for="(line, i) in postForm.lines" :key="i" class="grid grid-cols-4 gap-2">
                    <input v-model="line.description" placeholder="Description" class="rounded-lg border-gray-300" />
                    <input type="number" v-model="line.quantity" placeholder="Qty" class="rounded-lg border-gray-300" />
                    <input type="number" v-model="line.unit_price" placeholder="Unit price" class="rounded-lg border-gray-300" />
                    <select v-model="line.gl_account_id" class="rounded-lg border-gray-300">
                        <option value="">GL account…</option>
                        <option v-for="a in expenseAccounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.name }}</option>
                    </select>
                </div>
                <button @click="postForm.post(route('auditor.incoming-invoices.post', invoice.id))" class="rounded-lg bg-blue-600 text-white px-4 py-2">Post</button>
            </div>

            <!-- Timeline -->
            <div v-if="invoice.events?.length" class="border-t pt-4">
                <h2 class="font-medium">History</h2>
                <ol class="text-sm space-y-1">
                    <li v-for="e in invoice.events" :key="e.id" class="text-gray-600">
                        <span class="font-medium">{{ e.action }}</span>
                        <span v-if="e.actor"> by {{ e.actor.name }}</span>
                        <span class="text-gray-400"> · {{ e.created_at }}</span>
                        <span v-if="e.comment"> — {{ e.comment }}</span>
                    </li>
                </ol>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 6: Build the frontend and verify no compile errors**

Run: `npm run build`
Expected: build succeeds; the four new pages compile.

- [ ] **Step 7: Commit**

```bash
git add resources/js/Pages/Auditor resources/js/Layouts/AuthenticatedLayout.vue
git commit -m "feat(auditor): hub + incoming-invoice Vue pages and nav"
```

---

## Task 10: Full-suite verification

**Files:** none new — verification only.

- [ ] **Step 1: Run the full Auditor test directory**

Run: `php artisan test tests/Feature/Auditor`
Expected: PASS (all files green — permissions, migrations, service, post, endpoints, hub).

- [ ] **Step 2: Run the full suite to confirm no regressions**

Run: `php artisan test`
Expected: PASS — the pre-existing suite count plus the new Auditor tests, zero failures.

- [ ] **Step 3: Confirm routes resolve**

Run: `php artisan route:list --path=auditor`
Expected: lists `auditor.hub` and all `auditor.incoming-invoices.*` routes.

- [ ] **Step 4: Final commit (if any fixups were needed)**

```bash
git add -A
git commit -m "test(auditor): full-suite green for invoice vetting module"
```

---

## Self-Review Notes

- **Spec coverage:** intake entity (Task 2) ✓; submitters = dept_head/finance/hr_admin/manager/admins (Task 1) ✓; return-for-correction + resubmit (Tasks 4/5, tested) ✓; GL coding by Finance after CEO approval via `post()` (Task 6) ✓; state machine draft→submitted→vetted→approved→posted + returned (Tasks 3–6) ✓; dual control (Task 4) ✓; SequenceService references (Task 3) ✓; attachments on private disk (Tasks 2/8) ✓; append-only event trail (Task 2/3) ✓; permission slugs in all three synced places (Task 1) ✓; Auditor Hub + nav (Tasks 8/9) ✓; Pest tests per project patterns (throughout) ✓.
- **Type consistency:** service method names (`create/update/submit/vetAccept/vetReturn/ceoApprove/ceoReturn/post/recordEvent/markReturned/nextReference`) are used identically across tasks and tests. Route names (`auditor.incoming-invoices.*`) match between routes, controllers, and Vue.
- **External route names in Hub.vue** (`assets.index`, `reports.auditor-general`, `audit.index`) are flagged in Task 9 Step 2 to be verified with `route:list` before finalizing — do not assume.
- **Out of scope (deferred):** active asset-audit counts/discrepancy flagging; org-wide audit portal beyond hub link-outs; submitter-supplied line-level coding.
