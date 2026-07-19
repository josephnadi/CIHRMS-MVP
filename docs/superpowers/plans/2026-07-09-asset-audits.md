# Asset Audits Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let auditors run campaign-based physical asset audits — open a run, snapshot the expected assets, count each line, flag discrepancies, and apply one-click write-back corrections through the existing `AssetService` — all from the Auditor Hub.

**Architecture:** A new `AssetAudit` run + `AssetAuditLine` snapshot + append-only `AssetAuditEvent` trail. All lifecycle logic lives in `AssetAuditService` (transitions guarded by `DomainException`); resolution actions delegate to the existing `AssetService`. Follows the repo's Enum → FormRequest → Service → Event → Resource → Controller(Inertia) convention, slotting into the existing `auditor.*` route group and Auditor Hub.

**Tech Stack:** Laravel 13.7 (PHP 8.3), Inertia.js v2 + Vue 3, Tailwind v3 (Material-style design tokens), Pest.

## Global Constraints

- Audit references MUST use `SequenceService::next('asset_audit')` — never `count()+1`.
- State transitions live ONLY in `AssetAuditService`; each guards its source state and throws `DomainException` on an illegal transition (mirror `IncomingInvoiceService`/`AssetService`).
- New permission slugs (`asset_audits.view`, `asset_audits.manage`) MUST be added in THREE synced places: `App\Enums\Permission`, `Database\Seeders\RolePermissionSeeder` (`PERMISSIONS` + `ROLE_PERMS`), and `App\Models\User::ROLE_PERMISSIONS`. `super_admin`/`ceo` keep their `null`/`['*']` wildcard — do not add slugs to them.
- Resolution write-backs go THROUGH `AssetService` methods (`markLost`, `logMaintenance`) or a direct `Asset.location` update; the apply path is authorized by `asset_audits.manage` (NOT `assets.manage`).
- Expected asset set snapshotted at open = assets with `current_status` ∈ {in_stock, assigned, maintenance} (retired & lost excluded), filtered by scope.
- Vue pages use the existing design tokens seen in `Hub.vue`: `text-primary`, `text-on-surface-variant`, `bg-surface-container-lowest`, `border-outline-variant/60`, `rounded-xl`.
- Feature tests are Pest function-style; `RefreshDatabase` is auto-applied in `tests/Feature`. Seed `RolePermissionSeeder` in `beforeEach`; grant per-user perms via the `permissions` JSON column; create assets via `Asset::factory()` / `AssetService`.

---

## File Structure

**Create:**
- `app/Enums/AssetAuditStatus.php`, `AssetAuditResult.php`, `AssetAuditAction.php`
- `database/migrations/2026_07_09_100001_create_asset_audits.php`, `_100002_create_asset_audit_lines.php`, `_100003_create_asset_audit_events.php`
- `app/Models/AssetAudit.php`, `AssetAuditLine.php`, `AssetAuditEvent.php`
- `database/factories/AssetAuditFactory.php`
- `app/Events/AssetAuditOpened.php`, `AssetAuditCompleted.php`
- `app/Services/AssetAuditService.php`
- `app/Http/Requests/Assets/StoreAssetAuditRequest.php`, `CountAssetAuditLineRequest.php`, `ResolveAssetAuditLineRequest.php`, `CompleteAssetAuditRequest.php`, `CancelAssetAuditRequest.php`
- `app/Http/Resources/AssetAuditResource.php`, `AssetAuditLineResource.php`, `AssetAuditEventResource.php`
- `app/Http/Controllers/Auditor/AssetAuditController.php`
- `resources/js/Pages/Auditor/AssetAudits/Index.vue`, `Create.vue`, `Show.vue`
- Tests under `tests/Feature/Auditor/`: `AssetAuditPermissionsSeedTest.php`, `AssetAuditMigrationsTest.php`, `AssetAuditServiceTest.php`, `AssetAuditResolutionTest.php`, `AssetAuditEndpointTest.php`

**Modify:**
- `app/Enums/Permission.php` (2 cases), `database/seeders/RolePermissionSeeder.php`, `app/Models/User.php` (RBAC sync)
- `app/Enums/AssetStatus.php` (add `label()`)
- `app/Http/Controllers/AuditorController.php` (hub stats + link)
- `routes/web.php` (asset-audit route group)
- `resources/js/Pages/Auditor/Hub.vue` (stat + link card)
- `resources/js/Layouts/AuthenticatedLayout.vue` (nav child)

---

## Task 1: Permissions & RBAC + AssetStatus label()

**Files:**
- Modify: `app/Enums/Permission.php`, `database/seeders/RolePermissionSeeder.php`, `app/Models/User.php`, `app/Enums/AssetStatus.php`
- Test: `tests/Feature/Auditor/AssetAuditPermissionsSeedTest.php`

**Interfaces:**
- Produces slugs `asset_audits.view`, `asset_audits.manage`.
- Produces `AssetStatus::label(): string`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Auditor/AssetAuditPermissionsSeedTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\AssetStatus;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(fn () => (new RolePermissionSeeder())->run());

it('grants auditor asset-audit view + manage', function () {
    $u = User::factory()->create(['role' => 'auditor']);
    expect($u->hasPermission('asset_audits.view'))->toBeTrue();
    expect($u->hasPermission('asset_audits.manage'))->toBeTrue();
});

it('does not grant a plain employee asset-audit perms', function () {
    $u = User::factory()->create(['role' => 'employee']);
    expect($u->hasPermission('asset_audits.view'))->toBeFalse();
    expect($u->hasPermission('asset_audits.manage'))->toBeFalse();
});

it('ceo wildcard covers asset-audit manage', function () {
    $u = User::factory()->create(['role' => 'ceo']);
    expect($u->hasPermission('asset_audits.manage'))->toBeTrue();
});

