# Incident Reporting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the Incident Reporting sub-module under Governance — employees compose private grievances/suggestions/safety reports that route only to users holding `incidents.review`, with threaded replies and attachments.

**Architecture:** New Laravel module following the project's Enum → FormRequest → Service → Event → Resource → Policy pattern. Four new tables, one private storage disk, one new permission, six events with notification-writing listeners, two Inertia pages (`Governance/Incidents/{Index,Show}`) plus four small Vue components. Strict privacy enforced in a single `IncidentReportPolicy::view` method — no super-admin override.

**Tech Stack:** Laravel 13, Inertia v2, Vue 3.5, Tailwind v3, Pest tests (per-user JSON `permissions` for test grants), the project's own RBAC (`App\Models\Permission` + `App\Models\Role`, slug-based — NOT Spatie). Existing notifications table is reused for in-app delivery.

**Spec:** [docs/superpowers/specs/2026-05-18-incident-reporting-design.md](../specs/2026-05-18-incident-reporting-design.md)

---

## File Structure

### Backend — create

| Path | Responsibility |
|---|---|
| `database/migrations/2026_05_18_000001_create_incident_reports_tables.php` | All four tables in one migration |
| `database/seeders/IncidentPermissionsSeeder.php` | Seed `incidents.review` permission |
| `app/Enums/IncidentCategory.php` | grievance / improvement / safety / other |
| `app/Enums/IncidentStatus.php` | open / in_review / closed |
| `app/Models/IncidentReport.php` | Aggregate root |
| `app/Models/IncidentReportMessage.php` | Thread message |
| `app/Models/IncidentReportAttachment.php` | Polymorphic attachment |
| `app/Policies/IncidentReportPolicy.php` | Single source of privacy truth |
| `app/Services/IncidentReportService.php` | All business logic, all DB transactions |
| `app/Http/Controllers/IncidentReportController.php` | Thin; defers to service |
| `app/Http/Requests/IncidentReport/StoreIncidentReportRequest.php` | Submission validation |
| `app/Http/Requests/IncidentReport/UpdateIncidentReportRequest.php` | Edit-while-open validation |
| `app/Http/Requests/IncidentReport/AssignIncidentReportRequest.php` | Assignment validation (target holds incidents.review) |
| `app/Http/Requests/IncidentReport/StoreIncidentMessageRequest.php` | Reply validation |
| `app/Http/Requests/IncidentReport/CloseIncidentReportRequest.php` | Close validation |
| `app/Http/Resources/IncidentReportResource.php` | Detail + index serialisation |
| `app/Http/Resources/IncidentReportMessageResource.php` | Thread message serialisation |
| `app/Http/Resources/IncidentReportAttachmentResource.php` | Signed download URL + metadata only |
| `app/Events/Incident/IncidentReportAssigned.php` | Fires from service |
| `app/Events/Incident/IncidentReportUnassigned.php` | |
| `app/Events/Incident/IncidentMessagePosted.php` | |
| `app/Events/Incident/IncidentReportClosed.php` | |
| `app/Events/Incident/IncidentReportReopened.php` | |
| `app/Listeners/Incident/NotifyAssignee.php` | Writes notification row |
| `app/Listeners/Incident/NotifyUnassigned.php` | |
| `app/Listeners/Incident/NotifyMessageRecipients.php` | |
| `app/Listeners/Incident/NotifySubmitterOnClose.php` | |
| `app/Listeners/Incident/NotifyCircleOnReopen.php` | |
| `tests/Feature/Governance/IncidentReportTest.php` | 21 feature tests (§9.1 of spec) |
| `tests/Unit/Policies/IncidentReportPolicyTest.php` | Policy matrix tests |

### Backend — modify

| Path | Why |
|---|---|
| `config/filesystems.php` | Add `incidents` private disk |
| `routes/web.php` | Add `/governance/incidents` route group inside the existing auth+audit middleware group |
| `app/Providers/AppServiceProvider.php` | Register `IncidentReport → IncidentReportPolicy` mapping and the event-listener pairs |
| `database/seeders/DatabaseSeeder.php` | Call the new `IncidentPermissionsSeeder` |

### Frontend — create

| Path | Responsibility |
|---|---|
| `resources/js/Pages/Governance/Incidents/Index.vue` | List + filter rail |
| `resources/js/Pages/Governance/Incidents/Show.vue` | Detail + thread + assignment SlidePanel |
| `resources/js/Components/Incidents/CategoryBadge.vue` | Color-keyed pill |
| `resources/js/Components/Incidents/StatusPill.vue` | Three states |
| `resources/js/Components/Incidents/MessageBubble.vue` | Single thread message |
| `resources/js/Components/Incidents/AttachmentChip.vue` | File chip with signed download |

### Frontend — modify

| Path | Why |
|---|---|
| `resources/js/Layouts/AuthenticatedLayout.vue` | Add `Incident Reports` child under Governance group; add `Compose Incident` Quick Action |

---

## Task 1: Migration and Enums

**Files:**
- Create: `database/migrations/2026_05_18_000001_create_incident_reports_tables.php`
- Create: `app/Enums/IncidentCategory.php`
- Create: `app/Enums/IncidentStatus.php`

- [ ] **Step 1: Create the migration**

Write `database/migrations/2026_05_18_000001_create_incident_reports_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('incident_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $t->enum('category', ['grievance', 'improvement', 'safety', 'other']);
            $t->string('title', 180);
            $t->text('body');
            $t->enum('status', ['open', 'in_review', 'closed'])->default('open');
            $t->timestamp('closed_at')->nullable();
            $t->foreignId('closed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('resolution_note')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['employee_id', 'status']);
            $t->index(['status', 'created_at']);
        });

        Schema::create('incident_report_assignees', function (Blueprint $t) {
            $t->foreignId('incident_report_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->timestamp('assigned_at')->useCurrent();
            $t->foreignId('assigned_by_id')->constrained('users');
            $t->timestamp('removed_at')->nullable();
            $t->primary(['incident_report_id', 'user_id']);
        });

        Schema::create('incident_report_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('incident_report_id')->constrained()->cascadeOnDelete();
            $t->foreignId('author_id')->constrained('users');
            $t->text('body');
            $t->timestamps();
            $t->index(['incident_report_id', 'created_at']);
        });

        Schema::create('incident_report_attachments', function (Blueprint $t) {
            $t->id();
            $t->string('attachable_type');
            $t->unsignedBigInteger('attachable_id');
            $t->string('file_path');
            $t->string('original_name');
            $t->string('mime_type', 120);
            $t->unsignedInteger('size_bytes');
            $t->foreignId('uploaded_by_id')->constrained('users');
            $t->timestamp('created_at')->useCurrent();
            $t->index(['attachable_type', 'attachable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_report_attachments');
        Schema::dropIfExists('incident_report_messages');
        Schema::dropIfExists('incident_report_assignees');
        Schema::dropIfExists('incident_reports');
    }
};
```

- [ ] **Step 2: Run migration up**

Run: `php artisan migrate`
Expected: `INFO  Running migrations. … 2026_05_18_000001_create_incident_reports_tables ... DONE`

- [ ] **Step 3: Roll back to verify down() works**

Run: `php artisan migrate:rollback --step=1`
Expected: Rolling back this migration, no errors.

- [ ] **Step 4: Migrate up again**

Run: `php artisan migrate`
Expected: clean.

- [ ] **Step 5: Create IncidentCategory enum**

Write `app/Enums/IncidentCategory.php`:

```php
<?php

namespace App\Enums;

enum IncidentCategory: string
{
    case Grievance             = 'grievance';
    case ImprovementSuggestion = 'improvement';
    case WorkplaceSafety       = 'safety';
    case Other                 = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Grievance             => 'Grievance',
            self::ImprovementSuggestion => 'Improvement Suggestion',
            self::WorkplaceSafety       => 'Workplace Safety',
            self::Other                 => 'Other',
        };
    }
}
```

- [ ] **Step 6: Create IncidentStatus enum**

Write `app/Enums/IncidentStatus.php`:

```php
<?php

namespace App\Enums;

enum IncidentStatus: string
{
    case Open     = 'open';
    case InReview = 'in_review';
    case Closed   = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open     => 'Open',
            self::InReview => 'In Review',
            self::Closed   => 'Closed',
        };
    }
}
```

- [ ] **Step 7: Lint check**

Run: `php -l app/Enums/IncidentCategory.php app/Enums/IncidentStatus.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_05_18_000001_create_incident_reports_tables.php app/Enums/IncidentCategory.php app/Enums/IncidentStatus.php
git commit -m "feat(incidents): add migration and enums for incident reporting"
```

---

## Task 2: Private disk + Permission seed

**Files:**
- Modify: `config/filesystems.php`
- Create: `database/seeders/IncidentPermissionsSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`

- [ ] **Step 1: Add the `incidents` private disk**

Open `config/filesystems.php`. Inside the `'disks' => [ ... ]` array, after the existing disks, add:

```php
        'incidents' => [
            'driver'     => 'local',
            'root'       => storage_path('app/incidents'),
            'visibility' => 'private',
            'throw'      => true,
        ],
```

- [ ] **Step 2: Create the seeder**

Write `database/seeders/IncidentPermissionsSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

class IncidentPermissionsSeeder extends Seeder
{
    private const PERMISSIONS = [
        'incidents.review' => [
            'Incidents',
            'Can be assigned to and view confidential incident reports submitted by employees',
        ],
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $slug => [$group, $description]) {
            Permission::updateOrCreate(
                ['slug' => $slug],
                [
                    'name'        => str_replace('.', ': ', $slug),
                    'group'       => $group,
                    'description' => $description,
                ]
            );
        }

        // No role gets incidents.review by default. Super-admin / HR-admin
        // grants it explicitly via the RBAC UI to the CEO + chosen execs.
        Cache::flush();
    }
}
```

- [ ] **Step 3: Wire the seeder into DatabaseSeeder**

Open `database/seeders/DatabaseSeeder.php`. Find the `run()` method's seeder list and add the new seeder. The line to add (place it after the existing `DocumentPermissionsSeeder::class` if present, otherwise alongside the other permission seeders):

```php
            \Database\Seeders\IncidentPermissionsSeeder::class,
```

Use the exact bracket position matching the existing seeders in that file — typically inside a `$this->call([ ... ])` array.

- [ ] **Step 4: Run the seeder**

Run: `php artisan db:seed --class=IncidentPermissionsSeeder`
Expected: `INFO  Seeding: Database\Seeders\IncidentPermissionsSeeder` / `INFO  Database seeding completed successfully.`

- [ ] **Step 5: Verify the permission row**

Run: `php artisan tinker --execute='echo App\Models\Permission::where("slug","incidents.review")->value("name");'`
Expected: `incidents: review`

- [ ] **Step 6: Commit**

```bash
git add config/filesystems.php database/seeders/IncidentPermissionsSeeder.php database/seeders/DatabaseSeeder.php
git commit -m "feat(incidents): add private disk and incidents.review permission seeder"
```

---

## Task 3: Models

