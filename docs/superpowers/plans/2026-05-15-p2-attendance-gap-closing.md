# P2 — Attendance: Gap Closing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the five documented gaps in the already-shipped Attendance module (commit `1b029e5`) to bring it to enterprise-deeper depth per the spec.

**Architecture:** Build on the existing `AttendanceService` / `OvertimeCalculator` / `BiometricIngestionService` foundation. Add (1) shift schedules + assignments, (2) GPS geofence enforcement, (3) attendance-correction request/approve workflow, (4) daily auto-mark-absent scheduled command, (5) overtime → payroll line supplement.

**Tech Stack:** Laravel 13.7 / PHP 8.3 / Pest 4 / Vue 3 / Inertia v2.

**Reference docs:**
- [docs/PHASE_2_TIME_ATTENDANCE_DELIVERY.md](../../PHASE_2_TIME_ATTENDANCE_DELIVERY.md) — what's already shipped + gaps list
- [docs/superpowers/specs/2026-05-15-cihrms-end-to-end-wiring-design.md](../specs/2026-05-15-cihrms-end-to-end-wiring-design.md) — §8 P2 enterprise-deeper spec

---

## What's already committed (P2 foundation — do not redo)

| Layer | Files |
|---|---|
| Enums | `AttendanceStatus`, `AttendanceSource` |
| Migrations | `biometric_devices`, `public_holidays`, `attendance_records`+`attendance_summaries` |
| Models | `BiometricDevice`, `AttendanceRecord`, `AttendanceSummary`, `PublicHoliday` |
| Services | `AttendanceService`, `OvertimeCalculator`, `BiometricIngestionService` |
| Webhook | `Webhooks\BiometricWebhookController` + `VerifyWebhookSignature::verifyBiometric()` |
| Routes | `GET /attendance`, `GET /attendance/me`, `POST /attendance/clock`, `POST /attendance/manual`, `POST /webhooks/biometric` |
| Permissions | `attendance.view`, `attendance.manage`, `attendance.clock_self` |
| Pages | `Attendance/Index.vue`, `Attendance/MyAttendance.vue` |
| Tests | 4 feature files, 17 cases |
| Payroll integration | `PayrollService` zero-attendance gate |

## What this plan adds

| Gap | Solution | Tasks |
|---|---|---|
| Shifts / rosters | `shifts` + `shift_assignments` tables; `ShiftService::scheduleFor()`; `AttendanceService` reads shift instead of hard-coded constants | 1–4 |
| GPS geofence enforcement | `AttendanceService::record()` validates `geo_lat`/`geo_lng` against device or zone radius; rejects out-of-zone clocks | 5–6 |
| Correction request/approve workflow | New `attendance_corrections` table separating employee-requested corrections from HR's direct `source=manual` entries | 7–9 |
| Daily auto-mark-absent | `App\Console\Commands\MarkAbsentEmployees` scheduled at 23:55 daily | 10 |
| Overtime → payroll line | `PayrollService::calculateLine()` reads `attendance_summaries.overtime_hours` × OT premium | 11 |
| Live wiring | New Vue pages: `Attendance/Shifts.vue`, `Attendance/Corrections.vue`; update `Attendance/Index.vue` quick-stats | 12–13 |
| Docs refresh | Update `docs/PROJECT_STATE.md` to reflect P2 complete | 14 |

---

## File map

### Created
- `app/Enums/ShiftFrequency.php` (optional — only if shifts have varying weekly patterns; defer for V1)
- `app/Enums/CorrectionStatus.php`
- `database/migrations/2026_05_27_000001_create_shifts_and_assignments.php`
- `database/migrations/2026_05_27_000002_create_attendance_corrections.php`
- `app/Models/Shift.php`
- `app/Models/ShiftAssignment.php`
- `app/Models/AttendanceCorrection.php`
- `app/Services/Attendance/ShiftService.php`
- `app/Http/Requests/Attendance/StoreShiftRequest.php`
- `app/Http/Requests/Attendance/UpdateShiftRequest.php`
- `app/Http/Requests/Attendance/AssignShiftRequest.php`
- `app/Http/Requests/Attendance/StoreCorrectionRequest.php`
- `app/Http/Requests/Attendance/ReviewCorrectionRequest.php`
- `app/Http/Resources/ShiftResource.php`
- `app/Http/Resources/AttendanceCorrectionResource.php`
- `app/Events/AttendanceCorrectionRequested.php`
- `app/Events/AttendanceCorrectionDecided.php`
- `app/Console/Commands/MarkAbsentEmployees.php`
- `resources/js/Pages/Attendance/Shifts.vue`
- `resources/js/Pages/Attendance/Corrections.vue`
- `tests/Feature/Attendance/ShiftServiceTest.php`
- `tests/Feature/Attendance/AttendanceCorrectionTest.php`
- `tests/Feature/Attendance/GeofenceEnforcementTest.php`
- `tests/Feature/Attendance/MarkAbsentCommandTest.php`
- `tests/Feature/Payroll/PayrollOvertimeSupplementTest.php`

### Modified
- `app/Services/Attendance/AttendanceService.php` — schedule lookup goes through ShiftService; geofence enforcement; correction approval applies new event times.
- `app/Http/Controllers/AttendanceController.php` — adds shift + correction endpoints.
- `app/Policies/AttendancePolicy.php` — adds `approveCorrection`, `manageShifts` methods.
- `app/Services/Payroll/PayrollService.php` — overtime supplement.
- `database/seeders/RolePermissionSeeder.php` — adds `attendance.approve`, `attendance.correct`, `attendance.shift_manage`.
- `routes/web.php` — adds shifts + corrections routes.
- `resources/js/Pages/Attendance/Index.vue` — links to new Shifts + Corrections pages, shift name column on the daily summary table.
- `routes/console.php` — schedules `MarkAbsentEmployees` daily.
- `docs/PROJECT_STATE.md` — reflect new state.

---

## TASK 1: Migration + models for shifts

**Files:**
- Create: `database/migrations/2026_05_27_000001_create_shifts_and_assignments.php`
- Create: `app/Models/Shift.php`
- Create: `app/Models/ShiftAssignment.php`

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 80);
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('grace_period_minutes')->default(15);
            $table->decimal('full_day_hours', 4, 2)->default(8.00);
            $table->decimal('half_day_hours', 4, 2)->default(4.00);
            $table->json('working_days')->nullable(); // ["mon","tue","wed","thu","fri"]; null = Mon–Fri
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->index(['employee_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_assignments');
        Schema::dropIfExists('shifts');
    }
};
```

- [ ] **Step 2: Create the Shift model**

`app/Models/Shift.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shift extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code', 'name', 'start_time', 'end_time',
        'grace_period_minutes', 'full_day_hours', 'half_day_hours',
        'working_days', 'department_id', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'working_days'         => 'array',
            'is_active'            => 'boolean',
            'full_day_hours'       => 'decimal:2',
            'half_day_hours'       => 'decimal:2',
            'grace_period_minutes' => 'integer',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class);
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
```

- [ ] **Step 3: Create the ShiftAssignment model**

`app/Models/ShiftAssignment.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ShiftAssignment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'employee_id', 'shift_id', 'effective_from', 'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to'   => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
```

- [ ] **Step 4: Run migration locally**

```powershell
php artisan migrate
```
Expected: both new tables created. If migrate fails, fix syntax and retry.

- [ ] **Step 5: Commit**

```powershell
git add database/migrations/2026_05_27_000001_create_shifts_and_assignments.php app/Models/Shift.php app/Models/ShiftAssignment.php
git commit -m "feat(attendance): add shifts + shift_assignments tables and models"
```

---

## TASK 2: ShiftService

**Files:**
- Create: `app/Services/Attendance/ShiftService.php`
- Create: `tests/Feature/Attendance/ShiftServiceTest.php`

- [ ] **Step 1: Write failing test**

`tests/Feature/Attendance/ShiftServiceTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Services\Attendance\ShiftService;
use Carbon\CarbonImmutable;

it('returns the default Ghana public-service schedule when no assignment exists', function () {
    $emp = Employee::factory()->create();
    $schedule = app(ShiftService::class)->scheduleFor($emp, CarbonImmutable::parse('2026-06-15'));

    expect($schedule['start_time'])->toBe('08:00')
        ->and($schedule['end_time'])->toBe('17:00')
        ->and($schedule['grace_period_minutes'])->toBe(15)
        ->and($schedule['full_day_hours'])->toBe(8.0)
        ->and($schedule['half_day_hours'])->toBe(4.0)
        ->and($schedule['working_days'])->toBe(['mon','tue','wed','thu','fri']);
});

