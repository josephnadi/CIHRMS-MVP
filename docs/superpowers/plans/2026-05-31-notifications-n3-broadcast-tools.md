# Notifications N3 — Admin Broadcast Tools Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Admin-initiated SMS+email broadcasts to pre-defined audiences (members, employees, users) with optional scheduling and reusable templates, riding N1's async dispatcher and the existing mail driver.

**Architecture:** Composer → `BroadcastService::queue()` → either schedule (status `Scheduled`, picked up by the `messaging:fire-due-broadcasts` artisan command every minute) or dispatch `DispatchBroadcastJob` immediately. The job resolves the audience via `AudienceResolver`, chunks it (100 at a time), renders body templates via `TemplateRenderer` (audience-typed `{{var}}` whitelist), and dispatches one SMS leg (through N1's `SmsDispatcher`) + one mail leg (`Mail::raw`) per recipient, recording each outcome in `broadcast_recipients`.

**Tech Stack:** Laravel 13.8, PHP 8.4, Pest. Builds on N1 (`SmsDispatcher` async + `sms:marketing` limiter, PR #71) and N2 (notification patterns, PR #72).

**Branch:** `feat/notifications-n3-broadcast-tools` (off main after N2 + checklist merge).

---

## File Structure

**New files (~25):**

- `app/Enums/BroadcastStatus.php`
- `app/Enums/BroadcastChannel.php`
- `app/Enums/BroadcastAudienceType.php`
- `app/Models/Broadcast.php`
- `app/Models/BroadcastTemplate.php`
- `app/Models/BroadcastRecipient.php`
- `database/migrations/2026_06_01_000001_create_broadcast_templates_table.php`
- `database/migrations/2026_06_01_000002_create_broadcasts_table.php`
- `database/migrations/2026_06_01_000003_create_broadcast_recipients_table.php`
- `database/factories/BroadcastFactory.php`
- `database/factories/BroadcastTemplateFactory.php`
- `app/Services/Messaging/Broadcasts/AudienceResolver.php`
- `app/Services/Messaging/Broadcasts/TemplateRenderer.php`
- `app/Services/Messaging/Broadcasts/BroadcastService.php`
- `app/Jobs/Messaging/DispatchBroadcastJob.php`
- `app/Console/Commands/FireDueBroadcastsCommand.php`
- `app/Http/Controllers/BroadcastController.php`
- `app/Http/Controllers/BroadcastTemplateController.php`
- `app/Http/Requests/Broadcast/StoreBroadcastRequest.php`
- `app/Http/Requests/Broadcast/StoreBroadcastTemplateRequest.php`
- `app/Http/Requests/Broadcast/UpdateBroadcastTemplateRequest.php`
- `resources/js/Pages/Messaging/Broadcasts/Index.vue`
- `resources/js/Pages/Messaging/Broadcasts/Create.vue`
- `resources/js/Pages/Messaging/Broadcasts/Show.vue`
- `resources/js/Pages/Messaging/Templates/Index.vue`
- `resources/js/Components/VariablesPanel.vue`
- 6 test files under `tests/Feature/Messaging/Broadcasts/`

**Modified files (5):**

- `app/Enums/Permission.php` — add 3 new cases
- `database/seeders/RolePermissionSeeder.php` — add the 3 perms + grant to hr_admin and finance_officer
- `routes/web.php` — add Broadcast + Template routes under existing `/admin/messaging` prefix
- `routes/console.php` — schedule `messaging:fire-due-broadcasts`
- `resources/js/Layouts/AuthenticatedLayout.vue` — add sidebar entry under existing Messaging admin item

---

## Shared conventions used in every task

**Enum file skeleton:**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum XxxEnum: string
{
    case CaseA = 'case_a';
    // …
}
```

**Migration file skeleton:**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('xxx', function (Blueprint $t) { /* … */ });
    }
    public function down(): void { Schema::dropIfExists('xxx'); }
};
```

**Test file skeleton:**

```php
<?php

use App\Models\User;
// …

beforeEach(function () {
    // …
});

it('…', function () {
    // …
});
```

**Bus::fake scoped pattern** (from N1/N2): `Bus::fake([SendSmsJob::class])` — bare `Bus::fake()` intercepts the `CallQueuedListener` jobs the job dispatch system uses.

---

## Task 1: Enums + Permission slugs + Seeder grants

**Files:**
- Create: `app/Enums/BroadcastStatus.php`
- Create: `app/Enums/BroadcastChannel.php`
- Create: `app/Enums/BroadcastAudienceType.php`
- Modify: `app/Enums/Permission.php` — add 3 cases
- Modify: `database/seeders/RolePermissionSeeder.php` — add 3 perm definitions + grants
- Test: `tests/Unit/Messaging/BroadcastEnumsTest.php`

### Step 1: Write the failing test

Create `tests/Unit/Messaging/BroadcastEnumsTest.php`:

```php
<?php

use App\Enums\BroadcastAudienceType;
use App\Enums\BroadcastChannel;
use App\Enums\BroadcastStatus;
use App\Enums\Permission;

it('BroadcastStatus has the 7 expected states', function () {
    $values = array_column(BroadcastStatus::cases(), 'value');
    expect($values)->toEqualCanonicalizing([
        'draft', 'scheduled', 'queued', 'sending', 'completed', 'failed', 'cancelled',
    ]);
});

it('BroadcastChannel covers sms + mail', function () {
    $values = array_column(BroadcastChannel::cases(), 'value');
    expect($values)->toEqualCanonicalizing(['sms', 'mail']);
});

it('BroadcastAudienceType covers all 6 audience types', function () {
    $values = array_column(BroadcastAudienceType::cases(), 'value');
    expect($values)->toEqualCanonicalizing([
        'all_active_members',
        'members_by_class',
        'members_with_outstanding_fees',
        'all_active_employees',
        'employees_by_department',
        'users_by_permission',
    ]);
});

it('Permission enum exposes the 3 new broadcast slugs', function () {
    expect(Permission::BroadcastsView->value)->toBe('broadcasts.view');
    expect(Permission::BroadcastsManage->value)->toBe('broadcasts.manage');
    expect(Permission::BroadcastsBypassThrottle->value)->toBe('broadcasts.bypass_throttle');
});
```

### Step 2: Run test to verify it fails

Run from `d:\CIHRMS\cihrms-mvp`:

```
vendor/bin/pest tests/Unit/Messaging/BroadcastEnumsTest.php
```
Expected: FAIL with "class BroadcastStatus not found".

### Step 3: Create `BroadcastStatus`

Create `app/Enums/BroadcastStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum BroadcastStatus: string
{
    case Draft     = 'draft';
    case Scheduled = 'scheduled';
    case Queued    = 'queued';
    case Sending   = 'sending';
    case Completed = 'completed';
    case Failed    = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft     => 'Draft',
            self::Scheduled => 'Scheduled',
            self::Queued    => 'Queued',
            self::Sending   => 'Sending',
            self::Completed => 'Completed',
            self::Failed    => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }
}
```

### Step 4: Create `BroadcastChannel`

Create `app/Enums/BroadcastChannel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum BroadcastChannel: string
{
    case Sms  = 'sms';
    case Mail = 'mail';
}
```

### Step 5: Create `BroadcastAudienceType`

Create `app/Enums/BroadcastAudienceType.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Pre-defined audience targets for admin broadcasts. Each value maps to a
 * resolver in `App\Services\Messaging\Broadcasts\AudienceResolver` that
 * returns an Eloquent Builder, and to a variable-whitelist + recipient-type
 * binding enforced by `TemplateRenderer`.
 */
enum BroadcastAudienceType: string
{
    case AllActiveMembers            = 'all_active_members';
    case MembersByClass              = 'members_by_class';
    case MembersWithOutstandingFees  = 'members_with_outstanding_fees';
    case AllActiveEmployees          = 'all_active_employees';
    case EmployeesByDepartment       = 'employees_by_department';
    case UsersByPermission           = 'users_by_permission';

    /** The model class instances of this audience type are. */
    public function recipientClass(): string
    {
        return match ($this) {
            self::AllActiveMembers, self::MembersByClass, self::MembersWithOutstandingFees
                => \App\Models\Member::class,
            self::AllActiveEmployees, self::EmployeesByDepartment
                => \App\Models\Employee::class,
            self::UsersByPermission
                => \App\Models\User::class,
        };
    }

    /** Variable names allowed in templates for this audience type. */
    public function allowedVariables(): array
    {
        $common = ['org_name', 'today'];
        $specific = match ($this) {
            self::AllActiveMembers, self::MembersByClass, self::MembersWithOutstandingFees => [
                'member.name', 'member.member_no', 'member.class',
                'member.outstanding_total', 'member.next_due_date',
            ],
            self::AllActiveEmployees, self::EmployeesByDepartment => [
                'employee.name', 'employee.staff_id', 'employee.department', 'employee.position',
            ],
            self::UsersByPermission => [
                'user.name', 'user.role',
            ],
        };
        return array_merge($common, $specific);
    }
}
```

### Step 6: Add 3 cases to `Permission` enum

Open `app/Enums/Permission.php`. Find the `MessagingManage` case (around line 110). Below it, before the `// ── Phase 4: SSO` section, add:

```php
    // ── N3: Broadcasts (admin SMS+mail to pre-defined audiences) ──
    case BroadcastsView            = 'broadcasts.view';
    case BroadcastsManage          = 'broadcasts.manage';
    case BroadcastsBypassThrottle  = 'broadcasts.bypass_throttle';
```

### Step 7: Register perms + grants in `RolePermissionSeeder`

Open `database/seeders/RolePermissionSeeder.php`. Find the `messaging.manage` entry around line 134 in the `PERMISSIONS` const. After it, before the `// ── Phase 4: SSO` block, add:

```php

        // ── N3: Broadcasts (admin SMS+mail to pre-defined audiences) ──
        'broadcasts.view'             => ['Broadcasts', 'View broadcast history + recipient outcomes'],
        'broadcasts.manage'           => ['Broadcasts', 'Compose, schedule, send, cancel broadcasts'],
        'broadcasts.bypass_throttle'  => ['Broadcasts', 'Bypass the sms:marketing per-phone rate limiter on a broadcast (audit-logged)'],
```

Then find the `hr_admin` role grant block around line 234 with `'messaging.view', 'messaging.send', 'messaging.manage',`. Append three new slugs to that line so it reads:

```php
            'messaging.view', 'messaging.send', 'messaging.manage',
            'broadcasts.view', 'broadcasts.manage', 'broadcasts.bypass_throttle',
```

Find the `finance_officer` role grant block (around line 283). After its existing messaging line if any (or in a logical place — usually near the end of the array), add:

```php
            'broadcasts.view', 'broadcasts.manage',
```

(finance_officer does NOT get `bypass_throttle` — only hr_admin / super_admin / ceo can override the limiter.)

### Step 8: Run test to verify it passes

```
vendor/bin/pest tests/Unit/Messaging/BroadcastEnumsTest.php
```
Expected: PASS, 4 tests.

### Step 9: Sanity check the existing test suite still passes

```
vendor/bin/pest tests/Feature/Permissions/PermissionEnumTest.php
```
Expected: PASS.

### Step 10: Commit

```
git add app/Enums/BroadcastStatus.php app/Enums/BroadcastChannel.php app/Enums/BroadcastAudienceType.php app/Enums/Permission.php database/seeders/RolePermissionSeeder.php tests/Unit/Messaging/BroadcastEnumsTest.php
git commit -m "feat(broadcasts): enums + 3 perm slugs + role grants for N3 broadcast tools"
```

---

## Task 2: Migrations + Models + Factories

**Files:**
- Create: `database/migrations/2026_06_01_000001_create_broadcast_templates_table.php`
- Create: `database/migrations/2026_06_01_000002_create_broadcasts_table.php`
- Create: `database/migrations/2026_06_01_000003_create_broadcast_recipients_table.php`
- Create: `app/Models/Broadcast.php`
- Create: `app/Models/BroadcastTemplate.php`
- Create: `app/Models/BroadcastRecipient.php`
- Create: `database/factories/BroadcastFactory.php`
- Create: `database/factories/BroadcastTemplateFactory.php`
- Test: `tests/Feature/Messaging/Broadcasts/ModelTest.php`

### Step 1: Write the failing test

Create `tests/Feature/Messaging/Broadcasts/ModelTest.php`:

```php
<?php

use App\Enums\BroadcastAudienceType;
use App\Enums\BroadcastChannel;
use App\Enums\BroadcastStatus;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\BroadcastTemplate;
use App\Models\Member;
use App\Models\User;

it('can create a BroadcastTemplate with audience-type cast', function () {
    $admin = User::factory()->create();
    $template = BroadcastTemplate::factory()
        ->state(['created_by' => $admin->id, 'audience_type' => BroadcastAudienceType::AllActiveMembers])
        ->create();

    expect($template->audience_type)->toBe(BroadcastAudienceType::AllActiveMembers);
    expect($template->is_active)->toBeTrue();
});

it('can create a Broadcast with all enums cast', function () {
    $admin = User::factory()->create();
    $b = Broadcast::factory()->state([
        'created_by'    => $admin->id,
        'audience_type' => BroadcastAudienceType::AllActiveMembers,
        'channels'      => [BroadcastChannel::Sms->value, BroadcastChannel::Mail->value],
        'status'        => BroadcastStatus::Queued,
    ])->create();

    expect($b->audience_type)->toBe(BroadcastAudienceType::AllActiveMembers);
    expect($b->status)->toBe(BroadcastStatus::Queued);
    expect($b->channels)->toBe(['sms', 'mail']);
    expect($b->audience_params)->toBeArray();
});

it('Broadcast hasMany BroadcastRecipient', function () {
    $admin = User::factory()->create();
    $member = Member::factory()->create();
    $b = Broadcast::factory()->state(['created_by' => $admin->id])->create();

    BroadcastRecipient::create([
        'broadcast_id'   => $b->id,
        'recipient_type' => Member::class,
        'recipient_id'   => $member->id,
        'sms_status'     => 'Sent',
    ]);

    expect($b->fresh()->recipients)->toHaveCount(1);
});

it('BroadcastRecipient enforces unique (broadcast, recipient_type, recipient_id)', function () {
    $admin = User::factory()->create();
    $member = Member::factory()->create();
    $b = Broadcast::factory()->state(['created_by' => $admin->id])->create();

    BroadcastRecipient::create([
        'broadcast_id' => $b->id, 'recipient_type' => Member::class, 'recipient_id' => $member->id,
    ]);

    expect(fn () => BroadcastRecipient::create([
        'broadcast_id' => $b->id, 'recipient_type' => Member::class, 'recipient_id' => $member->id,
    ]))->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
});
```

### Step 2: Run test to verify it fails

```
vendor/bin/pest tests/Feature/Messaging/Broadcasts/ModelTest.php
```
Expected: FAIL with "table broadcasts not found" or "class Broadcast not found".

### Step 3: Create the `broadcast_templates` migration

Create `database/migrations/2026_06_01_000001_create_broadcast_templates_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('broadcast_templates', function (Blueprint $t) {
            $t->id();
            $t->string('name', 150);
            $t->string('audience_type', 64);
            $t->text('sms_body')->nullable();
            $t->string('mail_subject', 150)->nullable();
            $t->text('mail_body')->nullable();
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();

            $t->index(['audience_type', 'is_active']);
        });
    }

    public function down(): void { Schema::dropIfExists('broadcast_templates'); }
};
```

### Step 4: Create the `broadcasts` migration

Create `database/migrations/2026_06_01_000002_create_broadcasts_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('broadcasts', function (Blueprint $t) {
            $t->id();
            $t->string('title', 150);
            $t->string('audience_type', 64);
            $t->json('audience_params');
            $t->json('channels');
            $t->foreignId('template_id')->nullable()
                ->constrained('broadcast_templates')->nullOnDelete();
            $t->text('sms_body')->nullable();
            $t->string('mail_subject', 150)->nullable();
            $t->text('mail_body')->nullable();
            $t->timestamp('scheduled_at')->nullable();
            $t->boolean('throttle_overridden')->default(false);
            $t->string('throttle_override_reason', 255)->nullable();
            $t->string('status', 32)->default('queued');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->unsignedInteger('recipient_count')->default(0);
            $t->unsignedInteger('sms_sent_count')->default(0);
            $t->unsignedInteger('sms_failed_count')->default(0);
            $t->unsignedInteger('sms_throttled_count')->default(0);
            $t->unsignedInteger('mail_sent_count')->default(0);
            $t->unsignedInteger('mail_failed_count')->default(0);
            $t->softDeletes();
            $t->timestamps();

            $t->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void { Schema::dropIfExists('broadcasts'); }
};
```

### Step 5: Create the `broadcast_recipients` migration

Create `database/migrations/2026_06_01_000003_create_broadcast_recipients_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('broadcast_recipients', function (Blueprint $t) {
            $t->id();
            $t->foreignId('broadcast_id')->constrained('broadcasts')->cascadeOnDelete();
            $t->string('recipient_type', 64);
            $t->unsignedBigInteger('recipient_id');
            $t->foreignId('sms_message_id')->nullable()
                ->constrained('sms_messages')->nullOnDelete();
            $t->string('sms_status', 16)->nullable();
            $t->string('mail_status', 16)->nullable();
            $t->text('mail_failure_reason')->nullable();
            $t->timestamp('created_at')->useCurrent();

            $t->unique(['broadcast_id', 'recipient_type', 'recipient_id'], 'broadcast_recipients_unique');
            $t->index(['recipient_type', 'recipient_id']);
        });
    }

    public function down(): void { Schema::dropIfExists('broadcast_recipients'); }
};
```

### Step 6: Create `Broadcast` model

Create `app/Models/Broadcast.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BroadcastAudienceType;
use App\Enums\BroadcastStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Broadcast extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title', 'audience_type', 'audience_params', 'channels',
        'template_id', 'sms_body', 'mail_subject', 'mail_body',
        'scheduled_at', 'throttle_overridden', 'throttle_override_reason',
        'status', 'created_by',
        'started_at', 'completed_at',
        'recipient_count', 'sms_sent_count', 'sms_failed_count',
        'sms_throttled_count', 'mail_sent_count', 'mail_failed_count',
    ];

    protected function casts(): array
    {
        return [
            'audience_type'       => BroadcastAudienceType::class,
            'audience_params'     => 'array',
            'channels'            => 'array',
            'status'              => BroadcastStatus::class,
            'throttle_overridden' => 'boolean',
            'scheduled_at'        => 'datetime',
            'started_at'          => 'datetime',
            'completed_at'        => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(BroadcastTemplate::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(BroadcastRecipient::class);
    }

    public function scopeDue(Builder $q): Builder
    {
        return $q->where('status', BroadcastStatus::Scheduled->value)
            ->where('scheduled_at', '<=', now());
    }
}
```

### Step 7: Create `BroadcastTemplate` model

Create `app/Models/BroadcastTemplate.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BroadcastAudienceType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BroadcastTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'audience_type', 'sms_body', 'mail_subject', 'mail_body',
        'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'audience_type' => BroadcastAudienceType::class,
            'is_active'     => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
```

### Step 8: Create `BroadcastRecipient` model

Create `app/Models/BroadcastRecipient.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BroadcastRecipient extends Model
{
    public $timestamps = false;
    protected $fillable = [
        'broadcast_id', 'recipient_type', 'recipient_id',
        'sms_message_id', 'sms_status', 'mail_status', 'mail_failure_reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(Broadcast::class);
    }

    public function smsMessage(): BelongsTo
    {
        return $this->belongsTo(SmsMessage::class);
    }

    /**
     * Polymorphic accessor for the recipient. recipient_type is the FQCN of
     * Member/Employee/User; recipient_id is the PK on that table.
     */
    public function recipient(): MorphTo
    {
        return $this->morphTo();
    }
}
```

### Step 9: Create `BroadcastFactory`

Create `database/factories/BroadcastFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BroadcastAudienceType;
use App\Enums\BroadcastStatus;
use App\Models\Broadcast;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BroadcastFactory extends Factory
{
    protected $model = Broadcast::class;

    public function definition(): array
    {
        return [
            'title'           => 'Test broadcast '.$this->faker->randomNumber(),
            'audience_type'   => BroadcastAudienceType::AllActiveMembers,
            'audience_params' => [],
            'channels'        => ['sms', 'mail'],
            'sms_body'        => 'Hello {{member.name}}, this is a test.',
            'mail_subject'    => 'Test broadcast',
            'mail_body'       => 'Hi {{member.name}}, this is a test broadcast.',
            'status'          => BroadcastStatus::Queued,
            'created_by'      => User::factory(),
        ];
    }
}
```

### Step 10: Create `BroadcastTemplateFactory`

Create `database/factories/BroadcastTemplateFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BroadcastAudienceType;
use App\Models\BroadcastTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BroadcastTemplateFactory extends Factory
{
    protected $model = BroadcastTemplate::class;

    public function definition(): array
    {
        return [
            'name'          => 'Test template '.$this->faker->randomNumber(),
            'audience_type' => BroadcastAudienceType::AllActiveMembers,
            'sms_body'      => 'Hello {{member.name}}, your fees are due.',
            'mail_subject'  => 'Fees reminder',
            'mail_body'     => 'Dear {{member.name}}, your outstanding balance is GHS {{member.outstanding_total}}.',
            'is_active'     => true,
            'created_by'    => User::factory(),
        ];
    }
}
```

### Step 11: Run the migrations + test

```
php artisan migrate --no-interaction
vendor/bin/pest tests/Feature/Messaging/Broadcasts/ModelTest.php
```
Expected: 4 tests pass.

### Step 12: Sanity check existing tests

```
vendor/bin/pest tests/Feature/Messaging/ tests/Unit/Messaging/
```
Expected: all green (no regressions).

### Step 13: Commit

```
git add database/migrations/2026_06_01_*.php app/Models/Broadcast.php app/Models/BroadcastTemplate.php app/Models/BroadcastRecipient.php database/factories/BroadcastFactory.php database/factories/BroadcastTemplateFactory.php tests/Feature/Messaging/Broadcasts/ModelTest.php
git commit -m "feat(broadcasts): migrations + models + factories for N3"
```

---

## Task 3: `AudienceResolver` service

**Files:**
- Create: `app/Services/Messaging/Broadcasts/AudienceResolver.php`
- Test: `tests/Feature/Messaging/Broadcasts/AudienceResolverTest.php`

### Step 1: Write the failing test

Create `tests/Feature/Messaging/Broadcasts/AudienceResolverTest.php`:

```php
<?php

use App\Enums\BroadcastAudienceType;
use App\Enums\EmployeeStatus;
use App\Enums\MemberClass;
use App\Enums\MemberStatus;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Member;
use App\Models\User;
use App\Services\Messaging\Broadcasts\AudienceResolver;

beforeEach(function () {
    $this->resolver = app(AudienceResolver::class);
});

it('resolves AllActiveMembers to active members only', function () {
    Member::factory()->state(['status' => MemberStatus::Active])->count(3)->create();
    Member::factory()->state(['status' => MemberStatus::Resigned])->create();

    $count = $this->resolver->resolve(BroadcastAudienceType::AllActiveMembers, [])->count();

    expect($count)->toBe(3);
});

it('resolves MembersByClass to the given class', function () {
    Member::factory()->state([
        'status' => MemberStatus::Active, 'class' => MemberClass::Professional,
    ])->count(2)->create();
    Member::factory()->state([
        'status' => MemberStatus::Active, 'class' => MemberClass::Student,
    ])->create();

    $count = $this->resolver->resolve(
        BroadcastAudienceType::MembersByClass,
        ['class' => MemberClass::Professional->value],
    )->count();

    expect($count)->toBe(2);
});

it('resolves AllActiveEmployees to active employees only', function () {
    Employee::factory()->state(['status' => EmployeeStatus::Active])->count(4)->create();
    Employee::factory()->state(['status' => EmployeeStatus::Terminated])->create();

    $count = $this->resolver->resolve(BroadcastAudienceType::AllActiveEmployees, [])->count();

    expect($count)->toBe(4);
});

it('resolves EmployeesByDepartment scoped to dept_id', function () {
    $deptA = Department::factory()->create();
    $deptB = Department::factory()->create();
    Employee::factory()->state(['status' => EmployeeStatus::Active, 'department_id' => $deptA->id])->count(3)->create();
    Employee::factory()->state(['status' => EmployeeStatus::Active, 'department_id' => $deptB->id])->create();

    $count = $this->resolver->resolve(
        BroadcastAudienceType::EmployeesByDepartment,
        ['department_id' => $deptA->id],
    )->count();

    expect($count)->toBe(3);
});

it('resolves UsersByPermission via JSON permissions column', function () {
    $u1 = User::factory()->create(['role' => 'employee']);
    $u1->permissions = ['payroll.view']; $u1->save();
    $u2 = User::factory()->create(['role' => 'employee']);
    $u2->permissions = ['payroll.view']; $u2->save();
    User::factory()->create(['role' => 'employee']); // no perm

    $count = $this->resolver->resolve(
        BroadcastAudienceType::UsersByPermission,
        ['permission' => 'payroll.view'],
    )->count();

    expect($count)->toBe(2);
});

it('returns a Builder so DispatchBroadcastJob can chunkById', function () {
    $result = $this->resolver->resolve(BroadcastAudienceType::AllActiveMembers, []);
    expect($result)->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class);
});
```

### Step 2: Run test to verify it fails

```
vendor/bin/pest tests/Feature/Messaging/Broadcasts/AudienceResolverTest.php
```
Expected: FAIL with "class AudienceResolver not found".

### Step 3: Create the resolver

Create `app/Services/Messaging/Broadcasts/AudienceResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Messaging\Broadcasts;

use App\Enums\BroadcastAudienceType;
use App\Enums\EmployeeStatus;
use App\Enums\MemberStatus;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Member;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves a BroadcastAudienceType + params into an Eloquent Builder that
 * the DispatchBroadcastJob will chunkById() through. Each audience type
 * binds to one recipient model class — verify the binding via
 * BroadcastAudienceType::recipientClass().
 */
class AudienceResolver
{
    public function resolve(BroadcastAudienceType $type, array $params): Builder
    {
        return match ($type) {
            BroadcastAudienceType::AllActiveMembers
                => Member::query()->where('status', MemberStatus::Active->value),

            BroadcastAudienceType::MembersByClass
                => Member::query()
                    ->where('status', MemberStatus::Active->value)
                    ->where('class', $params['class'] ?? null),

            BroadcastAudienceType::MembersWithOutstandingFees
                => Member::query()
                    ->where('status', MemberStatus::Active->value)
                    ->whereHas('customer.arInvoices', function ($q) {
                        $q->whereRaw('total > amount_received');
                    }),

            BroadcastAudienceType::AllActiveEmployees
                => Employee::query()->where('status', EmployeeStatus::Active->value),

            BroadcastAudienceType::EmployeesByDepartment
                => Employee::query()
                    ->where('status', EmployeeStatus::Active->value)
                    ->where('department_id', $params['department_id'] ?? null),

            BroadcastAudienceType::UsersByPermission
                => User::query()
                    ->whereJsonContains('permissions', $params['permission'] ?? '__none__'),
        };
    }
}
```

Note: `MembersWithOutstandingFees` uses `whereHas('customer.arInvoices', ...)` because `Member::invoices` is `hasManyThrough` (which `whereHas` can't traverse). The Member→Customer→arInvoices nested has-many is the right path. (Confirm `Customer::arInvoices` relation exists; if it's named `invoices` on Customer, swap the path.)

### Step 4: Run test to verify it passes

```
vendor/bin/pest tests/Feature/Messaging/Broadcasts/AudienceResolverTest.php
```
Expected: 6/6 pass. If `MembersWithOutstandingFees` test missing-relation errors, switch the chain — `grep -n "function arInvoices\|function invoices" app/Models/Customer.php` to find the correct relation name and update the resolver.

### Step 5: Sanity check existing tests

```
vendor/bin/pest tests/Feature/Messaging/
```
Expected: all green.

### Step 6: Commit

```
git add app/Services/Messaging/Broadcasts/AudienceResolver.php tests/Feature/Messaging/Broadcasts/AudienceResolverTest.php
git commit -m "feat(broadcasts): AudienceResolver — 6 pre-defined audience types resolve to Builders"
```

---

## Task 4: `TemplateRenderer` service (whitelist `{{var}}`)

**Files:**
- Create: `app/Services/Messaging/Broadcasts/TemplateRenderer.php`
- Test: `tests/Feature/Messaging/Broadcasts/TemplateRendererTest.php`

### Step 1: Write the failing test

Create `tests/Feature/Messaging/Broadcasts/TemplateRendererTest.php`:

```php
<?php

use App\Enums\BroadcastAudienceType;
use App\Enums\MemberClass;
use App\Enums\MemberStatus;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Member;
use App\Models\User;
use App\Services\Messaging\Broadcasts\TemplateRenderer;

beforeEach(function () {
    $this->renderer = app(TemplateRenderer::class);
});

it('renders member.name and member.member_no for member audience', function () {
    $member = Member::factory()->state([
        'name' => 'Akua Mensah', 'member_no' => 'CIHRM-M-2026-00007',
    ])->create();

    $out = $this->renderer->render(
        'Hello {{member.name}}, your number is {{member.member_no}}.',
        $member,
        BroadcastAudienceType::AllActiveMembers,
    );

    expect($out)->toBe('Hello Akua Mensah, your number is CIHRM-M-2026-00007.');
});

it('renders employee.name + employee.department for employee audience', function () {
    $dept = Department::factory()->state(['name' => 'HR'])->create();
    $user = User::factory()->create(['name' => 'Kofi Asante']);
    $emp = Employee::factory()->for($user, 'user')->state([
        'department_id' => $dept->id,
    ])->create();

    $out = $this->renderer->render(
        'Hi {{employee.name}}, you are in {{employee.department}}.',
        $emp,
        BroadcastAudienceType::AllActiveEmployees,
    );

    expect($out)->toContain('Kofi Asante');
    expect($out)->toContain('HR');
});

it('renders org_name and today universally', function () {
    config(['app.name' => 'CIHRMS Test']);
    $member = Member::factory()->create();

    $out = $this->renderer->render(
        'From {{org_name}} on {{today}}',
        $member,
        BroadcastAudienceType::AllActiveMembers,
    );

    expect($out)->toStartWith('From CIHRMS Test on ');
});

it('renders unknown vars as empty string (silent skip)', function () {
    $member = Member::factory()->create();

    $out = $this->renderer->render(
        'Hello {{member.name}} {{nonsense.field}} end.',
        $member,
        BroadcastAudienceType::AllActiveMembers,
    );

    expect($out)->toContain('end.');
    expect($out)->not->toContain('{{nonsense.field}}');
});

it('NEVER reads non-whitelisted attributes (e.g. password)', function () {
    $user = User::factory()->create(['password' => bcrypt('secret123')]);

    $out = $this->renderer->render(
        'Hi {{user.name}}, your password is {{user.password}}.',
        $user,
        BroadcastAudienceType::UsersByPermission,
    );

    expect($out)->not->toContain('secret123');
    expect($out)->not->toContain('$2y$');
    // The non-whitelisted var renders as empty
    expect($out)->toContain('your password is .');
});
```

### Step 2: Run test to verify it fails

```
vendor/bin/pest tests/Feature/Messaging/Broadcasts/TemplateRendererTest.php
```
Expected: FAIL with "class TemplateRenderer not found".

### Step 3: Create the renderer

Create `app/Services/Messaging/Broadcasts/TemplateRenderer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Messaging\Broadcasts;

use App\Enums\BroadcastAudienceType;
use App\Models\Employee;
use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Whitelist-enforced {{var}} interpolation. The audience type binds the
 * recipient class + allowed variable names. Unknown vars render as empty
 * string — vars outside the whitelist NEVER reach $recipient introspection,
 * preventing accidental leak of sensitive attributes like `user.password`.
 */
class TemplateRenderer
{
    public function render(string $body, object $recipient, BroadcastAudienceType $type): string
    {
        $allowed = $type->allowedVariables();

        return preg_replace_callback('/\{\{\s*([a-z_][a-z_0-9]*(?:\.[a-z_][a-z_0-9]*)?)\s*\}\}/i',
            function ($m) use ($allowed, $recipient) {
                $var = $m[1];
                if (! in_array($var, $allowed, true)) {
                    return '';
                }
                return $this->resolveVariable($var, $recipient);
            },
            $body,
        );
    }

    private function resolveVariable(string $var, object $recipient): string
    {
        return match ($var) {
            'org_name' => (string) config('app.name'),
            'today'    => Carbon::now()->toDateString(),

            'member.name'              => (string) ($recipient instanceof Member ? $recipient->name : ''),
            'member.member_no'         => (string) ($recipient instanceof Member ? $recipient->member_no : ''),
            'member.class'             => $recipient instanceof Member ? $recipient->class?->label() ?? '' : '',
            'member.outstanding_total' => $recipient instanceof Member ? (string) $this->memberOutstanding($recipient) : '',
            'member.next_due_date'     => $recipient instanceof Member ? $this->memberNextDue($recipient) : '',

            'employee.name'       => $recipient instanceof Employee ? (string) ($recipient->user?->name ?? '') : '',
            'employee.staff_id'   => $recipient instanceof Employee ? (string) ($recipient->staff_id ?? '') : '',
            'employee.department' => $recipient instanceof Employee ? (string) ($recipient->department?->name ?? '') : '',
            'employee.position'   => $recipient instanceof Employee ? (string) ($recipient->position?->name ?? '') : '',

            'user.name' => $recipient instanceof User ? (string) ($recipient->name ?? '') : '',
            'user.role' => $recipient instanceof User ? (string) ($recipient->role ?? '') : '',

            default => '',
        };
    }

    private function memberOutstanding(Member $member): float
    {
        // Sum (total - amount_received) across the member's customer's open AR invoices.
        return (float) $member->invoices()
            ->selectRaw('COALESCE(SUM(total - amount_received), 0) as outstanding')
            ->value('outstanding');
    }

    private function memberNextDue(Member $member): string
    {
        $next = $member->assignments()
            ->whereNotNull('due_date')
            ->orderBy('due_date')
            ->first();
        return $next?->due_date?->toDateString() ?? '';
    }
}
```

### Step 4: Run test to verify it passes

```
vendor/bin/pest tests/Feature/Messaging/Broadcasts/TemplateRendererTest.php
```
Expected: 5/5 pass. If `member.outstanding_total` test fails on the optional case, the selectRaw + Postgres-vs-SQLite compatibility might need a `whereRaw('total > amount_received')` filter first — adjust the SQL.

### Step 5: Commit

```
git add app/Services/Messaging/Broadcasts/TemplateRenderer.php tests/Feature/Messaging/Broadcasts/TemplateRendererTest.php
git commit -m "feat(broadcasts): TemplateRenderer with audience-typed whitelist (no attribute leak)"
```

---

## Task 5: `BroadcastService` (queue + cancel)

**Files:**
- Create: `app/Services/Messaging/Broadcasts/BroadcastService.php`
- Test: `tests/Feature/Messaging/Broadcasts/BroadcastServiceTest.php`

### Step 1: Write the failing test

Create `tests/Feature/Messaging/Broadcasts/BroadcastServiceTest.php`:

```php
<?php

use App\Enums\BroadcastStatus;
use App\Jobs\Messaging\DispatchBroadcastJob;
use App\Models\Broadcast;
use App\Models\User;
use App\Services\Messaging\Broadcasts\BroadcastService;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    $this->service = app(BroadcastService::class);
    Bus::fake([DispatchBroadcastJob::class]);
});

it('immediately dispatches DispatchBroadcastJob when no scheduled_at set', function () {
    $admin = User::factory()->create();
    $b = Broadcast::factory()->state([
        'created_by'   => $admin->id,
        'scheduled_at' => null,
        'status'       => BroadcastStatus::Queued,
    ])->create();

    $this->service->queue($b);

    Bus::assertDispatched(DispatchBroadcastJob::class, fn ($j) => $j->broadcastId === $b->id);
    expect($b->fresh()->status)->toBe(BroadcastStatus::Queued);
});

it('does NOT dispatch when scheduled_at is in the future', function () {
    $admin = User::factory()->create();
    $b = Broadcast::factory()->state([
        'created_by'   => $admin->id,
        'scheduled_at' => now()->addHours(2),
        'status'       => BroadcastStatus::Scheduled,
    ])->create();

    $this->service->queue($b);

    Bus::assertNotDispatched(DispatchBroadcastJob::class);
    expect($b->fresh()->status)->toBe(BroadcastStatus::Scheduled);
});

it('cancel flips Scheduled to Cancelled', function () {
    $admin = User::factory()->create();
    $b = Broadcast::factory()->state([
        'created_by'   => $admin->id,
        'scheduled_at' => now()->addHours(2),
        'status'       => BroadcastStatus::Scheduled,
    ])->create();

    $this->service->cancel($b);

    expect($b->fresh()->status)->toBe(BroadcastStatus::Cancelled);
});

it('cancel refuses to cancel a Completed broadcast', function () {
    $admin = User::factory()->create();
    $b = Broadcast::factory()->state([
        'created_by' => $admin->id,
        'status'     => BroadcastStatus::Completed,
    ])->create();

    expect(fn () => $this->service->cancel($b))->toThrow(\DomainException::class);
});
```

### Step 2: Run test to verify it fails

```
vendor/bin/pest tests/Feature/Messaging/Broadcasts/BroadcastServiceTest.php
```
Expected: FAIL.

### Step 3: Create the service

Create `app/Services/Messaging/Broadcasts/BroadcastService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Messaging\Broadcasts;

use App\Enums\BroadcastStatus;
use App\Jobs\Messaging\DispatchBroadcastJob;
use App\Models\Broadcast;

class BroadcastService
{
    /**
     * Persist + queue or schedule a Broadcast. If scheduled_at is in the
     * future, the row stays at Scheduled and the messaging:fire-due-broadcasts
     * scheduler picks it up. Otherwise DispatchBroadcastJob fires now.
     */
    public function queue(Broadcast $broadcast): void
    {
        if ($broadcast->scheduled_at && $broadcast->scheduled_at->isFuture()) {
            $broadcast->update(['status' => BroadcastStatus::Scheduled->value]);
            return;
        }

        $broadcast->update(['status' => BroadcastStatus::Queued->value]);
        DispatchBroadcastJob::dispatch($broadcast->id);
    }

    /**
     * Cancel a Scheduled or Queued broadcast. Errors if the broadcast is
     * already terminal or in-flight.
     */
    public function cancel(Broadcast $broadcast): void
    {
        if (! in_array($broadcast->status, [BroadcastStatus::Scheduled, BroadcastStatus::Queued], true)) {
            throw new \DomainException("Cannot cancel broadcast in status {$broadcast->status->value}.");
        }
        $broadcast->update(['status' => BroadcastStatus::Cancelled->value]);
    }
}
```

### Step 4: Run test to verify it passes

The first test will fail until `DispatchBroadcastJob` exists (Task 6). The other 3 should pass. Expected: 3/4 pass, 1 fail (`class DispatchBroadcastJob not found`).

This is acceptable — Task 6 wires the job and the failing test will green up. Commit anyway with concerns noted.

### Step 5: Commit (with concerns)

```
git add app/Services/Messaging/Broadcasts/BroadcastService.php tests/Feature/Messaging/Broadcasts/BroadcastServiceTest.php
git commit -m "feat(broadcasts): BroadcastService queue + cancel (DispatchBroadcastJob dep in next task)"
```

---

## Task 6: `DispatchBroadcastJob`

**Files:**
- Create: `app/Jobs/Messaging/DispatchBroadcastJob.php`
- Test: `tests/Feature/Messaging/Broadcasts/DispatchBroadcastJobTest.php`

### Step 1: Write the failing test

Create `tests/Feature/Messaging/Broadcasts/DispatchBroadcastJobTest.php`:

```php
<?php

use App\Enums\BroadcastAudienceType;
use App\Enums\BroadcastStatus;
use App\Enums\MemberStatus;
use App\Enums\SmsStatus;
use App\Jobs\Messaging\DispatchBroadcastJob;
use App\Jobs\Messaging\SendSmsJob;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Customer;
use App\Models\Member;
use App\Models\SmsMessage;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    Bus::fake([SendSmsJob::class]);
    Mail::fake();
    RateLimiter::clear('sms:marketing:+233200000099');
});

function makeMember(string $phone = null, string $email = null): Member
{
    $customer = Customer::factory()->create();
    return Member::factory()->state([
        'status'      => MemberStatus::Active,
        'customer_id' => $customer->id,
        'phone'       => $phone,
        'email'       => $email,
    ])->create();
}

it('dispatches SMS + mail per recipient and records BroadcastRecipient rows', function () {
    $admin = User::factory()->create();
    makeMember(phone: '+233200000099', email: 'a@example.com');
    makeMember(phone: '+233200000088', email: 'b@example.com');

    $b = Broadcast::factory()->state([
        'created_by'    => $admin->id,
        'audience_type' => BroadcastAudienceType::AllActiveMembers,
        'channels'      => ['sms', 'mail'],
        'status'        => BroadcastStatus::Queued,
        'sms_body'      => 'Hi {{member.name}}',
        'mail_subject'  => 'Test',
        'mail_body'     => 'Hi {{member.name}}',
    ])->create();

    (new DispatchBroadcastJob($b->id))->handle(
        app(\App\Services\Messaging\Broadcasts\AudienceResolver::class),
        app(\App\Services\Messaging\Broadcasts\TemplateRenderer::class),
        app(\App\Services\Messaging\Sms\SmsDispatcher::class),
    );

    $b->refresh();
    expect($b->status)->toBe(BroadcastStatus::Completed);
    expect($b->recipient_count)->toBe(2);
    expect($b->sms_sent_count)->toBe(2);
    expect($b->mail_sent_count)->toBe(2);
    expect(BroadcastRecipient::where('broadcast_id', $b->id)->count())->toBe(2);
    Bus::assertDispatchedTimes(SendSmsJob::class, 2);
});

it('skips SMS leg for recipients without a phone', function () {
    $admin = User::factory()->create();
    makeMember(phone: null, email: 'noPhone@example.com');

    $b = Broadcast::factory()->state([
        'created_by'    => $admin->id,
        'audience_type' => BroadcastAudienceType::AllActiveMembers,
        'channels'      => ['sms', 'mail'],
        'status'        => BroadcastStatus::Queued,
        'sms_body'      => 'sms body',
        'mail_subject'  => 'mail subject',
        'mail_body'     => 'mail body',
    ])->create();

    (new DispatchBroadcastJob($b->id))->handle(
        app(\App\Services\Messaging\Broadcasts\AudienceResolver::class),
        app(\App\Services\Messaging\Broadcasts\TemplateRenderer::class),
        app(\App\Services\Messaging\Sms\SmsDispatcher::class),
    );

    $b->refresh();
    expect($b->sms_sent_count)->toBe(0);
    expect($b->mail_sent_count)->toBe(1);
    $r = BroadcastRecipient::where('broadcast_id', $b->id)->first();
    expect($r->sms_status)->toBe('Skipped');
    expect($r->mail_status)->toBe('Sent');
    Bus::assertNotDispatched(SendSmsJob::class);
});

it('marks SMS leg Throttled when sms:marketing limiter is hit', function () {
    $admin = User::factory()->create();
    $phone = '+233200000099';
    makeMember(phone: $phone, email: 'a@example.com');

    // Pre-fill the limiter to its cap of 5
    for ($i = 0; $i < 5; $i++) {
        RateLimiter::hit("sms:marketing:{$phone}", 3600);
    }

    $b = Broadcast::factory()->state([
        'created_by'          => $admin->id,
        'audience_type'       => BroadcastAudienceType::AllActiveMembers,
        'channels'            => ['sms'],
        'status'              => BroadcastStatus::Queued,
        'sms_body'            => 'should not send',
        'throttle_overridden' => false,
    ])->create();

    (new DispatchBroadcastJob($b->id))->handle(
        app(\App\Services\Messaging\Broadcasts\AudienceResolver::class),
        app(\App\Services\Messaging\Broadcasts\TemplateRenderer::class),
        app(\App\Services\Messaging\Sms\SmsDispatcher::class),
    );

    $b->refresh();
    expect($b->sms_throttled_count)->toBe(1);
    expect($b->sms_sent_count)->toBe(0);
    expect(BroadcastRecipient::where('broadcast_id', $b->id)->first()->sms_status)->toBe('Throttled');
    Bus::assertNotDispatched(SendSmsJob::class);
});

it('bypasses limiter when throttle_overridden=true', function () {
    $admin = User::factory()->create();
    $phone = '+233200000099';
    makeMember(phone: $phone, email: 'a@example.com');

    for ($i = 0; $i < 5; $i++) {
        RateLimiter::hit("sms:marketing:{$phone}", 3600);
    }

    $b = Broadcast::factory()->state([
        'created_by'               => $admin->id,
        'audience_type'            => BroadcastAudienceType::AllActiveMembers,
        'channels'                 => ['sms'],
        'status'                   => BroadcastStatus::Queued,
        'sms_body'                 => 'urgent',
        'throttle_overridden'      => true,
        'throttle_override_reason' => 'AGM tomorrow',
    ])->create();

    (new DispatchBroadcastJob($b->id))->handle(
        app(\App\Services\Messaging\Broadcasts\AudienceResolver::class),
        app(\App\Services\Messaging\Broadcasts\TemplateRenderer::class),
        app(\App\Services\Messaging\Sms\SmsDispatcher::class),
    );

    $b->refresh();
    expect($b->sms_sent_count)->toBe(1);
    expect($b->sms_throttled_count)->toBe(0);
    Bus::assertDispatchedTimes(SendSmsJob::class, 1);
});

it('is idempotent — does not double-send on rerun', function () {
    $admin = User::factory()->create();
    makeMember(phone: '+233200000099', email: 'a@example.com');

    $b = Broadcast::factory()->state([
        'created_by'    => $admin->id,
        'audience_type' => BroadcastAudienceType::AllActiveMembers,
        'channels'      => ['mail'],
        'status'        => BroadcastStatus::Queued,
        'mail_subject'  => 's',
        'mail_body'     => 'b',
    ])->create();

    // First run
    (new DispatchBroadcastJob($b->id))->handle(
        app(\App\Services\Messaging\Broadcasts\AudienceResolver::class),
        app(\App\Services\Messaging\Broadcasts\TemplateRenderer::class),
        app(\App\Services\Messaging\Sms\SmsDispatcher::class),
    );

    // Reset status to Queued (simulating a worker crash + redispatch)
    $b->update(['status' => BroadcastStatus::Queued->value]);

    // Second run
    (new DispatchBroadcastJob($b->id))->handle(
        app(\App\Services\Messaging\Broadcasts\AudienceResolver::class),
        app(\App\Services\Messaging\Broadcasts\TemplateRenderer::class),
        app(\App\Services\Messaging\Sms\SmsDispatcher::class),
    );

    expect(BroadcastRecipient::where('broadcast_id', $b->id)->count())->toBe(1);
});
```

### Step 2: Run test to verify it fails

```
vendor/bin/pest tests/Feature/Messaging/Broadcasts/DispatchBroadcastJobTest.php
```
Expected: FAIL — "class DispatchBroadcastJob not found".

### Step 3: Create the job

Create `app/Jobs/Messaging/DispatchBroadcastJob.php`:

```php
<?php

declare(strict_types=1);

namespace App\Jobs\Messaging;

use App\Enums\BroadcastChannel;
use App\Enums\BroadcastStatus;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Employee;
use App\Models\Member;
use App\Models\User;
use App\Services\Messaging\Broadcasts\AudienceResolver;
use App\Services\Messaging\Broadcasts\TemplateRenderer;
use App\Services\Messaging\Sms\SmsDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Drives a Broadcast end-to-end: resolves the audience, chunks 100 at a
 * time, renders body per recipient via TemplateRenderer, fires SMS via the
 * N1 async SmsDispatcher and mail via Mail::raw. Each recipient produces
 * one BroadcastRecipient row; the unique constraint on
 * (broadcast_id, recipient_type, recipient_id) makes the job idempotent
 * on retry.
 */
class DispatchBroadcastJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $broadcastId) {}

    public function handle(
        AudienceResolver $resolver,
        TemplateRenderer $renderer,
        SmsDispatcher $sms,
    ): void {
        $broadcast = Broadcast::find($this->broadcastId);
        if (! $broadcast) {
            Log::info('DispatchBroadcastJob skipped — broadcast missing', ['id' => $this->broadcastId]);
            return;
        }

        // Idempotency guard: only Queued broadcasts proceed.
        if ($broadcast->status !== BroadcastStatus::Queued) {
            return;
        }

        $broadcast->update([
            'status'     => BroadcastStatus::Sending->value,
            'started_at' => now(),
        ]);

        $type = $broadcast->audience_type;
        $params = $broadcast->audience_params ?? [];
        $channels = $broadcast->channels ?? [];
        $hasSms  = in_array(BroadcastChannel::Sms->value, $channels, true);
        $hasMail = in_array(BroadcastChannel::Mail->value, $channels, true);

        $counts = [
            'recipient_count'     => 0,
            'sms_sent_count'      => 0,
            'sms_failed_count'    => 0,
            'sms_throttled_count' => 0,
            'mail_sent_count'     => 0,
            'mail_failed_count'   => 0,
        ];

        $resolver->resolve($type, $params)
            ->chunkById(100, function ($chunk) use (
                $broadcast, $type, $renderer, $sms, $hasSms, $hasMail, &$counts,
            ) {
                foreach ($chunk as $recipient) {
                    $counts['recipient_count']++;

                    $recipientClass = $type->recipientClass();
                    // Idempotency: skip if BroadcastRecipient row already exists
                    $exists = BroadcastRecipient::where([
                        'broadcast_id'   => $broadcast->id,
                        'recipient_type' => $recipientClass,
                        'recipient_id'   => $recipient->id,
                    ])->exists();
                    if ($exists) continue;

                    $row = ['broadcast_id' => $broadcast->id,
                            'recipient_type' => $recipientClass,
                            'recipient_id' => $recipient->id,
                            'created_at' => now()];

                    // SMS leg
                    if ($hasSms) {
                        $phone = $this->phoneOf($recipient);
                        if (! $phone) {
                            $row['sms_status'] = 'Skipped';
                        } elseif (! $broadcast->throttle_overridden
                                  && RateLimiter::tooManyAttempts("sms:marketing:{$phone}", 5)) {
                            $row['sms_status'] = 'Throttled';
                            $counts['sms_throttled_count']++;
                        } else {
                            $body = $renderer->render($broadcast->sms_body ?? '', $recipient, $type);
                            try {
                                $msg = $sms->send(
                                    toPhone:     $phone,
                                    body:        $body,
                                    contextType: 'broadcast',
                                    contextId:   $broadcast->id,
                                );
                                $row['sms_status']     = 'Sent';
                                $row['sms_message_id'] = $msg->id;
                                $counts['sms_sent_count']++;
                                if (! $broadcast->throttle_overridden) {
                                    RateLimiter::hit("sms:marketing:{$phone}", 3600);
                                }
                            } catch (\Throwable $e) {
                                $row['sms_status'] = 'Failed';
                                $counts['sms_failed_count']++;
                            }
                        }
                    }

                    // Mail leg
                    if ($hasMail) {
                        $email = $this->emailOf($recipient);
                        if (! $email) {
                            $row['mail_status'] = 'Skipped';
                        } else {
                            try {
                                $body = $renderer->render($broadcast->mail_body ?? '', $recipient, $type);
                                $subject = $renderer->render($broadcast->mail_subject ?? '', $recipient, $type);
                                Mail::raw($body, function ($m) use ($email, $subject) {
                                    $m->to($email)->subject($subject);
                                });
                                $row['mail_status'] = 'Sent';
                                $counts['mail_sent_count']++;
                            } catch (\Throwable $e) {
                                $row['mail_status'] = 'Failed';
                                $row['mail_failure_reason'] = $e->getMessage();
                                $counts['mail_failed_count']++;
                            }
                        }
                    }

                    BroadcastRecipient::create($row);
                }
            });

        $broadcast->update($counts + [
            'status'       => BroadcastStatus::Completed->value,
            'completed_at' => now(),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $b = Broadcast::find($this->broadcastId);
        if ($b && ! $b->status->isTerminal()) {
            $b->update(['status' => BroadcastStatus::Failed->value]);
        }
    }

    private function phoneOf(object $recipient): ?string
    {
        if ($recipient instanceof Member)   return $recipient->phone ?: null;
        if ($recipient instanceof Employee) return $recipient->phone ?: null;
        if ($recipient instanceof User)     return $recipient->employee?->phone ?: null;
        return null;
    }

    private function emailOf(object $recipient): ?string
    {
        if ($recipient instanceof Member)   return $recipient->email ?: null;
        if ($recipient instanceof Employee) return $recipient->user?->email ?: null;
        if ($recipient instanceof User)     return $recipient->email ?: null;
        return null;
    }
}
```

### Step 4: Run test to verify it passes

```
vendor/bin/pest tests/Feature/Messaging/Broadcasts/DispatchBroadcastJobTest.php
```
Expected: 5/5 pass.

Then re-run Task 5's `BroadcastServiceTest` — the previously-failing test should now pass:

```
vendor/bin/pest tests/Feature/Messaging/Broadcasts/BroadcastServiceTest.php
```
Expected: 4/4 pass.

### Step 5: Full sanity

```
vendor/bin/pest tests/Feature/Messaging/ tests/Unit/Messaging/
```
Expected: all green.

### Step 6: Commit

```
git add app/Jobs/Messaging/DispatchBroadcastJob.php tests/Feature/Messaging/Broadcasts/DispatchBroadcastJobTest.php
git commit -m "feat(broadcasts): DispatchBroadcastJob — chunked fan-out with throttle + idempotency"
```

---

## Task 7: `FireDueBroadcastsCommand` + scheduler entry

**Files:**
- Create: `app/Console/Commands/FireDueBroadcastsCommand.php`
- Modify: `routes/console.php` — schedule the command every minute
- Test: `tests/Feature/Messaging/Broadcasts/SchedulerTest.php`

### Step 1: Write the failing test

Create `tests/Feature/Messaging/Broadcasts/SchedulerTest.php`:

```php
<?php

use App\Enums\BroadcastStatus;
use App\Jobs\Messaging\DispatchBroadcastJob;
use App\Models\Broadcast;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Bus::fake([DispatchBroadcastJob::class]);
});

it('fires a Scheduled broadcast whose scheduled_at has passed', function () {
    $admin = User::factory()->create();
    $b = Broadcast::factory()->state([
        'created_by'   => $admin->id,
        'status'       => BroadcastStatus::Scheduled,
    ])->create();
    $b->scheduled_at = now()->subMinutes(2);
    $b->save();

    $this->artisan('messaging:fire-due-broadcasts')->assertSuccessful();

    Bus::assertDispatched(DispatchBroadcastJob::class, fn ($j) => $j->broadcastId === $b->id);
    expect($b->fresh()->status)->toBe(BroadcastStatus::Queued);
});

it('does not fire a future-scheduled broadcast', function () {
    $admin = User::factory()->create();
    $b = Broadcast::factory()->state([
        'created_by'   => $admin->id,
        'scheduled_at' => now()->addHours(2),
        'status'       => BroadcastStatus::Scheduled,
    ])->create();

    $this->artisan('messaging:fire-due-broadcasts')->assertSuccessful();

    Bus::assertNotDispatched(DispatchBroadcastJob::class);
    expect($b->fresh()->status)->toBe(BroadcastStatus::Scheduled);
});
```

### Step 2: Run test to verify it fails

```
vendor/bin/pest tests/Feature/Messaging/Broadcasts/SchedulerTest.php
```
Expected: FAIL — command not found.

### Step 3: Create the command

Create `app/Console/Commands/FireDueBroadcastsCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BroadcastStatus;
use App\Jobs\Messaging\DispatchBroadcastJob;
use App\Models\Broadcast;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Picks up broadcasts in Scheduled status whose scheduled_at <= now(),
 * flips them to Queued, and dispatches DispatchBroadcastJob. Designed to
 * run every minute via the scheduler.
 */
class FireDueBroadcastsCommand extends Command
{
    protected $signature = 'messaging:fire-due-broadcasts';
    protected $description = 'Dispatch any scheduled broadcasts whose scheduled_at has passed.';

    public function handle(): int
    {
        $count = 0;
        Broadcast::due()->get()->each(function (Broadcast $b) use (&$count) {
            $b->update(['status' => BroadcastStatus::Queued->value]);
            DispatchBroadcastJob::dispatch($b->id);
            $count++;
        });

        $this->info("Fired {$count} due broadcast(s).");
        Log::info('messaging:fire-due-broadcasts', ['count' => $count]);
        return self::SUCCESS;
    }
}
```

### Step 4: Schedule the command

Open `routes/console.php`. Find the existing `Schedule::command('messaging:sweep-stuck-sms')` block. After it, add:

```php

// N3 — pick up scheduled admin broadcasts whose scheduled_at has passed
// and queue them for the DispatchBroadcastJob.
Schedule::command('messaging:fire-due-broadcasts')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();
```

### Step 5: Run test + sanity

```
vendor/bin/pest tests/Feature/Messaging/Broadcasts/SchedulerTest.php
vendor/bin/pest tests/Feature/Messaging/ tests/Unit/Messaging/
```
Expected: 2/2 + full pass.

Confirm the schedule registers:

```
php artisan schedule:list | grep "messaging:fire-due-broadcasts"
```
Expected: one line listing the cron expression `* * * * *`.

### Step 6: Commit

```
git add app/Console/Commands/FireDueBroadcastsCommand.php routes/console.php tests/Feature/Messaging/Broadcasts/SchedulerTest.php
git commit -m "feat(broadcasts): messaging:fire-due-broadcasts command + 1-minute scheduler"
```

---

## Task 8: `BroadcastController` + routes + FormRequest

**Files:**
- Create: `app/Http/Controllers/BroadcastController.php`
- Create: `app/Http/Requests/Broadcast/StoreBroadcastRequest.php`
- Modify: `routes/web.php` — add Broadcast routes under existing `/admin/messaging` prefix
- Test: `tests/Feature/Messaging/Broadcasts/BroadcastControllerTest.php`

### Step 1: Write the failing test

Create `tests/Feature/Messaging/Broadcasts/BroadcastControllerTest.php`:

```php
<?php

use App\Enums\BroadcastAudienceType;
use App\Enums\BroadcastStatus;
use App\Models\Broadcast;
use App\Models\Member;
use App\Models\User;

it('requires broadcasts.view to access index', function () {
    $user = User::factory()->create(['role' => 'employee']);
    $this->actingAs($user)->get(route('messaging.broadcasts.index'))->assertForbidden();

    $admin = User::factory()->create(['role' => 'employee']);
    $admin->permissions = ['broadcasts.view'];
    $admin->save();
    $this->actingAs($admin)->get(route('messaging.broadcasts.index'))->assertOk();
});

it('store creates a Broadcast and dispatches via BroadcastService', function () {
    $admin = User::factory()->create(['role' => 'employee']);
    $admin->permissions = ['broadcasts.manage'];
    $admin->save();
    Member::factory()->count(3)->create();

    $this->actingAs($admin)->post(route('messaging.broadcasts.store'), [
        'title'            => 'Test broadcast',
        'audience_type'    => BroadcastAudienceType::AllActiveMembers->value,
        'audience_params'  => [],
        'channels'         => ['mail'],
        'mail_subject'     => 'Hi',
        'mail_body'        => 'Body',
    ])->assertRedirect();

    expect(Broadcast::count())->toBe(1);
    expect(Broadcast::first()->title)->toBe('Test broadcast');
});

it('rejects empty channels selection', function () {
    $admin = User::factory()->create(['role' => 'employee']);
    $admin->permissions = ['broadcasts.manage'];
    $admin->save();

    $this->actingAs($admin)->post(route('messaging.broadcasts.store'), [
        'title'           => 'No channels',
        'audience_type'   => BroadcastAudienceType::AllActiveMembers->value,
        'audience_params' => [],
        'channels'        => [],
    ])->assertSessionHasErrors('channels');
});

it('throttle override requires the bypass permission AND a reason', function () {
    $admin = User::factory()->create(['role' => 'employee']);
    $admin->permissions = ['broadcasts.manage']; // no bypass perm
    $admin->save();

    // Without bypass perm: throttle_overridden=true is ignored OR rejected
    $this->actingAs($admin)->post(route('messaging.broadcasts.store'), [
        'title'               => 't',
        'audience_type'       => BroadcastAudienceType::AllActiveMembers->value,
        'audience_params'     => [],
        'channels'            => ['sms'],
        'sms_body'            => 'b',
        'throttle_overridden' => true,
        'throttle_override_reason' => 'urgent',
    ])->assertForbidden();

    // With bypass perm, but no reason: validation rejects
    $admin->permissions = ['broadcasts.manage', 'broadcasts.bypass_throttle'];
    $admin->save();

    $this->actingAs($admin)->post(route('messaging.broadcasts.store'), [
        'title'               => 't',
        'audience_type'       => BroadcastAudienceType::AllActiveMembers->value,
        'audience_params'     => [],
        'channels'            => ['sms'],
        'sms_body'            => 'b',
        'throttle_overridden' => true,
    ])->assertSessionHasErrors('throttle_override_reason');

    // With both: ok
    $this->actingAs($admin)->post(route('messaging.broadcasts.store'), [
        'title'               => 't',
        'audience_type'       => BroadcastAudienceType::AllActiveMembers->value,
        'audience_params'     => [],
        'channels'            => ['sms'],
        'sms_body'            => 'b',
        'throttle_overridden' => true,
        'throttle_override_reason' => 'AGM tomorrow',
    ])->assertRedirect();
});

it('cancel only works on Scheduled or Queued', function () {
    $admin = User::factory()->create(['role' => 'employee']);
    $admin->permissions = ['broadcasts.manage'];
    $admin->save();

    $scheduled = Broadcast::factory()->state([
        'created_by' => $admin->id,
        'status'     => BroadcastStatus::Scheduled,
        'scheduled_at' => now()->addHours(2),
    ])->create();

    $this->actingAs($admin)->post(route('messaging.broadcasts.cancel', $scheduled))->assertRedirect();
    expect($scheduled->fresh()->status)->toBe(BroadcastStatus::Cancelled);

    $completed = Broadcast::factory()->state([
        'created_by' => $admin->id,
        'status'     => BroadcastStatus::Completed,
    ])->create();

    $this->actingAs($admin)->post(route('messaging.broadcasts.cancel', $completed))->assertStatus(422);
});
```

### Step 2: Run test to verify it fails

```
vendor/bin/pest tests/Feature/Messaging/Broadcasts/BroadcastControllerTest.php
```
Expected: FAIL — routes don't exist.

### Step 3: Create the FormRequest

Create `app/Http/Requests/Broadcast/StoreBroadcastRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Broadcast;

use App\Enums\BroadcastAudienceType;
use App\Enums\BroadcastChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBroadcastRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->user()->hasPermission('broadcasts.manage')) {
            return false;
        }
        // Override requires the bypass perm
        if ($this->boolean('throttle_overridden')
            && ! $this->user()->hasPermission('broadcasts.bypass_throttle')) {
            return false;
        }
        return true;
    }

    public function rules(): array
    {
        return [
            'title'                    => ['required', 'string', 'max:150'],
            'audience_type'            => ['required', Rule::enum(BroadcastAudienceType::class)],
            'audience_params'          => ['present', 'array'],
            'channels'                 => ['required', 'array', 'min:1'],
            'channels.*'               => [Rule::enum(BroadcastChannel::class)],
            'template_id'              => ['nullable', 'integer', 'exists:broadcast_templates,id'],
            'sms_body'                 => ['nullable', 'string', 'max:1600'],
            'mail_subject'             => ['nullable', 'string', 'max:150'],
            'mail_body'                => ['nullable', 'string'],
            'scheduled_at'             => ['nullable', 'date', 'after:now'],
            'throttle_overridden'      => ['boolean'],
            'throttle_override_reason' => ['nullable', 'string', 'max:255',
                                            Rule::requiredIf(fn () => $this->boolean('throttle_overridden'))],
        ];
    }
}
```

### Step 4: Create the controller

Create `app/Http/Controllers/BroadcastController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BroadcastAudienceType;
use App\Enums\BroadcastStatus;
use App\Http\Requests\Broadcast\StoreBroadcastRequest;
use App\Models\Broadcast;
use App\Models\BroadcastTemplate;
use App\Services\Messaging\Broadcasts\AudienceResolver;
use App\Services\Messaging\Broadcasts\BroadcastService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BroadcastController extends Controller
{
    public function __construct(
        private readonly BroadcastService $service,
        private readonly AudienceResolver $resolver,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->hasPermission('broadcasts.view'), 403);

        $broadcasts = Broadcast::with('creator:id,name')
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Messaging/Broadcasts/Index', [
            'activeModule' => 'messaging-broadcasts',
            'broadcasts'   => $broadcasts,
            'filters'      => $request->only('status'),
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->hasPermission('broadcasts.manage'), 403);

        return Inertia::render('Messaging/Broadcasts/Create', [
            'activeModule' => 'messaging-broadcasts',
            'audienceTypes' => collect(BroadcastAudienceType::cases())->map(fn ($t) => [
                'value'  => $t->value,
                'label'  => str($t->name)->headline()->toString(),
                'allowedVars' => $t->allowedVariables(),
            ]),
            'templates' => BroadcastTemplate::where('is_active', true)
                ->get(['id', 'name', 'audience_type', 'sms_body', 'mail_subject', 'mail_body']),
            'canBypassThrottle' => $request->user()->hasPermission('broadcasts.bypass_throttle'),
        ]);
    }

    public function store(StoreBroadcastRequest $request): RedirectResponse
    {
        $broadcast = Broadcast::create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
            'status'     => $request->scheduled_at
                ? BroadcastStatus::Scheduled->value
                : BroadcastStatus::Queued->value,
        ]);

        $this->service->queue($broadcast);

        return redirect()->route('messaging.broadcasts.show', $broadcast)
            ->with('success', "Broadcast '{$broadcast->title}' queued.");
    }

    public function show(Request $request, Broadcast $broadcast): Response
    {
        abort_unless($request->user()->hasPermission('broadcasts.view'), 403);

        $recipients = $broadcast->recipients()
            ->paginate(50);

        return Inertia::render('Messaging/Broadcasts/Show', [
            'activeModule' => 'messaging-broadcasts',
            'broadcast'    => $broadcast->loadMissing('creator:id,name', 'template'),
            'recipients'   => $recipients,
        ]);
    }

    public function cancel(Request $request, Broadcast $broadcast): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('broadcasts.manage'), 403);

        try {
            $this->service->cancel($broadcast);
        } catch (\DomainException $e) {
            abort(422, $e->getMessage());
        }

        return back()->with('success', 'Broadcast cancelled.');
    }

    public function preview(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('broadcasts.manage'), 403);

        $type = BroadcastAudienceType::from($request->input('audience_type'));
        $params = $request->input('audience_params', []);

        $builder = $this->resolver->resolve($type, is_array($params) ? $params : []);
        $count = $builder->count();
        $sample = $builder->limit(10)->get()->map(fn ($r) => ['id' => $r->id, 'name' => $r->name ?? '—']);

        return response()->json(['count' => $count, 'sample' => $sample]);
    }
}
```

### Step 5: Register the routes

Open `routes/web.php`. Find the existing `Route::prefix('admin/messaging')` block (around lines 636–642). Inside that block, after the existing `pins` route, add:

```php

        // ── N3 Broadcasts ──
        Route::get('broadcasts',                [\App\Http\Controllers\BroadcastController::class, 'index'])
            ->middleware('permission:broadcasts.view')->name('broadcasts.index');
        Route::get('broadcasts/create',         [\App\Http\Controllers\BroadcastController::class, 'create'])
            ->middleware('permission:broadcasts.manage')->name('broadcasts.create');
        Route::post('broadcasts',               [\App\Http\Controllers\BroadcastController::class, 'store'])
            ->middleware('permission:broadcasts.manage')->name('broadcasts.store');
        Route::get('broadcasts/{broadcast}',    [\App\Http\Controllers\BroadcastController::class, 'show'])
            ->middleware('permission:broadcasts.view')->name('broadcasts.show');
        Route::post('broadcasts/{broadcast}/cancel', [\App\Http\Controllers\BroadcastController::class, 'cancel'])
            ->middleware('permission:broadcasts.manage')->name('broadcasts.cancel');
        Route::post('broadcasts/preview',       [\App\Http\Controllers\BroadcastController::class, 'preview'])
            ->middleware('permission:broadcasts.manage')->name('broadcasts.preview');
```

### Step 6: Run test + sanity

```
vendor/bin/pest tests/Feature/Messaging/Broadcasts/BroadcastControllerTest.php
vendor/bin/pest tests/Feature/Messaging/ tests/Unit/Messaging/
```
Expected: 5/5 pass + full pass.

### Step 7: Commit

```
git add app/Http/Controllers/BroadcastController.php app/Http/Requests/Broadcast/StoreBroadcastRequest.php routes/web.php tests/Feature/Messaging/Broadcasts/BroadcastControllerTest.php
git commit -m "feat(broadcasts): BroadcastController + routes + StoreBroadcastRequest"
```

---

## Task 9: `BroadcastTemplateController` + routes + FormRequests

**Files:**
- Create: `app/Http/Controllers/BroadcastTemplateController.php`
- Create: `app/Http/Requests/Broadcast/StoreBroadcastTemplateRequest.php`
- Create: `app/Http/Requests/Broadcast/UpdateBroadcastTemplateRequest.php`
- Modify: `routes/web.php` — 4 template routes
- Test: `tests/Feature/Messaging/Broadcasts/BroadcastTemplateControllerTest.php`

### Step 1: Write the failing test

Create `tests/Feature/Messaging/Broadcasts/BroadcastTemplateControllerTest.php`:

```php
<?php

use App\Enums\BroadcastAudienceType;
use App\Models\BroadcastTemplate;
use App\Models\User;

it('requires broadcasts.view to index templates', function () {
    $user = User::factory()->create(['role' => 'employee']);
    $this->actingAs($user)->get(route('messaging.templates.index'))->assertForbidden();
});

it('store creates a template', function () {
    $admin = User::factory()->create(['role' => 'employee']);
    $admin->permissions = ['broadcasts.manage'];
    $admin->save();

    $this->actingAs($admin)->post(route('messaging.templates.store'), [
        'name'          => 'Annual Dues Notice',
        'audience_type' => BroadcastAudienceType::AllActiveMembers->value,
        'sms_body'      => 'Hi {{member.name}}',
        'mail_subject'  => 'Dues',
        'mail_body'     => 'Hi {{member.name}}',
        'is_active'     => true,
    ])->assertRedirect();

    expect(BroadcastTemplate::count())->toBe(1);
});

it('rejects template with empty bodies', function () {
    $admin = User::factory()->create(['role' => 'employee']);
    $admin->permissions = ['broadcasts.manage'];
    $admin->save();

    $this->actingAs($admin)->post(route('messaging.templates.store'), [
        'name'          => 'Empty',
        'audience_type' => BroadcastAudienceType::AllActiveMembers->value,
        'is_active'     => true,
    ])->assertSessionHasErrors(['sms_body', 'mail_subject', 'mail_body']);
});

it('update mutates an existing template', function () {
    $admin = User::factory()->create(['role' => 'employee']);
    $admin->permissions = ['broadcasts.manage'];
    $admin->save();

    $t = BroadcastTemplate::factory()->state(['created_by' => $admin->id])->create();

    $this->actingAs($admin)->patch(route('messaging.templates.update', $t), [
        'name'          => 'Renamed',
        'audience_type' => $t->audience_type->value,
        'sms_body'      => $t->sms_body,
        'mail_subject'  => $t->mail_subject,
        'mail_body'     => $t->mail_body,
        'is_active'     => false,
    ])->assertRedirect();

    expect($t->fresh()->name)->toBe('Renamed');
    expect($t->fresh()->is_active)->toBeFalse();
});
```

### Step 2: Run test to verify it fails

```
vendor/bin/pest tests/Feature/Messaging/Broadcasts/BroadcastTemplateControllerTest.php
```
Expected: FAIL.

### Step 3: Create FormRequests

Create `app/Http/Requests/Broadcast/StoreBroadcastTemplateRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Broadcast;

use App\Enums\BroadcastAudienceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBroadcastTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('broadcasts.manage');
    }

    public function rules(): array
    {
        // The template must have at least one of (sms_body, mail_body+subject).
        // We use required_without to enforce: if sms_body is missing, mail_*
        // must be present, and vice versa.
        return [
            'name'          => ['required', 'string', 'max:150'],
            'audience_type' => ['required', Rule::enum(BroadcastAudienceType::class)],
            'sms_body'      => ['required_without_all:mail_subject,mail_body', 'nullable', 'string', 'max:1600'],
            'mail_subject'  => ['required_without:sms_body', 'nullable', 'string', 'max:150'],
            'mail_body'     => ['required_without:sms_body', 'nullable', 'string'],
            'is_active'     => ['boolean'],
        ];
    }
}
```

Create `app/Http/Requests/Broadcast/UpdateBroadcastTemplateRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Broadcast;

class UpdateBroadcastTemplateRequest extends StoreBroadcastTemplateRequest
{
    // Same rules as Store
}
```

### Step 4: Create the controller

Create `app/Http/Controllers/BroadcastTemplateController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BroadcastAudienceType;
use App\Http\Requests\Broadcast\StoreBroadcastTemplateRequest;
use App\Http\Requests\Broadcast\UpdateBroadcastTemplateRequest;
use App\Models\BroadcastTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BroadcastTemplateController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->hasPermission('broadcasts.view'), 403);

        return Inertia::render('Messaging/Templates/Index', [
            'activeModule'  => 'messaging-templates',
            'templates'     => BroadcastTemplate::with('creator:id,name')->latest()->paginate(25),
            'audienceTypes' => collect(BroadcastAudienceType::cases())->map(fn ($t) => [
                'value'       => $t->value,
                'label'       => str($t->name)->headline()->toString(),
                'allowedVars' => $t->allowedVariables(),
            ]),
        ]);
    }

    public function store(StoreBroadcastTemplateRequest $request): RedirectResponse
    {
        BroadcastTemplate::create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
        ]);
        return back()->with('success', 'Template created.');
    }

    public function update(UpdateBroadcastTemplateRequest $request, BroadcastTemplate $template): RedirectResponse
    {
        $template->update($request->validated());
        return back()->with('success', 'Template updated.');
    }

    public function destroy(Request $request, BroadcastTemplate $template): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('broadcasts.manage'), 403);
        $template->delete();
        return back()->with('success', 'Template deleted.');
    }
}
```

### Step 5: Register the routes

In `routes/web.php`, immediately after the Broadcast routes from Task 8, add:

```php

        // ── N3 Broadcast Templates ──
        Route::get('templates',              [\App\Http\Controllers\BroadcastTemplateController::class, 'index'])
            ->middleware('permission:broadcasts.view')->name('templates.index');
        Route::post('templates',             [\App\Http\Controllers\BroadcastTemplateController::class, 'store'])
            ->middleware('permission:broadcasts.manage')->name('templates.store');
        Route::patch('templates/{template}', [\App\Http\Controllers\BroadcastTemplateController::class, 'update'])
            ->middleware('permission:broadcasts.manage')->name('templates.update');
        Route::delete('templates/{template}',[\App\Http\Controllers\BroadcastTemplateController::class, 'destroy'])
            ->middleware('permission:broadcasts.manage')->name('templates.destroy');
```

### Step 6: Run test + sanity

```
vendor/bin/pest tests/Feature/Messaging/Broadcasts/BroadcastTemplateControllerTest.php
vendor/bin/pest tests/Feature/Messaging/ tests/Unit/Messaging/
```
Expected: 4/4 pass + full pass.

### Step 7: Commit

```
git add app/Http/Controllers/BroadcastTemplateController.php app/Http/Requests/Broadcast/StoreBroadcastTemplateRequest.php app/Http/Requests/Broadcast/UpdateBroadcastTemplateRequest.php routes/web.php tests/Feature/Messaging/Broadcasts/BroadcastTemplateControllerTest.php
git commit -m "feat(broadcasts): BroadcastTemplateController CRUD + routes"
```

---

## Task 10: Vue pages (Broadcasts list/create/show + Templates list)

**Files:**
- Create: `resources/js/Pages/Messaging/Broadcasts/Index.vue`
- Create: `resources/js/Pages/Messaging/Broadcasts/Create.vue`
- Create: `resources/js/Pages/Messaging/Broadcasts/Show.vue`
- Create: `resources/js/Pages/Messaging/Templates/Index.vue`
- Create: `resources/js/Components/VariablesPanel.vue`

No backend tests for this task — Vue isn't covered by Pest in this codebase. Verify by running `npm run build` (Vite compiles) and end-to-end smoke as part of Task 12.

### Step 1: Create `VariablesPanel.vue`

Create `resources/js/Components/VariablesPanel.vue`:

```vue
<script setup>
defineProps({
    variables: { type: Array, default: () => [] },
});
</script>

<template>
    <div class="rounded-xl border border-outline-variant/60 bg-surface-container-low p-4">
        <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70 mb-2">
            Available variables
        </p>
        <ul class="space-y-1.5">
            <li v-for="v in variables" :key="v" class="font-mono text-[12px] text-on-surface">
                <span class="text-primary">{{ '{{' }} {{ v }} {{ '}}' }}</span>
            </li>
        </ul>
        <p class="mt-3 text-[11px] text-on-surface-variant/70">
            Unknown variables render as empty. Click a variable to copy it.
        </p>
    </div>
</template>
```

### Step 2: Create `Broadcasts/Index.vue`

Create `resources/js/Pages/Messaging/Broadcasts/Index.vue`:

```vue
<script setup>
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import EmptyState from '@/Components/EmptyState.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    broadcasts: { type: Object, required: true },
    filters:    { type: Object, default: () => ({}) },
});

const status = ref(props.filters.status ?? '');

watch(status, () => {
    router.get(route('messaging.broadcasts.index'), {
        status: status.value || undefined,
    }, { preserveState: true, replace: true });
});

const rows = props.broadcasts.data ?? props.broadcasts ?? [];
</script>

<template>
<Head title="Broadcasts — Messaging" />
<div class="p-6 max-w-7xl mx-auto">
    <header class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-black text-primary">Broadcasts</h1>
            <p class="text-sm text-on-surface-variant">Admin-initiated SMS + email to pre-defined audiences.</p>
        </div>
        <PrimaryButton @click="router.visit(route('messaging.broadcasts.create'))">New broadcast</PrimaryButton>
    </header>

    <div class="mb-4">
        <select v-model="status" aria-label="Filter by status"
                class="rounded-xl border border-outline-variant px-3 py-2 text-sm bg-surface-container-lowest">
            <option value="">All statuses</option>
            <option value="scheduled">Scheduled</option>
            <option value="queued">Queued</option>
            <option value="sending">Sending</option>
            <option value="completed">Completed</option>
            <option value="failed">Failed</option>
            <option value="cancelled">Cancelled</option>
        </select>
    </div>

    <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-surface-container">
                <tr class="text-left text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">
                    <th class="px-4 py-3">Title</th>
                    <th class="px-4 py-3">Audience</th>
                    <th class="px-4 py-3">Channels</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Recipients</th>
                    <th class="px-4 py-3">Created</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="b in rows" :key="b.id" class="border-t border-outline-variant/40 hover:bg-surface-container/50">
                    <td class="px-4 py-2 font-semibold">
                        <Link :href="route('messaging.broadcasts.show', b.id)" class="text-primary hover:underline">
                            {{ b.title }}
                        </Link>
                    </td>
                    <td class="px-4 py-2">{{ b.audience_type }}</td>
                    <td class="px-4 py-2 font-mono text-xs">{{ (b.channels ?? []).join(' + ') }}</td>
                    <td class="px-4 py-2 capitalize">{{ b.status }}</td>
                    <td class="px-4 py-2 tabular-nums">{{ b.recipient_count }}</td>
                    <td class="px-4 py-2 text-xs">{{ new Date(b.created_at).toLocaleString() }}</td>
                </tr>
            </tbody>
        </table>
        <EmptyState v-if="rows.length === 0" title="No broadcasts yet" subtitle="Click 'New broadcast' to send your first." />
    </div>
</div>
</template>
```

### Step 3: Create `Broadcasts/Create.vue`

Create `resources/js/Pages/Messaging/Broadcasts/Create.vue`:

```vue
<script setup>
import { ref, computed, watch } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import TextInput from '@/Components/TextInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import VariablesPanel from '@/Components/VariablesPanel.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    audienceTypes:      { type: Array, required: true },
    templates:          { type: Array, default: () => [] },
    canBypassThrottle:  { type: Boolean, default: false },
});

const form = useForm({
    title:                    '',
    audience_type:            'all_active_members',
    audience_params:          {},
    channels:                 ['sms', 'mail'],
    template_id:              null,
    sms_body:                 '',
    mail_subject:             '',
    mail_body:                '',
    scheduled_at:             '',
    throttle_overridden:      false,
    throttle_override_reason: '',
});

const audienceType = computed(() =>
    props.audienceTypes.find(t => t.value === form.audience_type) ?? props.audienceTypes[0]
);

const allowedVars = computed(() => audienceType.value?.allowedVars ?? []);

const compatibleTemplates = computed(() =>
    props.templates.filter(t => t.audience_type === form.audience_type)
);

watch(() => form.template_id, (id) => {
    if (! id) return;
    const t = props.templates.find(x => x.id === id);
    if (! t) return;
    form.sms_body     = t.sms_body ?? '';
    form.mail_subject = t.mail_subject ?? '';
    form.mail_body    = t.mail_body ?? '';
});

const audienceCount = ref(null);
const audienceSample = ref([]);

async function previewAudience() {
    const r = await axios.post(route('messaging.broadcasts.preview'), {
        audience_type:   form.audience_type,
        audience_params: form.audience_params,
    });
    audienceCount.value  = r.data.count;
    audienceSample.value = r.data.sample;
}

function submit() {
    form.post(route('messaging.broadcasts.store'));
}
</script>

<template>
<Head title="New broadcast" />
<div class="p-6 max-w-5xl mx-auto">
    <header class="mb-6">
        <h1 class="text-2xl font-black text-primary">New broadcast</h1>
        <p class="text-sm text-on-surface-variant">Compose an SMS + email broadcast to a pre-defined audience.</p>
    </header>

    <form @submit.prevent="submit" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main column -->
        <div class="lg:col-span-2 space-y-4">
            <div>
                <InputLabel for="title" value="Title (internal only)" />
                <TextInput id="title" v-model="form.title" required class="mt-1 w-full" />
                <InputError :message="form.errors.title" class="mt-1" />
            </div>

            <div>
                <InputLabel for="audience_type" value="Audience" />
                <select id="audience_type" v-model="form.audience_type" required
                        class="mt-1 w-full rounded-xl border border-outline-variant px-3 py-2 text-sm">
                    <option v-for="t in audienceTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
                </select>
                <button type="button" @click="previewAudience"
                        class="mt-2 text-xs text-primary hover:underline">Preview audience…</button>
                <div v-if="audienceCount !== null" class="mt-2 text-xs text-on-surface-variant">
                    <strong>{{ audienceCount }}</strong> recipients
                    <span v-if="audienceSample.length">— sample: {{ audienceSample.slice(0, 5).map(s => s.name).join(', ') }}</span>
                </div>
                <InputError :message="form.errors.audience_type" class="mt-1" />
            </div>

            <div>
                <InputLabel value="Channels" />
                <div class="mt-1 flex gap-4">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" :value="'sms'" v-model="form.channels" /> SMS
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" :value="'mail'" v-model="form.channels" /> Email
                    </label>
                </div>
                <InputError :message="form.errors.channels" class="mt-1" />
            </div>

            <div v-if="compatibleTemplates.length">
                <InputLabel for="template_id" value="Use saved template (optional)" />
                <select id="template_id" v-model.number="form.template_id"
                        class="mt-1 w-full rounded-xl border border-outline-variant px-3 py-2 text-sm">
                    <option :value="null">— None —</option>
                    <option v-for="t in compatibleTemplates" :key="t.id" :value="t.id">{{ t.name }}</option>
                </select>
            </div>

            <div v-if="form.channels.includes('sms')">
                <InputLabel for="sms_body" value="SMS body" />
                <textarea id="sms_body" v-model="form.sms_body" rows="3"
                          class="mt-1 w-full rounded-xl border border-outline-variant px-3 py-2 text-sm font-mono"></textarea>
                <InputError :message="form.errors.sms_body" class="mt-1" />
            </div>

            <div v-if="form.channels.includes('mail')">
                <InputLabel for="mail_subject" value="Email subject" />
                <TextInput id="mail_subject" v-model="form.mail_subject" class="mt-1 w-full" />
                <InputError :message="form.errors.mail_subject" class="mt-1" />

                <InputLabel for="mail_body" value="Email body" class="mt-3" />
                <textarea id="mail_body" v-model="form.mail_body" rows="8"
                          class="mt-1 w-full rounded-xl border border-outline-variant px-3 py-2 text-sm"></textarea>
                <InputError :message="form.errors.mail_body" class="mt-1" />
            </div>

            <div>
                <InputLabel for="scheduled_at" value="Schedule for (optional — leave blank to send now)" />
                <TextInput id="scheduled_at" type="datetime-local" v-model="form.scheduled_at" class="mt-1 w-full" />
                <InputError :message="form.errors.scheduled_at" class="mt-1" />
            </div>

            <div v-if="canBypassThrottle" class="rounded-xl border border-warning/40 bg-warning/10 p-4">
                <label class="flex items-center gap-2 text-sm font-semibold text-warning-on-container">
                    <input type="checkbox" v-model="form.throttle_overridden" />
                    Bypass per-phone SMS throttle (logged in audit)
                </label>
                <TextInput v-if="form.throttle_overridden" v-model="form.throttle_override_reason"
                           placeholder="Reason for override (e.g. AGM tomorrow)"
                           class="mt-2 w-full" />
                <InputError :message="form.errors.throttle_override_reason" class="mt-1" />
            </div>

            <div class="pt-2 flex justify-end gap-2">
                <button type="button" @click="router.visit(route('messaging.broadcasts.index'))"
                        class="rounded-xl px-4 py-2 text-sm">Cancel</button>
                <PrimaryButton type="submit" :disabled="form.processing">
                    {{ form.scheduled_at ? 'Schedule' : 'Send now' }}
                </PrimaryButton>
            </div>
        </div>

        <!-- Sidebar: variables -->
        <div>
            <VariablesPanel :variables="allowedVars" />
        </div>
    </form>
</div>
</template>
```

### Step 4: Create `Broadcasts/Show.vue`

Create `resources/js/Pages/Messaging/Broadcasts/Show.vue`:

```vue
<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    broadcast:  { type: Object, required: true },
    recipients: { type: Object, required: true },
});

const cancelForm = useForm({});

function cancel() {
    if (! confirm('Cancel this scheduled broadcast?')) return;
    cancelForm.post(route('messaging.broadcasts.cancel', props.broadcast.id));
}

const rows = props.recipients.data ?? props.recipients ?? [];
</script>

<template>
<Head :title="`${broadcast.title} — Broadcast`" />
<div class="p-6 max-w-6xl mx-auto space-y-6">
    <header>
        <Link :href="route('messaging.broadcasts.index')" class="text-xs font-bold text-on-surface-variant hover:text-primary">← All broadcasts</Link>
        <h1 class="text-2xl font-black text-primary mt-1">{{ broadcast.title }}</h1>
        <p class="text-sm text-on-surface-variant">
            <span class="capitalize">{{ broadcast.audience_type.replaceAll('_', ' ') }}</span>
            · <span class="font-mono text-xs">{{ (broadcast.channels ?? []).join(' + ') }}</span>
            · <span class="capitalize">{{ broadcast.status }}</span>
        </p>
    </header>

    <button v-if="['scheduled','queued'].includes(broadcast.status)"
            @click="cancel"
            class="rounded-xl border border-red-500/40 text-red-600 px-4 py-2 text-sm hover:bg-red-50">
        Cancel broadcast
    </button>

    <section class="grid grid-cols-2 md:grid-cols-6 gap-3">
        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4">
            <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">Recipients</p>
            <p class="text-2xl font-black tabular-nums">{{ broadcast.recipient_count }}</p>
        </div>
        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4">
            <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">SMS sent</p>
            <p class="text-2xl font-black tabular-nums text-green-700">{{ broadcast.sms_sent_count }}</p>
        </div>
        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4">
            <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">SMS throttled</p>
            <p class="text-2xl font-black tabular-nums text-amber-700">{{ broadcast.sms_throttled_count }}</p>
        </div>
        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4">
            <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">SMS failed</p>
            <p class="text-2xl font-black tabular-nums text-red-700">{{ broadcast.sms_failed_count }}</p>
        </div>
        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4">
            <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">Mail sent</p>
            <p class="text-2xl font-black tabular-nums text-green-700">{{ broadcast.mail_sent_count }}</p>
        </div>
        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4">
            <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">Mail failed</p>
            <p class="text-2xl font-black tabular-nums text-red-700">{{ broadcast.mail_failed_count }}</p>
        </div>
    </section>

    <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest">
        <header class="px-6 py-4 border-b border-outline-variant/60">
            <h2 class="text-sm font-black text-primary">Recipients ({{ rows.length }} on this page)</h2>
        </header>
        <table v-if="rows.length" class="w-full text-sm">
            <thead class="text-left text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">
                <tr>
                    <th class="px-6 py-2">Recipient</th>
                    <th class="px-6 py-2">SMS</th>
                    <th class="px-6 py-2">Mail</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="r in rows" :key="r.id" class="border-t border-outline-variant/40">
                    <td class="px-6 py-2 font-mono text-xs">{{ r.recipient_type.split('\\').pop() }} #{{ r.recipient_id }}</td>
                    <td class="px-6 py-2">{{ r.sms_status ?? '—' }}</td>
                    <td class="px-6 py-2">{{ r.mail_status ?? '—' }}</td>
                </tr>
            </tbody>
        </table>
        <div v-else class="px-6 py-8 text-center text-on-surface-variant text-sm">No recipients yet.</div>
    </section>
</div>
</template>
```

### Step 5: Create `Templates/Index.vue`

Create `resources/js/Pages/Messaging/Templates/Index.vue`:

```vue
<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import TextInput from '@/Components/TextInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import VariablesPanel from '@/Components/VariablesPanel.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    templates:     { type: Object, required: true },
    audienceTypes: { type: Array, required: true },
});

const showForm = ref(false);
const form = useForm({
    name: '', audience_type: 'all_active_members',
    sms_body: '', mail_subject: '', mail_body: '',
    is_active: true,
});

function submit() {
    form.post(route('messaging.templates.store'), {
        preserveScroll: true,
        onSuccess: () => { showForm.value = false; form.reset(); },
    });
}

const allowedVarsFor = (v) =>
    props.audienceTypes.find(t => t.value === v)?.allowedVars ?? [];

const rows = props.templates.data ?? props.templates ?? [];
</script>

<template>
<Head title="Broadcast templates" />
<div class="p-6 max-w-6xl mx-auto">
    <header class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-black text-primary">Broadcast templates</h1>
            <p class="text-sm text-on-surface-variant">Reusable SMS + email bodies, audience-typed for variable safety.</p>
        </div>
        <PrimaryButton @click="showForm = true">New template</PrimaryButton>
    </header>

    <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-surface-container">
                <tr class="text-left text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">
                    <th class="px-4 py-3">Name</th>
                    <th class="px-4 py-3">Audience</th>
                    <th class="px-4 py-3">Channels</th>
                    <th class="px-4 py-3">Active</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="t in rows" :key="t.id" class="border-t border-outline-variant/40">
                    <td class="px-4 py-2 font-semibold">{{ t.name }}</td>
                    <td class="px-4 py-2 capitalize">{{ t.audience_type.replaceAll('_', ' ') }}</td>
                    <td class="px-4 py-2 font-mono text-xs">{{ [t.sms_body && 'sms', t.mail_body && 'mail'].filter(Boolean).join(' + ') }}</td>
                    <td class="px-4 py-2">{{ t.is_active ? 'Yes' : 'No' }}</td>
                </tr>
                <tr v-if="rows.length === 0">
                    <td colspan="4" class="px-4 py-6 text-center text-on-surface-variant">No templates yet.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <SlidePanel :open="showForm" @close="showForm = false" title="New template" size="lg">
        <form @submit.prevent="submit" class="grid grid-cols-3 gap-4">
            <div class="col-span-2 space-y-3">
                <div>
                    <InputLabel for="name" value="Template name" />
                    <TextInput id="name" v-model="form.name" required class="mt-1 w-full" />
                    <InputError :message="form.errors.name" class="mt-1" />
                </div>
                <div>
                    <InputLabel for="audience_type" value="Audience type" />
                    <select id="audience_type" v-model="form.audience_type" required
                            class="mt-1 w-full rounded-xl border border-outline-variant px-3 py-2 text-sm">
                        <option v-for="t in audienceTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
                    </select>
                </div>
                <div>
                    <InputLabel for="sms_body" value="SMS body (optional)" />
                    <textarea id="sms_body" v-model="form.sms_body" rows="3"
                              class="mt-1 w-full rounded-xl border border-outline-variant px-3 py-2 text-sm font-mono"></textarea>
                    <InputError :message="form.errors.sms_body" class="mt-1" />
                </div>
                <div>
                    <InputLabel for="mail_subject" value="Mail subject (optional)" />
                    <TextInput id="mail_subject" v-model="form.mail_subject" class="mt-1 w-full" />
                    <InputError :message="form.errors.mail_subject" class="mt-1" />
                </div>
                <div>
                    <InputLabel for="mail_body" value="Mail body (optional)" />
                    <textarea id="mail_body" v-model="form.mail_body" rows="6"
                              class="mt-1 w-full rounded-xl border border-outline-variant px-3 py-2 text-sm"></textarea>
                    <InputError :message="form.errors.mail_body" class="mt-1" />
                </div>
                <div class="flex justify-end pt-2">
                    <PrimaryButton type="submit" :disabled="form.processing">Create</PrimaryButton>
                </div>
            </div>
            <div>
                <VariablesPanel :variables="allowedVarsFor(form.audience_type)" />
            </div>
        </form>
    </SlidePanel>
</div>
</template>
```

### Step 6: Run the Vite build to confirm compile

```
npm run build
```
Expected: clean `✓ built in N.Ns` output.

### Step 7: Commit

```
git add resources/js/Pages/Messaging/Broadcasts/Index.vue resources/js/Pages/Messaging/Broadcasts/Create.vue resources/js/Pages/Messaging/Broadcasts/Show.vue resources/js/Pages/Messaging/Templates/Index.vue resources/js/Components/VariablesPanel.vue
git commit -m "feat(broadcasts): Vue pages for Broadcasts (list/create/show) + Templates (CRUD)"
```

---

## Task 11: Sidebar entries

**Files:**
- Modify: `resources/js/Layouts/AuthenticatedLayout.vue` — add Broadcasts + Templates entries under the existing Messaging admin item

### Step 1: Locate the existing Messaging sidebar entry

Open `resources/js/Layouts/AuthenticatedLayout.vue`. Find the line that wires the existing Messaging admin link (search for `'Messaging'` in the System section of the privileged sidebar — should be around line 198):

```php
                    { label: 'Messaging',     route: 'messaging.index',           module: 'messaging',     icon: 'sms',       visible: can('messaging.view') },
```

### Step 2: Replace with an expandable group

Replace that single line with an expandable group:

```js
                    {
                        label: 'Messaging', icon: 'sms', expandable: true,
                        visible: can('messaging.view') || can('broadcasts.view'),
                        children: [
                            { label: 'SMS Log',     route: 'messaging.index',                  module: 'messaging',            icon: 'sms',           visible: can('messaging.view') },
                            { label: 'Broadcasts',  route: 'messaging.broadcasts.index',       module: 'messaging-broadcasts', icon: 'campaign',      visible: can('broadcasts.view') },
                            { label: 'Templates',   route: 'messaging.templates.index',        module: 'messaging-templates',  icon: 'description',   visible: can('broadcasts.view') },
                        ],
                    },
```

### Step 3: Add icon palette entries

In the same file, find the `SIDEBAR_ICON_COLORS` const (around line 452). After the `'finance-journal'` entry but before the billing entries (or in any logical spot), add:

```js
    // Messaging
    'messaging':              '#3949ab',
    'messaging-broadcasts':   '#3949ab',
    'messaging-templates':    '#3949ab',
```

### Step 4: Run the build

```
npm run build
```
Expected: clean.

### Step 5: Commit

```
git add resources/js/Layouts/AuthenticatedLayout.vue
git commit -m "feat(broadcasts): sidebar — Messaging group with SMS Log + Broadcasts + Templates"
```

---

## Task 12: Full suite + build + push + PR

### Step 1: Full Pest suite

```
vendor/bin/pest --parallel
```
Expected: ALL PASS. Suite count should grow from current ~1153 by ~25 (the new broadcast tests).

If any pre-existing test fails: most likely some existing test that creates a `Broadcast` factory through unrelated code, or a test that asserts `Message` model count and now includes the broadcast tables. Audit + fix per-test.

### Step 2: Vite build

```
npm run build
```
Expected: clean.

### Step 3: Push branch

```
git push -u origin feat/notifications-n3-broadcast-tools
```

### Step 4: Open PR

```
gh pr create --title "feat(notifications): N3 — admin broadcast tools (send-now + scheduled + templates + dual SMS+mail)" --body-file - <<'EOF'
## Summary

Notifications v2 — Phase N3 (final). Admin-initiated broadcast surface that fans out SMS + email to one of 6 pre-defined audience types, with optional saved templates (audience-typed `{{var}}` whitelist), optional scheduled-send, and the N1 `sms:marketing` rate limiter applied by default (admin can bypass with audit-logged reason).

## What's in

- **3 new tables** — `broadcasts`, `broadcast_templates`, `broadcast_recipients`
- **3 new enums** — `BroadcastStatus`, `BroadcastChannel`, `BroadcastAudienceType`
- **3 new perms** — `broadcasts.view`, `broadcasts.manage`, `broadcasts.bypass_throttle` (granted to hr_admin + finance_officer)
- **3 services** — `AudienceResolver` (6 audience types → Builder), `TemplateRenderer` (whitelist `{{var}}` interpolation — no `user.password` leak), `BroadcastService` (queue + cancel)
- **1 job** — `DispatchBroadcastJob` (chunked fan-out riding N1's SmsDispatcher)
- **1 artisan + scheduler** — `messaging:fire-due-broadcasts` every minute
- **2 controllers + 3 FormRequests** — full CRUD for broadcasts + templates
- **4 Vue pages + 1 component** — Broadcasts list/create/show, Templates list, VariablesPanel
- **Sidebar** — Messaging group now expands to SMS Log + Broadcasts + Templates

## Test plan

- [x] Full Pest suite green (~25 new tests)
- [x] `npm run build` clean
- [ ] Manual: create a template, compose a broadcast referencing it, schedule for 2 minutes out, wait for the scheduler to fire it
- [ ] Manual: send-now to AllActiveMembers with both channels, watch counters in /admin/messaging/broadcasts/{id}
- [ ] Manual: try to bypass throttle without `broadcasts.bypass_throttle` perm → 403
- [ ] Manual: pre-saturate `sms:marketing:+233...` → throttled rows recorded as `Throttled` with correct counter

## Spec + plan

- Spec: `docs/superpowers/specs/2026-05-31-notifications-n3-broadcast-tools-design.md`
- Plan: `docs/superpowers/plans/2026-05-31-notifications-n3-broadcast-tools.md`
- Builds on N1 reliability (PR #71) + N2 event wiring (PR #72)
EOF
```

### Step 5: Merge once CI is green

```
gh pr merge --squash --delete-branch
git checkout main && git pull --ff-only
```

---

## Self-Review

**Spec coverage:**

| Spec requirement | Task |
|---|---|
| `broadcasts`, `broadcast_templates`, `broadcast_recipients` tables | Task 2 |
| `BroadcastStatus`/`BroadcastChannel`/`BroadcastAudienceType` enums | Task 1 |
| 3 permissions + role grants | Task 1 |
| `AudienceResolver` returns Builder per type | Task 3 |
| `TemplateRenderer` audience-typed whitelist | Task 4 |
| `BroadcastService::queue` + `cancel` | Task 5 |
| `DispatchBroadcastJob` chunkById + per-recipient SMS+mail + recipient rows | Task 6 |
| Throttle check + override bypass | Task 6 |
| Idempotency via unique constraint | Tasks 2 + 6 |
| `messaging:fire-due-broadcasts` + scheduler | Task 7 |
| Controllers + 6 routes | Tasks 8 + 9 |
| FormRequest with throttle-override auth gating | Task 8 |
| 4 Vue pages + VariablesPanel | Task 10 |
| Sidebar entry | Task 11 |
| Test plan ~27 cases | Tasks 1, 2, 3, 4, 5, 6, 7, 8, 9 (sums to ~26 cases, close enough) |
| Suite stays green | Task 12 |
| Push + PR | Task 12 |

Every spec section maps to a task.

**Placeholder scan:** Searched for "TBD"/"TODO"/"similar to"/"fill in" — none. Every code block is complete and self-contained.

**Type consistency:**
- `BroadcastAudienceType::recipientClass()` and `::allowedVariables()` defined in Task 1 → consumed in Tasks 3, 4, 6.
- `BroadcastStatus::isTerminal()` defined in Task 1 → consumed in Task 6.
- `Broadcast::scopeDue()` defined in Task 2 → consumed in Task 7.
- `BroadcastService::queue()` / `cancel()` signatures match call sites in Tasks 7 + 8.
- `DispatchBroadcastJob` constructor takes `int $broadcastId` consistently in Tasks 5, 6, 7, 8.
- `RateLimiter::tooManyAttempts("sms:marketing:{$phone}", 5)` matches N1's registered limiter shape.
- `SmsDispatcher::send(toPhone:, body:, contextType:, contextId:)` matches the N1 signature confirmed during audit.
- All Notification class patterns from N1/N2 reused as-is (no new Notification classes — broadcasts use `Mail::raw` directly for the mail leg).

No drift found.
