# Onboarding Lifecycle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A new-hire onboarding lifecycle mirroring off-boarding — an `OnboardingCase` with a templated task checklist, manual + auto-on-hire creation, course auto-enrolment, sign-off, and completion.

**Architecture:** `OnboardingCase` + `OnboardingTask` (↔ `OffboardingCase` + `ClearanceItem`); `OnboardingService` owns the lifecycle; an `EmployeeCreated` listener auto-initiates; a controller/policy/Vue pages mirror off-boarding. No `EmployeeStatus` change — onboarding lives on the case.

**Tech Stack:** Laravel 13, Inertia + Vue 3, Pest.

## Global Constraints

- Mirror the off-boarding module file-for-file in shape (`app/Services/Offboarding/OffboardingService.php`, `app/Models/OffboardingCase.php`, `app/Models/ClearanceItem.php`, `OffboardingController`, `OffboardingCasePolicy`, `resources/js/Pages/Offboarding/*`).
- `declare(strict_types=1)` on new PHP classes; `casts()` method form.
- DB-backed permissions; per-user JSON `permissions` for test grants.
- One open case per employee (idempotent initiate).
- Every new form/date input carries an `aria-label` (the `AccessibilityAuditorTest` gate).

**Spec:** `docs/superpowers/specs/2026-06-19-onboarding-lifecycle-design.md`

---

### Task 1: Enums + permissions

**Files:**
- Create: `app/Enums/OnboardingStatus.php`, `app/Enums/OnboardingArea.php`, `app/Enums/OnboardingTaskStatus.php`
- Modify: `database/seeders/RolePermissionSeeder.php`
- Test: `tests/Feature/Onboarding/OnboardingEnumsPermissionTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\OnboardingArea;
use App\Enums\OnboardingStatus;
use App\Enums\OnboardingTaskStatus;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(fn () => (new RolePermissionSeeder())->run());

it('exposes onboarding enums', function () {
    expect(OnboardingStatus::InProgress->value)->toBe('in_progress')
        ->and(OnboardingStatus::Completed->isTerminal())->toBeTrue()
        ->and(OnboardingStatus::InProgress->isTerminal())->toBeFalse()
        ->and(OnboardingArea::ItProvisioning->label())->toBe('IT Provisioning')
        ->and(OnboardingTaskStatus::Pending->value)->toBe('pending');
});

it('grants onboarding permissions to an HR role, not to a plain employee', function () {
    $hr = User::factory()->create(['role' => 'hr_manager']);
    expect($hr->hasPermission('onboarding.initiate'))->toBeTrue()
        ->and(User::factory()->create(['role' => 'employee'])->hasPermission('onboarding.initiate'))->toBeFalse();
});
```