it('returns the assigned shift when an active assignment covers the date', function () {
    $emp = Employee::factory()->create();
    $shift = Shift::create([
        'code' => 'NIGHT', 'name' => 'Night Shift',
        'start_time' => '22:00', 'end_time' => '06:00',
        'grace_period_minutes' => 10, 'full_day_hours' => 8.0, 'half_day_hours' => 4.0,
        'is_active' => true,
    ]);
    ShiftAssignment::create([
        'employee_id'    => $emp->id,
        'shift_id'       => $shift->id,
        'effective_from' => '2026-06-01',
        'effective_to'   => null,
    ]);

    $schedule = app(ShiftService::class)->scheduleFor($emp, CarbonImmutable::parse('2026-06-15'));

    expect($schedule['start_time'])->toBe('22:00')
        ->and($schedule['end_time'])->toBe('06:00')
        ->and($schedule['grace_period_minutes'])->toBe(10);
});

it('falls back to default when the assignment has expired', function () {
    $emp = Employee::factory()->create();
    $shift = Shift::create([
        'code' => 'OLD', 'name' => 'Old Shift',
        'start_time' => '06:00', 'end_time' => '14:00',
        'grace_period_minutes' => 5, 'full_day_hours' => 8.0, 'half_day_hours' => 4.0,
        'is_active' => true,
    ]);
    ShiftAssignment::create([
        'employee_id'    => $emp->id,
        'shift_id'       => $shift->id,
        'effective_from' => '2026-01-01',
        'effective_to'   => '2026-05-31',
    ]);

    $schedule = app(ShiftService::class)->scheduleFor($emp, CarbonImmutable::parse('2026-06-15'));

    expect($schedule['start_time'])->toBe('08:00');
});
```

`php -l tests/Feature/Attendance/ShiftServiceTest.php`. Don't run Pest locally (PHP 8.5 blocker) — rely on CI.

- [ ] **Step 2: Implement ShiftService**

`app/Services/Attendance/ShiftService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use Carbon\CarbonImmutable;

class ShiftService
{
    public const DEFAULT_SCHEDULE = [
        'start_time'           => '08:00',
        'end_time'             => '17:00',
        'grace_period_minutes' => 15,
        'full_day_hours'       => 8.0,
        'half_day_hours'       => 4.0,
        'working_days'         => ['mon','tue','wed','thu','fri'],
    ];

    public function scheduleFor(Employee $employee, CarbonImmutable $date): array
    {
        $assignment = ShiftAssignment::query()
            ->where('employee_id', $employee->id)
            ->where('effective_from', '<=', $date->toDateString())
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date->toDateString());
            })
            ->with('shift')
            ->latest('effective_from')
            ->first();

        if (! $assignment || ! $assignment->shift?->is_active) {
            return self::DEFAULT_SCHEDULE;
        }

        $shift = $assignment->shift;

        return [
            'start_time'           => substr((string) $shift->start_time, 0, 5),
            'end_time'             => substr((string) $shift->end_time, 0, 5),
            'grace_period_minutes' => (int) $shift->grace_period_minutes,
            'full_day_hours'       => (float) $shift->full_day_hours,
            'half_day_hours'       => (float) $shift->half_day_hours,
            'working_days'         => $shift->working_days ?: self::DEFAULT_SCHEDULE['working_days'],
        ];
    }
}
```

- [ ] **Step 3: Register ShiftService in AppServiceProvider**

Open `app/Providers/AppServiceProvider.php`. Find the existing `$this->app->singleton(AttendanceService::class);` line in the `register()` method. Add:

```php
$this->app->singleton(\App\Services\Attendance\ShiftService::class);
```

- [ ] **Step 4: Commit**

```powershell
git add app/Services/Attendance/ShiftService.php tests/Feature/Attendance/ShiftServiceTest.php app/Providers/AppServiceProvider.php
git commit -m "feat(attendance): ShiftService.scheduleFor() with employee-shift assignments + default Ghana schedule"
```

---

## TASK 3: Wire AttendanceService to use ShiftService

**Files:**
- Modify: `app/Services/Attendance/AttendanceService.php`

Currently `AttendanceService` uses hard-coded constants (`SCHEDULE_START = '08:00'` etc.). Replace the constants with calls to `ShiftService::scheduleFor()`.

- [ ] **Step 1: Read the existing AttendanceService**

```powershell
Get-Content app/Services/Attendance/AttendanceService.php
```

Note every usage of `self::SCHEDULE_START`, `self::SCHEDULE_END`, `self::LATE_THRESHOLD`, `self::HALF_DAY_HOURS`, `self::FULL_DAY_HOURS`. These will be replaced.

- [ ] **Step 2: Inject ShiftService into AttendanceService**

Add the constructor (if missing) — or modify the existing constructor:

```php
public function __construct(private readonly ShiftService $shiftService) {}
```

And add the import at the top:
```php
use App\Services\Attendance\ShiftService;
```

- [ ] **Step 3: Replace constant usages with schedule lookups**

In the method that computes daily status (likely `recomputeDailySummary` or `deriveStatus`), find where `self::SCHEDULE_START` etc. are used. At the top of that method, add:

```php
$schedule = $this->shiftService->scheduleFor($employee, CarbonImmutable::parse($date));
```

Then replace:
- `self::SCHEDULE_START` → `$schedule['start_time']`
- `self::SCHEDULE_END` → `$schedule['end_time']`
- `self::LATE_THRESHOLD` → use `Carbon::parse($schedule['start_time'])->addMinutes($schedule['grace_period_minutes'])->format('H:i')`
- `self::HALF_DAY_HOURS` → `$schedule['half_day_hours']`
- `self::FULL_DAY_HOURS` → `$schedule['full_day_hours']`

For weekend detection, replace any hard-coded Saturday/Sunday check with `! in_array(strtolower(Carbon::parse($date)->englishDayOfWeek), $schedule['working_days'], true)`.

- [ ] **Step 4: Remove the now-unused constants**

Delete the four `private const SCHEDULE_*` and `private const *_HOURS` lines. Keep them only if other methods still use them.

- [ ] **Step 5: Re-run existing AttendanceServiceTest to confirm green**

Tests should still pass because the default `ShiftService::DEFAULT_SCHEDULE` matches the old constants exactly. Can't run locally — push to CI.

`php -l app/Services/Attendance/AttendanceService.php`.

- [ ] **Step 6: Commit**

```powershell
git add app/Services/Attendance/AttendanceService.php
git commit -m "refactor(attendance): replace hard-coded schedule constants with ShiftService lookup"
```

---

## TASK 4: Shift CRUD endpoints + Vue page

**Files:**
- Create: `app/Http/Requests/Attendance/StoreShiftRequest.php`
- Create: `app/Http/Requests/Attendance/UpdateShiftRequest.php`
- Create: `app/Http/Requests/Attendance/AssignShiftRequest.php`
- Create: `app/Http/Resources/ShiftResource.php`
- Modify: `app/Http/Controllers/AttendanceController.php`
- Modify: `app/Policies/AttendancePolicy.php`
- Modify: `routes/web.php`
- Modify: `database/seeders/RolePermissionSeeder.php`
- Create: `resources/js/Pages/Attendance/Shifts.vue`

- [ ] **Step 1: Add `attendance.shift_manage` permission to RolePermissionSeeder**

Open `database/seeders/RolePermissionSeeder.php`. Find the `PERMISSIONS` constant. Add a new entry:

```php
'attendance.shift_manage' => ['Attendance', 'Manage shift schedules and assignments'],
```

Grant it in `ROLE_PERMS`:
- `hr_admin` array: add `'attendance.shift_manage'`
- `super_admin` already gets all via wildcard.

- [ ] **Step 2: Create StoreShiftRequest**

`app/Http/Requests/Attendance/StoreShiftRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class StoreShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('attendance.shift_manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'code'                 => ['required', 'string', 'max:20', 'unique:shifts,code'],
            'name'                 => ['required', 'string', 'max:80'],
            'start_time'           => ['required', 'date_format:H:i'],
            'end_time'             => ['required', 'date_format:H:i'],
            'grace_period_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
            'full_day_hours'       => ['nullable', 'numeric', 'min:1', 'max:24'],
            'half_day_hours'       => ['nullable', 'numeric', 'min:0.5', 'max:12'],
            'working_days'         => ['nullable', 'array'],
            'working_days.*'       => ['string', 'in:mon,tue,wed,thu,fri,sat,sun'],
            'department_id'        => ['nullable', 'integer', 'exists:departments,id'],
            'is_active'            => ['nullable', 'boolean'],
        ];
    }
}
```

- [ ] **Step 3: Create UpdateShiftRequest**

Same as StoreShiftRequest but `code` rule changes to `['required', 'string', 'max:20', 'unique:shifts,code,' . $this->route('shift')?->id]`.

- [ ] **Step 4: Create AssignShiftRequest**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class AssignShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('attendance.shift_manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'employee_id'    => ['required', 'integer', 'exists:employees,id'],
            'shift_id'       => ['required', 'integer', 'exists:shifts,id'],
            'effective_from' => ['required', 'date'],
            'effective_to'   => ['nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }
}
```