**Files:**
- Create: `app/Models/IncidentReport.php`
- Create: `app/Models/IncidentReportMessage.php`
- Create: `app/Models/IncidentReportAttachment.php`

- [ ] **Step 1: Create IncidentReport model**

Write `app/Models/IncidentReport.php`:

```php
<?php

namespace App\Models;

use App\Enums\IncidentCategory;
use App\Enums\IncidentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class IncidentReport extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'employee_id',
        'category',
        'title',
        'body',
        'status',
        'closed_at',
        'closed_by_id',
        'resolution_note',
    ];

    protected function casts(): array
    {
        return [
            'category'  => IncidentCategory::class,
            'status'    => IncidentStatus::class,
            'closed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }

    /** All assignment pivot rows including soft-removed. */
    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'incident_report_assignees')
            ->withPivot(['assigned_at', 'assigned_by_id', 'removed_at']);
    }

    /** Only currently-active assignees (the privacy circle membership). */
    public function currentAssignees(): BelongsToMany
    {
        return $this->assignees()->wherePivotNull('removed_at');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(IncidentReportMessage::class)->orderBy('created_at');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(IncidentReportAttachment::class, 'attachable');
    }

    /** True iff $user is the submitter or a current assignee. */
    public function isInCircle(User $user): bool
    {
        if ($this->employee && $this->employee->user_id === $user->id) {
            return true;
        }
        return $this->currentAssignees()->where('users.id', $user->id)->exists();
    }

    /** Scope to reports visible to the given user (submitter or current assignee). */
    public function scopeVisibleTo(Builder $q, ?User $user): Builder
    {
        if (! $user) return $q->whereRaw('1=0');

        return $q->where(function (Builder $q) use ($user) {
            $q->whereHas('employee', fn ($e) => $e->where('user_id', $user->id))
              ->orWhereHas('currentAssignees', fn ($a) => $a->where('users.id', $user->id));
        });
    }
}
```

- [ ] **Step 2: Create IncidentReportMessage model**

Write `app/Models/IncidentReportMessage.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class IncidentReportMessage extends Model
{
    protected $fillable = ['incident_report_id', 'author_id', 'body'];

    public function report(): BelongsTo
    {
        return $this->belongsTo(IncidentReport::class, 'incident_report_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(IncidentReportAttachment::class, 'attachable');
    }
}
```

- [ ] **Step 3: Create IncidentReportAttachment model**

Write `app/Models/IncidentReportAttachment.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class IncidentReportAttachment extends Model
{
    public $timestamps = false;
    protected $fillable = [
        'attachable_type', 'attachable_id',
        'file_path', 'original_name', 'mime_type', 'size_bytes',
        'uploaded_by_id',
    ];
    protected $casts = ['created_at' => 'datetime'];

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    /** Walk the polymorphic chain back to the owning report. */
    public function reportRoot(): IncidentReport
    {
        $owner = $this->attachable;
        if ($owner instanceof IncidentReport)        return $owner;
        if ($owner instanceof IncidentReportMessage) return $owner->report;
        throw new \LogicException('Attachment is attached to neither IncidentReport nor IncidentReportMessage.');
    }
}
```

- [ ] **Step 4: Lint**

Run: `php -l app/Models/IncidentReport.php app/Models/IncidentReportMessage.php app/Models/IncidentReportAttachment.php`
Expected: three `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add app/Models/IncidentReport.php app/Models/IncidentReportMessage.php app/Models/IncidentReportAttachment.php
git commit -m "feat(incidents): add IncidentReport, Message, and Attachment models"
```

---

## Task 4: Policy (TDD)

Writing the policy tests first; the policy is the privacy invariant of the whole feature, so we encode the rules as failing tests and then make them pass.

**Files:**
- Create: `tests/Unit/Policies/IncidentReportPolicyTest.php`
- Create: `app/Policies/IncidentReportPolicy.php`
- Modify: `app/Providers/AppServiceProvider.php`

- [ ] **Step 1: Write failing policy tests**

Write `tests/Unit/Policies/IncidentReportPolicyTest.php`:

```php
<?php

use App\Models\Employee;
use App\Models\IncidentReport;
use App\Models\User;
use App\Policies\IncidentReportPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeReportWithAssignee(): array {
    $submitterUser = User::factory()->create();
    $submitter     = Employee::factory()->create(['user_id' => $submitterUser->id]);
    $report = IncidentReport::create([
        'employee_id' => $submitter->id,
        'category'    => 'grievance',
        'title'       => 'Test',
        'body'        => 'Lorem ipsum dolor sit amet.',
        'status'      => 'open',
    ]);
    $reviewer = User::factory()->create(['permissions' => ['incidents.review']]);
    $report->assignees()->attach($reviewer->id, [
        'assigned_at'    => now(),
        'assigned_by_id' => $submitterUser->id,
    ]);
    return compact('report', 'submitterUser', 'reviewer');
}

test('view: submitter can view their own report', function () {
    ['report' => $r, 'submitterUser' => $u] = makeReportWithAssignee();
    expect((new IncidentReportPolicy)->view($u, $r))->toBeTrue();
});

test('view: current assignee can view', function () {
    ['report' => $r, 'reviewer' => $u] = makeReportWithAssignee();
    expect((new IncidentReportPolicy)->view($u, $r))->toBeTrue();
});

test('view: removed assignee cannot view', function () {
    ['report' => $r, 'reviewer' => $u] = makeReportWithAssignee();
    $r->assignees()->updateExistingPivot($u->id, ['removed_at' => now()]);
    expect((new IncidentReportPolicy)->view($u->fresh(), $r->fresh()))->toBeFalse();
});

test('view: unrelated employee cannot view', function () {
    ['report' => $r] = makeReportWithAssignee();
    $stranger = User::factory()->create();
    Employee::factory()->create(['user_id' => $stranger->id]);
    expect((new IncidentReportPolicy)->view($stranger, $r))->toBeFalse();
});

test('view: super_admin without assignment cannot view (privacy invariant)', function () {
    ['report' => $r] = makeReportWithAssignee();
    $sa = User::factory()->create(['role' => 'super_admin']);
    expect((new IncidentReportPolicy)->view($sa, $r))->toBeFalse();
});

test('create: only users with an employee row can submit', function () {
    $userWithEmployee = User::factory()->create();
    Employee::factory()->create(['user_id' => $userWithEmployee->id]);
    expect((new IncidentReportPolicy)->create($userWithEmployee))->toBeTrue();

    $userWithoutEmployee = User::factory()->create(['role' => 'super_admin']);
    expect((new IncidentReportPolicy)->create($userWithoutEmployee))->toBeFalse();
});

test('update: submitter can edit while open and no assignees yet', function () {
    $u = User::factory()->create();
    $e = Employee::factory()->create(['user_id' => $u->id]);
    $r = IncidentReport::create([
        'employee_id' => $e->id, 'category' => 'other',
        'title' => 'T', 'body' => 'B body B body', 'status' => 'open',
    ]);
    expect((new IncidentReportPolicy)->update($u, $r))->toBeTrue();
});

test('update: submitter cannot edit after first assignment', function () {
    ['report' => $r, 'submitterUser' => $u] = makeReportWithAssignee();
    expect((new IncidentReportPolicy)->update($u, $r))->toBeFalse();
});

test('close: only current assignees can close', function () {
    ['report' => $r, 'submitterUser' => $sub, 'reviewer' => $rev] = makeReportWithAssignee();
    expect((new IncidentReportPolicy)->close($rev, $r))->toBeTrue();
    expect((new IncidentReportPolicy)->close($sub, $r))->toBeFalse();
});

test('postMessage: requires view + status not closed', function () {
    ['report' => $r, 'submitterUser' => $u] = makeReportWithAssignee();
    expect((new IncidentReportPolicy)->postMessage($u, $r))->toBeTrue();
    $r->update(['status' => 'closed']);
    expect((new IncidentReportPolicy)->postMessage($u, $r->fresh()))->toBeFalse();
});
```

- [ ] **Step 2: Run tests — must fail**

