<?php

declare(strict_types=1);

use App\Enums\DisbursementStatus;
use App\Enums\PayoutBatchStatus;
use App\Models\Disbursement;
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