- [ ] **Step 5: Create ShiftResource**

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ShiftResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                   => $this->id,
            'code'                 => $this->code,
            'name'                 => $this->name,
            'start_time'           => substr((string) $this->start_time, 0, 5),
            'end_time'             => substr((string) $this->end_time, 0, 5),
            'grace_period_minutes' => $this->grace_period_minutes,
            'full_day_hours'       => (float) $this->full_day_hours,
            'half_day_hours'       => (float) $this->half_day_hours,
            'working_days'         => $this->working_days,
            'department'           => $this->whenLoaded('department', fn () => [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ]),
            'is_active'            => (bool) $this->is_active,
        ];
    }
}
```

- [ ] **Step 6: Add policy method**

In `app/Policies/AttendancePolicy.php`, add:

```php
public function manageShifts(User $user): bool
{
    return $user->hasPermission('attendance.shift_manage');
}
```

- [ ] **Step 7: Add controller actions in AttendanceController**

Methods to add to `AttendanceController` (alongside existing `index`, `myAttendance`, `clockSelf`, `manualEntry`):

```php
public function shiftsIndex(): \Inertia\Response
{
    $this->authorize('manageShifts', \App\Models\AttendanceRecord::class);

    return Inertia::render('Attendance/Shifts', [
        'shifts' => ShiftResource::collection(
            Shift::with('department:id,name')->latest()->paginate(20)
        ),
        'departments' => Department::orderBy('name')->get(['id', 'name', 'code']),
        'employees'   => Employee::with('user:id,name')->active()->orderBy('id')->get(['id', 'user_id', 'employee_no', 'position']),
        'assignments' => ShiftAssignment::with(['shift:id,name,code', 'employee:id,user_id,employee_no'])
            ->where(function ($q) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', today());
            })
            ->latest('effective_from')
            ->limit(50)
            ->get(),
    ]);
}

public function storeShift(StoreShiftRequest $request)
{
    $shift = Shift::create($request->validated());
    return back()->with('success', "Shift {$shift->code} created");
}

public function updateShift(UpdateShiftRequest $request, Shift $shift)
{
    $shift->update($request->validated());
    return back()->with('success', "Shift {$shift->code} updated");
}

public function destroyShift(Shift $shift)
{
    $this->authorize('manageShifts', \App\Models\AttendanceRecord::class);
    $shift->delete();
    return back()->with('success', 'Shift archived');
}

public function assignShift(AssignShiftRequest $request)
{
    ShiftAssignment::create($request->validated());
    return back()->with('success', 'Shift assigned');
}
```

Add the necessary imports at the top of `AttendanceController.php`:
```php
use App\Http\Requests\Attendance\AssignShiftRequest;
use App\Http\Requests\Attendance\StoreShiftRequest;
use App\Http\Requests\Attendance\UpdateShiftRequest;
use App\Http\Resources\ShiftResource;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftAssignment;
```

- [ ] **Step 8: Add routes**

In `routes/web.php`, find the existing `attendance.*` route group. Add inside:

```php
Route::prefix('shifts')->name('shifts.')->middleware('permission:attendance.shift_manage')->group(function () {
    Route::get('/',           [AttendanceController::class, 'shiftsIndex'])->name('index');
    Route::post('/',          [AttendanceController::class, 'storeShift'])->name('store');
    Route::patch('/{shift}',  [AttendanceController::class, 'updateShift'])->name('update');
    Route::delete('/{shift}', [AttendanceController::class, 'destroyShift'])->name('destroy');
    Route::post('/assignments', [AttendanceController::class, 'assignShift'])->name('assign');
});
```

- [ ] **Step 9: Create Vue page Shifts.vue**

`resources/js/Pages/Attendance/Shifts.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    shifts:      Object,
    departments: Array,
    employees:   Array,
    assignments: Array,
});

const showCreate = ref(false);
const showAssign = ref(false);

const newShift = useForm({
    code: '', name: '',
    start_time: '08:00', end_time: '17:00',
    grace_period_minutes: 15,
    full_day_hours: 8.0, half_day_hours: 4.0,
    working_days: ['mon','tue','wed','thu','fri'],
    department_id: null,
    is_active: true,
});

const newAssignment = useForm({
    employee_id: '', shift_id: '',
    effective_from: new Date().toISOString().slice(0,10),
    effective_to: null,
});

function createShift() {
    newShift.post(route('attendance.shifts.store'), { onSuccess: () => { showCreate.value = false; newShift.reset(); } });
}

function assignShift() {
    newAssignment.post(route('attendance.shifts.assign'), { onSuccess: () => { showAssign.value = false; newAssignment.reset(); } });
}

const dayLabels = { mon:'Mon', tue:'Tue', wed:'Wed', thu:'Thu', fri:'Fri', sat:'Sat', sun:'Sun' };
</script>