Run: `php artisan test tests/Unit/Policies/IncidentReportPolicyTest.php`
Expected: All 10 tests fail with `Class "App\Policies\IncidentReportPolicy" not found` (policy doesn't exist yet).

- [ ] **Step 3: Implement the policy**

Write `app/Policies/IncidentReportPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Enums\IncidentStatus;
use App\Models\IncidentReport;
use App\Models\IncidentReportAttachment;
use App\Models\User;

class IncidentReportPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, IncidentReport $report): bool
    {
        return $report->isInCircle($user);
    }

    public function create(User $user): bool
    {
        return $user->employee !== null;
    }

    public function update(User $user, IncidentReport $report): bool
    {
        return $report->employee?->user_id === $user->id
            && $report->status === IncidentStatus::Open
            && $report->currentAssignees()->count() === 0;
    }

    public function close(User $user, IncidentReport $report): bool
    {
        return $report->currentAssignees()->where('users.id', $user->id)->exists();
    }

    public function assign(User $user, IncidentReport $report): bool
    {
        return $report->employee?->user_id === $user->id
            || $report->currentAssignees()->where('users.id', $user->id)->exists();
    }

    public function postMessage(User $user, IncidentReport $report): bool
    {
        return $this->view($user, $report) && $report->status !== IncidentStatus::Closed;
    }

    public function downloadAttachment(User $user, IncidentReportAttachment $attachment): bool
    {
        return $this->view($user, $attachment->reportRoot());
    }
}
```

- [ ] **Step 4: Ensure `User::employee()` relation exists**

Run: `grep -n "function employee" app/Models/User.php`
If the User model already has an `employee()` `hasOne` relation, skip. If not, add this method into `app/Models/User.php` alongside the existing relations:

```php
    public function employee(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Models\Employee::class);
    }
```

(This is the standard inverse of the `Employee.user_id` foreign key. Other policies depend on it too — the EmployeeService already uses `$request->user()` with this relation.)

- [ ] **Step 5: Run tests — must pass**

Run: `php artisan test tests/Unit/Policies/IncidentReportPolicyTest.php`
Expected: 10 passed.

- [ ] **Step 6: Register the policy in AppServiceProvider**

Open `app/Providers/AppServiceProvider.php`. Inside `boot()`, near other `Gate::policy(...)` or `Model::policy(...)` registrations, add:

```php
        \Illuminate\Support\Facades\Gate::policy(
            \App\Models\IncidentReport::class,
            \App\Policies\IncidentReportPolicy::class,
        );
```

- [ ] **Step 7: Commit**

```bash
git add tests/Unit/Policies/IncidentReportPolicyTest.php app/Policies/IncidentReportPolicy.php app/Models/User.php app/Providers/AppServiceProvider.php
git commit -m "feat(incidents): add IncidentReportPolicy (TDD)"
```

---

## Task 5: Service layer

**Files:**
- Create: `app/Services/IncidentReportService.php`

- [ ] **Step 1: Write the service**

Write `app/Services/IncidentReportService.php`:

```php
<?php

namespace App\Services;

use App\Enums\IncidentStatus;
use App\Events\Incident\IncidentMessagePosted;
use App\Events\Incident\IncidentReportAssigned;
use App\Events\Incident\IncidentReportClosed;
use App\Events\Incident\IncidentReportReopened;
use App\Events\Incident\IncidentReportUnassigned;
use App\Models\IncidentReport;
use App\Models\IncidentReportAttachment;
use App\Models\IncidentReportMessage;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IncidentReportService
{
    public function list(Request $request): LengthAwarePaginator
    {
        return IncidentReport::with(['employee.user', 'currentAssignees'])
            ->visibleTo($request->user())
            ->when($request->category, fn ($q, $v) => $q->where('category', $v))
            ->when($request->status,   fn ($q, $v) => $q->where('status', $v))
            ->when($request->q,        fn ($q, $v) => $q->where('title', 'like', "%{$v}%"))
            ->latest()
            ->paginate(20)
            ->withQueryString();
    }

    public function create(User $author, array $data, array $files = []): IncidentReport
    {
        if (! $author->employee) {
            throw ValidationException::withMessages(['employee_id' => 'You must have an employee profile to submit an incident report.']);
        }

        return DB::transaction(function () use ($author, $data, $files) {
            $report = IncidentReport::create([
                'employee_id' => $author->employee->id,
                'category'    => $data['category'],
                'title'       => $data['title'],
                'body'        => $data['body'],
                'status'      => IncidentStatus::Open,
            ]);

            foreach ($files as $file) {
                $this->attachFile($report, $file, $author);
            }

            return $report->fresh(['attachments']);
        });
    }

    public function update(IncidentReport $report, array $data): IncidentReport
    {
        $report->update([
            'title' => $data['title'],
            'body'  => $data['body'],
        ]);
        return $report->fresh();
    }

    public function assign(IncidentReport $report, int $userId, User $actor): void
    {
        $target = User::findOrFail($userId);

        if (! $this->userHoldsReviewerPermission($target)) {
            throw ValidationException::withMessages(['user_id' => 'Selected user does not hold the incidents.review permission.']);
        }

        // Already assigned and active?
        $existing = $report->assignees()->where('users.id', $target->id)->first();
        if ($existing && $existing->pivot->removed_at === null) {
            return;
        }

        DB::transaction(function () use ($report, $target, $actor, $existing) {
            if ($existing) {
                $report->assignees()->updateExistingPivot($target->id, [
                    'assigned_at'    => now(),
                    'assigned_by_id' => $actor->id,
                    'removed_at'     => null,
                ]);
            } else {
                $report->assignees()->attach($target->id, [
                    'assigned_at'    => now(),
                    'assigned_by_id' => $actor->id,
                ]);
            }

            if ($report->status === IncidentStatus::Open) {
                $report->update(['status' => IncidentStatus::InReview]);
            }
        });

        event(new IncidentReportAssigned($report->fresh(), $target, $actor));
    }

    public function unassign(IncidentReport $report, int $userId, User $actor): void
    {
        $report->assignees()->updateExistingPivot($userId, ['removed_at' => now()]);
        $user = User::find($userId);
        if ($user) {
            event(new IncidentReportUnassigned($report->fresh(), $user, $actor));
        }
    }

    public function postMessage(IncidentReport $report, User $author, array $data, array $files = []): IncidentReportMessage
    {
        return DB::transaction(function () use ($report, $author, $data, $files) {
            $message = IncidentReportMessage::create([
                'incident_report_id' => $report->id,
                'author_id'          => $author->id,
                'body'               => $data['body'],
            ]);

            foreach ($files as $file) {
                $this->attachFile($message, $file, $author);
            }

            event(new IncidentMessagePosted($message->fresh(['attachments'])));
            return $message;
        });
    }

    public function close(IncidentReport $report, User $actor, ?string $note): void
    {
        $report->update([
            'status'          => IncidentStatus::Closed,
            'closed_at'       => now(),
            'closed_by_id'    => $actor->id,
            'resolution_note' => $note,
        ]);
        event(new IncidentReportClosed($report->fresh(), $actor));
    }

    public function reopen(IncidentReport $report, User $actor): void
    {
        $report->update([
            'status'          => IncidentStatus::InReview,
            'closed_at'       => null,
            'closed_by_id'    => null,
            'resolution_note' => null,
        ]);
        event(new IncidentReportReopened($report->fresh(), $actor));
    }

    /** Holders pool: users with the permission via role OR per-user permissions JSON. */
    public function eligibleReviewers(): \Illuminate\Database\Eloquent\Collection
    {
        return User::query()
            ->where(function ($q) {
                $q->whereHas('roles.permissions', fn ($p) => $p->where('slug', 'incidents.review'))
                  ->orWhereJsonContains('permissions', 'incidents.review');
            })
            ->select('id', 'name', 'role')
            ->orderBy('name')
            ->get();
    }

    private function userHoldsReviewerPermission(User $user): bool
    {
        if (in_array('incidents.review', (array) ($user->permissions ?? []), true)) {
            return true;
        }
        return $user->roles()
            ->whereHas('permissions', fn ($q) => $q->where('slug', 'incidents.review'))
            ->exists();
    }

    private function attachFile($attachable, UploadedFile $file, User $uploader): IncidentReportAttachment
    {
        $dir  = ($attachable instanceof IncidentReport ? $attachable->id : $attachable->report->id);
        $name = Str::uuid() . '-' . $file->getClientOriginalName();
        $path = $file->storeAs((string) $dir, $name, 'incidents');

        return $attachable->attachments()->create([
            'file_path'      => $path,
            'original_name'  => $file->getClientOriginalName(),
            'mime_type'      => $file->getMimeType(),
            'size_bytes'     => $file->getSize(),
            'uploaded_by_id' => $uploader->id,
        ]);
    }
}
```

- [ ] **Step 2: Lint**

Run: `php -l app/Services/IncidentReportService.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add app/Services/IncidentReportService.php
git commit -m "feat(incidents): add IncidentReportService"
```

---

## Task 6: Form Requests

**Files:**
- Create: `app/Http/Requests/IncidentReport/StoreIncidentReportRequest.php`
- Create: `app/Http/Requests/IncidentReport/UpdateIncidentReportRequest.php`
- Create: `app/Http/Requests/IncidentReport/AssignIncidentReportRequest.php`
- Create: `app/Http/Requests/IncidentReport/StoreIncidentMessageRequest.php`
- Create: `app/Http/Requests/IncidentReport/CloseIncidentReportRequest.php`

- [ ] **Step 1: StoreIncidentReportRequest**

Write `app/Http/Requests/IncidentReport/StoreIncidentReportRequest.php`:

```php
<?php

namespace App\Http\Requests\IncidentReport;

use Illuminate\Foundation\Http\FormRequest;

class StoreIncidentReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->employee !== null;
    }

    public function rules(): array
    {
        return [
            'category'        => ['required', 'in:grievance,improvement,safety,other'],
            'title'           => ['required', 'string', 'min:6', 'max:180'],
            'body'            => ['required', 'string', 'min:20', 'max:10000'],
            'attachments'     => ['nullable', 'array', 'max:3'],
            'attachments.*'   => ['file', 'mimes:pdf,png,jpg,jpeg,doc,docx', 'max:10240'],
        ];
    }
}
```

- [ ] **Step 2: UpdateIncidentReportRequest**

Write `app/Http/Requests/IncidentReport/UpdateIncidentReportRequest.php`:

```php
<?php

namespace App\Http\Requests\IncidentReport;

use Illuminate\Foundation\Http\FormRequest;

class UpdateIncidentReportRequest extends FormRequest
{
    public function authorize(): bool { return true; /* policy handles auth */ }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:6', 'max:180'],
            'body'  => ['required', 'string', 'min:20', 'max:10000'],
        ];
    }
}
```

- [ ] **Step 3: AssignIncidentReportRequest**

Write `app/Http/Requests/IncidentReport/AssignIncidentReportRequest.php`:

```php
<?php

namespace App\Http\Requests\IncidentReport;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignIncidentReportRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'user_id' => [
                'required',
                Rule::exists('users', 'id'),
                function ($attr, $value, $fail) {
                    $u = User::find($value);
                    if (! $u) { $fail('User not found.'); return; }

                    $hasPerm = in_array('incidents.review', (array) ($u->permissions ?? []), true)
                        || $u->roles()->whereHas('permissions', fn ($q) => $q->where('slug', 'incidents.review'))->exists();

                    if (! $hasPerm) {
                        $fail('Selected user does not hold the incidents.review permission.');
                    }
                },
            ],
        ];
    }
}
```

- [ ] **Step 4: StoreIncidentMessageRequest**

Write `app/Http/Requests/IncidentReport/StoreIncidentMessageRequest.php`:

```php
<?php

namespace App\Http\Requests\IncidentReport;

use Illuminate\Foundation\Http\FormRequest;

class StoreIncidentMessageRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'body'          => ['required', 'string', 'min:1', 'max:10000'],
            'attachments'   => ['nullable', 'array', 'max:3'],
            'attachments.*' => ['file', 'mimes:pdf,png,jpg,jpeg,doc,docx', 'max:10240'],
        ];
    }
}
```

- [ ] **Step 5: CloseIncidentReportRequest**

Write `app/Http/Requests/IncidentReport/CloseIncidentReportRequest.php`:

```php
<?php

namespace App\Http\Requests\IncidentReport;

use Illuminate\Foundation\Http\FormRequest;

class CloseIncidentReportRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'resolution_note' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
```

- [ ] **Step 6: Lint all five**

Run: `php -l app/Http/Requests/IncidentReport/StoreIncidentReportRequest.php app/Http/Requests/IncidentReport/UpdateIncidentReportRequest.php app/Http/Requests/IncidentReport/AssignIncidentReportRequest.php app/Http/Requests/IncidentReport/StoreIncidentMessageRequest.php app/Http/Requests/IncidentReport/CloseIncidentReportRequest.php`
Expected: five `No syntax errors detected`.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/IncidentReport
git commit -m "feat(incidents): add form requests for incident endpoints"
```

---

## Task 7: Events

**Files:**
- Create: `app/Events/Incident/IncidentReportAssigned.php`
- Create: `app/Events/Incident/IncidentReportUnassigned.php`
- Create: `app/Events/Incident/IncidentMessagePosted.php`
- Create: `app/Events/Incident/IncidentReportClosed.php`
- Create: `app/Events/Incident/IncidentReportReopened.php`

Each event is a small data carrier — no business logic, no broadcasting. Listeners (Task 8) consume them.

- [ ] **Step 1: IncidentReportAssigned**

Write `app/Events/Incident/IncidentReportAssigned.php`:

```php
<?php

namespace App\Events\Incident;

use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class IncidentReportAssigned
{
    use Dispatchable;

    public function __construct(
        public IncidentReport $report,
        public User $assignee,
        public User $actor,
    ) {}
}
```

- [ ] **Step 2: IncidentReportUnassigned**

Write `app/Events/Incident/IncidentReportUnassigned.php`:

```php
<?php

namespace App\Events\Incident;

use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class IncidentReportUnassigned
{
    use Dispatchable;

    public function __construct(
        public IncidentReport $report,
        public User $removedAssignee,
        public User $actor,
    ) {}
}
```

- [ ] **Step 3: IncidentMessagePosted**

Write `app/Events/Incident/IncidentMessagePosted.php`:

```php
<?php

namespace App\Events\Incident;

use App\Models\IncidentReportMessage;
use Illuminate\Foundation\Events\Dispatchable;

class IncidentMessagePosted
{
    use Dispatchable;

    public function __construct(public IncidentReportMessage $message) {}
}
```

- [ ] **Step 4: IncidentReportClosed**

Write `app/Events/Incident/IncidentReportClosed.php`:

```php
<?php

namespace App\Events\Incident;

use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class IncidentReportClosed
{
    use Dispatchable;

    public function __construct(
        public IncidentReport $report,
        public User $actor,
    ) {}
}
```

- [ ] **Step 5: IncidentReportReopened**

Write `app/Events/Incident/IncidentReportReopened.php`:

```php
<?php

namespace App\Events\Incident;

use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class IncidentReportReopened
{
    use Dispatchable;

    public function __construct(
        public IncidentReport $report,
        public User $actor,
    ) {}
}
```

- [ ] **Step 6: Lint all**

Run: `php -l app/Events/Incident/IncidentReportAssigned.php app/Events/Incident/IncidentReportUnassigned.php app/Events/Incident/IncidentMessagePosted.php app/Events/Incident/IncidentReportClosed.php app/Events/Incident/IncidentReportReopened.php`
Expected: five `No syntax errors detected`.

- [ ] **Step 7: Commit**

```bash
git add app/Events/Incident
git commit -m "feat(incidents): add domain events"
```

---

## Task 8: Listeners (notification writers)

Each listener writes one or more rows to the existing `notifications` table. The notification row shape matches the project's existing notification convention — read `app/Models/Notification.php` or one existing listener (e.g. `app/Listeners/Tickets/NotifyAssignee.php` if present) to confirm the column layout before authoring. Below the listeners assume the standard shape `{ user_id, kind, message, data (json), read_at (null), created_at }`.

**Files:**
- Create: `app/Listeners/Incident/NotifyAssignee.php`
- Create: `app/Listeners/Incident/NotifyUnassigned.php`
- Create: `app/Listeners/Incident/NotifyMessageRecipients.php`
- Create: `app/Listeners/Incident/NotifySubmitterOnClose.php`
- Create: `app/Listeners/Incident/NotifyCircleOnReopen.php`
- Modify: `app/Providers/AppServiceProvider.php`

- [ ] **Step 1: Confirm the notifications row shape**

Run: `grep -n "fillable" app/Models/Notification.php`
Read the resulting `$fillable` array to confirm column names. If the file does not exist or the shape differs from `{ user_id, kind, message, data, read_at }`, STOP and inspect — listeners below will need to be adjusted to match the actual schema. (The existing `notifications` table is what `NotificationBell.vue` reads from via `page.props.notifications`.)

- [ ] **Step 2: NotifyAssignee**

Write `app/Listeners/Incident/NotifyAssignee.php`:

```php
<?php

namespace App\Listeners\Incident;

use App\Events\Incident\IncidentReportAssigned;
use App\Models\Notification;

class NotifyAssignee
{
    public function handle(IncidentReportAssigned $e): void
    {
        Notification::create([
            'user_id' => $e->assignee->id,
            'kind'    => 'incident.assigned',
            'message' => "You've been assigned an incident report: '{$e->report->title}'",
            'data'    => [
                'incident_report_id' => $e->report->id,
                'route'              => 'incidents.show',
            ],
        ]);
    }
}
```

- [ ] **Step 3: NotifyUnassigned**

Write `app/Listeners/Incident/NotifyUnassigned.php`:

```php
<?php

namespace App\Listeners\Incident;

use App\Events\Incident\IncidentReportUnassigned;
use App\Models\Notification;

class NotifyUnassigned
{
    public function handle(IncidentReportUnassigned $e): void
    {
        // Spec §6 note: this row is written BEFORE removed_at takes effect
        // server-side, so the recipient reads the title once; the deep-link
        // will return 403 on next click. Sound preset is silent in NotificationBell.
        Notification::create([
            'user_id' => $e->removedAssignee->id,
            'kind'    => 'incident.unassigned',
            'message' => "You no longer have access to incident: '{$e->report->title}'",
            'data'    => [
                'incident_report_id' => $e->report->id,
                'route'              => 'incidents.show',
            ],
        ]);
    }
}
```

- [ ] **Step 4: NotifyMessageRecipients**

Write `app/Listeners/Incident/NotifyMessageRecipients.php`:

```php
<?php

namespace App\Listeners\Incident;

use App\Events\Incident\IncidentMessagePosted;
use App\Models\Notification;

class NotifyMessageRecipients
{
    public function handle(IncidentMessagePosted $e): void
    {
        $report = $e->message->report()->with(['employee.user', 'currentAssignees'])->first();
        if (! $report) return;

        $authorId = $e->message->author_id;
        $recipients = collect()
            ->push($report->employee?->user_id)
            ->merge($report->currentAssignees->pluck('id'))
            ->filter()
            ->unique()
            ->reject(fn ($id) => $id === $authorId);

        foreach ($recipients as $userId) {
            Notification::create([
                'user_id' => $userId,
                'kind'    => 'incident.message',
                'message' => "New reply on incident: '{$report->title}'",
                'data'    => [
                    'incident_report_id' => $report->id,
                    'route'              => 'incidents.show',
                ],
            ]);
        }
    }
}
```

- [ ] **Step 5: NotifySubmitterOnClose**

Write `app/Listeners/Incident/NotifySubmitterOnClose.php`:

```php
<?php

namespace App\Listeners\Incident;

use App\Events\Incident\IncidentReportClosed;
use App\Models\Notification;

class NotifySubmitterOnClose
{
    public function handle(IncidentReportClosed $e): void
    {
        $submitterUserId = $e->report->employee?->user_id;
        if (! $submitterUserId) return;

        Notification::create([
            'user_id' => $submitterUserId,
            'kind'    => 'incident.closed',
            'message' => "Your incident report '{$e->report->title}' has been resolved",
            'data'    => [
                'incident_report_id' => $e->report->id,
                'route'              => 'incidents.show',
            ],
        ]);
    }
}
```

- [ ] **Step 6: NotifyCircleOnReopen**

Write `app/Listeners/Incident/NotifyCircleOnReopen.php`:

```php
<?php

namespace App\Listeners\Incident;

use App\Events\Incident\IncidentReportReopened;
use App\Models\Notification;

class NotifyCircleOnReopen
{
    public function handle(IncidentReportReopened $e): void
    {
        $report = $e->report->load(['employee.user', 'currentAssignees']);
        $recipients = collect()
            ->push($report->employee?->user_id)
            ->merge($report->currentAssignees->pluck('id'))
            ->filter()
            ->unique()
            ->reject(fn ($id) => $id === $e->actor->id);

        foreach ($recipients as $userId) {
            Notification::create([
                'user_id' => $userId,
                'kind'    => 'incident.reopened',
                'message' => "Incident '{$report->title}' was reopened for further review",
                'data'    => [
                    'incident_report_id' => $report->id,
                    'route'              => 'incidents.show',
                ],
            ]);
        }
    }
}
```

- [ ] **Step 7: Register event-listener pairs**

Open `app/Providers/AppServiceProvider.php`. Inside `boot()`, add (alongside any existing `Event::listen(...)` calls):

```php
        \Illuminate\Support\Facades\Event::listen(
            \App\Events\Incident\IncidentReportAssigned::class,
            \App\Listeners\Incident\NotifyAssignee::class,
        );
        \Illuminate\Support\Facades\Event::listen(
            \App\Events\Incident\IncidentReportUnassigned::class,
            \App\Listeners\Incident\NotifyUnassigned::class,
        );
        \Illuminate\Support\Facades\Event::listen(
            \App\Events\Incident\IncidentMessagePosted::class,
            \App\Listeners\Incident\NotifyMessageRecipients::class,
        );
        \Illuminate\Support\Facades\Event::listen(
            \App\Events\Incident\IncidentReportClosed::class,
            \App\Listeners\Incident\NotifySubmitterOnClose::class,
        );
        \Illuminate\Support\Facades\Event::listen(
            \App\Events\Incident\IncidentReportReopened::class,
            \App\Listeners\Incident\NotifyCircleOnReopen::class,
        );
```

- [ ] **Step 8: Lint**

Run: `php -l app/Listeners/Incident/NotifyAssignee.php app/Listeners/Incident/NotifyUnassigned.php app/Listeners/Incident/NotifyMessageRecipients.php app/Listeners/Incident/NotifySubmitterOnClose.php app/Listeners/Incident/NotifyCircleOnReopen.php`
Expected: five `No syntax errors detected`.

- [ ] **Step 9: Commit**

```bash
git add app/Listeners/Incident app/Providers/AppServiceProvider.php
git commit -m "feat(incidents): wire notification listeners"
```

---

## Task 9: Resources + Controller + Routes

**Files:**
- Create: `app/Http/Resources/IncidentReportResource.php`
- Create: `app/Http/Resources/IncidentReportMessageResource.php`
- Create: `app/Http/Resources/IncidentReportAttachmentResource.php`
- Create: `app/Http/Controllers/IncidentReportController.php`
- Modify: `routes/web.php`

- [ ] **Step 1: IncidentReportAttachmentResource**

Write `app/Http/Resources/IncidentReportAttachmentResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IncidentReportAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'original_name' => $this->original_name,
            'mime_type'     => $this->mime_type,
            'size_bytes'    => $this->size_bytes,
            'download_url'  => route('incidents.attachments.download', $this->id),
        ];
    }
}
```

- [ ] **Step 2: IncidentReportMessageResource**

Write `app/Http/Resources/IncidentReportMessageResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IncidentReportMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'author'      => [
                'id'   => $this->author?->id,
                'name' => $this->author?->name,
            ],
            'body'        => $this->body,
            'created_at'  => $this->created_at?->toISOString(),
            'attachments' => IncidentReportAttachmentResource::collection($this->whenLoaded('attachments')),
        ];
    }
}
```

- [ ] **Step 3: IncidentReportResource**

Write `app/Http/Resources/IncidentReportResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IncidentReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'category'        => $this->category?->value,
            'category_label'  => $this->category?->label(),
            'title'           => $this->title,
            'body'            => $this->body,
            'status'          => $this->status?->value,
            'status_label'    => $this->status?->label(),
            'closed_at'       => $this->closed_at?->toISOString(),
            'resolution_note' => $this->resolution_note,
            'created_at'      => $this->created_at?->toISOString(),
            'submitter'       => $this->whenLoaded('employee', fn () => [
                'id'   => $this->employee->id,
                'name' => $this->employee->user?->name,
            ]),
            'assignees'       => $this->whenLoaded('currentAssignees', fn () => $this->currentAssignees->map(fn ($u) => [
                'id'   => $u->id,
                'name' => $u->name,
                'role' => $u->role,
            ])->values()),
            'messages'        => IncidentReportMessageResource::collection($this->whenLoaded('messages')),
            'attachments'     => IncidentReportAttachmentResource::collection($this->whenLoaded('attachments')),
        ];
    }
}
```

- [ ] **Step 4: Controller**

Write `app/Http/Controllers/IncidentReportController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\IncidentReport\AssignIncidentReportRequest;
use App\Http\Requests\IncidentReport\CloseIncidentReportRequest;
use App\Http\Requests\IncidentReport\StoreIncidentMessageRequest;
use App\Http\Requests\IncidentReport\StoreIncidentReportRequest;
use App\Http\Requests\IncidentReport\UpdateIncidentReportRequest;
use App\Http\Resources\IncidentReportResource;
use App\Models\IncidentReport;
use App\Models\IncidentReportAttachment;
use App\Models\User;
use App\Services\IncidentReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncidentReportController extends Controller
{
    public function __construct(private readonly IncidentReportService $service) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Governance/Incidents/Index', [
            'reports'      => IncidentReportResource::collection($this->service->list($request)),
            'reviewers'    => $this->service->eligibleReviewers(),
            'filters'      => $request->only(['category', 'status', 'q']),
            'activeModule' => 'governance',
        ]);
    }

    public function show(IncidentReport $report): Response
    {
        $this->authorize('view', $report);
        $report->load(['employee.user', 'currentAssignees', 'messages.author', 'messages.attachments', 'attachments']);

        return Inertia::render('Governance/Incidents/Show', [
            'report'       => new IncidentReportResource($report),
            'reviewers'    => $this->service->eligibleReviewers(),
            'activeModule' => 'governance',
        ]);
    }

    public function store(StoreIncidentReportRequest $request): RedirectResponse
    {
        $report = $this->service->create(
            $request->user(),
            $request->validated(),
            (array) $request->file('attachments', []),
        );
        return redirect()->route('incidents.show', $report)->with('success', 'Your report has been submitted privately.');
    }

    public function update(UpdateIncidentReportRequest $request, IncidentReport $report): RedirectResponse
    {
        $this->authorize('update', $report);
        $this->service->update($report, $request->validated());
        return back()->with('success', 'Report updated.');
    }

    public function assign(AssignIncidentReportRequest $request, IncidentReport $report): RedirectResponse
    {
        $this->authorize('assign', $report);
        $this->service->assign($report, (int) $request->validated('user_id'), $request->user());
        return back()->with('success', 'Reviewer assigned.');
    }

    public function unassign(IncidentReport $report, User $user, Request $request): RedirectResponse
    {
        $this->authorize('assign', $report);
        $this->service->unassign($report, $user->id, $request->user());
        return back()->with('success', 'Reviewer removed.');
    }

    public function postMessage(StoreIncidentMessageRequest $request, IncidentReport $report): RedirectResponse
    {
        $this->authorize('postMessage', $report);
        $this->service->postMessage(
            $report,
            $request->user(),
            $request->validated(),
            (array) $request->file('attachments', []),
        );
        return back()->with('success', 'Reply posted.');
    }

    public function close(CloseIncidentReportRequest $request, IncidentReport $report): RedirectResponse
    {
        $this->authorize('close', $report);
        $this->service->close($report, $request->user(), $request->validated('resolution_note'));
        return back()->with('success', 'Report closed.');
    }

    public function reopen(IncidentReport $report, Request $request): RedirectResponse
    {
        $this->authorize('close', $report); // reopen permission == close permission (current assignees)
        $this->service->reopen($report, $request->user());
        return back()->with('success', 'Report reopened.');
    }

    public function downloadAttachment(IncidentReportAttachment $attachment): StreamedResponse
    {
        $this->authorize('downloadAttachment', $attachment);
        return Storage::disk('incidents')->download($attachment->file_path, $attachment->original_name);
    }
}
```

- [ ] **Step 5: Add routes**

Open `routes/web.php`. Locate the existing authenticated-route group (typically `Route::middleware(['auth', 'audit'])->group(function () { … })`). Add the following INSIDE that group, near the existing Governance / Complaints route blocks:

```php
    Route::prefix('governance/incidents')->name('incidents.')->group(function () {
        Route::get('/',                                  [\App\Http\Controllers\IncidentReportController::class, 'index'])    ->name('index');
        Route::get('/{report}',                          [\App\Http\Controllers\IncidentReportController::class, 'show'])     ->name('show');
        Route::post('/',                                 [\App\Http\Controllers\IncidentReportController::class, 'store'])    ->name('store');
        Route::patch('/{report}',                        [\App\Http\Controllers\IncidentReportController::class, 'update'])   ->name('update');
        Route::post('/{report}/assign',                  [\App\Http\Controllers\IncidentReportController::class, 'assign'])   ->name('assign');
        Route::delete('/{report}/assign/{user}',         [\App\Http\Controllers\IncidentReportController::class, 'unassign']) ->name('unassign');
        Route::post('/{report}/messages',                [\App\Http\Controllers\IncidentReportController::class, 'postMessage'])->name('messages.store');
        Route::post('/{report}/close',                   [\App\Http\Controllers\IncidentReportController::class, 'close'])    ->name('close');
        Route::post('/{report}/reopen',                  [\App\Http\Controllers\IncidentReportController::class, 'reopen'])   ->name('reopen');
        Route::get('/attachments/{attachment}/download', [\App\Http\Controllers\IncidentReportController::class, 'downloadAttachment'])->name('attachments.download');
    });