it('AssetStatus has a label', function () {
    expect(AssetStatus::InStock->label())->toBe('In Stock');
    expect(AssetStatus::Lost->label())->toBe('Lost');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Auditor/AssetAuditPermissionsSeedTest.php`
Expected: FAIL (perms not granted / no `label()`).

- [ ] **Step 3: Add the two cases to `app/Enums/Permission.php`**

Find the block of `incoming_invoices.*` cases added by the auditors module and add beneath them:

```php
    case AssetAuditsView   = 'asset_audits.view';
    case AssetAuditsManage = 'asset_audits.manage';
```

- [ ] **Step 4: Register slugs in `RolePermissionSeeder::PERMISSIONS`**

In `database/seeders/RolePermissionSeeder.php`, after the `incoming_invoices.*` entries in the `PERMISSIONS` array add:

```php
        'asset_audits.view'   => ['Auditors', 'View physical asset-audit runs'],
        'asset_audits.manage' => ['Auditors', 'Open / count / resolve / complete asset audits'],
```

- [ ] **Step 5: Grant slugs to roles in `RolePermissionSeeder::ROLE_PERMS`**

Append to the `'auditor' => [ ... ]` array:

```php
            'asset_audits.view', 'asset_audits.manage',
```

Leave `'super_admin' => null` and `'ceo' => null` untouched.

- [ ] **Step 6: Mirror into `app/Models/User.php` `ROLE_PERMISSIONS`**

Add the same two slugs to the `'auditor'` entry of the `const ROLE_PERMISSIONS` array in `User.php`.

- [ ] **Step 7: Add `label()` to `app/Enums/AssetStatus.php`**

Replace the enum body so it becomes:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum AssetStatus: string
{
    case InStock     = 'in_stock';
    case Assigned    = 'assigned';
    case Maintenance = 'maintenance';
    case Retired     = 'retired';
    case Lost        = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::InStock     => 'In Stock',
            self::Assigned    => 'Assigned',
            self::Maintenance => 'Maintenance',
            self::Retired     => 'Retired',
            self::Lost        => 'Lost',
        };
    }
}
```

- [ ] **Step 8: Run test to verify it passes**

Run: `php artisan test tests/Feature/Auditor/AssetAuditPermissionsSeedTest.php`
Expected: PASS (4 passing).

- [ ] **Step 9: Commit**

```bash
git add app/Enums/Permission.php database/seeders/RolePermissionSeeder.php app/Models/User.php app/Enums/AssetStatus.php tests/Feature/Auditor/AssetAuditPermissionsSeedTest.php
git commit -m "feat(asset-audit): permissions + AssetStatus label()"
```

---

## Task 2: Enums, migrations, models, factory

**Files:**
- Create: `app/Enums/AssetAuditStatus.php`, `AssetAuditResult.php`, `AssetAuditAction.php`
- Create: `database/migrations/2026_07_09_100001_create_asset_audits.php`, `_100002_create_asset_audit_lines.php`, `_100003_create_asset_audit_events.php`
- Create: `app/Models/AssetAudit.php`, `AssetAuditLine.php`, `AssetAuditEvent.php`
- Create: `database/factories/AssetAuditFactory.php`
- Test: `tests/Feature/Auditor/AssetAuditMigrationsTest.php`

**Interfaces:**
- Produces enum `AssetAuditStatus` (`InProgress='in_progress'`, `Completed='completed'`, `Cancelled='cancelled'`), `AssetAuditResult` (`Pending='pending'`, `Present='present'`, `Missing='missing'`, `WrongLocation='wrong_location'`, `WrongHolder='wrong_holder'`, `Damaged='damaged'`), `AssetAuditAction` (`None='none'`, `MarkedLost='marked_lost'`, `Relocated='relocated'`, `MaintenanceLogged='maintenance_logged'`, `Flagged='flagged'`) — each with `label()`.
- Produces models `AssetAudit` (relations `lines()`, `events()`, `opener()`), `AssetAuditLine` (relations `audit()`, `asset()`, `expectedHolder()`), `AssetAuditEvent`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Auditor/AssetAuditMigrationsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\AssetAuditStatus;
use App\Models\AssetAudit;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

it('creates the three asset-audit tables', function () {
    expect(Schema::hasTable('asset_audits'))->toBeTrue();
    expect(Schema::hasTable('asset_audit_lines'))->toBeTrue();
    expect(Schema::hasTable('asset_audit_events'))->toBeTrue();
    expect(Schema::hasColumns('asset_audits', [
        'reference', 'status', 'scope_type', 'scope_value',
        'total_lines', 'counted_lines', 'discrepancy_lines', 'opened_by',
    ]))->toBeTrue();
    expect(Schema::hasColumns('asset_audit_lines', [
        'asset_audit_id', 'asset_id', 'expected_status', 'expected_location',
        'expected_holder_employee_id', 'result', 'observed_location',
        'is_discrepancy', 'resolution_action',
    ]))->toBeTrue();
});

it('casts status enum and defaults to in_progress', function () {
    $u = AssetAudit::create([
        'reference'  => 'ASA-TEST-1',
        'scope_type' => 'all',
        'opened_by'  => User::factory()->create()->id,
        'opened_at'  => now(),
    ]);
    expect($u->status)->toBe(AssetAuditStatus::InProgress);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Auditor/AssetAuditMigrationsTest.php`
Expected: FAIL ("Class AssetAudit not found").

- [ ] **Step 3: Create the three enums**

`app/Enums/AssetAuditStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum AssetAuditStatus: string
{
    case InProgress = 'in_progress';
    case Completed  = 'completed';
    case Cancelled  = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'In Progress',
            self::Completed  => 'Completed',
            self::Cancelled  => 'Cancelled',
        };
    }
}
```

`app/Enums/AssetAuditResult.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum AssetAuditResult: string
{
    case Pending       = 'pending';
    case Present       = 'present';
    case Missing       = 'missing';
    case WrongLocation = 'wrong_location';
    case WrongHolder   = 'wrong_holder';
    case Damaged       = 'damaged';

    public function label(): string
    {
        return match ($this) {
            self::Pending       => 'Not counted',
            self::Present       => 'Present',
            self::Missing       => 'Missing',
            self::WrongLocation => 'Wrong location',
            self::WrongHolder   => 'Wrong holder',
            self::Damaged       => 'Damaged',
        };
    }

    public function isDiscrepancy(): bool
    {
        return ! in_array($this, [self::Pending, self::Present], true);
    }
}
```

`app/Enums/AssetAuditAction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum AssetAuditAction: string
{
    case None              = 'none';
    case MarkedLost        = 'marked_lost';
    case Relocated         = 'relocated';
    case MaintenanceLogged = 'maintenance_logged';
    case Flagged           = 'flagged';

    public function label(): string
    {
        return match ($this) {
            self::None              => 'None',
            self::MarkedLost        => 'Marked lost',
            self::Relocated         => 'Relocated',
            self::MaintenanceLogged => 'Maintenance logged',
            self::Flagged           => 'Flagged for reassignment',
        };
    }
}
```

- [ ] **Step 4: Create the `asset_audits` migration**

`database/migrations/2026_07_09_100001_create_asset_audits.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Physical asset-audit run. An auditor opens a run scoped to all / a category /
 * a location; the expected asset set is snapshotted into asset_audit_lines.
 * Lifecycle: in_progress → completed (or → cancelled). Tallies (counted_lines,
 * discrepancy_lines) are recomputed from the lines as counting proceeds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_audits', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->string('status', 20)->default('in_progress')->index();
            $table->string('scope_type', 20);           // all | category | location
            $table->string('scope_value', 120)->nullable();
            $table->unsignedInteger('total_lines')->default(0);
            $table->unsignedInteger('counted_lines')->default(0);
            $table->unsignedInteger('discrepancy_lines')->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('opened_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_audits');
    }
};
```

- [ ] **Step 5: Create the `asset_audit_lines` migration**

`database/migrations/2026_07_09_100002_create_asset_audit_lines.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_audit_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_audit_id')->constrained('asset_audits')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();
            $table->string('expected_status', 16);
            $table->string('expected_location', 120)->nullable();
            $table->foreignId('expected_holder_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('result', 20)->default('pending');
            $table->string('observed_location', 120)->nullable();
            $table->text('observed_note')->nullable();
            $table->boolean('is_discrepancy')->default(false);
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('counted_at')->nullable();
            $table->string('resolution_action', 20)->default('none');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolved_note')->nullable();
            $table->timestamps();

            $table->unique(['asset_audit_id', 'asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_audit_lines');
    }
};
```

- [ ] **Step 6: Create the `asset_audit_events` migration**

`database/migrations/2026_07_09_100003_create_asset_audit_events.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Append-only trail of asset-audit activity. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_audit_id')->constrained('asset_audits')->cascadeOnDelete();
            $table->foreignId('asset_audit_line_id')->nullable()->constrained('asset_audit_lines')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->text('detail')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_audit_events');
    }
};
```

- [ ] **Step 7: Create the three models**

`app/Models/AssetAudit.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssetAuditStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssetAudit extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'reference', 'status', 'scope_type', 'scope_value',
        'total_lines', 'counted_lines', 'discrepancy_lines', 'notes',
        'opened_by', 'opened_at', 'completed_by', 'completed_at',
        'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected $attributes = ['status' => 'in_progress', 'scope_type' => 'all'];

    protected function casts(): array
    {
        return [
            'status'       => AssetAuditStatus::class,
            'opened_at'    => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AssetAuditLine::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AssetAuditEvent::class)->orderByDesc('id');
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }
}
```

`app/Models/AssetAuditLine.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssetAuditAction;
use App\Enums\AssetAuditResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetAuditLine extends Model
{
    protected $fillable = [
        'asset_audit_id', 'asset_id', 'expected_status', 'expected_location',
        'expected_holder_employee_id', 'result', 'observed_location', 'observed_note',
        'is_discrepancy', 'counted_by', 'counted_at',
        'resolution_action', 'resolved_by', 'resolved_at', 'resolved_note',
    ];

    protected function casts(): array
    {
        return [
            'result'            => AssetAuditResult::class,
            'resolution_action' => AssetAuditAction::class,
            'is_discrepancy'    => 'boolean',
            'counted_at'        => 'datetime',
            'resolved_at'       => 'datetime',
        ];
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(AssetAudit::class, 'asset_audit_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function expectedHolder(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'expected_holder_employee_id');
    }
}
```

`app/Models/AssetAuditEvent.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetAuditEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'asset_audit_id', 'asset_audit_line_id', 'actor_id', 'action', 'detail', 'created_at',
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

`database/factories/AssetAuditFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AssetAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssetAuditFactory extends Factory
{
    protected $model = AssetAudit::class;

    public function definition(): array
    {
        return [
            'reference'  => 'ASA-' . fake()->unique()->numerify('######'),
            'status'     => 'in_progress',
            'scope_type' => 'all',
            'opened_by'  => User::factory(),
            'opened_at'  => now(),
        ];
    }
}
```

- [ ] **Step 9: Run test to verify it passes**

Run: `php artisan test tests/Feature/Auditor/AssetAuditMigrationsTest.php`
Expected: PASS (2 passing).

- [ ] **Step 10: Commit**

```bash
git add app/Enums/AssetAudit*.php database/migrations/2026_07_09_10000*_*.php app/Models/AssetAudit*.php database/factories/AssetAuditFactory.php tests/Feature/Auditor/AssetAuditMigrationsTest.php
git commit -m "feat(asset-audit): enums, schema, models, factory"
```

---

## Task 3: Service — open() + snapshot

**Files:**
- Create: `app/Services/AssetAuditService.php`, `app/Events/AssetAuditOpened.php`, `AssetAuditCompleted.php`
- Test: `tests/Feature/Auditor/AssetAuditServiceTest.php`

**Interfaces:**
- Consumes: `SequenceService::next(string)` (constructor-injected).
- Produces:
  - `open(array $data, User $actor): AssetAudit` — `$data` = `['scope_type' => 'all'|'category'|'location', 'scope_value' => ?string, 'notes' => ?string]`. In one `DB::transaction`: creates the run (status in_progress, reference via sequence), snapshots one `AssetAuditLine` per expected asset (status ∈ in_stock/assigned/maintenance, scope-filtered), capturing `expected_status`, `expected_location`, `expected_holder_employee_id` (from the asset's open assignment), sets `total_lines`, records an `opened` event, dispatches `AssetAuditOpened`.
  - protected `recordEvent(AssetAudit, ?User, string $action, ?int $lineId, ?string $detail)`, `nextReference(): string`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Auditor/AssetAuditServiceTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\AssetAuditStatus;
use App\Enums\AssetStatus;
use App\Events\AssetAuditOpened;
use App\Models\Asset;
use App\Models\User;
use App\Services\AssetAuditService;
use Illuminate\Support\Facades\Event;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    $this->service = app(AssetAuditService::class);
});

