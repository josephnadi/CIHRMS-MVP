# LMS Compliance Enforcement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add mandatory-training compliance to the LMS — requirements that target all-staff/role/department, auto-assign with due dates, track overdue, surface a compliance dashboard + My-Learning badges, and remind. Soft/advisory; never blocks.

**Architecture:** `ComplianceRequirement` (course + target + due_in_days) → `ComplianceAssignmentService` auto-enrols matching employees (reusing `LearningService::enrol`) and stamps `enrolments.requirement_id` + `due_at`. Triggers: requirement create, `EmployeeCreated` listener, scheduled `compliance:sync`. Overdue derives from `due_at` + status. Mirrors the onboarding auto-enrol + `InitiateOnboardingOnHire` patterns.

**Tech Stack:** Laravel 13, Inertia + Vue 3, Pest.

## Global Constraints

- **Non-blocking & backwards-compatible**: an enrolment with `requirement_id` null is never "mandatory"/overdue; self-enrols and existing enrolments are untouched. The existing learning suite MUST stay green. The `EmployeeCreated` hook never breaks employee creation (try/caught).
- **Idempotent**: `enrol` is `firstOrCreate`; `assign` stamps `requirement_id`/`due_at` only when unset — re-sync never duplicates or moves due dates.
- Mirror `OnboardingService::autoEnrolOnboardingCourses` + `app/Listeners/InitiateOnboardingOnHire.php`.
- `declare(strict_types=1)`; `casts()` form; new inputs carry `aria-label`.

**Spec:** `docs/superpowers/specs/2026-06-20-lms-compliance-enforcement-design.md`

---

### Task 1: Enum + requirements table/model + permission

**Files:**
- Create: `app/Enums/ComplianceTarget.php`
- Create: `database/migrations/2026_06_20_200001_create_compliance_requirements.php`
- Create: `app/Models/ComplianceRequirement.php`
- Modify: wherever `learning.manage` permission is defined/granted (find it)
- Test: `tests/Feature/Learning/ComplianceRequirementTest.php`

**Interfaces:**
- Produces: `ComplianceTarget` (AllStaff='all_staff', Role='role', Department='department'; `label()`); `ComplianceRequirement` (fillable `course_id,name,target_type,target_value,due_in_days,is_active`; casts `target_type`→ComplianceTarget, `is_active`→bool; `course()`, `enrolments()` hasMany on `requirement_id`; `matches(Employee): bool`; `matchingEmployees(): Builder|Collection`); permission `learning.compliance.manage`.

- [ ] **Step 1: Find how learning permissions are defined**