<template>
<Head title="Shifts" />
<AuthenticatedLayout active-module="attendance">
    <div class="space-y-8 animate-reveal-up">
        <header class="flex items-center justify-between">
            <div>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary">Shift Schedules</h1>
                <p class="text-sm text-on-surface-variant">Define shift patterns and assign them to employees. Default Ghana public-service schedule (Mon–Fri 08:00–17:00, 15-min grace) applies when no assignment is active.</p>
            </div>
            <div class="flex gap-2">
                <button @click="showAssign = true" class="rounded-xl border border-outline-variant px-4 py-2 text-sm font-bold text-primary hover:bg-surface-container-low">Assign</button>
                <button @click="showCreate = true" class="rounded-xl bg-gradient-to-br from-primary to-secondary px-4 py-2 text-sm font-bold text-white shadow-glow-sm">+ New Shift</button>
            </div>
        </header>

        <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4">
            <h2 class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 mb-3">Shifts</h2>
            <table class="w-full text-sm">
                <thead><tr class="text-left text-[10px] font-black uppercase text-on-surface-variant tracking-widest">
                    <th class="pb-2">Code</th><th>Name</th><th>Window</th><th>Grace</th><th>Days</th><th>Dept</th><th></th>
                </tr></thead>
                <tbody>
                    <tr v-for="s in props.shifts.data" :key="s.id" class="border-t border-outline-variant/40">
                        <td class="py-2 font-mono">{{ s.code }}</td>
                        <td>{{ s.name }}</td>
                        <td>{{ s.start_time }} – {{ s.end_time }}</td>
                        <td>{{ s.grace_period_minutes }}m</td>
                        <td><span v-for="d in s.working_days" :key="d" class="px-1.5 py-0.5 mr-1 text-[10px] font-bold rounded bg-surface-container-low text-on-surface-variant">{{ dayLabels[d] }}</span></td>
                        <td>{{ s.department?.name ?? '—' }}</td>
                        <td><span v-if="s.is_active" class="text-[10px] font-bold text-emerald-700">ACTIVE</span><span v-else class="text-[10px] font-bold text-on-surface-variant">archived</span></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!props.shifts.data?.length" message="No shifts defined. Default schedule applies." class="py-8" />
        </section>

        <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4">
            <h2 class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 mb-3">Active Assignments</h2>
            <table class="w-full text-sm">
                <thead><tr class="text-left text-[10px] font-black uppercase text-on-surface-variant tracking-widest">
                    <th class="pb-2">Employee #</th><th>Shift</th><th>Effective From</th><th>Effective To</th>
                </tr></thead>
                <tbody>
                    <tr v-for="a in props.assignments" :key="a.id" class="border-t border-outline-variant/40">
                        <td class="py-2 font-mono">{{ a.employee?.employee_no }}</td>
                        <td>{{ a.shift?.name }} <span class="text-on-surface-variant">({{ a.shift?.code }})</span></td>
                        <td>{{ a.effective_from }}</td>
                        <td>{{ a.effective_to ?? 'open-ended' }}</td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!props.assignments?.length" message="No active assignments. All employees on default schedule." class="py-8" />
        </section>
    </div>

    <SlidePanel :show="showCreate" @close="showCreate = false" title="Create Shift">
        <form @submit.prevent="createShift" class="space-y-4 p-4">
            <div><label class="text-[11px] font-bold text-on-surface-variant">Code</label><input v-model="newShift.code" maxlength="20" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 font-mono uppercase" /></div>
            <div><label class="text-[11px] font-bold text-on-surface-variant">Name</label><input v-model="newShift.name" maxlength="80" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2" /></div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="text-[11px] font-bold text-on-surface-variant">Start</label><input v-model="newShift.start_time" type="time" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2" /></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">End</label><input v-model="newShift.end_time" type="time" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2" /></div>
            </div>
            <div class="grid grid-cols-3 gap-3">
                <div><label class="text-[11px] font-bold text-on-surface-variant">Grace (min)</label><input v-model.number="newShift.grace_period_minutes" type="number" min="0" max="120" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2" /></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Full day h</label><input v-model.number="newShift.full_day_hours" type="number" step="0.25" min="1" max="24" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2" /></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Half day h</label><input v-model.number="newShift.half_day_hours" type="number" step="0.25" min="0.5" max="12" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2" /></div>
            </div>
            <div><label class="text-[11px] font-bold text-on-surface-variant">Department (optional)</label><select v-model="newShift.department_id" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2"><option :value="null">— Any —</option><option v-for="d in props.departments" :key="d.id" :value="d.id">{{ d.name }}</option></select></div>
            <button type="submit" :disabled="newShift.processing" class="w-full rounded-xl bg-gradient-to-br from-primary to-secondary px-4 py-2 text-sm font-bold text-white">Create Shift</button>
        </form>
    </SlidePanel>

    <SlidePanel :show="showAssign" @close="showAssign = false" title="Assign Shift to Employee">
        <form @submit.prevent="assignShift" class="space-y-4 p-4">
            <div><label class="text-[11px] font-bold text-on-surface-variant">Employee</label><select v-model="newAssignment.employee_id" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2"><option value="" disabled>Select…</option><option v-for="e in props.employees" :key="e.id" :value="e.id">{{ e.employee_no }} — {{ e.position }}</option></select></div>
            <div><label class="text-[11px] font-bold text-on-surface-variant">Shift</label><select v-model="newAssignment.shift_id" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2"><option value="" disabled>Select…</option><option v-for="s in props.shifts.data" :key="s.id" :value="s.id">{{ s.code }} — {{ s.name }}</option></select></div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="text-[11px] font-bold text-on-surface-variant">Effective From</label><input v-model="newAssignment.effective_from" type="date" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2" /></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Effective To</label><input v-model="newAssignment.effective_to" type="date" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2" /></div>
            </div>
            <button type="submit" :disabled="newAssignment.processing" class="w-full rounded-xl bg-gradient-to-br from-primary to-secondary px-4 py-2 text-sm font-bold text-white">Assign</button>
        </form>
    </SlidePanel>
</AuthenticatedLayout>
</template>
```

- [ ] **Step 10: Build and verify**

```powershell
npm run build
php artisan route:list --name=attendance.shifts
```
Expected: build succeeds; 5 `attendance.shifts.*` routes listed.

- [ ] **Step 11: Commit**

```powershell
git add app/Http/Requests/Attendance/StoreShiftRequest.php app/Http/Requests/Attendance/UpdateShiftRequest.php app/Http/Requests/Attendance/AssignShiftRequest.php app/Http/Resources/ShiftResource.php app/Http/Controllers/AttendanceController.php app/Policies/AttendancePolicy.php routes/web.php database/seeders/RolePermissionSeeder.php resources/js/Pages/Attendance/Shifts.vue
git commit -m "feat(attendance): shift CRUD endpoints + employee assignment + Shifts.vue management page"
```

---

## TASK 5: Geofence enforcement

**Files:**
- Modify: `app/Services/Attendance/AttendanceService.php`
- Create: `tests/Feature/Attendance/GeofenceEnforcementTest.php`

The `biometric_devices` table already has `geo_lat`, `geo_lng`, `geo_radius_m`. Currently captured but never enforced.

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Models\BiometricDevice;
use App\Models\Employee;
use App\Services\Attendance\AttendanceService;

it('accepts a clock-in inside the device geofence', function () {
    $emp = Employee::factory()->create();
    $device = BiometricDevice::create([
        'code' => 'TEST-01', 'name' => 'Test Device', 'vendor' => 'zkteco',
        'shared_secret' => 'test', 'geo_lat' => 5.6037, 'geo_lng' => -0.1870,
        'geo_radius_m' => 100, 'is_active' => true,
    ]);

    $record = app(AttendanceService::class)->record(
        $emp, now(), 'in', AttendanceSource::GpsMobile,
        $device->id, 5.6037, -0.1870 // exact same coords
    );

    expect($record)->not->toBeNull();
});

it('rejects a clock-in outside the device geofence radius', function () {
    $emp = Employee::factory()->create();
    $device = BiometricDevice::create([
        'code' => 'TEST-02', 'name' => 'Test Device 2', 'vendor' => 'zkteco',
        'shared_secret' => 'test', 'geo_lat' => 5.6037, 'geo_lng' => -0.1870,
        'geo_radius_m' => 50, 'is_active' => true,
    ]);

    // ~5km away (Accra coordinates ±0.05° ≈ 5km)
    expect(fn () => app(AttendanceService::class)->record(
        $emp, now(), 'in', AttendanceSource::GpsMobile,
        $device->id, 5.6537, -0.1870
    ))->toThrow(\DomainException::class, 'geofence');
});

it('accepts a clock-in without geofence enforcement when no device is associated', function () {
    $emp = Employee::factory()->create();

    $record = app(AttendanceService::class)->record(
        $emp, now(), 'in', AttendanceSource::WebKiosk,
        null, null, null
    );

    expect($record)->not->toBeNull();
});
```

- [ ] **Step 2: Implement geofence enforcement in AttendanceService**

In `AttendanceService::record()`, after the existing input validation (direction check, reason check), add:

```php
if ($deviceId && $geoLat !== null && $geoLng !== null) {
    $device = \App\Models\BiometricDevice::find($deviceId);
    if ($device && $device->geo_lat !== null && $device->geo_radius_m !== null) {
        $distanceMeters = $this->haversineMeters(
            (float) $device->geo_lat, (float) $device->geo_lng,
            $geoLat, $geoLng
        );
        if ($distanceMeters > (int) $device->geo_radius_m) {
            throw new \DomainException(sprintf(
                'Clock event %.0fm outside %dm geofence for device %s.',
                $distanceMeters, $device->geo_radius_m, $device->code
            ));
        }
    }
}
```

Add the haversine helper as a private method:

```php
private function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earthRadius = 6_371_000.0; // metres
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
}
```

- [ ] **Step 3: `php -l` check**

```powershell
php -l app/Services/Attendance/AttendanceService.php
```

- [ ] **Step 4: Commit**

```powershell
git add app/Services/Attendance/AttendanceService.php tests/Feature/Attendance/GeofenceEnforcementTest.php
git commit -m "feat(attendance): enforce GPS geofence radius on clock events with haversine check"
```

---

## TASK 6: Surface geofence error in self-clock endpoint

**Files:**
- Modify: `app/Http/Controllers/AttendanceController.php`

The current `clockSelf` action doesn't pass a `device_id` for web/mobile self-clocks. The geofence enforcement above only runs when `deviceId && geoLat && geoLng`. For mobile self-clocks we want to enforce against the **nearest** device's geofence.

- [ ] **Step 1: Update clockSelf to do nearest-device geofence check**

In `AttendanceController::clockSelf`, after the request validation and before calling `AttendanceService::record`, look up the nearest active device by lat/lng if both are provided:

```php
$nearestDeviceId = null;
if ($validated['geo_lat'] !== null && $validated['geo_lng'] !== null) {
    // Bounding-box prefilter then exact haversine via the service
    $candidates = \App\Models\BiometricDevice::query()
        ->where('is_active', true)
        ->whereNotNull('geo_lat')
        ->whereNotNull('geo_lng')
        ->whereNotNull('geo_radius_m')
        ->whereBetween('geo_lat', [$validated['geo_lat'] - 0.5, $validated['geo_lat'] + 0.5])
        ->whereBetween('geo_lng', [$validated['geo_lng'] - 0.5, $validated['geo_lng'] + 0.5])
        ->get();

    $nearestDeviceId = $candidates->first()?->id;
}
```

Then pass `$nearestDeviceId` as the `deviceId` argument to `AttendanceService::record(...)`. The service will haversine-check it; if outside, the `DomainException` bubbles up and the controller should `return back()->with('error', $e->getMessage())`.

Wrap the `record()` call in try/catch:

```php
try {
    $this->attendance->record(
        $employee, now(), $validated['direction'], $source,
        $nearestDeviceId, $validated['geo_lat'], $validated['geo_lng'],
    );
} catch (\DomainException $e) {
    return back()->with('error', $e->getMessage());
}

return back()->with('success', "Clocked {$validated['direction']} successfully");
```

- [ ] **Step 2: Build and commit**

```powershell
npm run build
git add app/Http/Controllers/AttendanceController.php
git commit -m "feat(attendance): self-clock enforces nearest-device geofence with friendly error"
```

---

## TASK 7: AttendanceCorrection table + model + enum

**Files:**
- Create: `app/Enums/CorrectionStatus.php`
- Create: `database/migrations/2026_05_27_000002_create_attendance_corrections.php`
- Create: `app/Models/AttendanceCorrection.php`

- [ ] **Step 1: Enum**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum CorrectionStatus: string
{
    case Pending  = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
```

- [ ] **Step 2: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_record_id')->nullable()->constrained('attendance_records')->nullOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('requested_event_at');
            $table->string('requested_direction', 4); // in | out
            $table->text('reason');
            $table->string('status', 12)->default('pending');
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->index(['employee_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_corrections');
    }
};
```

- [ ] **Step 3: Model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CorrectionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AttendanceCorrection extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'attendance_record_id', 'employee_id', 'requester_id',
        'requested_event_at', 'requested_direction', 'reason',
        'status', 'reviewer_id', 'reviewed_at', 'decision_notes',
    ];

    protected function casts(): array
    {
        return [
            'requested_event_at' => 'datetime',
            'reviewed_at'        => 'datetime',
            'status'             => CorrectionStatus::class,
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class);
    }

    public function scopePending($q)
    {
        return $q->where('status', CorrectionStatus::Pending->value);
    }
}
```

- [ ] **Step 4: Run migration + commit**

```powershell
php artisan migrate
git add app/Enums/CorrectionStatus.php database/migrations/2026_05_27_000002_create_attendance_corrections.php app/Models/AttendanceCorrection.php
git commit -m "feat(attendance): add attendance_corrections table with pending/approved/rejected workflow"
```

---

## TASK 8: Correction request/approve service methods + events

**Files:**
- Modify: `app/Services/Attendance/AttendanceService.php`
- Create: `app/Events/AttendanceCorrectionRequested.php`
- Create: `app/Events/AttendanceCorrectionDecided.php`
- Modify: `app/Providers/AppServiceProvider.php` (event registration)
- Modify: `app/Listeners/RecordAnalyticsEvent.php` (handle new event names)

- [ ] **Step 1: Create event classes**

`app/Events/AttendanceCorrectionRequested.php`:
```php
<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\AttendanceCorrection;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class AttendanceCorrectionRequested
{
    use Dispatchable;

    public function __construct(
        public readonly AttendanceCorrection $correction,
        public readonly ?User $actor = null,
    ) {}
}
```

`app/Events/AttendanceCorrectionDecided.php` — same shape, just rename.

- [ ] **Step 2: Add service methods to AttendanceService**

```php
use App\Enums\CorrectionStatus;
use App\Events\AttendanceCorrectionDecided;
use App\Events\AttendanceCorrectionRequested;
use App\Models\AttendanceCorrection;

public function requestCorrection(
    Employee $employee,
    User $requester,
    \DateTimeInterface|string $requestedEventAt,
    string $direction,
    string $reason,
    ?int $attendanceRecordId = null,
): AttendanceCorrection {
    if (! in_array($direction, ['in', 'out'], true)) {
        throw new \InvalidArgumentException("direction must be 'in' or 'out'.");
    }

    $correction = AttendanceCorrection::create([
        'attendance_record_id' => $attendanceRecordId,
        'employee_id'          => $employee->id,
        'requester_id'         => $requester->id,
        'requested_event_at'   => $requestedEventAt,
        'requested_direction'  => $direction,
        'reason'               => $reason,
        'status'               => CorrectionStatus::Pending,
    ]);

    AttendanceCorrectionRequested::dispatch($correction, $requester);

    return $correction;
}

public function approveCorrection(AttendanceCorrection $correction, User $reviewer, ?string $notes = null): AttendanceCorrection
{
    if ($correction->status !== CorrectionStatus::Pending) {
        throw new \DomainException('Only pending corrections can be approved.');
    }

    DB::transaction(function () use ($correction, $reviewer, $notes) {
        // Materialize the approved correction as a manual AttendanceRecord
        $this->record(
            $correction->employee,
            $correction->requested_event_at,
            $correction->requested_direction,
            \App\Enums\AttendanceSource::Manual,
            null, null, null,
            $reviewer,
            'Approved correction #' . $correction->id . ': ' . $correction->reason,
        );

        $correction->update([
            'status'         => CorrectionStatus::Approved,
            'reviewer_id'    => $reviewer->id,
            'reviewed_at'    => now(),
            'decision_notes' => $notes,
        ]);

        // Recompute the daily summary for the corrected day
        $this->recomputeDailySummary(
            $correction->employee,
            CarbonImmutable::instance($correction->requested_event_at)->toDateString()
        );
    });

    AttendanceCorrectionDecided::dispatch($correction->fresh(), $reviewer);
    return $correction->fresh();
}

public function rejectCorrection(AttendanceCorrection $correction, User $reviewer, string $notes): AttendanceCorrection
{
    if ($correction->status !== CorrectionStatus::Pending) {
        throw new \DomainException('Only pending corrections can be rejected.');
    }

    $correction->update([
        'status'         => CorrectionStatus::Rejected,
        'reviewer_id'    => $reviewer->id,
        'reviewed_at'    => now(),
        'decision_notes' => $notes,
    ]);

    AttendanceCorrectionDecided::dispatch($correction->fresh(), $reviewer);
    return $correction->fresh();
}
```

- [ ] **Step 3: Register events**

In `AppServiceProvider::boot()`, after the existing `Event::listen()` calls, add:

```php
Event::listen(\App\Events\AttendanceCorrectionRequested::class, \App\Listeners\RecordAnalyticsEvent::class);
Event::listen(\App\Events\AttendanceCorrectionDecided::class, \App\Listeners\RecordAnalyticsEvent::class);
```

- [ ] **Step 4: Extend RecordAnalyticsEvent**

In `app/Listeners/RecordAnalyticsEvent.php`, add match arms before `default`:

```php
$event instanceof \App\Events\AttendanceCorrectionRequested => [
    'attendance.correction.requested',
    ['correction_id' => $event->correction->id, 'employee_id' => $event->correction->employee_id],
],
$event instanceof \App\Events\AttendanceCorrectionDecided => [
    'attendance.correction.decided',
    ['correction_id' => $event->correction->id, 'status' => $event->correction->status->value],
],
```

- [ ] **Step 5: Commit**

```powershell
git add app/Services/Attendance/AttendanceService.php app/Events/AttendanceCorrectionRequested.php app/Events/AttendanceCorrectionDecided.php app/Providers/AppServiceProvider.php app/Listeners/RecordAnalyticsEvent.php
git commit -m "feat(attendance): correction request/approve/reject service methods + events"
```

---

## TASK 9: Correction controller endpoints + Vue page

**Files:**
- Create: `app/Http/Requests/Attendance/StoreCorrectionRequest.php`
- Create: `app/Http/Requests/Attendance/ReviewCorrectionRequest.php`
- Create: `app/Http/Resources/AttendanceCorrectionResource.php`
- Modify: `app/Http/Controllers/AttendanceController.php`
- Modify: `app/Policies/AttendancePolicy.php`
- Modify: `routes/web.php`
- Modify: `database/seeders/RolePermissionSeeder.php`
- Create: `resources/js/Pages/Attendance/Corrections.vue`
- Create: `tests/Feature/Attendance/AttendanceCorrectionTest.php`

- [ ] **Step 1: Add permissions**

In `RolePermissionSeeder::PERMISSIONS`:
```php
'attendance.approve' => ['Attendance', 'Approve or reject attendance correction requests'],
'attendance.correct' => ['Attendance', 'Request a manual correction to own attendance'],
```

In `ROLE_PERMS`:
- `manager`, `dept_head`, `hr_admin` → add `'attendance.approve'`
- `employee`, all roles → add `'attendance.correct'` (it's self-service)

- [ ] **Step 2: StoreCorrectionRequest**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class StoreCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('attendance.correct') ?? false;
    }

    public function rules(): array
    {
        return [
            'requested_event_at'  => ['required', 'date'],
            'requested_direction' => ['required', 'in:in,out'],
            'reason'              => ['required', 'string', 'min:8', 'max:500'],
            'attendance_record_id'=> ['nullable', 'integer', 'exists:attendance_records,id'],
        ];
    }
}
```