```

- [ ] **Step 6: Verify routes are registered**

Run: `php artisan route:list --name=incidents`
Expected: 10 rows, each starting with `incidents.` (index/show/store/update/assign/unassign/messages.store/close/reopen/attachments.download).

- [ ] **Step 7: Lint**

Run: `php -l app/Http/Controllers/IncidentReportController.php app/Http/Resources/IncidentReportResource.php app/Http/Resources/IncidentReportMessageResource.php app/Http/Resources/IncidentReportAttachmentResource.php`
Expected: four `No syntax errors detected`.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/IncidentReportController.php app/Http/Resources routes/web.php
git commit -m "feat(incidents): add controller, resources, and routes"
```

---

## Task 10: Feature tests

The spec's §9.1 lists 21 feature tests. Implement them in one Pest file. Use the project's `permissions` JSON convention for granting `incidents.review` to fixture users.

**Files:**
- Create: `tests/Feature/Governance/IncidentReportTest.php`

- [ ] **Step 1: Write the feature test file**

Write `tests/Feature/Governance/IncidentReportTest.php`:

```php
<?php

use App\Events\Incident\IncidentMessagePosted;
use App\Events\Incident\IncidentReportAssigned;
use App\Models\Employee;
use App\Models\IncidentReport;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function asEmployee(): array {
    $user = User::factory()->create();
    $emp  = Employee::factory()->create(['user_id' => $user->id]);
    return [$user, $emp];
}

function asReviewer(): User {
    return User::factory()->create(['permissions' => ['incidents.review']]);
}

function aReport(?User $submitterUser = null, ?Employee $emp = null): IncidentReport {
    if (! $submitterUser) {
        [$submitterUser, $emp] = asEmployee();
    }
    return IncidentReport::create([
        'employee_id' => $emp->id,
        'category'    => 'grievance',
        'title'       => 'Concern about overtime policy',
        'body'        => 'I would like to discuss the overtime policy as it has been applied inconsistently.',
        'status'      => 'open',
    ]);
}

test('it_lets_an_employee_submit_an_incident_report', function () {
    Event::fake();
    [$user] = asEmployee();
    $this->actingAs($user)
        ->post(route('incidents.store'), [
            'category' => 'grievance',
            'title'    => 'Concern about overtime policy',
            'body'     => 'I would like to discuss the overtime policy because it has been applied inconsistently.',
        ])
        ->assertRedirect();

    $r = IncidentReport::latest()->first();
    expect($r)->not->toBeNull();
    expect($r->status->value)->toBe('open');
    expect($r->assignees()->count())->toBe(0);
});

test('it_rejects_submission_without_a_category', function () {
    [$user] = asEmployee();
    $this->actingAs($user)
        ->post(route('incidents.store'), [
            'title' => 'X X X X X X', 'body' => str_repeat('y', 25),
        ])
        ->assertSessionHasErrors('category');
});

test('it_rejects_an_attachment_over_10mb', function () {
    Storage::fake('incidents');
    [$user] = asEmployee();
    $this->actingAs($user)
        ->post(route('incidents.store'), [
            'category' => 'other', 'title' => 'X X X X X X',
            'body' => str_repeat('y', 25),
            'attachments' => [UploadedFile::fake()->create('big.pdf', 12000)],  // 12 MB
        ])
        ->assertSessionHasErrors('attachments.0');
});

test('it_persists_attachments_on_the_private_disk', function () {
    Storage::fake('incidents');
    [$user] = asEmployee();
    $this->actingAs($user)
        ->post(route('incidents.store'), [
            'category' => 'safety',
            'title'    => 'Wet floor in lobby',
            'body'     => 'There has been a wet floor in the lobby for two days without signage.',
            'attachments' => [UploadedFile::fake()->image('photo.jpg', 800, 600)],
        ])
        ->assertRedirect();

    $r = IncidentReport::latest()->first();
    expect($r->attachments()->count())->toBe(1);
    Storage::disk('incidents')->assertExists($r->attachments->first()->file_path);
});

test('submitter_can_view_their_own_report', function () {
    [$user, $emp] = asEmployee();
    $r = aReport($user, $emp);
    $this->actingAs($user)->get(route('incidents.show', $r))->assertOk();
});

test('unrelated_employee_cannot_view_a_report', function () {
    $r = aReport();
    [$stranger] = asEmployee();
    $this->actingAs($stranger)->get(route('incidents.show', $r))->assertForbidden();
});

test('super_admin_without_assignment_cannot_view_a_report', function () {
    $r = aReport();
    $sa = User::factory()->create(['role' => 'super_admin']);
    $this->actingAs($sa)->get(route('incidents.show', $r))->assertForbidden();
});

test('assignee_can_view_an_assigned_report', function () {
    [$subUser, $emp] = asEmployee();
    $r = aReport($subUser, $emp);
    $rev = asReviewer();
    $r->assignees()->attach($rev->id, ['assigned_at' => now(), 'assigned_by_id' => $subUser->id]);
    $this->actingAs($rev)->get(route('incidents.show', $r))->assertOk();
});

test('removed_assignee_can_no_longer_view_the_report', function () {
    [$subUser, $emp] = asEmployee();
    $r = aReport($subUser, $emp);
    $rev = asReviewer();
    $r->assignees()->attach($rev->id, ['assigned_at' => now(), 'assigned_by_id' => $subUser->id]);
    $r->assignees()->updateExistingPivot($rev->id, ['removed_at' => now()]);
    $this->actingAs($rev)->get(route('incidents.show', $r))->assertForbidden();
});

test('only_users_with_incidents_review_can_be_assigned', function () {
    [$subUser, $emp] = asEmployee();
    $r = aReport($subUser, $emp);
    $randomUser = User::factory()->create();  // no incidents.review
    $this->actingAs($subUser)
        ->post(route('incidents.assign', $r), ['user_id' => $randomUser->id])
        ->assertSessionHasErrors('user_id');
});

test('first_assignment_transitions_status_to_in_review', function () {
    [$subUser, $emp] = asEmployee();
    $r = aReport($subUser, $emp);
    $rev = asReviewer();
    $this->actingAs($subUser)
        ->post(route('incidents.assign', $r), ['user_id' => $rev->id])
        ->assertRedirect();
    expect($r->fresh()->status->value)->toBe('in_review');
});

test('assignee_can_post_a_message', function () {
    [$subUser, $emp] = asEmployee();
    $r = aReport($subUser, $emp);
    $rev = asReviewer();
    $r->assignees()->attach($rev->id, ['assigned_at' => now(), 'assigned_by_id' => $subUser->id]);
    $this->actingAs($rev)
        ->post(route('incidents.messages.store', $r), ['body' => 'Thanks, will look into this.'])
        ->assertRedirect();
    expect($r->messages()->count())->toBe(1);
});

test('submitter_can_post_a_message', function () {
    [$subUser, $emp] = asEmployee();
    $r = aReport($subUser, $emp);
    $rev = asReviewer();
    $r->assignees()->attach($rev->id, ['assigned_at' => now(), 'assigned_by_id' => $subUser->id]);
    $this->actingAs($subUser)
        ->post(route('incidents.messages.store', $r), ['body' => 'Additional context: this happened on Friday.'])
        ->assertRedirect();
});

test('non_member_cannot_post_a_message', function () {
    $r = aReport();
    [$stranger] = asEmployee();
    $this->actingAs($stranger)
        ->post(route('incidents.messages.store', $r), ['body' => 'Hi.'])
        ->assertForbidden();
});

test('assignee_can_close_a_report', function () {
    [$subUser, $emp] = asEmployee();
    $r = aReport($subUser, $emp);
    $rev = asReviewer();
    $r->assignees()->attach($rev->id, ['assigned_at' => now(), 'assigned_by_id' => $subUser->id]);
    $this->actingAs($rev)
        ->post(route('incidents.close', $r), ['resolution_note' => 'Discussed and addressed in 1:1.'])
        ->assertRedirect();
    expect($r->fresh()->status->value)->toBe('closed');
    expect($r->fresh()->closed_at)->not->toBeNull();
});

test('submitter_cannot_close_their_own_report', function () {
    [$subUser, $emp] = asEmployee();
    $r = aReport($subUser, $emp);
    $rev = asReviewer();
    $r->assignees()->attach($rev->id, ['assigned_at' => now(), 'assigned_by_id' => $subUser->id]);
    $this->actingAs($subUser)
        ->post(route('incidents.close', $r), ['resolution_note' => null])
        ->assertForbidden();
});

test('assignee_can_reopen_a_closed_report', function () {
    [$subUser, $emp] = asEmployee();
    $r = aReport($subUser, $emp);
    $rev = asReviewer();
    $r->assignees()->attach($rev->id, ['assigned_at' => now(), 'assigned_by_id' => $subUser->id]);
    $r->update(['status' => 'closed', 'closed_at' => now(), 'closed_by_id' => $rev->id]);
    $this->actingAs($rev)->post(route('incidents.reopen', $r))->assertRedirect();
    expect($r->fresh()->status->value)->toBe('in_review');
});

test('closing_locks_the_thread', function () {
    [$subUser, $emp] = asEmployee();
    $r = aReport($subUser, $emp);
    $rev = asReviewer();
    $r->assignees()->attach($rev->id, ['assigned_at' => now(), 'assigned_by_id' => $subUser->id]);
    $r->update(['status' => 'closed']);
    $this->actingAs($subUser)
        ->post(route('incidents.messages.store', $r), ['body' => 'Wait, one more thing.'])
        ->assertForbidden();
});

test('attachment_download_requires_view_permission', function () {
    Storage::fake('incidents');
    [$subUser, $emp] = asEmployee();
    $this->actingAs($subUser)
        ->post(route('incidents.store'), [
            'category' => 'safety', 'title' => 'Wet floor in lobby xyz',
            'body' => str_repeat('a', 25),
            'attachments' => [UploadedFile::fake()->image('photo.jpg')],
        ])
        ->assertRedirect();
    $att = IncidentReport::latest()->first()->attachments()->first();

    [$stranger] = asEmployee();
    $this->actingAs($stranger)->get(route('incidents.attachments.download', $att))->assertForbidden();

    $this->actingAs($subUser)->get(route('incidents.attachments.download', $att))->assertOk();
});

test('assigning_a_user_fires_assigned_notification', function () {
    [$subUser, $emp] = asEmployee();
    $r = aReport($subUser, $emp);
    $rev = asReviewer();
    $this->actingAs($subUser)
        ->post(route('incidents.assign', $r), ['user_id' => $rev->id])
        ->assertRedirect();
    expect(Notification::where('user_id', $rev->id)->where('kind', 'incident.assigned')->exists())->toBeTrue();
});

test('posting_a_message_notifies_other_circle_members_but_not_author', function () {
    [$subUser, $emp] = asEmployee();
    $r = aReport($subUser, $emp);
    $rev = asReviewer();
    $r->assignees()->attach($rev->id, ['assigned_at' => now(), 'assigned_by_id' => $subUser->id]);

    $this->actingAs($rev)
        ->post(route('incidents.messages.store', $r), ['body' => 'On it.'])
        ->assertRedirect();

    expect(Notification::where('user_id', $subUser->id)->where('kind', 'incident.message')->count())->toBe(1);
    expect(Notification::where('user_id', $rev->id)->where('kind', 'incident.message')->count())->toBe(0);
});
```