Search the codebase for where `learning.manage` is declared and granted (it's used by `permission:learning.manage` middleware but may live in `database/seeders/RolePermissionSeeder.php`, a `Permission` enum/model seeder, or another seeder). Determine the mechanism, then add `learning.compliance.manage` the SAME way and grant it to every role that holds `learning.manage`. Report where you added it.

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\ComplianceTarget;
use App\Models\ComplianceRequirement;
use App\Models\Course;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;

function makeCourse(): Course
{
    return Course::create(['title' => 'DPA Refresher', 'category' => 'compliance',
        'is_published' => true, 'created_by' => User::factory()->create()->id]);
}

it('matches all-staff, role, and department targets', function () {
    $course = makeCourse();
    $deptA = Department::factory()->create();
    $deptB = Department::factory()->create();

    $empA  = Employee::factory()->create(['department_id' => $deptA->id]);
    $empB  = Employee::factory()->create(['department_id' => $deptB->id]);
    $hr    = Employee::factory()->create(['department_id' => $deptA->id, 'user_id' => User::factory()->create(['role' => 'hr_admin'])->id]);

    $all   = ComplianceRequirement::create(['course_id' => $course->id, 'name' => 'All', 'target_type' => 'all_staff', 'due_in_days' => 30, 'is_active' => true]);
    $byDept = ComplianceRequirement::create(['course_id' => $course->id, 'name' => 'DeptA', 'target_type' => 'department', 'target_value' => (string) $deptA->id, 'due_in_days' => 30, 'is_active' => true]);
    $byRole = ComplianceRequirement::create(['course_id' => $course->id, 'name' => 'HR', 'target_type' => 'role', 'target_value' => 'hr_admin', 'due_in_days' => 30, 'is_active' => true]);

    expect($all->matches($empB))->toBeTrue()
        ->and($byDept->matches($empA))->toBeTrue()
        ->and($byDept->matches($empB))->toBeFalse()
        ->and($byRole->matches($hr))->toBeTrue()
        ->and($byRole->matches($empA))->toBeFalse();

    expect($byDept->matchingEmployees()->pluck('id'))->toContain($empA->id)->not->toContain($empB->id);
});

it('grants learning.compliance.manage to a learning manager, not a plain employee', function () {
    (new Database\Seeders\RolePermissionSeeder())->run();
    // Use whichever role holds learning.manage (confirm in Step 1); hr_admin is the likely holder.
    $mgr = User::factory()->create(['role' => 'hr_admin']);
    expect($mgr->hasPermission('learning.compliance.manage'))->toBeTrue()
        ->and(User::factory()->create(['role' => 'employee'])->hasPermission('learning.compliance.manage'))->toBeFalse();
});
```

> Adjust the role slug in the permission test to whichever role actually holds `learning.manage` (from Step 1). If `Employee::factory()` requires a `user_id`, supply it as shown for the `hr` employee.

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test tests/Feature/Learning/ComplianceRequirementTest.php`
Expected: FAIL — enum/table/model/permission missing.

- [ ] **Step 4: Write the enum**

`app/Enums/ComplianceTarget.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum ComplianceTarget: string
{
    case AllStaff   = 'all_staff';
    case Role       = 'role';
    case Department = 'department';

    public function label(): string
    {
        return match ($this) {
            self::AllStaff   => 'All staff',
            self::Role       => 'Role',
            self::Department => 'Department',
        };
    }
}
```

- [ ] **Step 5: Write the migration**

`database/migrations/2026_06_20_200001_create_compliance_requirements.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('target_type', 20)->default('all_staff'); // all_staff | role | department
            $table->string('target_value')->nullable();              // role slug or department id; null for all_staff
            $table->unsignedSmallInteger('due_in_days')->default(30);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_requirements');
    }
};
```

- [ ] **Step 6: Write the model**

`app/Models/ComplianceRequirement.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ComplianceTarget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComplianceRequirement extends Model
{
    protected $fillable = ['course_id', 'name', 'target_type', 'target_value', 'due_in_days', 'is_active'];

    protected function casts(): array
    {
        return [
            'target_type' => ComplianceTarget::class,
            'is_active'   => 'bool',
            'due_in_days' => 'integer',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function enrolments(): HasMany
    {
        return $this->hasMany(Enrolment::class, 'requirement_id');
    }

    public function matches(Employee $employee): bool
    {
        return match ($this->target_type) {
            ComplianceTarget::AllStaff   => true,
            ComplianceTarget::Role       => $employee->user?->role?->value === $this->target_value,
            ComplianceTarget::Department => (int) $employee->department_id === (int) $this->target_value,
        };
    }

    /** Active employees this requirement targets. */
    public function matchingEmployees(): Builder
    {
        $query = Employee::query()->where('status', 'active');

        return match ($this->target_type) {
            ComplianceTarget::AllStaff   => $query,
            ComplianceTarget::Department => $query->where('department_id', (int) $this->target_value),
            ComplianceTarget::Role       => $query->whereHas('user', fn ($u) => $u->where('role', $this->target_value)),
        };
    }
}
```

> Confirm `Employee` has a `user()` relation and `status` uses `'active'` (read `app/Models/Employee.php` + `EmployeeStatus`). If the active scope/value differs, match it.

- [ ] **Step 7: Run test + commit**

Run: `php artisan test tests/Feature/Learning/ComplianceRequirementTest.php`
Expected: PASS (both).

```bash
git add app/Enums/ComplianceTarget.php database/migrations/2026_06_20_200001_create_compliance_requirements.php app/Models/ComplianceRequirement.php <permission-file> tests/Feature/Learning/ComplianceRequirementTest.php
git commit -m "feat(learning): compliance requirement model (target all-staff/role/department) + permission"
```

---

### Task 2: Enrolment gains requirement_id + due_at

**Files:**
- Create: `database/migrations/2026_06_20_200002_add_compliance_to_enrolments.php`
- Modify: `app/Models/Enrolment.php`
- Test: `tests/Feature/Learning/EnrolmentComplianceTest.php`

**Interfaces:**
- Produces: `enrolments.requirement_id` (nullable FK) + `due_at` (nullable timestamp); `Enrolment` fillable + cast + `requirement()` belongsTo + scopes `mandatory()`, `overdue(?CarbonInterface $now = null)`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\ComplianceRequirement;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrolment;
use App\Models\User;

it('flags an overdue mandatory enrolment and excludes completed / non-mandatory ones', function () {
    $course = Course::create(['title' => 'X', 'category' => 'compliance', 'is_published' => true, 'created_by' => User::factory()->create()->id]);
    $req = ComplianceRequirement::create(['course_id' => $course->id, 'name' => 'R', 'target_type' => 'all_staff', 'due_in_days' => 30, 'is_active' => true]);
    $emp = Employee::factory()->create();

    $overdue = Enrolment::create(['course_id' => $course->id, 'employee_id' => $emp->id, 'status' => 'active',
        'requirement_id' => $req->id, 'due_at' => now()->subDay(), 'enrolled_at' => now()->subDays(40)]);
    $done = Enrolment::create(['course_id' => $course->id, 'employee_id' => Employee::factory()->create()->id, 'status' => 'completed',
        'requirement_id' => $req->id, 'due_at' => now()->subDay(), 'enrolled_at' => now()->subDays(40), 'completed_at' => now()]);
    $self = Enrolment::create(['course_id' => $course->id, 'employee_id' => Employee::factory()->create()->id, 'status' => 'active',
        'requirement_id' => null, 'enrolled_at' => now()]);

    $overdueIds = Enrolment::overdue()->pluck('id');
    expect($overdueIds)->toContain($overdue->id)->not->toContain($done->id)->not->toContain($self->id)
        ->and(Enrolment::mandatory()->pluck('id'))->toContain($overdue->id)->not->toContain($self->id);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Learning/EnrolmentComplianceTest.php`
Expected: FAIL — columns/scopes missing.

- [ ] **Step 3: Write the migration**

`database/migrations/2026_06_20_200002_add_compliance_to_enrolments.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrolments', function (Blueprint $table) {
            $table->foreignId('requirement_id')->nullable()->after('employee_id')
                ->constrained('compliance_requirements')->nullOnDelete();
            $table->timestamp('due_at')->nullable()->after('enrolled_at');
        });
    }

    public function down(): void
    {
        Schema::table('enrolments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requirement_id');
            $table->dropColumn('due_at');
        });
    }
};
```

- [ ] **Step 4: Update the model**

In `app/Models/Enrolment.php`: add `'requirement_id'`, `'due_at'` to `$fillable`; add `'due_at' => 'datetime'` to casts; add the relation + scopes:

```php
    public function requirement(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ComplianceRequirement::class, 'requirement_id');
    }

    public function scopeMandatory(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        return $q->whereNotNull('requirement_id');
    }

    public function scopeOverdue(\Illuminate\Database\Eloquent\Builder $q, ?\Carbon\CarbonInterface $now = null): \Illuminate\Database\Eloquent\Builder
    {
        return $q->whereNotNull('requirement_id')
            ->whereNotNull('due_at')
            ->where('due_at', '<', $now ?? now())
            ->where('status', '!=', \App\Enums\EnrolmentStatus::Completed->value);
    }
