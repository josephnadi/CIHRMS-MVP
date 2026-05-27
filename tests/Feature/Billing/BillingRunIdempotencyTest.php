<?php

use App\Enums\FeeAssignmentStatus;
use App\Enums\MemberClass;
use App\Models\ArInvoice;
use App\Models\FeeAssignment;
use App\Models\FeeProduct;
use App\Models\Member;
use App\Models\User;
use App\Services\Billing\BillingRunService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
});

it('mints one Draft AR invoice per eligible member and is idempotent on re-run', function () {
    $product = FeeProduct::factory()->create([
        'name'          => 'Annual Member Dues',
        'amount'        => 500.00,
        'billing_cycle' => 'annual',
    ]);

    // Three eligible Professional members; one already-lapsed member who is
    // NOT eligible. The lapsed member should be skipped entirely.
    $a = Member::factory()->create(['class' => MemberClass::Professional->value, 'status' => 'active']);
    $b = Member::factory()->create(['class' => MemberClass::Professional->value, 'status' => 'active']);
    $c = Member::factory()->create(['class' => MemberClass::Professional->value, 'status' => 'active']);
    Member::factory()->create(['class' => MemberClass::Professional->value, 'status' => 'lapsed']);

    $operator = User::factory()->create();

    $svc = app(BillingRunService::class);

    $first = $svc->run($product, '2026', $operator);
    expect($first->eligibleMembers)->toBe(3);
    expect($first->assignmentsCreated)->toBe(3);
    expect($first->invoicesCreated)->toBe(3);
    expect($first->alreadyBilled)->toBe(0);
    expect(FeeAssignment::where('period_label', '2026')->count())->toBe(3);
    expect(ArInvoice::count())->toBe(3);

    // Re-run for the same period — no new invoices, all three already billed.
    $second = $svc->run($product, '2026', $operator);
    expect($second->assignmentsCreated)->toBe(0);
    expect($second->invoicesCreated)->toBe(0);
    expect($second->alreadyBilled)->toBe(3);
    expect(FeeAssignment::where('period_label', '2026')->count())->toBe(3);
    expect(ArInvoice::count())->toBe(3);

    // Every assignment is now Billed and linked to an AR invoice.
    FeeAssignment::where('period_label', '2026')->get()->each(function ($a) {
        expect($a->status)->toBe(FeeAssignmentStatus::Billed);
        expect($a->ar_invoice_id)->not->toBeNull();
    });
});

it('writes the run reference into invoice notes for traceability', function () {
    $product = FeeProduct::factory()->create();
    $member  = Member::factory()->create(['class' => MemberClass::Professional->value, 'status' => 'active']);
    $op      = User::factory()->create();

    $result = app(BillingRunService::class)->run($product, '2026-S1', $op);

    $invoice = ArInvoice::find($result->invoiceIds[0]);
    expect($invoice->notes)->toContain($result->reference);
    expect($invoice->notes)->toContain('2026-S1');
    expect($result->reference)->toMatch('/^BR-\d{4}-\d{4}$/');
});