- [ ] **Step 2: Run feature tests — must pass**

Run: `php artisan test tests/Feature/Governance/IncidentReportTest.php`
Expected: 21 passed.

- [ ] **Step 3: Run the policy tests too as a regression check**

Run: `php artisan test tests/Unit/Policies/IncidentReportPolicyTest.php`
Expected: 10 passed.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Governance/IncidentReportTest.php
git commit -m "test(incidents): 21 feature tests for incident reporting"
```

---

## Task 11: Vue components (Category badge, Status pill, Message bubble, Attachment chip)

**Files:**
- Create: `resources/js/Components/Incidents/CategoryBadge.vue`
- Create: `resources/js/Components/Incidents/StatusPill.vue`
- Create: `resources/js/Components/Incidents/MessageBubble.vue`
- Create: `resources/js/Components/Incidents/AttachmentChip.vue`

- [ ] **Step 1: CategoryBadge**

Write `resources/js/Components/Incidents/CategoryBadge.vue`:

```vue
<script setup>
import { computed } from 'vue';

const props = defineProps({ category: { type: String, required: true } });

const meta = computed(() => ({
    grievance:    { label: 'Grievance',              cls: 'bg-fuchsia-50 text-fuchsia-700 border-fuchsia-200' },
    improvement:  { label: 'Improvement Suggestion', cls: 'bg-blue-50    text-blue-700    border-blue-200'    },
    safety:       { label: 'Workplace Safety',       cls: 'bg-red-50     text-red-700     border-red-200'     },
    other:        { label: 'Other',                  cls: 'bg-slate-50   text-slate-700   border-slate-200'   },
}[props.category] ?? { label: props.category, cls: 'bg-slate-50 text-slate-700 border-slate-200' }));
</script>

