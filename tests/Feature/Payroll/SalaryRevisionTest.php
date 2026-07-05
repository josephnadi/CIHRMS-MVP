<?php

declare(strict_types=1);

use App\Models\Grade;
use App\Models\GradeStep;
use App\Models\SalaryRevision;
use App\Models\User;
use App\Services\Payroll\SalaryRevisionService;

beforeEach(function () {
    $this->svc = app(SalaryRevisionService::class);
    $this->actor = User::factory()->create(['role' => 'hr_admin']);

    $this->gradeA = Grade::create(['code' => 'GS-A', 'name' => 'A', 'level' => 5, 'min_step' => 1, 'max_step' => 3]);
    GradeStep::create(['grade_id' => $this->gradeA->id, 'step' => 1, 'base_salary' => 10_000, 'currency' => 'GHS', 'effective_from' => '2025-01-01']);
    GradeStep::create(['grade_id' => $this->gradeA->id, 'step' => 2, 'base_salary' => 12_000, 'currency' => 'GHS', 'effective_from' => '2025-01-01']);

    $this->gradeB = Grade::create(['code' => 'GS-B', 'name' => 'B', 'level' => 6, 'min_step' => 1, 'max_step' => 3]);
    GradeStep::create(['grade_id' => $this->gradeB->id, 'step' => 1, 'base_salary' => 20_000, 'currency' => 'GHS', 'effective_from' => '2025-01-01']);
});

it('applies an institute-wide 10% revision as new effective-dated rates', function () {
    $rev = $this->svc->apply(10.0, '2026-04-01', 'institute', [], $this->actor);

    expect($rev->reference)->toStartWith('SR-2026-')
        ->and($rev->affected_count)->toBe(3);

    // Old rows are closed the day before; new rows carry the +10% figure.
    $a1old = GradeStep::where('grade_id', $this->gradeA->id)->where('step', 1)->whereNotNull('effective_to')->first();
    $a1new = GradeStep::where('grade_id', $this->gradeA->id)->where('step', 1)->whereNull('effective_to')->first();
    expect((float) $a1old->base_salary)->toBe(10_000.0)
        ->and($a1old->effective_to->toDateString())->toBe('2026-03-31')
        ->and((float) $a1new->base_salary)->toBe(11_000.0)
        ->and($a1new->effective_from->toDateString())->toBe('2026-04-01');

    // baseSalaryFor reads the right rate for each date (history preserved).
    expect($this->gradeA->fresh()->baseSalaryFor(1, '2026-03-15'))->toBe(10_000.0)   // before revision
        ->and($this->gradeA->fresh()->baseSalaryFor(1, '2026-04-15'))->toBe(11_000.0) // after
        ->and($this->gradeA->fresh()->baseSalaryFor(2, '2026-04-15'))->toBe(13_200.0); // 12,000 +10%
});

it('honours a per-grade override', function () {
    // Institute 10%, but grade B gets 15%.
    $this->svc->apply(10.0, '2026-04-01', 'grade', [$this->gradeB->id => 15.0], $this->actor);

    expect($this->gradeA->fresh()->baseSalaryFor(1, '2026-04-15'))->toBe(11_000.0)  // 10%
        ->and($this->gradeB->fresh()->baseSalaryFor(1, '2026-04-15'))->toBe(23_000.0); // 15%
});

it('previews without persisting', function () {
    $rows = $this->svc->preview(10.0, '2026-04-01');

    expect($rows)->toHaveCount(3);
    $a1 = collect($rows)->firstWhere(fn ($r) => $r['grade_code'] === 'GS-A' && $r['step'] === 1);
    expect($a1['old'])->toBe(10_000.0)->and($a1['new'])->toBe(11_000.0);

    // Nothing was written.
    expect(SalaryRevision::count())->toBe(0)
        ->and(GradeStep::whereNotNull('effective_to')->count())->toBe(0);
});

it('applies a revision over HTTP (payroll.run) and lists it', function () {
    $officer = User::factory()->create(['role' => 'finance_officer', 'permissions' => ['payroll.run']]);

    $this->actingAs($officer)
        ->post(route('salary-revisions.store'), [
            'percentage' => 10, 'effective_from' => '2026-04-01', 'scope' => 'institute',
        ])
        ->assertRedirect();

    expect(SalaryRevision::where('percentage', 10)->exists())->toBeTrue()
        ->and($this->gradeA->fresh()->baseSalaryFor(1, '2026-04-15'))->toBe(11_000.0);

    $this->actingAs($officer)->get(route('salary-revisions.index'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Payroll/Revisions/Index')->has('revisions', 1)->has('steps'));
});

it('refuses a second revision on the same effective date', function () {
    $this->svc->apply(10.0, '2026-04-01', 'institute', [], $this->actor);

    expect(fn () => $this->svc->apply(5.0, '2026-04-01', 'institute', [], $this->actor))
        ->toThrow(DomainException::class);
});
