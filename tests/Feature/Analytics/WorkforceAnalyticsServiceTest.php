<?php

declare(strict_types=1);

use App\Models\Department;
use App\Models\Employee;
use App\Models\OffboardingCase;
use App\Services\WorkforceAnalyticsService;
use Carbon\CarbonImmutable;

function makeWindow(): array
{
    $to   = CarbonImmutable::create(2026, 7, 9);
    $from = $to->subDays(364); // 365-day inclusive window
    return [$from, $to];
}

it('computes headcount, hires, leavers, turnover, tenure and delta', function () {
    [$from, $to] = makeWindow();
    $dept = Department::factory()->create();

    // 10 active employees; 2 hired exactly 2y ago, rest hired 4y ago → avg tenure 3.0
    Employee::factory()->count(2)->create([
        'department_id' => $dept->id,
        'status'        => 'active',
        'hire_date'     => $to->subYears(2)->toDateString(),
    ]);
    Employee::factory()->count(8)->create([
        'department_id' => $dept->id,
        'status'        => 'active',
        'hire_date'     => $to->subYears(4)->toDateString(),
    ]);

    // 3 new hires WITHIN the window
    Employee::factory()->count(3)->create([
        'department_id' => $dept->id,
        'status'        => 'active',
        'hire_date'     => $from->addMonths(2)->toDateString(),
    ]);

    // 1 hire OUTSIDE the window (before) — must be excluded from new_hires
    Employee::factory()->create([
        'department_id' => $dept->id,
        'status'        => 'active',
        'hire_date'     => $from->subDays(1)->toDateString(),
    ]);

    // 2 leavers within the window (offboarding cases)
    // hire_date pinned well before the window: the factory's default
    // hire_date is a random date in the last 5 years, which can otherwise
    // land inside [$from, $to] and non-deterministically inflate
    // `new_hires` (these employees aren't meant to exercise that KPI).
    $leaver1 = Employee::factory()->create(['department_id' => $dept->id, 'status' => 'terminated', 'hire_date' => $from->subYears(3)->toDateString()]);
    $leaver2 = Employee::factory()->create(['department_id' => $dept->id, 'status' => 'terminated', 'hire_date' => $from->subYears(3)->toDateString()]);
    OffboardingCase::factory()->create([
        'employee_id'                => $leaver1->id,
        'effective_termination_date' => $from->addMonths(3)->toDateString(),
    ]);
    OffboardingCase::factory()->create([
        'employee_id'                => $leaver2->id,
        'effective_termination_date' => $to->toDateString(), // boundary: on `to` counts
    ]);

    // 1 offboarding case OUTSIDE the window — excluded
    $leaver3 = Employee::factory()->create(['department_id' => $dept->id, 'status' => 'terminated', 'hire_date' => $from->subYears(3)->toDateString()]);
    OffboardingCase::factory()->create([
        'employee_id'                => $leaver3->id,
        'effective_termination_date' => $to->addDays(1)->toDateString(),
    ]);

    $k = app(WorkforceAnalyticsService::class)->metrics($dept->id, $from, $to)['kpis'];

    // active headcount now = 2 + 8 + 3 + 1 = 14 (terminated excluded)
    expect($k['headcount'])->toBe(14)
        ->and($k['new_hires'])->toBe(3)
        ->and($k['leavers'])->toBe(2)
        // turnover = leavers / avgHeadcount * (365/days) * 100 = 2/14 * 1 * 100 = 14.3
        ->and($k['turnover_rate'])->toBe(14.3)
        ->and($k['headcount_delta'])->toBe(1) // 3 hires - 2 leavers
        // avg tenure over 14 active: (2*2 + 8*4 + 3*(~0.83) + 1*(~1.0)) / 14 ... asserted loosely below
        ->and($k['avg_tenure'])->toBeGreaterThan(0.0);
});

it('never divides by zero when there are no employees', function () {
    [$from, $to] = makeWindow();

    $k = app(WorkforceAnalyticsService::class)->metrics(null, $from, $to)['kpis'];

    expect($k['headcount'])->toBe(0)
        ->and($k['turnover_rate'])->toBe(0.0)
        ->and($k['avg_tenure'])->toBe(0.0);
});

it('buckets tenure bands from hire_date relative to the window end', function () {
    [$from, $to] = makeWindow();
    $dept = Department::factory()->create();

    Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active', 'hire_date' => $to->subMonths(6)->toDateString()]);  // <1y
    Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active', 'hire_date' => $to->subYears(2)->toDateString()]);   // 1-3y
    Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active', 'hire_date' => $to->subYears(4)->toDateString()]);   // 3-5y
    Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active', 'hire_date' => $to->subYears(9)->toDateString()]);   // 5y+

    $bands = collect(app(WorkforceAnalyticsService::class)->metrics($dept->id, $from, $to)['series']['tenure_bands'])
        ->pluck('value', 'label');

    expect($bands['<1y'])->toBe(1)
        ->and($bands['1-3y'])->toBe(1)
        ->and($bands['3-5y'])->toBe(1)
        ->and($bands['5y+'])->toBe(1);
});

it('buckets gender with a null-safe Unspecified slice', function () {
    [$from, $to] = makeWindow();
    $dept = Department::factory()->create();

    Employee::factory()->count(2)->create(['department_id' => $dept->id, 'status' => 'active', 'gender' => 'female']);
    Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active', 'gender' => 'male']);
    Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active', 'gender' => null]);

    $g = collect(app(WorkforceAnalyticsService::class)->metrics($dept->id, $from, $to)['series']['gender'])
        ->pluck('value', 'label');

    expect($g['Female'])->toBe(2)
        ->and($g['Male'])->toBe(1)
        ->and($g['Unspecified'])->toBe(1);
});

it('sums cost-to-company per department', function () {
    [$from, $to] = makeWindow();
    $dept = Department::factory()->create(['name' => 'Engineering']);

    Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active', 'salary' => 1000]);
    Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active', 'salary' => 2500]);

    $cost = collect(app(WorkforceAnalyticsService::class)->metrics($dept->id, $from, $to)['series']['cost_by_department'])
        ->firstWhere('label', 'Engineering');

    expect((float) $cost['value'])->toBe(3500.0);
});

it('flags turnover_caveat when a terminated employee has no offboarding case in range', function () {
    [$from, $to] = makeWindow();
    $dept = Department::factory()->create();

    Employee::factory()->create(['department_id' => $dept->id, 'status' => 'terminated']); // no offboarding case

    $meta = app(WorkforceAnalyticsService::class)->metrics($dept->id, $from, $to)['meta'];

    expect($meta['turnover_caveat'])->toBeTrue();
});