```

(Add the needed `use` imports, or fully-qualify as above.)

- [ ] **Step 5: Run test + commit**

Run: `php artisan test tests/Feature/Learning/EnrolmentComplianceTest.php`
Expected: PASS.

Run: `php artisan migrate:fresh --seed` (clean) and `php artisan test tests/Feature/Learning` (existing learning tests green).

```bash
git add database/migrations/2026_06_20_200002_add_compliance_to_enrolments.php app/Models/Enrolment.php tests/Feature/Learning/EnrolmentComplianceTest.php
git commit -m "feat(learning): enrolment compliance fields (requirement_id + due_at) + mandatory/overdue scopes"
```

---

### Task 3: ComplianceAssignmentService

**Files:**
- Create: `app/Services/Learning/ComplianceAssignmentService.php`
- Test: `tests/Feature/Learning/ComplianceAssignmentTest.php`

**Interfaces:**
- Consumes: `LearningService::enrol(Course, Employee): Enrolment`; `ComplianceRequirement`, `Enrolment`, `Employee`.
- Produces: `assign(ComplianceRequirement, Employee): ?Enrolment` (enrol + stamp once); `syncRequirement(ComplianceRequirement): int`; `syncAll(): int`; `assignForEmployee(Employee): int`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\ComplianceRequirement;
use App\Models\Course;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Enrolment;
use App\Models\User;
use App\Services\Learning\ComplianceAssignmentService;

beforeEach(fn () => $this->svc = app(ComplianceAssignmentService::class));

function complianceCourse(): Course
{
    return Course::create(['title' => 'DPA', 'category' => 'compliance', 'is_published' => true, 'created_by' => User::factory()->create()->id]);
}

it('assigns matching employees with a due date and is idempotent', function () {
    $course = complianceCourse();
    $dept = Department::factory()->create();
    $a = Employee::factory()->create(['department_id' => $dept->id]);
    $b = Employee::factory()->create(); // different dept
    $req = ComplianceRequirement::create(['course_id' => $course->id, 'name' => 'R', 'target_type' => 'department', 'target_value' => (string) $dept->id, 'due_in_days' => 30, 'is_active' => true]);

    $count = $this->svc->syncRequirement($req);
    expect($count)->toBe(1);

    $enr = Enrolment::where('course_id', $course->id)->where('employee_id', $a->id)->first();
    expect($enr->requirement_id)->toBe($req->id)
        ->and($enr->due_at)->not->toBeNull()
        ->and(Enrolment::where('employee_id', $b->id)->exists())->toBeFalse(); // not targeted

    $due = $enr->due_at;
    $this->svc->syncRequirement($req); // idempotent
    expect(Enrolment::where('course_id', $course->id)->where('employee_id', $a->id)->count())->toBe(1)
        ->and($enr->fresh()->due_at->equalTo($due))->toBeTrue(); // due not moved
});

it('assigns all matching active requirements to a single employee (new-hire hook)', function () {
    $emp = Employee::factory()->create();
    $c1 = complianceCourse(); $c2 = complianceCourse();
    ComplianceRequirement::create(['course_id' => $c1->id, 'name' => 'All1', 'target_type' => 'all_staff', 'due_in_days' => 14, 'is_active' => true]);
    ComplianceRequirement::create(['course_id' => $c2->id, 'name' => 'Inactive', 'target_type' => 'all_staff', 'due_in_days' => 14, 'is_active' => false]);

    expect($this->svc->assignForEmployee($emp))->toBe(1) // only the active one
        ->and(Enrolment::where('employee_id', $emp->id)->mandatory()->count())->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Learning/ComplianceAssignmentTest.php`