- [ ] **Step 3: ReviewCorrectionRequest**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class ReviewCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('attendance.approve') ?? false;
    }

    public function rules(): array
    {
        return [
            'decision_notes' => ['nullable', 'string', 'max:500', 'required_if:decision,reject'],
            'decision'       => ['required', 'in:approve,reject'],
        ];
    }
}
```

- [ ] **Step 4: AttendanceCorrectionResource**

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceCorrectionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                  => $this->id,
            'employee'            => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'employee_no' => $this->employee->employee_no,
                'position' => $this->employee->position,
            ]),
            'requester'           => $this->whenLoaded('requester', fn () => [
                'id' => $this->requester->id,
                'name' => $this->requester->name,
            ]),
            'reviewer'            => $this->whenLoaded('reviewer', fn () => $this->reviewer ? [
                'id' => $this->reviewer->id,
                'name' => $this->reviewer->name,
            ] : null),
            'requested_event_at'  => $this->requested_event_at?->toIso8601String(),
            'requested_direction' => $this->requested_direction,
            'reason'              => $this->reason,
            'status'              => $this->status?->value,
            'reviewed_at'         => $this->reviewed_at?->toIso8601String(),
            'decision_notes'      => $this->decision_notes,
            'created_at'          => $this->created_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 5: AttendancePolicy methods**

```php
public function approveCorrection(User $user): bool
{
    return $user->hasPermission('attendance.approve');
}

public function requestCorrection(User $user): bool
{
    return $user->hasPermission('attendance.correct');
}
```

- [ ] **Step 6: Controller actions**

```php
public function correctionsIndex(): \Inertia\Response
{
    $this->authorize('approveCorrection', \App\Models\AttendanceRecord::class);

    return Inertia::render('Attendance/Corrections', [
        'corrections' => AttendanceCorrectionResource::collection(
            AttendanceCorrection::with(['employee:id,employee_no,position', 'requester:id,name', 'reviewer:id,name'])
                ->latest()
                ->paginate(20)
        ),
    ]);
}

public function storeCorrection(StoreCorrectionRequest $request)
{
    $employee = $request->user()->employee
        ?? throw new \LogicException('Authenticated user has no employee record.');

    $this->attendance->requestCorrection(
        $employee,
        $request->user(),
        $request->validated('requested_event_at'),
        $request->validated('requested_direction'),
        $request->validated('reason'),
        $request->validated('attendance_record_id'),
    );

    return back()->with('success', 'Correction request submitted.');
}

public function reviewCorrection(ReviewCorrectionRequest $request, AttendanceCorrection $correction)
{
    if ($request->validated('decision') === 'approve') {
        $this->attendance->approveCorrection($correction, $request->user(), $request->validated('decision_notes'));
        return back()->with('success', 'Correction approved and applied.');
    }

    $this->attendance->rejectCorrection($correction, $request->user(), $request->validated('decision_notes') ?? 'Rejected');
    return back()->with('success', 'Correction rejected.');
}
```

Add imports for `StoreCorrectionRequest`, `ReviewCorrectionRequest`, `AttendanceCorrectionResource`, `AttendanceCorrection`.

- [ ] **Step 7: Routes**

In the `attendance.*` group:

```php
Route::prefix('corrections')->name('corrections.')->group(function () {
    Route::get('/',  [AttendanceController::class, 'correctionsIndex'])
        ->middleware('permission:attendance.approve')->name('index');
    Route::post('/', [AttendanceController::class, 'storeCorrection'])
        ->middleware('permission:attendance.correct')->name('store');
    Route::patch('/{correction}/review', [AttendanceController::class, 'reviewCorrection'])
        ->middleware('permission:attendance.approve')->name('review');
});
```

- [ ] **Step 8: Vue page `Corrections.vue`**

`resources/js/Pages/Attendance/Corrections.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    corrections: Object,
});

const reviewing = ref(null);
const reviewForm = useForm({ decision: 'approve', decision_notes: '' });

function openReview(c, decision) {
    reviewing.value = c;
    reviewForm.decision = decision;
    reviewForm.decision_notes = '';
}

function submitReview() {
    reviewForm.patch(route('attendance.corrections.review', reviewing.value.id), {
        onSuccess: () => { reviewing.value = null; },
    });
}

const statusTone = { pending: 'warning', approved: 'success', rejected: 'danger' };
</script>

<template>
<Head title="Attendance Corrections" />
<AuthenticatedLayout active-module="attendance">
    <div class="space-y-6 animate-reveal-up">
        <header>
            <h1 class="text-[1.6rem] font-black tracking-tight text-primary">Attendance Corrections</h1>
            <p class="text-sm text-on-surface-variant">Review and decide on employee-submitted attendance correction requests. Approved corrections are applied as manual attendance records with the reviewer attribution.</p>
        </header>

        <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
            <table class="w-full text-sm">
                <thead class="border-b border-outline-variant"><tr class="text-left text-[10px] font-black uppercase text-on-surface-variant tracking-widest">
                    <th class="p-4">Submitted</th><th>Employee</th><th>Requested</th><th>Direction</th><th>Reason</th><th>Status</th><th></th>
                </tr></thead>
                <tbody>
                    <tr v-for="c in props.corrections.data" :key="c.id" class="border-t border-outline-variant/40">
                        <td class="p-4 text-xs text-on-surface-variant">{{ new Date(c.created_at).toLocaleString() }}</td>
                        <td class="font-mono">{{ c.employee?.employee_no }} <span class="text-on-surface-variant">{{ c.employee?.position }}</span></td>
                        <td class="text-xs">{{ new Date(c.requested_event_at).toLocaleString() }}</td>
                        <td><span class="font-mono uppercase text-xs">{{ c.requested_direction }}</span></td>
                        <td class="max-w-xs truncate" :title="c.reason">{{ c.reason }}</td>
                        <td><StatusBadge :label="c.status" :tone="statusTone[c.status]" /></td>
                        <td>
                            <div v-if="c.status === 'pending'" class="flex gap-2">
                                <button @click="openReview(c, 'approve')" class="rounded-lg bg-emerald-50 text-emerald-700 px-3 py-1 text-xs font-bold hover:bg-emerald-100">Approve</button>
                                <button @click="openReview(c, 'reject')" class="rounded-lg bg-rose-50 text-rose-700 px-3 py-1 text-xs font-bold hover:bg-rose-100">Reject</button>
                            </div>
                            <span v-else class="text-xs text-on-surface-variant">{{ c.reviewer?.name }} · {{ new Date(c.reviewed_at).toLocaleDateString() }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!props.corrections.data?.length" message="No correction requests yet." class="py-12" />
            <Pagination v-if="props.corrections.meta?.last_page > 1" :links="props.corrections.meta.links" />
        </section>
    </div>

    <div v-if="reviewing" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
        <div class="bg-surface-container-lowest rounded-2xl p-6 max-w-md w-full">
            <h3 class="text-lg font-black text-primary mb-2">{{ reviewForm.decision === 'approve' ? 'Approve' : 'Reject' }} Correction</h3>
            <p class="text-sm text-on-surface-variant mb-4">{{ reviewing.employee?.employee_no }} — {{ reviewing.requested_direction }} @ {{ new Date(reviewing.requested_event_at).toLocaleString() }}</p>
            <form @submit.prevent="submitReview" class="space-y-3">
                <div>
                    <label class="text-[11px] font-bold text-on-surface-variant">Decision notes{{ reviewForm.decision === 'reject' ? ' (required)' : '' }}</label>
                    <textarea v-model="reviewForm.decision_notes" :required="reviewForm.decision === 'reject'" rows="3" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 text-sm" />
                </div>
                <div class="flex gap-2 justify-end">
                    <button type="button" @click="reviewing = null" class="rounded-xl border border-outline-variant px-4 py-2 text-sm font-bold text-on-surface-variant">Cancel</button>
                    <button type="submit" :disabled="reviewForm.processing" class="rounded-xl bg-gradient-to-br from-primary to-secondary px-4 py-2 text-sm font-bold text-white">{{ reviewForm.decision === 'approve' ? 'Approve' : 'Reject' }}</button>
                </div>
            </form>
        </div>
    </div>
</AuthenticatedLayout>
</template>
```

- [ ] **Step 9: Tests**

`tests/Feature/Attendance/AttendanceCorrectionTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\CorrectionStatus;
use App\Models\AttendanceCorrection;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\AttendanceService;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);
});

