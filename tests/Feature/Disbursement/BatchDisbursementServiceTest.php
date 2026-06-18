<?php

use App\Enums\DisbursementChannel;
use App\Enums\DisbursementStatus;
use App\Models\Department;
use App\Models\Disbursement;
use App\Models\Employee;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Services\Disbursement\BatchDisbursementService;
use App\Services\Disbursement\Contracts\DisbursementProvider;
use App\Services\Disbursement\DisbursementResult;
use App\Services\Finance\PostingService;

beforeEach(function () {
    $this->dept = Department::factory()->create();

    $this->employee = Employee::factory()->create([
        'department_id'        => $this->dept->id,
        'disbursement_channel' => DisbursementChannel::MtnMomo->value,
        'mobile_money_number'  => '0244000001',
    ]);

    $this->run = PayrollRun::create([
        'reference'    => 'PR-2026-05-ORG',
        'period_year'  => 2026, 'period_month' => 5,
        'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
        'status'       => 'calculated',
    ]);

    PayrollLine::create([
        'payroll_run_id' => $this->run->id,
        'employee_id'    => $this->employee->id,
        'basic' => 5000, 'allowance_total' => 0, 'gross' => 5000,
        'ssnit_base' => 5000, 'ssnit_tier1_employee' => 275, 'ssnit_tier1_employer' => 650,
        'nhia_split' => 125, 'tier2_employer' => 250, 'tier3_employee' => 0,
        'paye' => 600, 'voluntary_deductions' => 0,
        'net' => 4125,
        'status' => 'calculated',
    ]);
});

it('materialises one Disbursement per payroll line with E-Levy applied on MoMo', function () {
    $svc = app(BatchDisbursementService::class);
    $created = $svc->materialise($this->run);

    expect($created)->toBe(1);

    $d = Disbursement::where('payroll_run_id', $this->run->id)->first();
    expect($d)->not->toBeNull();
    expect($d->channel)->toBe(DisbursementChannel::MtnMomo);
    expect((float) $d->gross_amount)->toBe(4125.0);
    // E-Levy fallback 1.5% on MoMo
    expect((float) $d->e_levy)->toEqualWithDelta(61.88, 0.01);
    expect((float) $d->net_to_recipient)->toEqualWithDelta(4063.12, 0.01);
    expect($d->beneficiary_account)->toBe('0244000001');
});

it('skips E-Levy for GhIPSS bank-transfer channel', function () {
    $this->employee->update(['disbursement_channel' => DisbursementChannel::GhipssAch->value, 'bank_account' => '1234567890']);

    $svc = app(BatchDisbursementService::class);
    $svc->materialise($this->run);

    $d = Disbursement::where('payroll_run_id', $this->run->id)->first();
    expect($d->channel)->toBe(DisbursementChannel::GhipssAch);
    expect((float) $d->e_levy)->toBe(0.0);
    expect((float) $d->net_to_recipient)->toBe(4125.0);
});

it('is idempotent — running materialise twice does not duplicate rows', function () {
    $svc = app(BatchDisbursementService::class);
    $svc->materialise($this->run);
    $svc->materialise($this->run);   // second call

    expect(Disbursement::where('payroll_run_id', $this->run->id)->count())->toBe(1);
});

it('dispatch() routes through the per-channel provider and records the result', function () {
    // Stub provider that always succeeds
    $stub = new class implements DisbursementProvider {
        public function channel(): string { return DisbursementChannel::MtnMomo->value; }
        public function send(Disbursement $d): DisbursementResult {
            return DisbursementResult::sent('FAKE-REF-001', ['stub' => true]);
        }
        public function refreshStatus(Disbursement $d): DisbursementResult {
            return DisbursementResult::settled('FAKE-REF-001');
        }
    };

    $svc = new BatchDisbursementService([DisbursementChannel::MtnMomo->value => $stub], app(PostingService::class));
    $svc->materialise($this->run);

    $result = $svc->dispatch($this->run);

    expect($result['sent'])->toBe(1);
    expect($result['failed'])->toBe(0);

    $d = Disbursement::where('payroll_run_id', $this->run->id)->first();
    expect($d->status)->toBe(DisbursementStatus::Sent);
    expect($d->provider_reference)->toBe('FAKE-REF-001');
});

it('dispatch() skips channels without a registered provider (e.g. cash)', function () {
    $this->employee->update(['disbursement_channel' => DisbursementChannel::Cash->value]);

    $svc = new BatchDisbursementService([], app(PostingService::class));    // no providers
    $svc->materialise($this->run);

    $result = $svc->dispatch($this->run);
    expect($result['skipped'])->toBe(1);
    expect($result['sent'])->toBe(0);
});