Expected: FAIL — service missing.

- [ ] **Step 3: Write the service**

`app/Services/Learning/ComplianceAssignmentService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Learning;

use App\Models\ComplianceRequirement;
use App\Models\Employee;
use App\Models\Enrolment;
use App\Services\LearningService;

/**
 * Auto-assigns mandatory (compliance) courses to the employees a requirement
 * targets, stamping a due date. Idempotent and non-blocking: re-syncing never
 * duplicates or moves a due date, and a failure for one employee/requirement
 * does not abort the batch.
 */
class ComplianceAssignmentService
{
    public function __construct(private readonly LearningService $learning)
    {
    }

    /** Enrol the employee in the requirement's course and stamp requirement_id + due_at once. */
    public function assign(ComplianceRequirement $requirement, Employee $employee): ?Enrolment
    {
        $course = $requirement->course;
        if ($course === null) {
            return null;
        }

        $enrolment = $this->learning->enrol($course, $employee);

        if ($enrolment->requirement_id === null) {
            $enrolment->update([
                'requirement_id' => $requirement->id,
                'due_at'         => now()->addDays((int) $requirement->due_in_days),
            ]);
        }

        return $enrolment->fresh();
    }

    /** Assign every active employee the requirement targets. Returns rows assigned. */
    public function syncRequirement(ComplianceRequirement $requirement): int
    {
        if (! $requirement->is_active) {
            return 0;
        }

        $count = 0;
        $requirement->matchingEmployees()->each(function (Employee $employee) use ($requirement, &$count) {
            try {
                if ($this->assign($requirement, $employee)) {
                    $count++;
                }
            } catch (\Throwable $e) {
                report($e); // never abort the batch
            }
        });

        return $count;
    }

    /** Sync every active requirement. Returns total rows assigned. */
    public function syncAll(): int
    {
        $total = 0;
        ComplianceRequirement::where('is_active', true)->each(function (ComplianceRequirement $r) use (&$total) {
            $total += $this->syncRequirement($r);
        });

        return $total;
    }

    /** Assign all active requirements that target a single employee (new-hire hook). Returns count. */
    public function assignForEmployee(Employee $employee): int
    {
        $count = 0;
        ComplianceRequirement::where('is_active', true)->get()->each(function (ComplianceRequirement $r) use ($employee, &$count) {
            try {
                if ($r->matches($employee) && $this->assign($r, $employee)) {
                    $count++;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        });

        return $count;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Learning/ComplianceAssignmentTest.php`