it('lets an employee submit a correction request', function () {
    $emp = Employee::factory()->create();
    $emp->user->syncRoles(['employee']);

    actingAs($emp->user)->post('/attendance/corrections', [
        'requested_event_at'  => now()->subDay()->toIso8601String(),
        'requested_direction' => 'in',
        'reason'              => 'Biometric reader was down yesterday morning.',
    ])->assertRedirect();

    expect(AttendanceCorrection::count())->toBe(1);
    expect(AttendanceCorrection::first()->status)->toBe(CorrectionStatus::Pending);
});

it('lets a manager approve a correction and applies it as a manual record', function () {
    $manager = User::factory()->create();
    $manager->syncRoles(['manager']);
    $emp = Employee::factory()->create();
    $correction = AttendanceCorrection::create([
        'employee_id'          => $emp->id,
        'requester_id'         => $emp->user_id,
        'requested_event_at'   => now()->subDay(),
        'requested_direction'  => 'in',
        'reason'               => 'Network down.',
        'status'               => CorrectionStatus::Pending,
    ]);

    actingAs($manager)->patch("/attendance/corrections/{$correction->id}/review", [
        'decision'       => 'approve',
        'decision_notes' => 'Verified with IT.',
    ])->assertRedirect();

    expect($correction->fresh()->status)->toBe(CorrectionStatus::Approved);
    expect(\App\Models\AttendanceRecord::where('employee_id', $emp->id)->count())->toBe(1);
});

it('forbids an employee from approving (RBAC deny)', function () {
    $other = Employee::factory()->create();
    $correction = AttendanceCorrection::create([
        'employee_id'          => $other->id,
        'requester_id'         => $other->user_id,
        'requested_event_at'   => now()->subDay(),
        'requested_direction'  => 'in',
        'reason'               => 'Reason for testing.',
        'status'               => CorrectionStatus::Pending,
    ]);

    $rando = User::factory()->create();
    $rando->syncRoles(['employee']);

    actingAs($rando)->patch("/attendance/corrections/{$correction->id}/review", [
        'decision' => 'approve',
    ])->assertForbidden();
});

it('rejects approving an already-decided correction', function () {
    $manager = User::factory()->create();
    $manager->syncRoles(['hr_admin']);
    $emp = Employee::factory()->create();
    $correction = AttendanceCorrection::create([
        'employee_id'          => $emp->id,
        'requester_id'         => $emp->user_id,
        'requested_event_at'   => now()->subDay(),
        'requested_direction'  => 'in',
        'reason'               => 'Reason.',
        'status'               => CorrectionStatus::Approved,
        'reviewer_id'          => $manager->id,
        'reviewed_at'          => now()->subHour(),
    ]);

    expect(fn () => app(AttendanceService::class)->approveCorrection($correction, $manager))
        ->toThrow(\DomainException::class);
});
```

- [ ] **Step 10: Build and commit**

```powershell
npm run build
php artisan migrate:status
git add app/Http/Requests/Attendance/StoreCorrectionRequest.php app/Http/Requests/Attendance/ReviewCorrectionRequest.php app/Http/Resources/AttendanceCorrectionResource.php app/Http/Controllers/AttendanceController.php app/Policies/AttendancePolicy.php routes/web.php database/seeders/RolePermissionSeeder.php resources/js/Pages/Attendance/Corrections.vue tests/Feature/Attendance/AttendanceCorrectionTest.php
git commit -m "feat(attendance): correction request/review controller + Corrections.vue + tests"
```

---

## TASK 10: Daily auto-mark-absent scheduled command

**Files:**
- Create: `app/Console/Commands/MarkAbsentEmployees.php`
- Modify: `routes/console.php`
- Create: `tests/Feature/Attendance/MarkAbsentCommandTest.php`

- [ ] **Step 1: Create command**

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\Attendance\AttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class MarkAbsentEmployees extends Command
{
    protected $signature = 'attendance:mark-absent {--date= : ISO date; defaults to today}';
    protected $description = 'Materializes attendance_summaries rows for any active employee without a record on the given date (or today). Honors approved leave.';

    public function handle(AttendanceService $attendance): int
    {
        $date = $this->option('date')
            ? CarbonImmutable::parse($this->option('date'))
            : CarbonImmutable::today();

        $count = 0;
        Employee::active()->each(function (Employee $emp) use ($attendance, $date, &$count) {
            $attendance->recomputeDailySummary($emp, $date->toDateString());
            $count++;
        });

        $this->info("Marked attendance for {$count} employees on {$date->toDateString()}");
        return self::SUCCESS;
    }
}
```

This delegates absence detection to the existing `recomputeDailySummary()`, which already handles the "no records + no approved leave + working day = absent" rule.

- [ ] **Step 2: Schedule it in routes/console.php**

Append:

```php
Schedule::command('attendance:mark-absent')->dailyAt('23:55')->withoutOverlapping();
```

- [ ] **Step 3: Test**

`tests/Feature/Attendance/MarkAbsentCommandTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Employee;
use Illuminate\Support\Carbon;

use function Pest\Laravel\artisan;

it('creates a summary row for every active employee with no records on the given date', function () {
    Carbon::setTestNow('2026-06-15 23:59'); // Monday
    Employee::factory()->count(3)->create();

    artisan('attendance:mark-absent --date=2026-06-15')->assertSuccessful();

    expect(\App\Models\AttendanceSummary::where('summary_date', '2026-06-15')->count())->toBe(3);

    Carbon::setTestNow();
});
```

- [ ] **Step 4: Commit**

```powershell
git add app/Console/Commands/MarkAbsentEmployees.php routes/console.php tests/Feature/Attendance/MarkAbsentCommandTest.php
git commit -m "feat(attendance): daily MarkAbsentEmployees command + 23:55 schedule"
```

---

## TASK 11: Overtime → payroll supplement

**Files:**
- Modify: `app/Services/Payroll/PayrollService.php`
- Create: `tests/Feature/Payroll/PayrollOvertimeSupplementTest.php`

- [ ] **Step 1: Read existing PayrollService::calculateLine**

```powershell
Get-Content app/Services/Payroll/PayrollService.php
```

Find the method that computes per-employee gross pay. It already gates on zero-attendance. Now it should also ADD overtime as a salary supplement when present.

- [ ] **Step 2: Implement OT supplement**

In `calculateLine()` (or whichever method builds the per-employee payroll line), after the base pay is computed:

```php
$overtimeHours = (float) \App\Models\AttendanceSummary::query()
    ->where('employee_id', $employee->id)
    ->whereBetween('summary_date', [$run->period_start, $run->period_end])
    ->sum('overtime_hours');

if ($overtimeHours > 0) {
    $hourlyRate = ((float) $employee->basic_salary) / 173.33; // 173.33 = standard monthly working hours
    $overtimePay = $overtimeHours * $hourlyRate; // overtime_hours already include premium multipliers
    $line['overtime_hours'] = $overtimeHours;
    $line['overtime_pay']   = round($overtimePay, 2);
    $line['gross_pay']      = round((float) $line['gross_pay'] + $overtimePay, 2);
}
```

