<?php

declare(strict_types=1);

use App\Enums\AmortizationMethod;
use App\Enums\DocumentStatus;
use App\Enums\EmployeeStatus;
use App\Enums\ExitType;
use App\Enums\LoanProductType;
use App\Models\Department;
use App\Models\Document;
use App\Models\Employee;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\Finance\SequenceService;
use App\Services\Hr\UserIdentifierAllocator;
use App\Services\Loans\LoanService;
use App\Services\Offboarding\OffboardingService;
use Database\Seeders\GhanaStatutoryReferenceSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Tier-3 sequence-ref regressions
|--------------------------------------------------------------------------
|
| Audit V2 — Tier 3 replaced four race-prone count()+1 / MAX()+1 reference
| generators with App\Services\Finance\SequenceService::next(). Each test
| below exercises one of those sites end-to-end and asserts that two
| successive calls produce sequential, zero-padded references — which is
| exactly what the locked sequence guarantees and what count()+1 did not
| under concurrency.
|
*/

it('OffboardingService::initiate produces sequential OFF-{year}-NNNNN references', function () {
    $this->seed(GhanaStatutoryReferenceSeeder::class);
    $year = now()->year;

    $dept = Department::factory()->create();
    $emp1 = Employee::factory()->create([
        'department_id' => $dept->id,
        'status'        => EmployeeStatus::Active->value,
        'hire_date'     => '2020-01-15',
        'salary'        => 6_000,
    ]);
    $emp2 = Employee::factory()->create([
        'department_id' => $dept->id,
        'status'        => EmployeeStatus::Active->value,
        'hire_date'     => '2019-03-01',
        'salary'        => 5_000,
    ]);
    $initiator = User::factory()->create(['role' => 'hr_admin']);

    $svc = app(OffboardingService::class);

    $a = $svc->initiate($emp1, ExitType::Resignation, '2026-06-01', '2026-06-30', $initiator);
    $b = $svc->initiate($emp2, ExitType::Resignation, '2026-06-01', '2026-06-30', $initiator);

    expect($a->reference)->toBe(sprintf('OFF-%04d-%05d', $year, 1));
    expect($b->reference)->toBe(sprintf('OFF-%04d-%05d', $year, 2));
});

it('LoanService::apply produces sequential LOAN-{year}-NNNNN references', function () {
    $year = now()->year;

    $product = LoanProduct::create([
        'code'                 => 'TST-SEQ',
        'name'                 => 'Sequence Test',
        'type'                 => LoanProductType::Personal->value,
        'min_amount'           => 1_000,
        'max_amount'           => 50_000,
        'min_term_months'      => 3,
        'max_term_months'      => 36,
        'annual_interest_rate' => 0.12,
        'amortization_method'  => AmortizationMethod::ReducingBalance->value,
        'is_active'            => true,
        'effective_from'       => '2026-01-01',
        'approvals_required'   => 2,
    ]);

    $applicant = User::factory()->create(['role' => 'employee']);
    $employee  = Employee::factory()->create(['user_id' => $applicant->id]);

    $svc = app(LoanService::class);

    $a = $svc->apply($employee, $product, 5_000, 12, 'first', $applicant);
    $b = $svc->apply($employee, $product, 7_000, 12, 'second', $applicant);

    expect($a->reference)->toBe(sprintf('LOAN-%04d-%05d', $year, 1));
    expect($b->reference)->toBe(sprintf('LOAN-%04d-%05d', $year, 2));
});

it('UserIdentifierAllocator::resolveEmployeeNo produces sequential CIHRM-NNNN values', function () {
    // Passing null forces the auto-generate path; silent collision recovery
    // is exercised via HTTP in UserManagementTest.
    $allocator = app(UserIdentifierAllocator::class);

    expect($allocator->resolveEmployeeNo(null))->toBe('CIHRM-0001');
    expect($allocator->resolveEmployeeNo(null))->toBe('CIHRM-0002');

    // And confirm the underlying key is unscoped by year (i.e. a 2027 call
    // still increments the same counter, not a fresh per-year one).
    expect(app(SequenceService::class)->next('employee_no'))->toBe(3);
});

it('DocumentService::upload produces sequential CIHRMS/DOC/{year}/NNNN refs', function () {
    Storage::fake('local');
    $year = now()->year;

    $owner = User::factory()->create(['role' => 'employee']);
    $svc   = app(DocumentService::class);

    $a = $svc->upload(
        UploadedFile::fake()->create('first.pdf', 50, 'application/pdf'),
        ['title' => 'First'],
        $owner,
    );
    $b = $svc->upload(
        UploadedFile::fake()->create('second.pdf', 50, 'application/pdf'),
        ['title' => 'Second'],
        $owner,
    );

    expect($a->ref_no)->toBe(sprintf('CIHRMS/DOC/%d/%04d', $year, 1));
    expect($b->ref_no)->toBe(sprintf('CIHRMS/DOC/%d/%04d', $year, 2));

    // Sanity check: both rows persisted with their generated refs.
    expect(Document::where('ref_no', $a->ref_no)->exists())->toBeTrue();
    expect(Document::where('ref_no', $b->ref_no)->exists())->toBeTrue();
});