Expected: PASS (both).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Learning/ComplianceAssignmentService.php tests/Feature/Learning/ComplianceAssignmentTest.php
git commit -m "feat(learning): ComplianceAssignmentService (assign/sync/assignForEmployee, idempotent)"
```

---

### Task 4: Triggers — listener, command, requirement endpoints

**Files:**
- Create: `app/Listeners/AssignComplianceOnHire.php`
- Create: `app/Console/Commands/ComplianceSync.php`
- Modify: `app/Providers/AppServiceProvider.php` (register listener — events are NOT auto-discovered here; use `Event::listen`), `routes/console.php` (schedule)
- Create: `app/Http/Controllers/Learning/ComplianceController.php`, `app/Http/Requests/Learning/StoreComplianceRequirementRequest.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Learning/ComplianceTriggersTest.php`

**Interfaces:**
- `EmployeeCreated` ⇒ `assignForEmployee`; `php artisan compliance:sync` ⇒ `syncAll`; `POST learning/compliance` (create requirement + sync) behind `permission:learning.compliance.manage`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Events\EmployeeCreated;
use App\Models\ComplianceRequirement;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrolment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(fn () => (new RolePermissionSeeder())->run());

function compCourse(): Course
{
    return Course::create(['title' => 'DPA', 'category' => 'compliance', 'is_published' => true, 'created_by' => User::factory()->create()->id]);
}

it('auto-assigns matching requirements when an employee is created', function () {
    ComplianceRequirement::create(['course_id' => compCourse()->id, 'name' => 'All', 'target_type' => 'all_staff', 'due_in_days' => 30, 'is_active' => true]);
    $emp = Employee::factory()->create();

    event(new EmployeeCreated($emp, User::factory()->create()));

    expect(Enrolment::where('employee_id', $emp->id)->mandatory()->count())->toBe(1);
});

it('compliance:sync assigns existing employees', function () {
    $emp = Employee::factory()->create();
    ComplianceRequirement::create(['course_id' => compCourse()->id, 'name' => 'All', 'target_type' => 'all_staff', 'due_in_days' => 30, 'is_active' => true]);

    $this->artisan('compliance:sync')->assertExitCode(0);

    expect(Enrolment::where('employee_id', $emp->id)->mandatory()->count())->toBe(1);
});

it('lets a manager create a requirement and forbids an employee', function () {
    $course = compCourse();
    $mgr = User::factory()->create(['role' => 'hr_admin', 'permissions' => ['learning.compliance.manage']]);

    $this->actingAs($mgr)->post('/learning/compliance', [
        'course_id' => $course->id, 'name' => 'Annual DPA', 'target_type' => 'all_staff', 'due_in_days' => 30,
    ])->assertRedirect();
    expect(ComplianceRequirement::where('name', 'Annual DPA')->exists())->toBeTrue();

    $this->actingAs(User::factory()->create(['role' => 'employee']))
        ->post('/learning/compliance', ['course_id' => $course->id, 'name' => 'x', 'target_type' => 'all_staff', 'due_in_days' => 30])
        ->assertForbidden();
});
```

