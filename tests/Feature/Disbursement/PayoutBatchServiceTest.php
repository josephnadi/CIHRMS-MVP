<?php

declare(strict_types=1);

use App\Enums\DisbursementStatus;
use App\Enums\PayoutBatchStatus;
use App\Models\Disbursement;
use App\Models\FinalSettlement;
use App\Models\OffboardingCase;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Disbursement\PayoutBatchService;

beforeEach(fn () => config()->set('finance.payouts.high_approval_threshold', 100000));

it('wraps a payroll run pending disbursements into a pending_release batch', function () {
    $maker = User::factory()->create();
    $run   = PayrollRun::factory()->create();

    Disbursement::factory()->count(3)->create([
        'payroll_run_id'   => $run->id,
        'status'           => DisbursementStatus::Pending->value,
        'net_to_recipient' => 2000.00,
    ]);

    $batch = app(PayoutBatchService::class)->createForPayrollRun($run, $maker->id);

    expect($batch->status)->toBe(PayoutBatchStatus::PendingRelease)
        ->and((float) $batch->total_amount)->toBe(6000.00)
        ->and($batch->requires_high_approval)->toBeFalse()
        ->and($batch->created_by)->toBe($maker->id)
        ->and($batch->disbursements()->count())->toBe(3);
});

it('flags requires_high_approval when total meets the threshold', function () {
    config()->set('finance.payouts.high_approval_threshold', 5000);
    $maker = User::factory()->create();
    $run   = PayrollRun::factory()->create();
    Disbursement::factory()->count(3)->create([
        'payroll_run_id' => $run->id, 'status' => DisbursementStatus::Pending->value, 'net_to_recipient' => 2000.00,
    ]);

    $batch = app(PayoutBatchService::class)->createForPayrollRun($run, $maker->id);

    expect($batch->requires_high_approval)->toBeTrue();
});

it('flags requires_high_approval when total is exactly equal to the threshold', function () {
    config()->set('finance.payouts.high_approval_threshold', 6000);
    $maker = User::factory()->create();
    $run   = PayrollRun::factory()->create();
    Disbursement::factory()->count(3)->create([
        'payroll_run_id' => $run->id, 'status' => DisbursementStatus::Pending->value, 'net_to_recipient' => 2000.00,
    ]);

    $batch = app(PayoutBatchService::class)->createForPayrollRun($run, $maker->id);

    expect((float) $batch->total_amount)->toBe(6000.00)
        ->and($batch->requires_high_approval)->toBeTrue();
});

it('never flags requires_high_approval when the threshold is 0 (disabled)', function () {
    config()->set('finance.payouts.high_approval_threshold', 0);
    $maker = User::factory()->create();
    $run   = PayrollRun::factory()->create();
    Disbursement::factory()->count(3)->create([
        'payroll_run_id' => $run->id, 'status' => DisbursementStatus::Pending->value, 'net_to_recipient' => 100000.00,
    ]);

    $batch = app(PayoutBatchService::class)->createForPayrollRun($run, $maker->id);

    expect((float) $batch->total_amount)->toBe(300000.00)
        ->and($batch->requires_high_approval)->toBeFalse();
});

it('wraps a final settlement pending disbursements into a pending_release batch', function () {
    $maker      = User::factory()->create();
    $case       = OffboardingCase::factory()->create();
    $settlement = FinalSettlement::create([
        'offboarding_case_id' => $case->id,
        'basic_salary'        => 5000,
        'years_of_service'    => 3,
        'accrued_leave_days'  => 10,
        'net_payable'         => 15000,
    ]);

    Disbursement::factory()->count(2)->create([
        'final_settlement_id' => $settlement->id,
        'status'              => DisbursementStatus::Pending->value,
        'net_to_recipient'    => 1500.00,
    ]);

    $batch = app(PayoutBatchService::class)->createForSettlement($settlement, $maker->id);

    expect($batch->status)->toBe(PayoutBatchStatus::PendingRelease)
        ->and((float) $batch->total_amount)->toBe(3000.00)
        ->and($batch->created_by)->toBe($maker->id)
        ->and($batch->source_type)->toBe(FinalSettlement::class)
        ->and($batch->source_id)->toBe($settlement->id)
        ->and($batch->disbursements()->count())->toBe(2);
});