<template>
    <span :class="['inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.14em]', meta.cls]">
        {{ meta.label }}
    </span>
</template>
```

- [ ] **Step 2: StatusPill**

Write `resources/js/Components/Incidents/StatusPill.vue`:

```vue
<script setup>
import { computed } from 'vue';

const props = defineProps({ status: { type: String, required: true } });

const meta = computed(() => ({
    open:      { label: 'Open',      cls: 'bg-cyan-50    text-cyan-700    border-cyan-200'   },
    in_review: { label: 'In Review', cls: 'bg-indigo-50  text-indigo-700  border-indigo-200' },
    closed:    { label: 'Closed',    cls: 'bg-green-50   text-green-700   border-green-200'  },
}[props.status] ?? { label: props.status, cls: 'bg-slate-50 text-slate-700 border-slate-200' }));
</script>

<template>
    <span :class="['inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-[11px] font-bold', meta.cls]">
        <span class="h-1.5 w-1.5 rounded-full bg-current opacity-70"></span>
        {{ meta.label }}
    </span>
</template>
```

- [ ] **Step 3: AttachmentChip**

Write `resources/js/Components/Incidents/AttachmentChip.vue`:

```vue
<script setup>
import { computed } from 'vue';

const props = defineProps({
    attachment: { type: Object, required: true },
});

const kb = computed(() => Math.max(1, Math.round(props.attachment.size_bytes / 1024)));
</script>

<template>
    <a :href="attachment.download_url"
       class="inline-flex items-center gap-2 rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-1.5 text-[12px] font-semibold text-on-surface hover:bg-surface-container transition-colors">
        <span class="material-symbols-outlined text-[16px] text-secondary">attach_file</span>
        <span class="truncate max-w-[200px]">{{ attachment.original_name }}</span>
        <span class="text-on-surface-variant/60 tabular-nums">{{ kb }} KB</span>
    </a>
</template>
```

- [ ] **Step 4: MessageBubble**

Write `resources/js/Components/Incidents/MessageBubble.vue`:

```vue
<script setup>
import AttachmentChip from './AttachmentChip.vue';

defineProps({
    message:  { type: Object, required: true },
    isOwn:    { type: Boolean, default: false },   // true when author === current user (right-aligned)
});
</script>

<template>
    <div :class="['flex', isOwn ? 'justify-end' : 'justify-start']">
        <div :class="['max-w-[75%] rounded-2xl border px-4 py-3 shadow-card',
                      isOwn ? 'bg-secondary/[0.06] border-secondary/15' : 'bg-surface-container-lowest border-outline-variant/50']">
            <div class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.12em] text-on-surface-variant/70">
                <span>{{ message.author?.name ?? 'Unknown' }}</span>
                <span class="text-on-surface-variant/40">·</span>
                <span class="tabular-nums">{{ new Date(message.created_at).toLocaleString('en-GB') }}</span>
            </div>
            <p class="mt-2 text-[13px] text-on-surface whitespace-pre-wrap">{{ message.body }}</p>
            <div v-if="message.attachments?.length" class="mt-3 flex flex-wrap gap-2">
                <AttachmentChip v-for="a in message.attachments" :key="a.id" :attachment="a" />
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 5: Build check**