If the existing payroll line is an Eloquent model instead of an array, adapt the property assignments. If `overtime_hours` and `overtime_pay` columns don't exist on the `payroll_line` table, add a small migration:

`database/migrations/2026_05_27_000003_add_overtime_columns_to_payroll_lines.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payroll_lines', 'overtime_hours')) {
            Schema::table('payroll_lines', function (Blueprint $table) {
                $table->decimal('overtime_hours', 6, 2)->default(0)->after('gross_pay');
                $table->decimal('overtime_pay', 12, 2)->default(0)->after('overtime_hours');
            });
        }
    }

    public function down(): void
    {
        Schema::table('payroll_lines', function (Blueprint $table) {
            $table->dropColumn(['overtime_hours', 'overtime_pay']);
        });
    }
};
```

(Inspect the existing `payroll_lines` table — if the column name is different e.g. `payroll_items` or `payroll_records`, adjust the migration.)

- [ ] **Step 3: Test**

```php
<?php

declare(strict_types=1);

use App\Models\AttendanceSummary;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Services\Payroll\PayrollService;

it('adds overtime pay to gross when AttendanceSummary has overtime_hours', function () {
    $emp = Employee::factory()->create(['basic_salary' => 1733.30]); // $10/hour at 173.33h
    AttendanceSummary::create([
        'employee_id'    => $emp->id,
        'summary_date'   => '2026-05-15',
        'status'         => 'present',
        'hours_worked'   => 10,
        'overtime_hours' => 3, // already 1.5x premium-applied
    ]);

    $run = PayrollRun::factory()->create([
        'period_start' => '2026-05-01',
        'period_end'   => '2026-05-31',
    ]);

    app(PayrollService::class)->calculate($run);

    $line = $run->lines()->where('employee_id', $emp->id)->first();
    expect((float) $line->overtime_hours)->toBe(3.0);
    expect((float) $line->overtime_pay)->toBe(30.0); // 3h × $10
    expect((float) $line->gross_pay)->toBeGreaterThan(1733.30);
});
```

- [ ] **Step 4: Run migration + commit**

```powershell
php artisan migrate
npm run build
git add app/Services/Payroll/PayrollService.php database/migrations/2026_05_27_000003_add_overtime_columns_to_payroll_lines.php tests/Feature/Payroll/PayrollOvertimeSupplementTest.php
git commit -m "feat(payroll): add overtime supplement from AttendanceSummary.overtime_hours"
```

---

## TASK 12: Surface new pages in Attendance/Index.vue

**Files:**
- Modify: `resources/js/Pages/Attendance/Index.vue`

- [ ] **Step 1: Add header tabs/links**

At the top of the `<template>` of `Attendance/Index.vue`, just under the page title, add a navigation strip:

```vue
<div class="flex gap-2 mb-4">
    <Link :href="route('attendance.index')" class="rounded-xl px-3 py-1.5 text-xs font-bold border border-primary text-primary">Daily</Link>
    <Link :href="route('attendance.corrections.index')" class="rounded-xl px-3 py-1.5 text-xs font-bold border border-outline-variant text-on-surface-variant hover:border-primary/40 hover:text-primary" v-if="$page.props.auth.permissions?.includes('attendance.approve')">Corrections</Link>
    <Link :href="route('attendance.shifts.index')" class="rounded-xl px-3 py-1.5 text-xs font-bold border border-outline-variant text-on-surface-variant hover:border-primary/40 hover:text-primary" v-if="$page.props.auth.permissions?.includes('attendance.shift_manage')">Shifts</Link>
</div>
```

Make sure `Link` is imported from `@inertiajs/vue3`.

- [ ] **Step 2: Build and commit**

```powershell
npm run build
git add resources/js/Pages/Attendance/Index.vue
git commit -m "feat(attendance): link Index page to Corrections and Shifts sub-pages"
```

---

## TASK 13: Add correction-request UI on MyAttendance.vue

**Files:**
- Modify: `resources/js/Pages/Attendance/MyAttendance.vue`

- [ ] **Step 1: Add "Request Correction" affordance**

Add a slide-panel triggered by a "Request Correction" button on the page. Form fields: `requested_event_at` (datetime-local), `requested_direction` (in/out select), `reason` (textarea, min 8 chars). Submits to `route('attendance.corrections.store')`.

Reuse `SlidePanel` component and `useForm` pattern from Shifts.vue.

```vue
<button @click="showCorrection = true" class="rounded-xl border border-outline-variant px-4 py-2 text-xs font-bold text-primary hover:bg-surface-container-low">Request Correction</button>

<SlidePanel :show="showCorrection" @close="showCorrection = false" title="Request Attendance Correction">
    <form @submit.prevent="submitCorrection" class="space-y-3 p-4">
        <div><label class="text-[11px] font-bold text-on-surface-variant">When (event time)</label><input v-model="correctionForm.requested_event_at" type="datetime-local" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2" /></div>
        <div><label class="text-[11px] font-bold text-on-surface-variant">Direction</label><select v-model="correctionForm.requested_direction" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2"><option value="in">Clock-in</option><option value="out">Clock-out</option></select></div>
        <div><label class="text-[11px] font-bold text-on-surface-variant">Reason (min 8 chars)</label><textarea v-model="correctionForm.reason" required minlength="8" maxlength="500" rows="3" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 text-sm" /></div>
        <button type="submit" :disabled="correctionForm.processing" class="w-full rounded-xl bg-gradient-to-br from-primary to-secondary px-4 py-2 text-sm font-bold text-white">Submit Request</button>
    </form>
</SlidePanel>
```

Add the imports, refs, and submit handler:
```js
import SlidePanel from '@/Components/SlidePanel.vue';
const showCorrection = ref(false);
const correctionForm = useForm({ requested_event_at: '', requested_direction: 'in', reason: '' });
function submitCorrection() {
    correctionForm.post(route('attendance.corrections.store'), {
        onSuccess: () => { showCorrection.value = false; correctionForm.reset(); },
    });
}
```

- [ ] **Step 2: Build and commit**

```powershell
npm run build
git add resources/js/Pages/Attendance/MyAttendance.vue
git commit -m "feat(attendance): MyAttendance page gets 'Request Correction' slide-panel"
```

---

## TASK 14: Update PROJECT_STATE.md

**Files:**
- Modify: `docs/PROJECT_STATE.md`

- [ ] **Step 1: Update the headline section**

Add a new bullet to "Headline" indicating P2 + gap-closing complete: Attendance now has shifts, geofence enforcement, correction workflow, daily auto-mark, OT → payroll supplement.

- [ ] **Step 2: Add `Shifts` and `Corrections` rows to the Vue pages table in §3**

- [ ] **Step 3: Add the new permissions to §5 risks/gaps (mark `pending_payments`/`applicants` event-emission as still open since not in P2 scope)**

- [ ] **Step 4: Commit**

```powershell
git add docs/PROJECT_STATE.md
git commit -m "docs: PROJECT_STATE — P2 Attendance gap-closing complete"
```

---

## Manual smoke checklist

Before declaring P2 complete:

1. `php artisan migrate` runs all new migrations successfully.
2. `php artisan db:seed --class=RolePermissionSeeder` adds the 3 new permissions.
3. `/attendance/shifts` loads — create a shift, assign it to an employee.
4. `/attendance/me` shows clock-in/out buttons + "Request Correction".
5. Employee submits correction; manager `/attendance/corrections` shows it pending; approve → an `attendance_records` row appears with `source=manual`.
6. `/attendance` daily summary shows OT hours for an employee with > 8h worked.
7. `php artisan attendance:mark-absent --date=$(today)` creates summaries for employees with no events.
8. `php artisan schedule:list` shows the new `attendance:mark-absent` daily.
9. Push to origin — CI on PHP 8.4 runs all 6 new test files green.

---

## Self-review checklist

- ✅ Spec §8 P2 enterprise-deeper requirements all covered (shifts, geofence, correction workflow, mark-absent cron, OT supplement)
- ✅ Each task ends with a commit
- ✅ Every test has actual test code (no placeholders)
- ✅ Method names, table names, column names consistent across tasks
- ✅ RBAC permissions all declared in RolePermissionSeeder before being middleware-checked
- ✅ Events registered in AppServiceProvider before being dispatched