> Confirm the `EmployeeCreated` constructor args (from the onboarding listener work: `new EmployeeCreated($employee, $actor)`). Mirror `app/Listeners/InitiateOnboardingOnHire.php` for registration + non-blocking shape.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Learning/ComplianceTriggersTest.php`
Expected: FAIL.

- [ ] **Step 3: Listener** (mirror `InitiateOnboardingOnHire`)

`app/Listeners/AssignComplianceOnHire.php`:

```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\EmployeeCreated;
use App\Services\Learning\ComplianceAssignmentService;
use Illuminate\Support\Facades\Log;

class AssignComplianceOnHire
{
    public function __construct(private readonly ComplianceAssignmentService $compliance)
    {
    }

    public function handle(EmployeeCreated $event): void
    {
        try {
            $this->compliance->assignForEmployee($event->employee);
        } catch (\Throwable $e) {
            Log::warning('Compliance auto-assignment on hire failed: ' . $e->getMessage(), ['employee_id' => $event->employee->id]);
        }
    }
}
```

Register it in `app/Providers/AppServiceProvider.php` next to `InitiateOnboardingOnHire`:

```php
Event::listen(EmployeeCreated::class, \App\Listeners\AssignComplianceOnHire::class);
```

- [ ] **Step 4: Command + schedule**

`app/Console/Commands/ComplianceSync.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Learning\ComplianceAssignmentService;
use Illuminate\Console\Command;

class ComplianceSync extends Command
{
    protected $signature = 'compliance:sync';
    protected $description = 'Assign mandatory compliance courses to all matching employees.';

    public function handle(ComplianceAssignmentService $compliance): int
    {
        $n = $compliance->syncAll();
        $this->info("Compliance sync complete: {$n} assignment(s) made.");

        return self::SUCCESS;
    }
}
```

In `routes/console.php`, schedule it daily (mirror the existing `Schedule::command(...)` calls):

```php
Schedule::command('compliance:sync')->dailyAt('06:00')->withoutOverlapping();
```

- [ ] **Step 5: FormRequest + controller + route**

`app/Http/Requests/Learning/StoreComplianceRequirementRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Learning;

use Illuminate\Foundation\Http\FormRequest;

class StoreComplianceRequirementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('learning.compliance.manage') === true;
    }

    public function rules(): array
    {
        return [
            'course_id'    => ['required', 'integer', 'exists:courses,id'],
            'name'         => ['required', 'string', 'max:160'],
            'target_type'  => ['required', 'in:all_staff,role,department'],
            'target_value' => ['nullable', 'string', 'max:64'],
            'due_in_days'  => ['required', 'integer', 'min:1', 'max:365'],
        ];
    }
}
```

`app/Http/Controllers/Learning/ComplianceController.php` — `store` creates the requirement then syncs it (and a `toggle`/destroy optional):

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Learning;

use App\Http\Controllers\Controller;
use App\Http\Requests\Learning\StoreComplianceRequirementRequest;
use App\Models\ComplianceRequirement;
use App\Services\Learning\ComplianceAssignmentService;
use Illuminate\Http\RedirectResponse;

class ComplianceController extends Controller
{
    public function __construct(private readonly ComplianceAssignmentService $compliance)
    {
    }

    public function store(StoreComplianceRequirementRequest $request): RedirectResponse
    {
        $req = ComplianceRequirement::create([
            ...$request->validated(),
            'is_active' => true,
            'target_value' => $request->input('target_type') === 'all_staff' ? null : $request->input('target_value'),
        ]);

        $assigned = $this->compliance->syncRequirement($req);

        return back()->with('success', "Requirement created; {$assigned} employee(s) assigned.");
    }
}
```