it('open() snapshots the expected assets and excludes retired/lost', function () {
    Asset::factory()->create(['current_status' => AssetStatus::InStock->value, 'location' => 'HQ']);
    Asset::factory()->create(['current_status' => AssetStatus::Assigned->value]);
    Asset::factory()->create(['current_status' => AssetStatus::Retired->value]);
    Asset::factory()->create(['current_status' => AssetStatus::Lost->value]);

    $actor = User::factory()->create(['role' => 'auditor']);
    $audit = $this->service->open(['scope_type' => 'all'], $actor);

    expect($audit->status)->toBe(AssetAuditStatus::InProgress);
    expect($audit->reference)->toStartWith('ASA-');
    expect($audit->total_lines)->toBe(2);            // retired + lost excluded
    expect($audit->lines()->count())->toBe(2);
    expect($audit->events()->where('action', 'opened')->exists())->toBeTrue();
});

it('open() with category scope only snapshots that category', function () {
    Asset::factory()->create(['category' => 'laptop', 'current_status' => AssetStatus::InStock->value]);
    Asset::factory()->create(['category' => 'monitor', 'current_status' => AssetStatus::InStock->value]);

    $actor = User::factory()->create(['role' => 'auditor']);
    $audit = $this->service->open(['scope_type' => 'category', 'scope_value' => 'laptop'], $actor);

    expect($audit->total_lines)->toBe(1);
});