> Before writing, confirm the HR role slug that holds `offboarding.initiate` (grep `RolePermissionSeeder.php` for `offboarding.initiate`) and use that same role in the test (it may be `hr_manager`, `hr_officer`, or similar — match reality).

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Onboarding/OnboardingEnumsPermissionTest.php`
Expected: FAIL — enums/permissions missing.

- [ ] **Step 3: Write the enums**

`app/Enums/OnboardingStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum OnboardingStatus: string
{
    case Draft      = 'draft';
    case InProgress = 'in_progress';
    case Completed  = 'completed';
    case Cancelled  = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft      => 'Draft',
            self::InProgress => 'In Progress',
            self::Completed  => 'Completed',
            self::Cancelled  => 'Cancelled',
        };
    }
}
```

`app/Enums/OnboardingArea.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum OnboardingArea: string
{
    case ItProvisioning       = 'it_provisioning';
    case HrOrientation        = 'hr_orientation';
    case PolicyAcknowledgement = 'policy_acknowledgement';
    case Learning             = 'learning';
    case Mentorship           = 'mentorship';
    case DeptIntroduction     = 'dept_introduction';
    case Other                = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ItProvisioning        => 'IT Provisioning',
            self::HrOrientation         => 'HR Orientation',
            self::PolicyAcknowledgement => 'Policy Acknowledgement',
            self::Learning              => 'Learning',
            self::Mentorship            => 'Mentorship',
            self::DeptIntroduction      => 'Department Introduction',
            self::Other                 => 'Other',
        };
    }
}
```

`app/Enums/OnboardingTaskStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum OnboardingTaskStatus: string
{
    case Pending   = 'pending';
    case Completed = 'completed';
    case Skipped   = 'skipped';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
```

- [ ] **Step 4: Add the permissions**

In `database/seeders/RolePermissionSeeder.php`, in `PERMISSIONS` (near the off-boarding block):

```php
        'onboarding.view'        => ['Onboarding', 'View onboarding cases'],
        'onboarding.initiate'    => ['Onboarding', 'Open a new onboarding case'],
        'onboarding.complete'    => ['Onboarding', 'Sign off onboarding tasks and complete cases'],
        'onboarding.manage'      => ['Onboarding', 'Cancel and administer onboarding cases'],
```

Grant all four `onboarding.*` in the SAME role array(s) that hold `offboarding.initiate` (the HR role). Also grant `onboarding.view` to any read-only role that holds `offboarding.view`.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/Onboarding/OnboardingEnumsPermissionTest.php`
Expected: PASS (both).

- [ ] **Step 6: Commit**

```bash
git add app/Enums/OnboardingStatus.php app/Enums/OnboardingArea.php app/Enums/OnboardingTaskStatus.php database/seeders/RolePermissionSeeder.php tests/Feature/Onboarding/OnboardingEnumsPermissionTest.php
git commit -m "feat(onboarding): status/area/task-status enums + onboarding.* permissions"
```

---

### Task 2: Migrations + models

**Files:**
- Create: `database/migrations/2026_06_19_100001_create_onboarding_cases.php`
- Create: `database/migrations/2026_06_19_100002_create_onboarding_tasks.php`
- Create: `app/Models/OnboardingCase.php`, `app/Models/OnboardingTask.php`
- Test: `tests/Feature/Onboarding/OnboardingModelTest.php`

**Interfaces:**
- Produces: `OnboardingCase` (fillable `reference,employee_id,initiated_by,status,hire_date,target_completion_date,completed_at,completed_by`; `status` cast `OnboardingStatus`; `tasks()` hasMany, `employee()`, `initiator()`, `completer()`; `isComplete():bool`, `progress():float`, scope `open()`), `OnboardingTask` (fillable `onboarding_case_id,area,label,status,is_required,responsible_user_id,completed_by,completed_at,notes`; casts `area`→OnboardingArea, `status`→OnboardingTaskStatus, `is_required`→bool, `completed_at`→datetime; `case()`, `responsible()`, `completer()`).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\OnboardingArea;
use App\Enums\OnboardingStatus;
use App\Enums\OnboardingTaskStatus;
use App\Models\Employee;
use App\Models\OnboardingCase;
use App\Models\OnboardingTask;
use App\Models\User;

it('stores a case with tasks, casts enums, and computes completeness', function () {
    $employee = Employee::factory()->create();
    $case = OnboardingCase::create([
        'reference' => 'ON-2026-00001', 'employee_id' => $employee->id,
        'initiated_by' => User::factory()->create()->id, 'status' => 'in_progress',
        'hire_date' => '2026-06-01',
    ]);

    $req = OnboardingTask::create(['onboarding_case_id' => $case->id, 'area' => 'it_provisioning',
        'label' => 'Issue laptop', 'status' => 'pending', 'is_required' => true]);
    OnboardingTask::create(['onboarding_case_id' => $case->id, 'area' => 'mentorship',
        'label' => 'Assign mentor', 'status' => 'pending', 'is_required' => false]);

    expect($case->fresh()->status)->toBe(OnboardingStatus::InProgress)
        ->and($case->tasks()->count())->toBe(2)
        ->and($req->fresh()->area)->toBe(OnboardingArea::ItProvisioning)
        ->and($case->isComplete())->toBeFalse(); // required task still pending

    $req->update(['status' => OnboardingTaskStatus::Completed->value]);
    expect($case->fresh()->isComplete())->toBeTrue()           // only the optional one remains pending
        ->and($case->fresh()->progress())->toBeGreaterThan(0.0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Onboarding/OnboardingModelTest.php`
Expected: FAIL — tables/models missing.

- [ ] **Step 3: Write the migrations**

`database/migrations/2026_06_19_100001_create_onboarding_cases.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_cases', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('in_progress')->index();
            $table->date('hire_date')->nullable();
            $table->date('target_completion_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_cases');
    }
};
```

`database/migrations/2026_06_19_100002_create_onboarding_tasks.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('onboarding_case_id')->constrained()->cascadeOnDelete();
            $table->string('area', 40);
            $table->string('label');
            $table->string('status', 20)->default('pending')->index();
            $table->boolean('is_required')->default(true);
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_tasks');
    }
};
```

- [ ] **Step 4: Write the models**

`app/Models/OnboardingCase.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OnboardingStatus;
use App\Enums\OnboardingTaskStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class OnboardingCase extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'reference', 'employee_id', 'initiated_by', 'status',
        'hire_date', 'target_completion_date', 'completed_at', 'completed_by',
    ];

    protected function casts(): array
    {
        return [
            'status'                 => OnboardingStatus::class,
            'hire_date'              => 'date',
            'target_completion_date' => 'date',
            'completed_at'           => 'datetime',
        ];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(OnboardingTask::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', [OnboardingStatus::Draft->value, OnboardingStatus::InProgress->value]);
    }

    /** Every required task is Completed or Skipped. */
    public function isComplete(): bool
    {
        return ! $this->tasks()
            ->where('is_required', true)
            ->whereNotIn('status', [OnboardingTaskStatus::Completed->value, OnboardingTaskStatus::Skipped->value])
            ->exists();
    }

    public function progress(): float
    {
        $total = $this->tasks()->count();
        if ($total === 0) {
            return 0.0;
        }
        $done = $this->tasks()
            ->whereIn('status', [OnboardingTaskStatus::Completed->value, OnboardingTaskStatus::Skipped->value])
            ->count();

        return round($done / $total, 4);
    }
}
```

`app/Models/OnboardingTask.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OnboardingArea;
use App\Enums\OnboardingTaskStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingTask extends Model
{
    protected $fillable = [
        'onboarding_case_id', 'area', 'label', 'status', 'is_required',
        'responsible_user_id', 'completed_by', 'completed_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'area'         => OnboardingArea::class,
            'status'       => OnboardingTaskStatus::class,
            'is_required'  => 'bool',
            'completed_at' => 'datetime',
        ];
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(OnboardingCase::class, 'onboarding_case_id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/Onboarding/OnboardingModelTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_19_100001_create_onboarding_cases.php database/migrations/2026_06_19_100002_create_onboarding_tasks.php app/Models/OnboardingCase.php app/Models/OnboardingTask.php tests/Feature/Onboarding/OnboardingModelTest.php
git commit -m "feat(onboarding): onboarding_cases + onboarding_tasks tables and models"
```

---

### Task 3: OnboardingService

**Files:**
- Create: `app/Services/Onboarding/OnboardingService.php`
- Test: `tests/Feature/Onboarding/OnboardingServiceTest.php`

**Interfaces:**
- Consumes: `SequenceService::next(string): int`; `LearningService::enrol(Course, Employee): Enrolment`; `Course::published()->category(CourseCategory::Onboarding)`.
- Produces: `OnboardingService::initiate(Employee, User $by, ?string $hireDate = null, ?string $targetDate = null): OnboardingCase` (idempotent — returns the open case if one exists); `completeTask(OnboardingTask, User, ?string $notes = null): OnboardingTask`; `skipTask(OnboardingTask, User, string $reason): OnboardingTask`; `complete(OnboardingCase, User): OnboardingCase`; `cancel(OnboardingCase, User, string $reason): OnboardingCase`; `openCaseFor(Employee): ?OnboardingCase`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\CourseCategory;
use App\Enums\OnboardingStatus;
use App\Enums\OnboardingTaskStatus;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrolment;
use App\Models\User;
use App\Services\Onboarding\OnboardingService;

beforeEach(fn () => $this->svc = app(OnboardingService::class));

it('initiates a case: seeds the template, sets in_progress, auto-enrols onboarding courses', function () {
    $employee = Employee::factory()->create(['hire_date' => '2026-06-01']);
    $course = Course::create(['title' => 'Welcome', 'category' => CourseCategory::Onboarding->value,
        'is_published' => true, 'created_by' => User::factory()->create()->id]);

    $case = $this->svc->initiate($employee, User::factory()->create());

    expect($case->status)->toBe(OnboardingStatus::InProgress)
        ->and($case->reference)->toStartWith('ON-')
        ->and($case->tasks()->count())->toBeGreaterThan(4)
        ->and(Enrolment::where('course_id', $course->id)->where('employee_id', $employee->id)->exists())->toBeTrue();

    // idempotent — second initiate returns the same open case
    expect($this->svc->initiate($employee->fresh(), User::factory()->create())->id)->toBe($case->id);
});

it('completes and skips tasks, and blocks case completion until required tasks are done', function () {
    $employee = Employee::factory()->create(['hire_date' => '2026-06-01']);
    $by = User::factory()->create();
    $case = $this->svc->initiate($employee, $by);

    // cannot complete while required tasks pending
    expect(fn () => $this->svc->complete($case->fresh(), $by))->toThrow(DomainException::class);

    // complete all required, skip the optional ones
    foreach ($case->tasks()->where('is_required', true)->get() as $t) {
        $this->svc->completeTask($t, $by, 'done');
    }
    foreach ($case->tasks()->where('is_required', false)->get() as $t) {
        $this->svc->skipTask($t, $by, 'n/a');
    }

    $completed = $this->svc->complete($case->fresh(), $by);
    expect($completed->status)->toBe(OnboardingStatus::Completed)
        ->and($completed->completed_by)->toBe($by->id);
});

it('cannot complete an already-completed task', function () {
    $employee = Employee::factory()->create(['hire_date' => '2026-06-01']);
    $by = User::factory()->create();
    $case = $this->svc->initiate($employee, $by);
    $task = $case->tasks()->first();

    $this->svc->completeTask($task, $by);
    expect(fn () => $this->svc->completeTask($task->fresh(), $by))->toThrow(DomainException::class);
});
```

> Confirm the `Course` fillable columns before the test (read `app/Models/Course.php`) — adjust the `Course::create([...])` to satisfy required columns (title/category/is_published/created_by at minimum).

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Onboarding/OnboardingServiceTest.php`
Expected: FAIL — service missing.

- [ ] **Step 3: Write the service**

`app/Services/Onboarding/OnboardingService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Enums\CourseCategory;
use App\Enums\OnboardingArea;
use App\Enums\OnboardingStatus;
use App\Enums\OnboardingTaskStatus;
use App\Models\Course;
use App\Models\Employee;
use App\Models\OnboardingCase;
use App\Models\OnboardingTask;
use App\Models\User;
use App\Services\Finance\SequenceService;
use App\Services\LearningService;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * New-hire onboarding lifecycle. Mirrors OffboardingService: a case is opened
 * (manually or on hire), a templated task checklist is seeded, the hire is
 * auto-enrolled in onboarding courses, task owners sign off, and HR completes
 * the case once every required task is done.
 */
class OnboardingService
{
    /** [area, label, required] — seeded on case creation. */
    public const DEFAULT_ONBOARDING_TEMPLATE = [
        [OnboardingArea::ItProvisioning,        'Issue laptop, phone & access badge',                 true],
        [OnboardingArea::ItProvisioning,        'Create email & system accounts',                     true],
        [OnboardingArea::HrOrientation,         'HR orientation & staff handbook walkthrough',        true],
        [OnboardingArea::HrOrientation,         'Collect statutory documents (Ghana Card, SSNIT, TIN)', true],
        [OnboardingArea::PolicyAcknowledgement, 'Acknowledge code of conduct & key policies',         true],
        [OnboardingArea::Learning,              'Complete mandatory onboarding courses',              true],
        [OnboardingArea::Mentorship,            'Assign onboarding buddy / mentor',                   false],
        [OnboardingArea::DeptIntroduction,      'Department introduction & first-week plan',          true],
    ];

    public function __construct(
        private readonly SequenceService $sequences,
        private readonly LearningService $learning,
    ) {
    }

    public function initiate(Employee $employee, User $by, ?string $hireDate = null, ?string $targetDate = null): OnboardingCase
    {
        if ($existing = $this->openCaseFor($employee)) {
            return $existing; // one open case per employee
        }

        return DB::transaction(function () use ($employee, $by, $hireDate, $targetDate) {
            $case = OnboardingCase::create([
                'reference'              => $this->nextReference(),
                'employee_id'            => $employee->id,
                'initiated_by'           => $by->id,
                'status'                 => OnboardingStatus::InProgress->value,
                'hire_date'              => $hireDate ?? $employee->hire_date,
                'target_completion_date' => $targetDate,
            ]);

            $this->seedDefaultTasks($case);
            $this->autoEnrolOnboardingCourses($employee);

            return $case->fresh('tasks');
        });
    }

    public function completeTask(OnboardingTask $task, User $by, ?string $notes = null): OnboardingTask
    {
        if ($task->status !== OnboardingTaskStatus::Pending) {
            throw new DomainException("Task '{$task->label}' is not pending (current: {$task->status->value}).");
        }

        $task->update([
            'status'       => OnboardingTaskStatus::Completed->value,
            'completed_by' => $by->id,
            'completed_at' => now(),
            'notes'        => $notes,
        ]);

        return $task->fresh();
    }

    public function skipTask(OnboardingTask $task, User $by, string $reason): OnboardingTask
    {
        if ($task->status !== OnboardingTaskStatus::Pending) {
            throw new DomainException("Task '{$task->label}' is not pending.");
        }

        $task->update([
            'status'       => OnboardingTaskStatus::Skipped->value,
            'completed_by' => $by->id,
            'completed_at' => now(),
            'notes'        => $reason,
        ]);

        return $task->fresh();
    }

    public function complete(OnboardingCase $case, User $by): OnboardingCase
    {
        if ($case->status === OnboardingStatus::Completed) {
            return $case;
        }
        if (! $case->isComplete()) {
            throw new DomainException('Cannot complete: required onboarding tasks are still pending.');
        }

        $case->update([
            'status'       => OnboardingStatus::Completed->value,
            'completed_by' => $by->id,
            'completed_at' => now(),
        ]);

        return $case->fresh();
    }

    public function cancel(OnboardingCase $case, User $by, string $reason): OnboardingCase
    {
        if ($case->status === OnboardingStatus::Completed) {
            throw new DomainException('Cannot cancel a completed onboarding case.');
        }

        $case->update([
            'status'       => OnboardingStatus::Cancelled->value,
            'completed_by' => $by->id,
            'completed_at' => now(),
        ]);

        return $case->fresh();
    }

    public function openCaseFor(Employee $employee): ?OnboardingCase
    {
        return OnboardingCase::where('employee_id', $employee->id)->open()->first();
    }

    private function seedDefaultTasks(OnboardingCase $case): void
    {
        foreach (self::DEFAULT_ONBOARDING_TEMPLATE as [$area, $label, $required]) {
            OnboardingTask::create([
                'onboarding_case_id' => $case->id,
                'area'               => $area->value,
                'label'              => $label,
                'status'             => OnboardingTaskStatus::Pending->value,
                'is_required'        => $required,
            ]);
        }
    }

    /** Best-effort: enrol the hire in every published onboarding course. */
    private function autoEnrolOnboardingCourses(Employee $employee): void
    {
        Course::query()->published()->category(CourseCategory::Onboarding)->get()
            ->each(fn (Course $course) => $this->learning->enrol($course, $employee));
    }

    private function nextReference(): string
    {
        $year = now()->year;

        return sprintf('ON-%04d-%05d', $year, $this->sequences->next("onboarding:{$year}"));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Onboarding/OnboardingServiceTest.php`
Expected: PASS (all three).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Onboarding/OnboardingService.php tests/Feature/Onboarding/OnboardingServiceTest.php
git commit -m "feat(onboarding): OnboardingService (initiate/seed/auto-enrol/complete/skip/cancel)"
```

---

### Task 4: Auto-initiate on hire (listener)

**Files:**
- Create: `app/Listeners/InitiateOnboardingOnHire.php`
- Test: `tests/Feature/Onboarding/OnboardingOnHireTest.php`

**Interfaces:**
- Consumes: `EmployeeCreated` event, `OnboardingService::initiate`.

- [ ] **Step 1: Confirm the event shape**

Read `app/Events/EmployeeCreated.php` and confirm the public property names (likely `$employee` and an actor like `$actor`/`$user`/`$createdBy`). Confirm Laravel event auto-discovery is on (an existing listener like `app/Listeners/CreateZohoContactOnHire.php` is wired with no manual registration → auto-discovery is active). Use the real property names in Step 3.

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Events\EmployeeCreated;
use App\Models\Employee;
use App\Models\OnboardingCase;
use App\Models\User;

it('auto-initiates an onboarding case when an employee with a hire date is created', function () {
    $employee = Employee::factory()->create(['hire_date' => '2026-06-01']);
    event(new EmployeeCreated($employee, User::factory()->create()));

    expect(OnboardingCase::where('employee_id', $employee->id)->open()->count())->toBe(1);
});

it('does not auto-initiate when the employee has no hire date', function () {
    $employee = Employee::factory()->create(['hire_date' => null]);
    event(new EmployeeCreated($employee, User::factory()->create()));

    expect(OnboardingCase::where('employee_id', $employee->id)->exists())->toBeFalse();
});

it('does not create a duplicate case if one is already open', function () {
    $employee = Employee::factory()->create(['hire_date' => '2026-06-01']);
    $actor = User::factory()->create();
    event(new EmployeeCreated($employee, $actor));
    event(new EmployeeCreated($employee->fresh(), $actor));

    expect(OnboardingCase::where('employee_id', $employee->id)->count())->toBe(1);
});
```

> Adjust the `new EmployeeCreated(...)` args to the real constructor signature confirmed in Step 1.

- [ ] **Step 3: Write the listener**

`app/Listeners/InitiateOnboardingOnHire.php` (adjust the event property access to match Step 1):

```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\EmployeeCreated;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Support\Facades\Log;

class InitiateOnboardingOnHire
{
    public function __construct(private readonly OnboardingService $onboarding)
    {
    }

    public function handle(EmployeeCreated $event): void
    {
        $employee = $event->employee;
        $actor    = $event->actor ?? null; // the user who created the employee

        // No hire date → not a real hire yet; no actor → no initiator to attribute.
        if (! $employee->hire_date || $actor === null) {
            return;
        }

        try {
            // initiate is idempotent (returns the existing open case).
            $this->onboarding->initiate($employee, $actor);
        } catch (\Throwable $e) {
            // Onboarding must never block employee creation.
            Log::warning('Auto onboarding initiation failed: ' . $e->getMessage(), ['employee_id' => $employee->id]);
        }
    }
}
```

- [ ] **Step 4: Run test + commit**

Run: `php artisan test tests/Feature/Onboarding/OnboardingOnHireTest.php`
Expected: PASS (all three). If auto-discovery doesn't pick the listener up, register it explicitly wherever the app wires events (check `app/Providers/` for an `EventServiceProvider` or `bootstrap/app.php` `withEvents`); report what you did.

```bash
git add app/Listeners/InitiateOnboardingOnHire.php tests/Feature/Onboarding/OnboardingOnHireTest.php
git commit -m "feat(onboarding): auto-initiate onboarding on EmployeeCreated (idempotent, non-blocking)"
```

---

### Task 5: Controller + policy + routes + resources

**Files:**
- Create: `app/Http/Controllers/OnboardingController.php`
- Create: `app/Policies/OnboardingCasePolicy.php`
- Create: `app/Http/Resources/OnboardingCaseResource.php`, `app/Http/Resources/OnboardingTaskResource.php`
- Modify: `routes/web.php`
- Modify: `app/Providers/AuthServiceProvider.php` (register the policy IF policies aren't auto-discovered — check first)
- Test: `tests/Feature/Onboarding/OnboardingEndpointTest.php`

**Interfaces:**
- Mirror `OffboardingController` + `OffboardingCasePolicy`. Routes: `GET onboarding` (index), `GET onboarding/{case}` (show), `POST onboarding` (store — manual initiate by employee_id), `POST onboarding/{case}/tasks/{task}` (completeTask/skipTask via an `action` field), `POST onboarding/{case}/complete`, `POST onboarding/{case}/cancel`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\OnboardingCase;
use App\Models\User;
use App\Services\Onboarding\OnboardingService;
use Database\Seeders\RolePermissionSeeder;

beforeEach(fn () => (new RolePermissionSeeder())->run());

it('lets an authorized user open and view onboarding cases', function () {
    $u = User::factory()->create(['role' => 'employee', 'permissions' => ['onboarding.view', 'onboarding.initiate']]);
    $employee = Employee::factory()->create(['hire_date' => '2026-06-01']);

    $this->actingAs($u)->post('/onboarding', ['employee_id' => $employee->id])->assertRedirect();
    $case = OnboardingCase::where('employee_id', $employee->id)->firstOrFail();

    $this->actingAs($u)->get('/onboarding')->assertOk();
    $this->actingAs($u)->get("/onboarding/{$case->id}")->assertOk();
});

it('completes a task and then the case', function () {
    $u = User::factory()->create(['role' => 'employee',
        'permissions' => ['onboarding.view', 'onboarding.initiate', 'onboarding.complete']]);
    $employee = Employee::factory()->create(['hire_date' => '2026-06-01']);
    $case = app(OnboardingService::class)->initiate($employee, $u);

    foreach ($case->tasks as $t) {
        $action = $t->is_required ? 'complete' : 'skip';
        $this->actingAs($u)->post("/onboarding/{$case->id}/tasks/{$t->id}", ['action' => $action, 'reason' => 'x'])->assertRedirect();
    }

    $this->actingAs($u)->post("/onboarding/{$case->id}/complete")->assertRedirect();
    expect($case->fresh()->status->value)->toBe('completed');
});

it('forbids a user without onboarding permission', function () {
    $u = User::factory()->create(['role' => 'employee']);
    $this->actingAs($u)->get('/onboarding')->assertForbidden();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Onboarding/OnboardingEndpointTest.php`
Expected: FAIL — routes/controller/policy missing.

- [ ] **Step 3: Write the policy**

`app/Policies/OnboardingCasePolicy.php` (mirror `OffboardingCasePolicy`):

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OnboardingCase;
use App\Models\User;

class OnboardingCasePolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('onboarding.view') || $user->hasPermission('onboarding.manage');
    }

    public function view(User $user, OnboardingCase $case): bool
    {
        if ($user->hasPermission('onboarding.view') || $user->hasPermission('onboarding.manage')) {
            return true;
        }

        return $case->employee?->user_id === $user->id; // self-view
    }

    public function initiate(User $user): bool
    {
        return $user->hasPermission('onboarding.initiate');
    }

    public function complete(User $user): bool
    {
        return $user->hasPermission('onboarding.complete');
    }

    public function manage(User $user): bool
    {
        return $user->hasPermission('onboarding.manage');
    }
}
```

If off-boarding's policy is registered explicitly in `AuthServiceProvider`, register `OnboardingCase => OnboardingCasePolicy` there too; otherwise rely on auto-discovery (check how `OffboardingCasePolicy` is wired).

- [ ] **Step 4: Write the resources**

`app/Http/Resources/OnboardingTaskResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class OnboardingTaskResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'           => $this->id,
            'area'         => $this->area?->value,
            'area_label'   => $this->area?->label(),
            'label'        => $this->label,
            'status'       => $this->status?->value,
            'is_required'  => (bool) $this->is_required,
            'notes'        => $this->notes,
            'completed_at' => optional($this->completed_at)->toIso8601String(),
            'completed_by' => $this->completer?->name,
        ];
    }
}
```

`app/Http/Resources/OnboardingCaseResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class OnboardingCaseResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'              => $this->id,
            'reference'       => $this->reference,
            'status'          => $this->status?->value,
            'status_label'    => $this->status?->label(),
            'hire_date'       => optional($this->hire_date)->toDateString(),
            'target_date'     => optional($this->target_completion_date)->toDateString(),
            'progress'        => $this->progress(),
            'employee'        => [
                'id'          => $this->employee?->id,
                'name'        => $this->employee?->user?->name ?? $this->employee?->full_name ?? null,
            ],
            'tasks'           => OnboardingTaskResource::collection($this->whenLoaded('tasks')),
            'completed_at'    => optional($this->completed_at)->toIso8601String(),
            'can' => [
                'complete' => $request->user()?->can('complete', $this->resource),
                'manage'   => $request->user()?->can('manage', $this->resource),
            ],
        ];
    }
}
```

(Adjust the employee name accessor to whatever off-boarding's resource uses — match it.)

- [ ] **Step 5: Write the controller**

`app/Http/Controllers/OnboardingController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\OnboardingCaseResource;
use App\Models\Employee;
use App\Models\OnboardingCase;
use App\Models\OnboardingTask;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    public function __construct(private readonly OnboardingService $service)
    {
    }

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', OnboardingCase::class);

        $cases = OnboardingCase::query()
            ->with(['employee.user', 'initiator'])
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Onboarding/Index', [
            'cases'        => OnboardingCaseResource::collection($cases),
            'filters'      => $request->only(['status']),
            'activeModule' => 'onboarding',
        ]);
    }

    public function show(OnboardingCase $case): Response
    {
        $this->authorize('view', $case);
        $case->load(['employee.user', 'initiator', 'completer', 'tasks.completer']);

        return Inertia::render('Onboarding/Show', [
            'case'         => (new OnboardingCaseResource($case))->resolve(request()),
            'activeModule' => 'onboarding',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('initiate', OnboardingCase::class);
        $data = $request->validate([
            'employee_id'            => ['required', 'integer', 'exists:employees,id'],
            'target_completion_date' => ['nullable', 'date'],
        ]);

        $employee = Employee::findOrFail($data['employee_id']);
        $case = $this->service->initiate($employee, $request->user(), null, $data['target_completion_date'] ?? null);

        return redirect()->route('onboarding.show', $case->id)->with('success', "Onboarding opened for {$case->reference}.");
    }

    public function updateTask(Request $request, OnboardingCase $case, OnboardingTask $task): RedirectResponse
    {
        $this->authorize('complete', $case);
        $data = $request->validate([
            'action' => ['required', 'in:complete,skip'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            if ($data['action'] === 'skip') {
                $this->service->skipTask($task, $request->user(), $data['reason'] ?? 'Skipped');
            } else {
                $this->service->completeTask($task, $request->user(), $data['reason'] ?? null);
            }
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Task updated.');
    }

    public function complete(Request $request, OnboardingCase $case): RedirectResponse
    {
        $this->authorize('complete', $case);
        try {
            $this->service->complete($case, $request->user());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Onboarding {$case->reference} completed.");
    }

    public function cancel(Request $request, OnboardingCase $case): RedirectResponse
    {
        $this->authorize('manage', $case);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        try {
            $this->service->cancel($case, $request->user(), $data['reason']);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Onboarding {$case->reference} cancelled.");
    }
}
```

- [ ] **Step 6: Add the routes**

In `routes/web.php`, mirror the off-boarding group (near it):

```php
    Route::prefix('onboarding')->name('onboarding.')->group(function () {
        Route::get('/',                       [\App\Http\Controllers\OnboardingController::class, 'index'])
            ->middleware('permission:onboarding.view')->name('index');
        Route::post('/',                      [\App\Http\Controllers\OnboardingController::class, 'store'])
            ->middleware('permission:onboarding.initiate')->name('store');
        Route::get('{case}',                  [\App\Http\Controllers\OnboardingController::class, 'show'])
            ->middleware('permission:onboarding.view')->name('show');
        Route::post('{case}/tasks/{task}',    [\App\Http\Controllers\OnboardingController::class, 'updateTask'])
            ->middleware('permission:onboarding.complete')->name('tasks.update');
        Route::post('{case}/complete',        [\App\Http\Controllers\OnboardingController::class, 'complete'])
            ->middleware('permission:onboarding.complete')->name('complete');
        Route::post('{case}/cancel',          [\App\Http\Controllers\OnboardingController::class, 'cancel'])
            ->middleware('permission:onboarding.manage')->name('cancel');
    });
```

(Place inside the same authenticated group the off-boarding routes live in.)

- [ ] **Step 7: Run test + commit**

Run: `php artisan test tests/Feature/Onboarding/OnboardingEndpointTest.php`
Expected: PASS (all three).

```bash
git add app/Http/Controllers/OnboardingController.php app/Policies/OnboardingCasePolicy.php app/Http/Resources/OnboardingCaseResource.php app/Http/Resources/OnboardingTaskResource.php routes/web.php tests/Feature/Onboarding/OnboardingEndpointTest.php
git commit -m "feat(onboarding): controller + policy + routes + resources"
```

(If you registered the policy in `AuthServiceProvider`, stage it too.)

---

### Task 6: Vue pages + nav + gate

**Files:**
- Create: `resources/js/Pages/Onboarding/Index.vue`, `resources/js/Pages/Onboarding/Show.vue`
- Modify: the nav/menu source (wherever `Offboarding` appears — mirror it)
- Test: none new (verification only).

- [ ] **Step 1: Build the pages by mirroring off-boarding**

Read `resources/js/Pages/Offboarding/Index.vue` and `Show.vue` and create the onboarding equivalents:
- **Index.vue**: a case list (reference, employee name, status badge, progress bar, hire date), a status filter, and a "Start onboarding" affordance (employee picker → `POST onboarding`). Use the same layout/components/design tokens as off-boarding's Index.
- **Show.vue**: tasks grouped by `area_label` with a status pill; for a Pending task, a **Complete** and **Skip** control (Skip captures a reason) posting to `onboarding.tasks.update`; a **Complete onboarding** button (shown when `case.can.complete` and progress allows) posting to `onboarding.complete`; a **Cancel** action (when `case.can.manage`). Any text input (skip reason, employee picker) carries an `aria-label`.

Mirror the off-boarding pages' structure and styling closely; keep new markup minimal and consistent.

- [ ] **Step 2: Add the nav entry**

Find where the off-boarding link is registered in the sidebar/menu and add an **Onboarding** entry alongside it (same permission-gating pattern, gated on `onboarding.view`).

- [ ] **Step 3: Build**

Run: `npm run build`
Expected: succeeds, no Vue compile errors.

- [ ] **Step 4: Regression gate**

Run: `php artisan test tests/Feature/Onboarding`
Expected: PASS.

Run: `php artisan test`
Expected: PASS — accessibility green (new inputs carry `aria-label`); the `AuthenticatedRoutesSmokeTest` passes (the param-less `onboarding` index renders); allow only the known `KioskRecentTest` flake.

Run: `php artisan migrate:fresh --seed`
Expected: completes cleanly (the two new tables migrate).

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Onboarding/Index.vue resources/js/Pages/Onboarding/Show.vue <nav-file>
git commit -m "feat(onboarding): onboarding Index/Show pages + nav entry"
git commit --allow-empty -m "test(onboarding): onboarding lifecycle regression gate green"
```

---

## Self-Review notes (for the implementer)

- **Mirror, don't reinvent**: every piece has an off-boarding twin — copy its shape (model, policy, controller, resource, Vue) and adapt names. Stay consistent with its conventions.
- **Idempotent initiate**: `openCaseFor` (status Draft/InProgress) guards against duplicates; the listener and the manual `store` both rely on it.
- **Listener never blocks hire**: `EmployeeCreated` handling is try/caught + logged; no hire date or no actor → skip.
- **No employee-status change**: onboarding state lives on the case; `EmployeeStatus` is untouched.
- **Auto-enrol is best-effort**: zero onboarding courses is fine; `LearningService::enrol` is itself idempotent.
- **Complete guard**: every required task must be Completed or Skipped before `complete()`.
- **Accessibility**: skip-reason + employee-picker inputs carry `aria-label`.