Route (`routes/web.php`, inside the `learning` group):

```php
        Route::post('compliance', [\App\Http\Controllers\Learning\ComplianceController::class, 'store'])
            ->middleware('permission:learning.compliance.manage')->name('compliance.store');
```

- [ ] **Step 6: Run test + commit**

Run: `php artisan test tests/Feature/Learning/ComplianceTriggersTest.php`
Expected: PASS (all three).

```bash
git add app/Listeners/AssignComplianceOnHire.php app/Console/Commands/ComplianceSync.php app/Providers/AppServiceProvider.php routes/console.php app/Http/Requests/Learning/StoreComplianceRequirementRequest.php app/Http/Controllers/Learning/ComplianceController.php routes/web.php tests/Feature/Learning/ComplianceTriggersTest.php
git commit -m "feat(learning): compliance triggers — on-hire listener, compliance:sync, requirement endpoint"
```

---

### Task 5: Dashboard + My-Learning surface + reminders + gate

**Files:**
- Modify: `app/Http/Controllers/Learning/ComplianceController.php` (add `index` dashboard)
- Modify: `app/Http/Controllers/LearningController.php` (`myLearning` — add mandatory/overdue)
- Create: `resources/js/Pages/Learning/Compliance.vue`
- Modify: `resources/js/Pages/Learning/MyLearning.vue` (mandatory/overdue badges)
- Create: `app/Console/Commands/ComplianceRemind.php` + `app/Notifications/ComplianceTrainingDue.php`
- Modify: `routes/web.php` (dashboard route), `routes/console.php` (schedule remind), nav
- Test: `tests/Feature/Learning/ComplianceDashboardTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\ComplianceRequirement;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrolment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(fn () => (new RolePermissionSeeder())->run());

it('shows the compliance dashboard with per-requirement counts', function () {
    $course = Course::create(['title' => 'DPA', 'category' => 'compliance', 'is_published' => true, 'created_by' => User::factory()->create()->id]);
    $req = ComplianceRequirement::create(['course_id' => $course->id, 'name' => 'All', 'target_type' => 'all_staff', 'due_in_days' => 30, 'is_active' => true]);
    $emp = Employee::factory()->create();
    Enrolment::create(['course_id' => $course->id, 'employee_id' => $emp->id, 'status' => 'active',
        'requirement_id' => $req->id, 'due_at' => now()->subDay(), 'enrolled_at' => now()->subDays(40)]);

    $mgr = User::factory()->create(['role' => 'hr_admin', 'permissions' => ['learning.compliance.manage']]);
    $this->actingAs($mgr)->get('/learning/compliance')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Learning/Compliance'));

    $this->actingAs(User::factory()->create(['role' => 'employee']))->get('/learning/compliance')->assertForbidden();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Learning/ComplianceDashboardTest.php`
Expected: FAIL — route/page missing.

- [ ] **Step 3: Dashboard action + route**

Add to `ComplianceController`:

```php
    public function index(\Illuminate\Http\Request $request): \Inertia\Response
    {
        abort_unless($request->user()->hasPermission('learning.compliance.manage'), 403);

        $requirements = ComplianceRequirement::with('course')->latest()->get()->map(function ($r) {
            $enrolments = $r->enrolments();
            return [
                'id' => $r->id, 'name' => $r->name, 'course' => $r->course?->title,
                'target' => $r->target_type->label(), 'due_in_days' => $r->due_in_days, 'is_active' => $r->is_active,
                'assigned'  => (clone $enrolments)->count(),
                'completed' => (clone $enrolments)->where('status', 'completed')->count(),
                'overdue'   => $r->enrolments()->overdue()->count(),
            ];
        });

        $overduePeople = \App\Models\Enrolment::overdue()->with(['employee.user', 'course'])->get()->map(fn ($e) => [
            'employee' => $e->employee?->user?->name, 'course' => $e->course?->title, 'due_at' => optional($e->due_at)->toDateString(),
        ]);

        return \Inertia\Inertia::render('Learning/Compliance', [
            'requirements'  => $requirements,
            'overduePeople' => $overduePeople,
            'courses'       => \App\Models\Course::published()->get(['id', 'title']),
            'activeModule'  => 'learning',
        ]);
    }
```