it('open() dispatches AssetAuditOpened', function () {
    Event::fake([AssetAuditOpened::class]);
    Asset::factory()->create(['current_status' => AssetStatus::InStock->value]);
    $actor = User::factory()->create(['role' => 'auditor']);
    $this->service->open(['scope_type' => 'all'], $actor);
    Event::assertDispatched(AssetAuditOpened::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Auditor/AssetAuditServiceTest.php`
Expected: FAIL ("Target class [AssetAuditService] does not exist").

- [ ] **Step 3: Create the two event classes**

`app/Events/AssetAuditOpened.php`:

```php
<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\AssetAudit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AssetAuditOpened
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly AssetAudit $audit)
    {
    }
}
```

`app/Events/AssetAuditCompleted.php` — identical but class name `AssetAuditCompleted`.

- [ ] **Step 4: Create the service with open()**

`app/Services/AssetAuditService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AssetAuditStatus;
use App\Enums\AssetStatus;
use App\Events\AssetAuditOpened;
use App\Models\Asset;
use App\Models\AssetAudit;
use App\Models\AssetAuditEvent;
use App\Models\User;
use App\Services\Finance\SequenceService;
use DomainException;
use Illuminate\Support\Facades\DB;

class AssetAuditService
{
    /** Statuses a physically-present asset can have (retired/lost are not expected). */
    private const EXPECTED_STATUSES = [
        AssetStatus::InStock->value,
        AssetStatus::Assigned->value,
        AssetStatus::Maintenance->value,
    ];

    public function __construct(private readonly SequenceService $sequences)
    {
    }

    public function open(array $data, User $actor): AssetAudit
    {
        $scopeType  = $data['scope_type'] ?? 'all';
        $scopeValue = $data['scope_value'] ?? null;

        return DB::transaction(function () use ($scopeType, $scopeValue, $data, $actor) {
            $audit = AssetAudit::create([
                'reference'   => $this->nextReference(),
                'status'      => AssetAuditStatus::InProgress->value,
                'scope_type'  => $scopeType,
                'scope_value' => $scopeValue,
                'notes'       => $data['notes'] ?? null,
                'opened_by'   => $actor->id,
                'opened_at'   => now(),
            ]);

            $query = Asset::query()
                ->with('currentAssignment')
                ->whereIn('current_status', self::EXPECTED_STATUSES);

            if ($scopeType === 'category' && $scopeValue !== null) {
                $query->where('category', $scopeValue);
            } elseif ($scopeType === 'location' && $scopeValue !== null) {
                $query->where('location', $scopeValue);
            }

            $count = 0;
            foreach ($query->cursor() as $asset) {
                $audit->lines()->create([
                    'asset_id'                    => $asset->id,
                    'expected_status'             => $asset->current_status->value,
                    'expected_location'           => $asset->location,
                    'expected_holder_employee_id' => $asset->currentAssignment?->employee_id,
                    'result'                      => 'pending',
                ]);
                $count++;
            }

            $audit->update(['total_lines' => $count]);
            $this->recordEvent($audit, $actor, 'opened', null, "Snapshot {$count} assets ({$scopeType})");
            AssetAuditOpened::dispatch($audit->fresh());

            return $audit->fresh(['lines']);
        });
    }

    protected function recordEvent(AssetAudit $audit, ?User $actor, string $action, ?int $lineId = null, ?string $detail = null): void
    {
        AssetAuditEvent::create([
            'asset_audit_id'      => $audit->id,
            'asset_audit_line_id' => $lineId,
            'actor_id'            => $actor?->id,
            'action'              => $action,
            'detail'              => $detail,
            'created_at'          => now(),
        ]);
    }

    protected function nextReference(): string
    {
        $n = $this->sequences->next('asset_audit');
        return 'ASA-' . now()->format('Y') . '-' . str_pad((string) $n, 5, '0', STR_PAD_LEFT);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/Auditor/AssetAuditServiceTest.php`
Expected: PASS (3 passing).

- [ ] **Step 6: Commit**

```bash
git add app/Services/AssetAuditService.php app/Events/AssetAudit*.php tests/Feature/Auditor/AssetAuditServiceTest.php
git commit -m "feat(asset-audit): service open() + snapshot"
```

---

## Task 4: Service — count()

**Files:**
- Modify: `app/Services/AssetAuditService.php`
- Test: `tests/Feature/Auditor/AssetAuditServiceTest.php` (append)

**Interfaces:**
- Produces `count(AssetAuditLine $line, AssetAuditResult $result, array $observed, User $actor): AssetAuditLine` — only when the run is `in_progress` (else `DomainException`). Sets `result`, `observed_location`, `observed_note`, `counted_by/at`, and `is_discrepancy = $result->isDiscrepancy()`; recomputes the run's `counted_lines` (lines where result ≠ pending) and `discrepancy_lines` (lines where is_discrepancy); records a `counted` event.

- [ ] **Step 1: Write the failing test (append)**

Append to `tests/Feature/Auditor/AssetAuditServiceTest.php`:

```php
it('count() records a present line as no discrepancy', function () {
    Asset::factory()->create(['current_status' => AssetStatus::InStock->value]);
    $actor = User::factory()->create(['role' => 'auditor']);
    $audit = $this->service->open(['scope_type' => 'all'], $actor);
    $line  = $audit->lines()->first();

    $this->service->count($line, \App\Enums\AssetAuditResult::Present, [], $actor);

    expect($line->fresh()->is_discrepancy)->toBeFalse();
    expect($audit->fresh()->counted_lines)->toBe(1);
    expect($audit->fresh()->discrepancy_lines)->toBe(0);
});

it('count() flags a missing line as a discrepancy and updates tallies', function () {
    Asset::factory()->create(['current_status' => AssetStatus::InStock->value]);
    $actor = User::factory()->create(['role' => 'auditor']);
    $audit = $this->service->open(['scope_type' => 'all'], $actor);
    $line  = $audit->lines()->first();

    $this->service->count($line, \App\Enums\AssetAuditResult::Missing, ['observed_note' => 'not on rack'], $actor);

    expect($line->fresh()->is_discrepancy)->toBeTrue();
    expect($line->fresh()->result)->toBe(\App\Enums\AssetAuditResult::Missing);
    expect($audit->fresh()->counted_lines)->toBe(1);
    expect($audit->fresh()->discrepancy_lines)->toBe(1);
});

it('count() refuses a completed run', function () {
    Asset::factory()->create(['current_status' => AssetStatus::InStock->value]);
    $actor = User::factory()->create(['role' => 'auditor']);
    $audit = $this->service->open(['scope_type' => 'all'], $actor);
    $audit->update(['status' => 'completed']);
    $line  = $audit->lines()->first();

    $this->service->count($line->fresh(), \App\Enums\AssetAuditResult::Present, [], $actor);
})->throws(DomainException::class);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Auditor/AssetAuditServiceTest.php`
Expected: FAIL ("Call to undefined method ...count()").

- [ ] **Step 3: Add count() to the service**

Add `use App\Enums\AssetAuditResult;` and `use App\Models\AssetAuditLine;` at the top, then add:

```php
    public function count(AssetAuditLine $line, AssetAuditResult $result, array $observed, User $actor): AssetAuditLine
    {
        $audit = $line->audit;
        if ($audit->status !== AssetAuditStatus::InProgress) {
            throw new DomainException('Only an in-progress audit can be counted.');
        }

        $line->update([
            'result'            => $result->value,
            'observed_location' => $observed['observed_location'] ?? null,
            'observed_note'     => $observed['observed_note'] ?? null,
            'is_discrepancy'    => $result->isDiscrepancy(),
            'counted_by'        => $actor->id,
            'counted_at'        => now(),
        ]);

        $this->recomputeTallies($audit);
        $this->recordEvent($audit, $actor, 'counted', $line->id, $result->value);

        return $line->fresh();
    }

    protected function recomputeTallies(AssetAudit $audit): void
    {
        $audit->update([
            'counted_lines'     => $audit->lines()->where('result', '!=', 'pending')->count(),
            'discrepancy_lines' => $audit->lines()->where('is_discrepancy', true)->count(),
        ]);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Auditor/AssetAuditServiceTest.php`
Expected: PASS (6 passing).

- [ ] **Step 5: Commit**

```bash
git add app/Services/AssetAuditService.php tests/Feature/Auditor/AssetAuditServiceTest.php
git commit -m "feat(asset-audit): service count() + discrepancy tallies"
```

---

## Task 5: Service — applyResolution() write-backs

**Files:**
- Modify: `app/Services/AssetAuditService.php`
- Test: `tests/Feature/Auditor/AssetAuditResolutionTest.php`

**Interfaces:**
- Consumes: `AssetService::markLost(Asset,User,string)`, `AssetService::logMaintenance(Asset, MaintenanceType, User, array)` (constructor-inject `AssetService`).
- Produces `applyResolution(AssetAuditLine $line, AssetAuditAction $action, User $actor): AssetAuditLine` — guards: run must be `in_progress` or `completed`; the line must be a discrepancy; the action must be valid for the line's `result` (missing→marked_lost, wrong_location→relocated, damaged→maintenance_logged, wrong_holder→flagged) else `DomainException`. Applies the write-back inside a `DB::transaction`, sets `resolution_action`/`resolved_by/at`, records a `resolved` event.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Auditor/AssetAuditResolutionTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\AssetAuditAction;
use App\Enums\AssetAuditResult;
use App\Enums\AssetStatus;
use App\Enums\MaintenanceStatus;
use App\Models\Asset;
use App\Models\User;
use App\Services\AssetAuditService;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    $this->service = app(AssetAuditService::class);
    $this->actor = User::factory()->create(['role' => 'auditor']);
});

function openWithOneAsset(array $assetAttrs): array
{
    $asset = Asset::factory()->create(array_merge(['current_status' => AssetStatus::InStock->value], $assetAttrs));
    $audit = test()->service->open(['scope_type' => 'all'], test()->actor);
    return [$asset, $audit->lines()->first()];
}

it('marked_lost flips the asset to lost', function () {
    [$asset, $line] = openWithOneAsset([]);
    $this->service->count($line, AssetAuditResult::Missing, [], $this->actor);

    $this->service->applyResolution($line->fresh(), AssetAuditAction::MarkedLost, $this->actor);

    expect($asset->fresh()->current_status)->toBe(AssetStatus::Lost);
    expect($line->fresh()->resolution_action)->toBe(AssetAuditAction::MarkedLost);
});

it('relocated updates the asset location to the observed value', function () {
    [$asset, $line] = openWithOneAsset(['location' => 'HQ']);
    $this->service->count($line, AssetAuditResult::WrongLocation, ['observed_location' => 'Annex'], $this->actor);

    $this->service->applyResolution($line->fresh(), AssetAuditAction::Relocated, $this->actor);

    expect($asset->fresh()->location)->toBe('Annex');
});

it('maintenance_logged opens a maintenance record and sets status', function () {
    [$asset, $line] = openWithOneAsset([]);
    $this->service->count($line, AssetAuditResult::Damaged, [], $this->actor);

    $this->service->applyResolution($line->fresh(), AssetAuditAction::MaintenanceLogged, $this->actor);

    expect($asset->fresh()->current_status)->toBe(AssetStatus::Maintenance);
    expect($asset->maintenance()->where('status', MaintenanceStatus::Open->value)->exists())->toBeTrue();
});

it('flagged is record-only (asset unchanged)', function () {
    [$asset, $line] = openWithOneAsset(['current_status' => AssetStatus::Assigned->value]);
    $this->service->count($line, AssetAuditResult::WrongHolder, [], $this->actor);

    $this->service->applyResolution($line->fresh(), AssetAuditAction::Flagged, $this->actor);

    expect($asset->fresh()->current_status)->toBe(AssetStatus::Assigned);
    expect($line->fresh()->resolution_action)->toBe(AssetAuditAction::Flagged);
});

it('rejects an action that does not match the result', function () {
    [$asset, $line] = openWithOneAsset([]);
    $this->service->count($line, AssetAuditResult::Missing, [], $this->actor);

    $this->service->applyResolution($line->fresh(), AssetAuditAction::Relocated, $this->actor);
})->throws(DomainException::class);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Auditor/AssetAuditResolutionTest.php`
Expected: FAIL ("undefined method applyResolution").

- [ ] **Step 3: Inject AssetService and add applyResolution()**

Update the constructor (keep `SequenceService`, add `AssetService`):

```php
    public function __construct(
        private readonly SequenceService $sequences,
        private readonly AssetService $assets,
    ) {
    }
```

Add these imports at the top: `use App\Enums\AssetAuditAction;`, `use App\Enums\MaintenanceType;`. Then add:

```php
    public function applyResolution(AssetAuditLine $line, AssetAuditAction $action, User $actor): AssetAuditLine
    {
        $audit = $line->audit;
        if (! in_array($audit->status, [AssetAuditStatus::InProgress, AssetAuditStatus::Completed], true)) {
            throw new DomainException('Resolutions can only be applied to an in-progress or completed audit.');
        }
        if (! $line->is_discrepancy) {
            throw new DomainException('Only discrepancy lines can be resolved.');
        }

        $expected = match ($line->result) {
            AssetAuditResult::Missing       => AssetAuditAction::MarkedLost,
            AssetAuditResult::WrongLocation => AssetAuditAction::Relocated,
            AssetAuditResult::Damaged       => AssetAuditAction::MaintenanceLogged,
            AssetAuditResult::WrongHolder   => AssetAuditAction::Flagged,
            default                         => null,
        };
        if ($expected === null || $action !== $expected) {
            throw new DomainException("Action {$action->value} is not valid for a {$line->result->value} line.");
        }

        return DB::transaction(function () use ($line, $action, $actor, $audit) {
            $line->loadMissing('asset');
            $asset = $line->asset;

            match ($action) {
                AssetAuditAction::MarkedLost        => $this->assets->markLost($asset, $actor, "Asset audit {$audit->reference}: not found"),
                AssetAuditAction::Relocated         => $asset->update(['location' => $line->observed_location]),
                AssetAuditAction::MaintenanceLogged => $this->assets->logMaintenance($asset, MaintenanceType::Repair, $actor, ['notes' => "Asset audit {$audit->reference}: found damaged"]),
                AssetAuditAction::Flagged           => null, // record-only
                default                             => null,
            };

            $line->update([
                'resolution_action' => $action->value,
                'resolved_by'       => $actor->id,
                'resolved_at'       => now(),
            ]);

            $this->recordEvent($audit, $actor, 'resolved', $line->id, $action->value);

            return $line->fresh();
        });
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Auditor/AssetAuditResolutionTest.php`
Expected: PASS (5 passing).

- [ ] **Step 5: Commit**

```bash
git add app/Services/AssetAuditService.php tests/Feature/Auditor/AssetAuditResolutionTest.php
git commit -m "feat(asset-audit): applyResolution() write-backs via AssetService"
```

---

## Task 6: Service — complete() / cancel()

**Files:**
- Modify: `app/Services/AssetAuditService.php`
- Test: `tests/Feature/Auditor/AssetAuditServiceTest.php` (append)

**Interfaces:**
- Produces:
  - `complete(AssetAudit $audit, User $actor): AssetAudit` — from `in_progress` → `completed`; sets `completed_by/at`; records `completed` event; dispatches `AssetAuditCompleted`.
  - `cancel(AssetAudit $audit, User $actor, string $reason): AssetAudit` — from `in_progress` → `cancelled`; sets `cancelled_by/at`, `cancel_reason`; records `cancelled` event.

- [ ] **Step 1: Write the failing test (append)**

Append to `tests/Feature/Auditor/AssetAuditServiceTest.php`:

```php
it('complete() moves in_progress to completed', function () {
    Asset::factory()->create(['current_status' => AssetStatus::InStock->value]);
    $actor = User::factory()->create(['role' => 'auditor']);
    $audit = $this->service->open(['scope_type' => 'all'], $actor);

    $this->service->complete($audit, $actor);

    expect($audit->fresh()->status)->toBe(AssetAuditStatus::Completed);
    expect($audit->fresh()->completed_by)->toBe($actor->id);
});

it('complete() refuses an already-completed run', function () {
    Asset::factory()->create(['current_status' => AssetStatus::InStock->value]);
    $actor = User::factory()->create(['role' => 'auditor']);
    $audit = $this->service->open(['scope_type' => 'all'], $actor);
    $this->service->complete($audit, $actor);

    $this->service->complete($audit->fresh(), $actor);
})->throws(DomainException::class);

it('cancel() moves in_progress to cancelled with a reason', function () {
    Asset::factory()->create(['current_status' => AssetStatus::InStock->value]);
    $actor = User::factory()->create(['role' => 'auditor']);
    $audit = $this->service->open(['scope_type' => 'all'], $actor);

    $this->service->cancel($audit, $actor, 'duplicate run');

    expect($audit->fresh()->status)->toBe(AssetAuditStatus::Cancelled);
    expect($audit->fresh()->cancel_reason)->toBe('duplicate run');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Auditor/AssetAuditServiceTest.php`
Expected: FAIL ("undefined method complete").

- [ ] **Step 3: Add complete()/cancel()**

Add `use App\Events\AssetAuditCompleted;` at the top, then:

```php
    public function complete(AssetAudit $audit, User $actor): AssetAudit
    {
        if ($audit->status !== AssetAuditStatus::InProgress) {
            throw new DomainException('Only an in-progress audit can be completed.');
        }

        $audit->update([
            'status'       => AssetAuditStatus::Completed->value,
            'completed_by' => $actor->id,
            'completed_at' => now(),
        ]);

        $this->recordEvent($audit, $actor, 'completed', null, null);
        AssetAuditCompleted::dispatch($audit->fresh());

        return $audit->fresh();
    }

    public function cancel(AssetAudit $audit, User $actor, string $reason): AssetAudit
    {
        if ($audit->status !== AssetAuditStatus::InProgress) {
            throw new DomainException('Only an in-progress audit can be cancelled.');
        }

        $audit->update([
            'status'        => AssetAuditStatus::Cancelled->value,
            'cancelled_by'  => $actor->id,
            'cancelled_at'  => now(),
            'cancel_reason' => $reason,
        ]);

        $this->recordEvent($audit, $actor, 'cancelled', null, $reason);

        return $audit->fresh();
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Auditor/AssetAuditServiceTest.php`
Expected: PASS (9 passing).

- [ ] **Step 5: Commit**

```bash
git add app/Services/AssetAuditService.php tests/Feature/Auditor/AssetAuditServiceTest.php
git commit -m "feat(asset-audit): complete/cancel transitions"
```

---

## Task 7: FormRequests + Resources

**Files:**
- Create: `app/Http/Requests/Assets/StoreAssetAuditRequest.php`, `CountAssetAuditLineRequest.php`, `ResolveAssetAuditLineRequest.php`, `CompleteAssetAuditRequest.php`, `CancelAssetAuditRequest.php`
- Create: `app/Http/Resources/AssetAuditResource.php`, `AssetAuditLineResource.php`, `AssetAuditEventResource.php`
- Test: covered via Task 8 endpoint tests.

**Interfaces:**
- Produces validated payloads for the controller. `StoreAssetAuditRequest`: `scope_type` in all/category/location, `scope_value` nullable (required unless all), `notes` nullable. `CountAssetAuditLineRequest`: `result` in the enum values (not `pending`), `observed_location`/`observed_note` nullable. `ResolveAssetAuditLineRequest`: `action` in the action values. `CancelAssetAuditRequest`: `reason` required.

- [ ] **Step 1: Create `StoreAssetAuditRequest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Assets;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssetAuditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('asset_audits.manage') === true;
    }

    public function rules(): array
    {
        return [
            'scope_type'  => ['required', Rule::in(['all', 'category', 'location'])],
            'scope_value' => ['nullable', 'string', 'max:120', Rule::requiredIf(fn () => $this->input('scope_type') !== 'all')],
            'notes'       => ['nullable', 'string', 'max:2000'],
        ];
    }
}
```

- [ ] **Step 2: Create `CountAssetAuditLineRequest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Assets;

use App\Enums\AssetAuditResult;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CountAssetAuditLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('asset_audits.manage') === true;
    }

    public function rules(): array
    {
        return [
            'result' => ['required', Rule::in([
                AssetAuditResult::Present->value,
                AssetAuditResult::Missing->value,
                AssetAuditResult::WrongLocation->value,
                AssetAuditResult::WrongHolder->value,
                AssetAuditResult::Damaged->value,
            ])],
            'observed_location' => ['nullable', 'string', 'max:120'],
            'observed_note'     => ['nullable', 'string', 'max:2000'],
        ];
    }
}
```

- [ ] **Step 3: Create `ResolveAssetAuditLineRequest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Assets;

use App\Enums\AssetAuditAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveAssetAuditLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('asset_audits.manage') === true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in([
                AssetAuditAction::MarkedLost->value,
                AssetAuditAction::Relocated->value,
                AssetAuditAction::MaintenanceLogged->value,
                AssetAuditAction::Flagged->value,
            ])],
        ];
    }
}
```

- [ ] **Step 4: Create `CompleteAssetAuditRequest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Assets;

use Illuminate\Foundation\Http\FormRequest;

class CompleteAssetAuditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('asset_audits.manage') === true;
    }

    public function rules(): array
    {
        return [];
    }
}
```

- [ ] **Step 5: Create `CancelAssetAuditRequest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Assets;

use Illuminate\Foundation\Http\FormRequest;

class CancelAssetAuditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('asset_audits.manage') === true;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:2000']];
    }
}
```

- [ ] **Step 6: Create `AssetAuditEventResource.php`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AssetAuditEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AssetAuditEvent */
class AssetAuditEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'action'     => $this->action,
            'detail'     => $this->detail,
            'actor'      => $this->whenLoaded('actor', fn () => [
                'id' => $this->actor?->id, 'name' => $this->actor?->name,
            ]),
            'created_at' => $this->created_at?->format('Y-m-d H:i'),
        ];
    }
}
```

- [ ] **Step 7: Create `AssetAuditLineResource.php`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AssetAuditLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AssetAuditLine */
class AssetAuditLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'asset'             => $this->whenLoaded('asset', fn () => [
                'id'       => $this->asset->id,
                'asset_tag'=> $this->asset->asset_tag,
                'name'     => $this->asset->name,
            ]),
            'expected_status'   => $this->expected_status,
            'expected_location' => $this->expected_location,
            'expected_holder'   => $this->whenLoaded('expectedHolder', fn () => $this->expectedHolder?->full_name ?? $this->expectedHolder?->name),
            'result'            => ['value' => $this->result->value, 'label' => $this->result->label()],
            'observed_location' => $this->observed_location,
            'observed_note'     => $this->observed_note,
            'is_discrepancy'    => $this->is_discrepancy,
            'resolution_action' => ['value' => $this->resolution_action->value, 'label' => $this->resolution_action->label()],
            'resolved_at'       => $this->resolved_at?->format('Y-m-d H:i'),
        ];
    }
}
```

- [ ] **Step 8: Create `AssetAuditResource.php`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AssetAudit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AssetAudit */
class AssetAuditResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'reference'         => $this->reference,
            'status'            => ['value' => $this->status->value, 'label' => $this->status->label()],
            'scope_type'        => $this->scope_type,
            'scope_value'       => $this->scope_value,
            'total_lines'       => $this->total_lines,
            'counted_lines'     => $this->counted_lines,
            'discrepancy_lines' => $this->discrepancy_lines,
            'notes'             => $this->notes,
            'opened_at'         => $this->opened_at?->format('Y-m-d H:i'),
            'completed_at'      => $this->completed_at?->format('Y-m-d H:i'),
            'lines'             => AssetAuditLineResource::collection($this->whenLoaded('lines')),
            'events'            => AssetAuditEventResource::collection($this->whenLoaded('events')),
        ];
    }
}
```

- [ ] **Step 9: Commit**

```bash
git add app/Http/Requests/Assets/*AssetAudit*.php app/Http/Resources/AssetAudit*.php
git commit -m "feat(asset-audit): form requests + resources"
```

---

## Task 8: Controller, routes, hub stats + endpoint tests

**Files:**
- Create: `app/Http/Controllers/Auditor/AssetAuditController.php`
- Modify: `routes/web.php`, `app/Http/Controllers/AuditorController.php`
- Test: `tests/Feature/Auditor/AssetAuditEndpointTest.php`

**Interfaces:**
- Consumes: `AssetAuditService`, the Task 7 requests/resources.
- Produces route names `auditor.asset-audits.{index,create,show,store,count,resolve,complete,cancel}`.

- [ ] **Step 1: Write the failing endpoint test**

Create `tests/Feature/Auditor/AssetAuditEndpointTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\AssetAuditStatus;
use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\AssetAudit;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(fn () => (new RolePermissionSeeder())->run());

it('auditor can list, employee cannot', function () {
    $this->actingAs(User::factory()->create(['role' => 'auditor']))
        ->get('/auditor/asset-audits')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Auditor/AssetAudits/Index'));

    $this->actingAs(User::factory()->create(['role' => 'employee']))
        ->get('/auditor/asset-audits')->assertForbidden();
});

it('opens an audit via POST and snapshots assets', function () {
    Asset::factory()->count(3)->create(['current_status' => AssetStatus::InStock->value]);
    $this->actingAs(User::factory()->create(['role' => 'auditor']))
        ->post('/auditor/asset-audits', ['scope_type' => 'all'])->assertRedirect();

    $audit = AssetAudit::latest()->first();
    expect($audit->total_lines)->toBe(3);
    expect($audit->status)->toBe(AssetAuditStatus::InProgress);
});

it('counts a line and applies a resolution over HTTP', function () {
    Asset::factory()->create(['current_status' => AssetStatus::InStock->value]);
    $auditor = User::factory()->create(['role' => 'auditor']);
    $this->actingAs($auditor)->post('/auditor/asset-audits', ['scope_type' => 'all']);
    $audit = AssetAudit::latest()->first();
    $line  = $audit->lines()->first();

    $this->actingAs($auditor)
        ->post("/auditor/asset-audits/{$audit->id}/lines/{$line->id}/count", ['result' => 'missing'])
        ->assertRedirect();
    expect($line->fresh()->is_discrepancy)->toBeTrue();

    $this->actingAs($auditor)
        ->post("/auditor/asset-audits/{$audit->id}/lines/{$line->id}/resolve", ['action' => 'marked_lost'])
        ->assertRedirect();
    expect($line->fresh()->asset->current_status)->toBe(AssetStatus::Lost);
});

it('completes an audit', function () {
    Asset::factory()->create(['current_status' => AssetStatus::InStock->value]);
    $auditor = User::factory()->create(['role' => 'auditor']);
    $this->actingAs($auditor)->post('/auditor/asset-audits', ['scope_type' => 'all']);
    $audit = AssetAudit::latest()->first();

    $this->actingAs($auditor)->post("/auditor/asset-audits/{$audit->id}/complete")->assertRedirect();
    expect($audit->fresh()->status)->toBe(AssetAuditStatus::Completed);
});

it('cancel requires a reason', function () {
    Asset::factory()->create(['current_status' => AssetStatus::InStock->value]);
    $auditor = User::factory()->create(['role' => 'auditor']);
    $this->actingAs($auditor)->post('/auditor/asset-audits', ['scope_type' => 'all']);
    $audit = AssetAudit::latest()->first();

    $this->actingAs($auditor)->post("/auditor/asset-audits/{$audit->id}/cancel", [])
        ->assertSessionHasErrors(['reason']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Auditor/AssetAuditEndpointTest.php`
Expected: FAIL (404 / no route).

- [ ] **Step 3: Create the controller**

`app/Http/Controllers/Auditor/AssetAuditController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auditor;

use App\Enums\AssetAuditAction;
use App\Enums\AssetAuditResult;
use App\Enums\AssetAuditStatus;
use App\Enums\AssetCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assets\CancelAssetAuditRequest;
use App\Http\Requests\Assets\CompleteAssetAuditRequest;
use App\Http\Requests\Assets\CountAssetAuditLineRequest;
use App\Http\Requests\Assets\ResolveAssetAuditLineRequest;
use App\Http\Requests\Assets\StoreAssetAuditRequest;
use App\Http\Resources\AssetAuditResource;
use App\Models\AssetAudit;
use App\Models\AssetAuditLine;
use App\Services\AssetAuditService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AssetAuditController extends Controller
{
    public function __construct(private readonly AssetAuditService $service)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $request->only(['status']);
        $q = AssetAudit::query()->withCount('lines');
        if (! empty($filters['status'])) $q->where('status', $filters['status']);

        return Inertia::render('Auditor/AssetAudits/Index', [
            'activeModule' => 'auditor-asset-audits',
            'audits'       => AssetAuditResource::collection($q->orderByDesc('created_at')->paginate(50)->withQueryString()),
            'filters'      => $filters,
            'statuses'     => collect(AssetAuditStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Auditor/AssetAudits/Create', [
            'activeModule' => 'auditor-asset-audits',
            'categories'   => collect(AssetCategory::cases())->map(fn ($c) => ['value' => $c->value, 'label' => $c->label()]),
        ]);
    }

    public function show(AssetAudit $assetAudit, Request $request): Response
    {
        abort_unless($request->user()?->hasPermission('asset_audits.view'), 403);
        $assetAudit->load(['lines.asset', 'lines.expectedHolder', 'events.actor']);

        return Inertia::render('Auditor/AssetAudits/Show', [
            'activeModule' => 'auditor-asset-audits',
            'audit'        => (new AssetAuditResource($assetAudit))->resolve(),
            'resultOptions'=> collect(AssetAuditResult::cases())->reject(fn ($r) => $r === AssetAuditResult::Pending)
                                ->map(fn ($r) => ['value' => $r->value, 'label' => $r->label()])->values(),
            'can'          => ['manage' => $request->user()->hasPermission('asset_audits.manage')],
        ]);
    }

    public function store(StoreAssetAuditRequest $request): RedirectResponse
    {
        $audit = $this->service->open($request->validated(), $request->user());
        return redirect()->route('auditor.asset-audits.show', $audit->id)->with('success', 'Audit opened — assets snapshotted.');
    }

    public function count(CountAssetAuditLineRequest $request, AssetAudit $assetAudit, AssetAuditLine $line): RedirectResponse
    {
        abort_unless($line->asset_audit_id === $assetAudit->id, 404);
        try {
            $this->service->count($line, AssetAuditResult::from($request->validated('result')), $request->validated(), $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
        return back()->with('success', 'Line counted.');
    }

    public function resolve(ResolveAssetAuditLineRequest $request, AssetAudit $assetAudit, AssetAuditLine $line): RedirectResponse
    {
        abort_unless($line->asset_audit_id === $assetAudit->id, 404);
        try {
            $this->service->applyResolution($line, AssetAuditAction::from($request->validated('action')), $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
        return back()->with('success', 'Resolution applied.');
    }

    public function complete(CompleteAssetAuditRequest $request, AssetAudit $assetAudit): RedirectResponse
    {
        try {
            $this->service->complete($assetAudit, $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
        return back()->with('success', 'Audit completed.');
    }

    public function cancel(CancelAssetAuditRequest $request, AssetAudit $assetAudit): RedirectResponse
    {
        try {
            $this->service->cancel($assetAudit, $request->user(), $request->validated('reason'));
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }
        return back()->with('success', 'Audit cancelled.');
    }
}
```

- [ ] **Step 4: Register routes in `routes/web.php`**

Inside the existing `Route::middleware(['auth','verified'])->prefix('auditor')->name('auditor.')->group(function () { ... });` block (the one containing the incoming-invoice routes), add before its closing `});`:

```php
    // Asset audits
    Route::middleware('permission:asset_audits.view')->group(function () {
        Route::get('asset-audits',                 [\App\Http\Controllers\Auditor\AssetAuditController::class, 'index'])->name('asset-audits.index');
        Route::get('asset-audits/create',          [\App\Http\Controllers\Auditor\AssetAuditController::class, 'create'])->name('asset-audits.create');
        Route::get('asset-audits/{assetAudit}',    [\App\Http\Controllers\Auditor\AssetAuditController::class, 'show'])->name('asset-audits.show');
    });
    Route::middleware('permission:asset_audits.manage')->group(function () {
        Route::post('asset-audits',                              [\App\Http\Controllers\Auditor\AssetAuditController::class, 'store'])->name('asset-audits.store');
        Route::post('asset-audits/{assetAudit}/lines/{line}/count',   [\App\Http\Controllers\Auditor\AssetAuditController::class, 'count'])->name('asset-audits.count');
        Route::post('asset-audits/{assetAudit}/lines/{line}/resolve', [\App\Http\Controllers\Auditor\AssetAuditController::class, 'resolve'])->name('asset-audits.resolve');
        Route::post('asset-audits/{assetAudit}/complete',       [\App\Http\Controllers\Auditor\AssetAuditController::class, 'complete'])->name('asset-audits.complete');
        Route::post('asset-audits/{assetAudit}/cancel',         [\App\Http\Controllers\Auditor\AssetAuditController::class, 'cancel'])->name('asset-audits.cancel');
    });
```

- [ ] **Step 5: Add asset-audit stats + link to `AuditorController::hub()`**

In `app/Http/Controllers/AuditorController.php`, add `use App\Enums\AssetAuditStatus;` and `use App\Models\AssetAudit;` at the top. Inside `hub()`, before the `return`, add:

```php
        $auditCounts = AssetAudit::query()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');
        $openAssetAudits = (int) ($auditCounts[AssetAuditStatus::InProgress->value] ?? 0);
        $openDiscrepancies = (int) AssetAudit::where('status', AssetAuditStatus::InProgress->value)->sum('discrepancy_lines');
```

Then in the `'stats' => [ ... ]` array add:

```php
                'open_asset_audits'  => $openAssetAudits,
                'open_discrepancies' => $openDiscrepancies,
```

And in the `'links' => [ ... ]` array add:

```php
                'asset_audits' => $request->user()->hasPermission('asset_audits.view'),
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/Auditor/AssetAuditEndpointTest.php`
Expected: PASS (5 passing). (Inertia component-name assertions pass without the `.vue` files — but this codebase `@vite`s the page component, so the Index/Create/Show pages must exist for a full GET to render. Task 9 creates them; if a GET test 500s here on a missing manifest entry, create minimal placeholder `.vue` stubs for `Auditor/AssetAudits/{Index,Create,Show}.vue` now and let Task 9 flesh them out. This mirrors what the incoming-invoice module required.)

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Auditor/AssetAuditController.php routes/web.php app/Http/Controllers/AuditorController.php tests/Feature/Auditor/AssetAuditEndpointTest.php resources/js/Pages/Auditor/AssetAudits
git commit -m "feat(asset-audit): controller, routes, hub stats + endpoint tests"
```

---

## Task 9: Vue pages + Hub card + nav

**Files:**
- Create/overwrite: `resources/js/Pages/Auditor/AssetAudits/Index.vue`, `Create.vue`, `Show.vue`
- Modify: `resources/js/Pages/Auditor/Hub.vue`, `resources/js/Layouts/AuthenticatedLayout.vue`

**Interfaces:**
- Consumes controller props: Index `{audits, filters, statuses}`; Create `{categories}`; Show `{audit, resultOptions, can}`; Hub adds `stats.open_asset_audits` + `links.asset_audits`.

- [ ] **Step 1: Add the nav child**

In `resources/js/Layouts/AuthenticatedLayout.vue`, find the `Auditor` expandable nav group (added by the auditors module, containing `Hub` and `Incoming Invoices`) and add a child after `Incoming Invoices`:

```js
                            { label: 'Asset Audits', route: 'auditor.asset-audits.index', module: 'auditor-asset-audits', icon: 'fact_check', visible: can('asset_audits.view') },
```

- [ ] **Step 2: Add the Hub stat + link card**

In `resources/js/Pages/Auditor/Hub.vue`, inside the first `grid` (stat cards), after the "Returned" card add:

```html
                <Link v-if="links.asset_audits" :href="route('auditor.asset-audits.index', { status: 'in_progress' })" class="rounded-xl border border-outline-variant/60 bg-surface-container-lowest p-4 hover:shadow-md transition-shadow">
                    <div class="text-3xl font-bold text-primary">{{ stats.open_asset_audits ?? 0 }}</div>
                    <div class="text-sm text-on-surface-variant">Open asset audits</div>
                </Link>
```

And inside the second `grid` (link cards), after the Audit Log card add:

```html
                <Link v-if="links.asset_audits" :href="route('auditor.asset-audits.index')" class="rounded-xl border border-outline-variant/60 bg-surface-container-lowest p-4 hover:shadow-md transition-shadow">
                    <div class="font-medium text-primary">Asset Audits</div>
                    <div class="text-sm text-on-surface-variant">Run physical asset counts</div>
                </Link>
```

- [ ] **Step 3: Create `Index.vue`**

`resources/js/Pages/Auditor/AssetAudits/Index.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    audits: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    statuses: { type: Array, default: () => [] },
});

const status = ref(props.filters.status ?? '');

function applyFilters() {
    router.get(route('auditor.asset-audits.index'), { status: status.value }, { preserveState: true, replace: true });
}
</script>

<template>
    <Head title="Asset Audits" />
    <AuthenticatedLayout>
        <div class="p-6 space-y-4">
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-semibold text-primary">Asset Audits</h1>
                <Link :href="route('auditor.asset-audits.create')" class="rounded-lg bg-primary text-on-primary px-4 py-2 text-sm">New audit</Link>
            </div>

            <select v-model="status" @change="applyFilters" aria-label="Filter by status" class="rounded-lg border-outline-variant text-sm">
                <option value="">All statuses</option>
                <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>

            <table class="w-full text-sm">
                <thead class="text-left text-on-surface-variant border-b border-outline-variant/60">
                    <tr><th class="py-2">Reference</th><th>Scope</th><th>Coverage</th><th>Discrepancies</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <tr v-for="a in audits.data" :key="a.id" class="border-b border-outline-variant/40 hover:bg-surface-container-lowest">
                        <td class="py-2"><Link :href="route('auditor.asset-audits.show', a.id)" class="text-primary">{{ a.reference }}</Link></td>
                        <td>{{ a.scope_type }}<span v-if="a.scope_value"> — {{ a.scope_value }}</span></td>
                        <td>{{ a.counted_lines }} / {{ a.total_lines }}</td>
                        <td>{{ a.discrepancy_lines }}</td>
                        <td>{{ a.status.label }}</td>
                    </tr>
                    <tr v-if="!audits.data.length"><td colspan="5" class="py-6 text-center text-on-surface-variant">No audits yet.</td></tr>
                </tbody>
            </table>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 4: Create `Create.vue`**

`resources/js/Pages/Auditor/AssetAudits/Create.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineProps({
    categories: { type: Array, default: () => [] },
});

const form = useForm({ scope_type: 'all', scope_value: '', notes: '' });

function submit() {
    form.post(route('auditor.asset-audits.store'));
}
</script>

<template>
    <Head title="New Asset Audit" />
    <AuthenticatedLayout>
        <form @submit.prevent="submit" class="p-6 max-w-xl space-y-4">
            <h1 class="text-2xl font-semibold text-primary">New Asset Audit</h1>

            <div>
                <label class="block text-sm text-on-surface-variant">Scope</label>
                <select v-model="form.scope_type" aria-label="Audit scope" class="w-full rounded-lg border-outline-variant">
                    <option value="all">All assets</option>
                    <option value="category">By category</option>
                    <option value="location">By location</option>
                </select>
            </div>

            <div v-if="form.scope_type === 'category'">
                <label class="block text-sm text-on-surface-variant">Category</label>
                <select v-model="form.scope_value" aria-label="Category" class="w-full rounded-lg border-outline-variant">
                    <option value="">Select…</option>
                    <option v-for="c in categories" :key="c.value" :value="c.value">{{ c.label }}</option>
                </select>
                <div v-if="form.errors.scope_value" class="text-error text-xs">{{ form.errors.scope_value }}</div>
            </div>

            <div v-if="form.scope_type === 'location'">
                <label class="block text-sm text-on-surface-variant">Location</label>
                <input v-model="form.scope_value" aria-label="Location" class="w-full rounded-lg border-outline-variant" />
                <div v-if="form.errors.scope_value" class="text-error text-xs">{{ form.errors.scope_value }}</div>
            </div>

            <div>
                <label class="block text-sm text-on-surface-variant">Notes</label>
                <textarea v-model="form.notes" aria-label="Notes" class="w-full rounded-lg border-outline-variant"></textarea>
            </div>

            <button :disabled="form.processing" class="rounded-lg bg-primary text-on-primary px-4 py-2">Open audit</button>
        </form>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 5: Create `Show.vue`**

`resources/js/Pages/Auditor/AssetAudits/Show.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    audit: { type: Object, required: true },
    resultOptions: { type: Array, default: () => [] },
    can: { type: Object, required: true },
});

const isOpen = props.audit.status.value === 'in_progress';

// per-line count form state keyed by line id
function countLine(line, result) {
    router.post(route('auditor.asset-audits.count', [props.audit.id, line.id]), {
        result,
        observed_location: line._observed_location ?? null,
        observed_note: line._observed_note ?? null,
    }, { preserveScroll: true });
}

function resolveLine(line, action) {
    router.post(route('auditor.asset-audits.resolve', [props.audit.id, line.id]), { action }, { preserveScroll: true });
}

const cancelForm = useForm({ reason: '' });

// maps a discrepancy result to its single valid resolution action + label
const RESOLUTION = {
    missing:        { action: 'marked_lost',        label: 'Mark lost' },
    wrong_location: { action: 'relocated',          label: 'Relocate' },
    damaged:        { action: 'maintenance_logged', label: 'Log maintenance' },
    wrong_holder:   { action: 'flagged',            label: 'Flag for reassignment' },
};
</script>

<template>
    <Head :title="audit.reference" />
    <AuthenticatedLayout>
        <div class="p-6 space-y-6">
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-semibold text-primary">{{ audit.reference }}</h1>
                <span class="rounded-full bg-surface-container px-3 py-1 text-sm">{{ audit.status.label }}</span>
            </div>

            <div class="flex gap-6 text-sm text-on-surface-variant">
                <div>Scope: <span class="text-primary">{{ audit.scope_type }}<span v-if="audit.scope_value"> — {{ audit.scope_value }}</span></span></div>
                <div>Coverage: <span class="text-primary">{{ audit.counted_lines }} / {{ audit.total_lines }}</span></div>
                <div>Discrepancies: <span class="text-primary">{{ audit.discrepancy_lines }}</span></div>
            </div>

            <div v-if="can.manage && isOpen" class="flex gap-2">
                <button @click="router.post(route('auditor.asset-audits.complete', audit.id), {}, { preserveScroll: true })" class="rounded-lg bg-primary text-on-primary px-4 py-2">Complete audit</button>
                <input v-model="cancelForm.reason" aria-label="Cancel reason" placeholder="Cancel reason" class="rounded-lg border-outline-variant text-sm" />
                <button @click="cancelForm.post(route('auditor.asset-audits.cancel', audit.id), { preserveScroll: true })" class="rounded-lg bg-error text-on-error px-4 py-2">Cancel</button>
            </div>

            <table class="w-full text-sm">
                <thead class="text-left text-on-surface-variant border-b border-outline-variant/60">
                    <tr><th class="py-2">Asset</th><th>Expected</th><th>Result</th><th>Observed</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <tr v-for="line in audit.lines" :key="line.id" class="border-b border-outline-variant/40 align-top">
                        <td class="py-2">
                            <div class="text-primary">{{ line.asset?.asset_tag }}</div>
                            <div class="text-on-surface-variant">{{ line.asset?.name }}</div>
                        </td>
                        <td>
                            <div>{{ line.expected_status }}</div>
                            <div class="text-on-surface-variant">{{ line.expected_location }}</div>
                            <div v-if="line.expected_holder" class="text-on-surface-variant">{{ line.expected_holder }}</div>
                        </td>
                        <td>
                            <span :class="line.is_discrepancy ? 'text-error' : 'text-primary'">{{ line.result.label }}</span>
                            <div v-if="can.manage && isOpen" class="mt-1 flex flex-wrap gap-1">
                                <button v-for="opt in resultOptions" :key="opt.value" @click="countLine(line, opt.value)" class="rounded border border-outline-variant/60 px-1.5 py-0.5 text-xs hover:bg-surface-container">{{ opt.label }}</button>
                            </div>
                        </td>
                        <td>
                            <template v-if="can.manage && isOpen">
                                <input v-model="line._observed_location" aria-label="Observed location" placeholder="location" class="w-28 rounded border-outline-variant text-xs" />
                                <input v-model="line._observed_note" aria-label="Observed note" placeholder="note" class="w-28 rounded border-outline-variant text-xs mt-1" />
                            </template>
                            <template v-else>{{ line.observed_location }}</template>
                        </td>
                        <td>
                            <span v-if="line.resolution_action.value !== 'none'" class="text-on-surface-variant">{{ line.resolution_action.label }}</span>
                            <button v-else-if="can.manage && line.is_discrepancy && RESOLUTION[line.result.value]"
                                @click="resolveLine(line, RESOLUTION[line.result.value].action)"
                                class="rounded bg-primary text-on-primary px-2 py-0.5 text-xs">
                                {{ RESOLUTION[line.result.value].label }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 6: Build the frontend**

Run: `npm run build`
Expected: build succeeds; all three pages compile.

- [ ] **Step 7: Commit**

```bash
git add resources/js/Pages/Auditor resources/js/Layouts/AuthenticatedLayout.vue
git commit -m "feat(asset-audit): hub card, nav, and Vue pages"
```

---

## Task 10: Full-suite verification

**Files:** none — verification only.

- [ ] **Step 1: Run the Auditor test directory**

Run: `php artisan test tests/Feature/Auditor`
Expected: PASS (all asset-audit + incoming-invoice files green).

- [ ] **Step 2: Run the full suite**

Run: `php artisan test`
Expected: PASS — the pre-existing suite total plus the new asset-audit tests, zero failures. If the accessibility auditor test (`tests/Feature/Accessibility/AccessibilityAuditorTest.php`) flags any of the new `.vue` form controls for a missing label (WCAG 3.3.2), add an `aria-label` to each flagged control (the plan's Vue already includes `aria-label` on every input/select/textarea — verify none were missed) and re-run.

- [ ] **Step 3: Confirm routes resolve**

Run: `php artisan route:list --path=auditor/asset-audits`
Expected: lists all `auditor.asset-audits.*` routes.

- [ ] **Step 4: Final commit (if fixups were needed)**

```bash
git add -A
git commit -m "test(asset-audit): full-suite green"
```

---

## Self-Review Notes

- **Spec coverage:** campaign+snapshot (Task 3) ✓; count + discrepancy flags (Task 4) ✓; one-click write-back via AssetService — markLost/relocate/logMaintenance/flag (Task 5) ✓; auditor-owned complete/cancel, no second sign-off (Task 6) ✓; expected set excludes retired/lost, scope filters (Task 3) ✓; SequenceService reference (Task 3) ✓; append-only event trail (Tasks 2–6) ✓; 2 perms synced 3 ways + AssetStatus label() (Task 1) ✓; apply gated by `asset_audits.manage` not `assets.manage` (Task 7 requests + Task 8 routes) ✓; Hub card + nav (Tasks 8/9) ✓; Pest tests per patterns (throughout) ✓.
- **Placeholder scan:** none — every step has concrete code/commands.
- **Type consistency:** service methods (`open/count/applyResolution/complete/cancel/recordEvent/recomputeTallies/nextReference`) and route names (`auditor.asset-audits.*`) are used identically across tasks, tests, controller, and Vue. Enum values match between migrations (string columns), enums, requests (Rule::in), and Vue string comparisons.
- **A11y:** every new form control carries an `aria-label` (learned from the incoming-invoice WCAG merge-gate); Task 10 Step 2 re-checks.
- **Known integration note:** this codebase `@vite`s the Inertia page component, so Task 8's endpoint GET tests need the `Auditor/AssetAudits/{Index,Create,Show}.vue` files to exist — Task 8 Step 6 instructs creating minimal stubs if run before Task 9, exactly as the incoming-invoice module required.