Run: `npx vite build`
Expected: clean build, the four new components compile.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Components/Incidents
git commit -m "feat(incidents): add CategoryBadge, StatusPill, MessageBubble, AttachmentChip components"
```

---

## Task 12: Index page

**Files:**
- Create: `resources/js/Pages/Governance/Incidents/Index.vue`

- [ ] **Step 1: Index page**

Write `resources/js/Pages/Governance/Incidents/Index.vue`:

```vue
<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import CategoryBadge from '@/Components/Incidents/CategoryBadge.vue';
import StatusPill   from '@/Components/Incidents/StatusPill.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    reports:      Object,                                       // paginated { data, meta, links }
    reviewers:    { type: Array, default: () => [] },           // unused on Index — kept for prop parity with Show
    filters:      { type: Object, default: () => ({}) },
    activeModule: String,
});

const localFilters = reactive({
    category: props.filters?.category ?? '',
    status:   props.filters?.status   ?? '',
    q:        props.filters?.q        ?? '',
});

const applyFilters = () => router.get(route('incidents.index'), {
    category: localFilters.category || undefined,
    status:   localFilters.status   || undefined,
    q:        localFilters.q        || undefined,
}, { preserveState: true, replace: true });

let qTimer = null;
watch(() => localFilters.q, () => {
    clearTimeout(qTimer);
    qTimer = setTimeout(applyFilters, 380);
});

// ── New report panel ────────────────────────────────────────────────
const showNew = ref(false);

// Auto-open from Quick Action ?new=1
onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    if (params.get('new') === '1') {
        showNew.value = true;
        params.delete('new');
        const qs = params.toString();
        window.history.replaceState({}, '', window.location.pathname + (qs ? `?${qs}` : '') + window.location.hash);
    }
});

const form = useForm({
    category: 'grievance',
    title:    '',
    body:     '',
    attachments: [],
});

const submit = () => {
    form.post(route('incidents.store'), {
        preserveState:  true,
        preserveScroll: true,
        forceFormData:  true,
        onSuccess: () => { form.reset(); showNew.value = false; },
    });
};

const formatRel = (iso) => {
    if (!iso) return '—';
    const diff = (Date.now() - new Date(iso).getTime()) / 1000;
    if (diff < 60)        return 'just now';
    if (diff < 3600)      return `${Math.floor(diff / 60)} min ago`;
    if (diff < 86400)     return `${Math.floor(diff / 3600)} h ago`;
    return new Date(iso).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

const reports = computed(() => props.reports?.data ?? []);
</script>

<template>
    <Head title="Incident Reports" />
    <Teleport to="#page-header-mount" defer>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-2 mb-1">
                    <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">report</span>
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">INSTITUTIONAL VOICE</p>
                </div>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Incident Reports</h1>
                <p class="mt-1 text-[13px] font-medium text-on-surface-variant">Private channel for grievances, suggestions, and safety concerns.</p>
            </div>
            <button @click="showNew = true"
                    class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2 text-[13px] font-bold text-white shadow-glow-sm hover:shadow-glow transition-shadow"
                    style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                <span class="material-symbols-outlined text-[16px]" style="font-variation-settings:'FILL' 1">edit_note</span>
                New Report
            </button>
        </div>
    </Teleport>

    <div data-page-root="true">
        <div class="grid lg:grid-cols-[240px_1fr] gap-6">
            <!-- Filter rail -->
            <aside class="space-y-4">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-on-surface-variant/70 mb-2">Category</p>
                    <div class="flex flex-wrap gap-1.5">
                        <button v-for="c in [['','All'],['grievance','Grievance'],['improvement','Improvement'],['safety','Safety'],['other','Other']]"
                                :key="c[0]"
                                @click="localFilters.category = c[0]; applyFilters()"
                                :class="['rounded-full border px-3 py-1 text-[11px] font-semibold',
                                         localFilters.category === c[0]
                                            ? 'bg-secondary/10 border-secondary/30 text-secondary'
                                            : 'bg-surface-container-lowest border-outline-variant text-on-surface-variant hover:bg-surface-container']">
                            {{ c[1] }}
                        </button>
                    </div>
                </div>
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-on-surface-variant/70 mb-2">Status</p>
                    <div class="flex flex-wrap gap-1.5">
                        <button v-for="s in [['','All'],['open','Open'],['in_review','In Review'],['closed','Closed']]"
                                :key="s[0]"
                                @click="localFilters.status = s[0]; applyFilters()"
                                :class="['rounded-full border px-3 py-1 text-[11px] font-semibold',
                                         localFilters.status === s[0]
                                            ? 'bg-secondary/10 border-secondary/30 text-secondary'
                                            : 'bg-surface-container-lowest border-outline-variant text-on-surface-variant hover:bg-surface-container']">
                            {{ s[1] }}
                        </button>
                    </div>
                </div>
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-on-surface-variant/70 mb-2">Search</p>
                    <input v-model="localFilters.q" placeholder="Title…"
                           class="w-full rounded-xl border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[13px]" />
                </div>
            </aside>

            <!-- Card list -->
            <main class="space-y-3">
                <Link v-for="r in reports" :key="r.id" :href="route('incidents.show', r.id)"
                      class="block rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-4 shadow-card hover:-translate-y-px hover:shadow-lifted transition-all">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <CategoryBadge :category="r.category" />
                                <StatusPill :status="r.status" />
                            </div>
                            <h3 class="mt-2 text-[14px] font-bold text-on-surface truncate">{{ r.title }}</h3>
                            <p class="mt-1 text-[12px] text-on-surface-variant line-clamp-2">{{ r.body }}</p>
                        </div>
                        <span class="text-[11px] text-on-surface-variant/60 tabular-nums whitespace-nowrap">{{ formatRel(r.created_at) }}</span>
                    </div>
                </Link>
                <div v-if="reports.length === 0" class="rounded-2xl border border-dashed border-outline-variant/50 bg-surface-container-lowest p-12 text-center text-[13px] text-on-surface-variant">
                    No reports in this view.
                </div>
            </main>
        </div>

        <!-- New Report SlidePanel -->
        <SlidePanel :open="showNew" title="New Incident Report" size="lg" @close="showNew = false">
            <form @submit.prevent="submit" class="space-y-5 p-6" enctype="multipart/form-data">
                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Category</label>
                    <div class="flex flex-wrap gap-2">
                        <label v-for="c in [['grievance','Grievance'],['improvement','Improvement Suggestion'],['safety','Workplace Safety'],['other','Other']]"
                               :key="c[0]" class="cursor-pointer">
                            <input type="radio" v-model="form.category" :value="c[0]" class="sr-only" />
                            <span :class="['inline-flex rounded-full border px-3 py-1.5 text-[12px] font-semibold',
                                           form.category === c[0]
                                              ? 'bg-secondary/10 border-secondary/40 text-secondary'
                                              : 'bg-surface-container-lowest border-outline-variant text-on-surface-variant']">
                                {{ c[1] }}
                            </span>
                        </label>
                    </div>
                </div>
                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Title</label>
                    <input v-model="form.title" required minlength="6" maxlength="180"
                           class="w-full rounded-xl border border-outline-variant px-4 py-2.5 text-[13px]" />
                    <p v-if="form.errors.title" class="mt-1 text-[11px] text-red-500">{{ form.errors.title }}</p>
                </div>
                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Details</label>
                    <textarea v-model="form.body" rows="8" required minlength="20" maxlength="10000"
                              class="w-full rounded-xl border border-outline-variant px-4 py-2.5 text-[13px]" />
                    <p v-if="form.errors.body" class="mt-1 text-[11px] text-red-500">{{ form.errors.body }}</p>
                </div>
                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Attachments (optional, up to 3, 10 MB each)</label>
                    <input type="file" multiple
                           @change="(e) => { form.attachments = Array.from(e.target.files).slice(0, 3); }"
                           accept=".pdf,.png,.jpg,.jpeg,.doc,.docx"
                           class="text-[12px]" />
                </div>
            </form>
            <template #footer>
                <button type="button" @click="showNew = false"
                        class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant">Cancel</button>
                <button @click="submit" :disabled="form.processing"
                        class="btn-shimmer rounded-xl px-5 py-2.5 text-[13px] font-bold text-white shadow-glow-sm disabled:opacity-60"
                        style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                    {{ form.processing ? 'Submitting…' : 'Submit Privately' }}
                </button>
            </template>
        </SlidePanel>
    </div>
</template>
```

- [ ] **Step 2: Build check**

Run: `npx vite build`
Expected: clean build, Index page compiles.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Governance/Incidents/Index.vue
git commit -m "feat(incidents): add Index page with filter rail and New Report panel"
```

---

## Task 13: Show page

**Files:**
- Create: `resources/js/Pages/Governance/Incidents/Show.vue`

- [ ] **Step 1: Show page**

Write `resources/js/Pages/Governance/Incidents/Show.vue`:

```vue
<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import CategoryBadge from '@/Components/Incidents/CategoryBadge.vue';
import StatusPill   from '@/Components/Incidents/StatusPill.vue';
import MessageBubble from '@/Components/Incidents/MessageBubble.vue';
import AttachmentChip from '@/Components/Incidents/AttachmentChip.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    report:    { type: Object, required: true },
    reviewers: { type: Array, default: () => [] },
    activeModule: String,
});

const page = usePage();
const me = computed(() => page.props.auth?.user);

const r = computed(() => props.report?.data ?? props.report);
const isSubmitter = computed(() => r.value?.submitter?.id !== undefined && me.value?.id && r.value.submitter.id === me.value.id);

// ── Reply composer ────────────────────────────────────────────────
const reply = useForm({ body: '', attachments: [] });
const sendReply = () => {
    reply.post(route('incidents.messages.store', r.value.id), {
        preserveState:  true,
        preserveScroll: true,
        forceFormData:  true,
        onSuccess: () => reply.reset(),
    });
};

// ── Close + reopen ────────────────────────────────────────────────
const closeForm = useForm({ resolution_note: '' });
const doClose = () => closeForm.post(route('incidents.close', r.value.id), { preserveScroll: true });
const doReopen = () => {
    if (! window.confirm('Reopen this report?')) return;
    useForm({}).post(route('incidents.reopen', r.value.id), { preserveScroll: true });
};

// ── Assignment ────────────────────────────────────────────────────
const showAssign = ref(false);
const assignForm = useForm({ user_id: '' });
const doAssign = (userId) => {
    assignForm.user_id = userId;
    assignForm.post(route('incidents.assign', r.value.id), {
        preserveScroll: true,
        onSuccess: () => assignForm.reset(),
    });
};
const doUnassign = (userId) => {
    if (! window.confirm('Remove this reviewer? They will lose access immediately.')) return;
    useForm({}).delete(route('incidents.unassign', { report: r.value.id, user: userId }), { preserveScroll: true });
};

const assigneeIds = computed(() => new Set((r.value.assignees ?? []).map(a => a.id)));
</script>

<template>
    <Head :title="r.title" />
    <Teleport to="#page-header-mount" defer>
        <div class="space-y-2">
            <Link :href="route('incidents.index')" class="inline-flex items-center gap-1 text-[12px] font-semibold text-on-surface-variant/70 hover:text-secondary">
                <span class="material-symbols-outlined text-[14px]">arrow_back</span> Back to reports
            </Link>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <CategoryBadge :category="r.category" />
                        <StatusPill :status="r.status" />
                    </div>
                    <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">{{ r.title }}</h1>
                    <p class="mt-1 text-[12px] text-on-surface-variant">
                        Submitted {{ new Date(r.created_at).toLocaleString('en-GB') }}
                        <span v-if="!isSubmitter && r.submitter"> · by {{ r.submitter.name }}</span>
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="showAssign = true"
                            class="rounded-xl border border-outline-variant bg-surface-container-lowest px-4 py-2 text-[13px] font-semibold text-on-surface hover:bg-surface-container">
                        Assign
                    </button>
                    <button v-if="r.status !== 'closed'" @click="doClose"
                            class="rounded-xl bg-green-600 text-white px-4 py-2 text-[13px] font-bold hover:bg-green-700">
                        Close
                    </button>
                    <button v-if="r.status === 'closed'" @click="doReopen"
                            class="rounded-xl bg-amber-500 text-white px-4 py-2 text-[13px] font-bold hover:bg-amber-600">
                        Reopen
                    </button>
                </div>
            </div>
        </div>
    </Teleport>

    <div data-page-root="true">
        <div class="grid lg:grid-cols-[1fr_320px] gap-6">
            <!-- Main column: body + thread -->
            <section class="space-y-4">
                <article class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-5 shadow-card">
                    <p class="text-[13px] text-on-surface whitespace-pre-wrap">{{ r.body }}</p>
                    <div v-if="r.attachments?.length" class="mt-4 flex flex-wrap gap-2">
                        <AttachmentChip v-for="a in r.attachments" :key="a.id" :attachment="a" />
                    </div>
                </article>

                <div class="space-y-3">
                    <MessageBubble v-for="m in r.messages" :key="m.id" :message="m" :is-own="m.author?.id === me?.id" />
                </div>

                <!-- Reply composer (hidden when closed) -->
                <form v-if="r.status !== 'closed'" @submit.prevent="sendReply"
                      class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-4 shadow-card space-y-3"
                      enctype="multipart/form-data">
                    <textarea v-model="reply.body" rows="3" required maxlength="10000"
                              placeholder="Write a reply…"
                              class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 text-[13px] resize-none" />
                    <div class="flex items-center justify-between gap-3">
                        <input type="file" multiple
                               @change="(e) => { reply.attachments = Array.from(e.target.files).slice(0, 3); }"
                               accept=".pdf,.png,.jpg,.jpeg,.doc,.docx"
                               class="text-[11px]" />
                        <button :disabled="reply.processing"
                                class="rounded-xl px-4 py-2 text-[12px] font-bold text-white shadow-glow-sm disabled:opacity-60"
                                style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                            {{ reply.processing ? 'Posting…' : 'Post Reply' }}
                        </button>
                    </div>
                </form>
                <p v-else class="text-center text-[12px] text-on-surface-variant/60">This report is closed. The thread is locked.</p>
            </section>

            <!-- Right rail -->
            <aside class="space-y-4">
                <div class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-4">
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-on-surface-variant/70">Status</p>
                    <div class="mt-2"><StatusPill :status="r.status" /></div>
                    <p v-if="r.resolution_note" class="mt-3 text-[12px] text-on-surface-variant whitespace-pre-wrap">{{ r.resolution_note }}</p>
                </div>
                <div class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-4">
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-on-surface-variant/70 mb-2">Assignees</p>
                    <ul class="space-y-2">
                        <li v-for="a in r.assignees" :key="a.id" class="flex items-center justify-between gap-2">
                            <div>
                                <p class="text-[13px] font-bold text-on-surface">{{ a.name }}</p>
                                <p class="text-[11px] text-on-surface-variant/60">{{ a.role }}</p>
                            </div>
                            <button @click="doUnassign(a.id)" class="text-[11px] text-red-600 font-semibold hover:underline">Remove</button>
                        </li>
                        <li v-if="r.assignees?.length === 0" class="text-[12px] text-on-surface-variant/60 italic">No reviewers yet.</li>
                    </ul>
                </div>
            </aside>
        </div>

        <!-- Assign SlidePanel -->
        <SlidePanel :open="showAssign" title="Assign Reviewer" size="md" @close="showAssign = false">
            <div class="p-6 space-y-2">
                <p class="text-[12px] text-on-surface-variant">Only users with the <code class="text-secondary">incidents.review</code> permission appear here.</p>
                <ul class="divide-y divide-outline-variant/30">
                    <li v-for="u in reviewers" :key="u.id" class="flex items-center justify-between py-3">
                        <div>
                            <p class="text-[13px] font-bold text-on-surface">{{ u.name }}</p>
                            <p class="text-[11px] text-on-surface-variant/60">{{ u.role }}</p>
                        </div>
                        <button v-if="!assigneeIds.has(u.id)"
                                @click="doAssign(u.id)"
                                class="rounded-lg border border-secondary/30 bg-secondary/10 px-3 py-1.5 text-[12px] font-bold text-secondary">Assign</button>
                        <span v-else class="text-[12px] font-semibold text-green-600">✓ Assigned</span>
                    </li>
                    <li v-if="reviewers.length === 0" class="py-6 text-center text-[12px] text-on-surface-variant/60 italic">
                        No users hold the incidents.review permission yet.
                    </li>
                </ul>
            </div>
        </SlidePanel>
    </div>
</template>
```

- [ ] **Step 2: Build check**

Run: `npx vite build`
Expected: clean build.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Governance/Incidents/Show.vue
git commit -m "feat(incidents): add Show page with thread, assignment, and lifecycle controls"
```

---

## Task 14: Sidebar + Quick Action wiring

**Files:**
- Modify: `resources/js/Layouts/AuthenticatedLayout.vue`

- [ ] **Step 1: Add Incident Reports to the Governance group**

Open `resources/js/Layouts/AuthenticatedLayout.vue`. Locate the Governance navigation entry. Right now it appears as a single regular nav item:

```js
                    { label: 'Governance',   route: 'modules.governance',   module: 'governance',  icon: 'account_balance',  visible: true },
```

Replace it with an expandable group whose children include the existing Governance routes plus the new Incident Reports entry:

```js
                    {
                        label: 'Governance', icon: 'account_balance', expandable: true, visible: true,
                        children: [
                            { label: 'Overview',         route: 'modules.governance',     module: 'governance',          icon: 'dashboard',  visible: true },
                            { label: 'Manage',           route: 'governance.manage',      module: 'governance-manage',   icon: 'tune',       visible: can('governance.manage') },
                            { label: 'Certifications',   route: 'governance.certifications', module: 'governance-certs',  icon: 'verified',   visible: true },
                            { label: 'Incident Reports', route: 'incidents.index',        module: 'incidents',           icon: 'report',     visible: true },
                        ],
                    },
```

If the existing `governance.manage` or `governance.certifications` route names differ, adapt to match what's currently in the file. Only the **Incident Reports** child line is the new addition. Do not invent route names that don't exist — verify each with `php artisan route:list --name=governance`.

- [ ] **Step 2: Add Compose Incident to Quick Actions**

In the same file, find the `quickActions` computed array. Add at the bottom of the array (just before the closing `].filter(i => i.visible));`):

```js
    { label: 'Compose Incident', icon: 'edit_note', href: route('incidents.index') + '?new=1', visible: true },
```

- [ ] **Step 3: Build check**

Run: `npx vite build`
Expected: clean build.

- [ ] **Step 4: Verify sidebar in dev server**

Start dev server (`npm run dev`) in one terminal and the Laravel server (`php artisan serve`) in another. Open the app, log in as any user, expand the Governance group in the sidebar — `Incident Reports` appears as a child. Click it; lands at `/governance/incidents`.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Layouts/AuthenticatedLayout.vue
git commit -m "feat(incidents): wire sidebar entry and Compose Incident quick action"
```

---

## Task 15: End-to-end manual verification + final build

**Files:** none — verification only.

- [ ] **Step 1: Run the full test suite**

Run: `php artisan test`
Expected: all tests pass, including the 21 new incident feature tests and 10 policy tests.

- [ ] **Step 2: Production build**

Run: `npx vite build`
Expected: clean build, no errors.

- [ ] **Step 3: Manual end-to-end walk**

With dev servers running, perform the following sequence in the browser:

1. Log in as an employee user (any user with an `employees` row).
2. From the Quick Action top-bar menu, click **Compose Incident**. The New Report SlidePanel auto-opens, URL drops `?new=1`.
3. Fill in: Category=Grievance, Title (≥6 chars), Body (≥20 chars), one PDF attachment. Submit. Toast appears, redirect to the Show page.
4. Verify the report renders with the category badge, status `open`, your name suppressed in the header (because you're the submitter), and the attachment chip downloadable.
5. Log out. Log in as a different employee user (no `incidents.review`). Navigate to `/governance/incidents/{id}` directly — see a 403.
6. Log out. Log in as a `super_admin` who does NOT have `incidents.review`. Navigate to the same URL — see a 403. This verifies the privacy invariant.
7. Grant `incidents.review` to a user (CEO mock) via tinker: `User::find($id)->update(['permissions' => ['incidents.review']]);`. Log in as that user. Open the Index page — the report does NOT appear (you're not yet assigned).
8. Log back in as the original submitter. Open the Show page, click **Assign**, select the CEO user. Toast confirms assignment; status flips to `in_review`.
9. Log in as the CEO user. The Index page now lists the report. Open it. Post a reply. Add an attachment to the reply.
10. Log in as the submitter — read the reply, see the new attachment chip, post another reply.
11. Log in as the CEO. Click **Close**, write a resolution note. Status flips to `closed`. Reply composer hides; banner appears.
12. As the submitter, attempt to post another message — composer is gone, direct POST returns 403 (verify in DevTools network).
13. Check the bell: the submitter has an `incident.closed` notification; both have `incident.message` notifications for replies they didn't author.
14. Click **Reopen** as the CEO. Status flips to `in_review`. Composer reappears.

If any step misbehaves, fix and re-test before declaring done.

- [ ] **Step 4: Confirm no stale `grep` artefacts**

Run: `grep -rln "TODO\|FIXME\|NAVDIAG" app/ resources/js/Components/Incidents resources/js/Pages/Governance/Incidents | head`
Expected: no hits.

- [ ] **Step 5: Final commit if any cleanup was needed**

If any tweaks were made in Step 3, commit them with `git commit -am "fix(incidents): post-E2E adjustments"`. Otherwise nothing to commit.

---

## Done Criteria (re-verify before declaring complete)

- [ ] All four tables exist (`incident_reports`, `incident_report_assignees`, `incident_report_messages`, `incident_report_attachments`); `php artisan migrate:rollback --step=1` cleanly removes them.
- [ ] `php artisan db:seed --class=IncidentPermissionsSeeder` creates `incidents.review`.
- [ ] `php artisan route:list --name=incidents` shows 10 named routes.
- [ ] `php artisan test` — all green, including 10 policy + 21 feature tests.
- [ ] `npx vite build` — clean.
- [ ] Manual E2E walk in Task 15 Step 3 passes every step.
- [ ] Privacy invariant verified in Steps 5 and 6 of the E2E walk (unrelated employees and unassigned super_admins both receive 403).