Route (in the `learning` group):

```php
        Route::get('compliance', [\App\Http\Controllers\Learning\ComplianceController::class, 'index'])
            ->middleware('permission:learning.compliance.manage')->name('compliance.index');
```

(Place the `GET compliance` before/after the `POST compliance` from Task 4; both under the same permission.)

- [ ] **Step 4: Build the Compliance.vue page**

`resources/js/Pages/Learning/Compliance.vue`: a requirements table (name, course, target, due-in-days, assigned/completed/overdue), a "New requirement" form (course select, name input, target type select [All staff/Role/Department], a conditional target-value input — role slug or department — when not all-staff, due-in-days number; all inputs carry `aria-label`) posting to `learning.compliance.store`, and an "Overdue" panel listing `overduePeople`. Mirror the styling of an existing admin/list page (e.g. the Onboarding Index or a Finance report page). Use the `courses` prop for the course select.

- [ ] **Step 5: My-Learning mandatory/overdue surface**

In `LearningController::myLearning`, include each enrolment's `requirement_id`/`due_at`/overdue flag (compute `is_overdue = requirement_id !== null && due_at < now && status !== completed`), and in `resources/js/Pages/Learning/MyLearning.vue` show a "Mandatory" badge + an "Overdue" (rose) / "Due {date}" badge on those enrolments. Keep it minimal and consistent with the page.

- [ ] **Step 6: Reminders — notification + command**

`app/Notifications/ComplianceTrainingDue.php`: mirror an existing simple `*Notification` (e.g. `AssetAssignedNotification`) — a database (and/or mail) notification carrying the course title + due date. `app/Console/Commands/ComplianceRemind.php`: find employees with `Enrolment::overdue()` (and optionally within N days due), notify each employee's user once. Schedule it in `routes/console.php` (e.g. `->dailyAt('07:00')->withoutOverlapping()`). Keep the reminder minimal; if the notification infra is heavy, a database notification is sufficient.

- [ ] **Step 7: Nav + build**

Add a **Compliance** entry to the Learning section of the nav (gated on `learning.compliance.manage`), mirroring how other learning/admin links are gated.

Run: `npm run build`
Expected: succeeds, no Vue errors.

- [ ] **Step 8: Regression gate**

Run: `php artisan test tests/Feature/Learning`
Expected: PASS.

Run: `php artisan test`
Expected: PASS — accessibility green (new inputs carry `aria-label`); the param-less `learning/compliance` index renders for the smoke test; allow only the known `KioskRecentTest` flake.

Run: `php artisan migrate:fresh --seed`
Expected: clean.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/Learning/ComplianceController.php app/Http/Controllers/LearningController.php resources/js/Pages/Learning/Compliance.vue resources/js/Pages/Learning/MyLearning.vue app/Console/Commands/ComplianceRemind.php app/Notifications/ComplianceTrainingDue.php routes/web.php routes/console.php <nav-file> tests/Feature/Learning/ComplianceDashboardTest.php
git commit -m "feat(learning): compliance dashboard + My-Learning overdue surface + reminders"
git commit --allow-empty -m "test(learning): LMS compliance enforcement regression gate green"
```

---

## Self-Review notes (for the implementer)

- **Backwards-compatible**: `requirement_id` null ⇒ not mandatory ⇒ never overdue; self-enrols + existing enrolments untouched; the existing learning suite stays green.
- **Idempotent**: `assign` stamps `requirement_id`/`due_at` only when unset — re-sync never duplicates or moves due dates.
- **Non-blocking**: the on-hire listener and per-employee/requirement loops are try/caught; nothing blocks employee creation or the employee's access.
- **Targeting**: `matches`/`matchingEmployees` cover all_staff/role/department; role matches `employee.user.role` (a `UserRole`), department matches `department_id`.
- **Soft enforcement**: overdue is surfaced (dashboard + My Learning) and reminded; never gates.
- **Accessibility**: the requirement-form inputs (course/name/target/target-value/due-in-days) carry `aria-label`.
