<?php

use App\Enums\MemberClass;
use App\Models\ArInvoice;
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

it('only bills members whose class is in applies_to_classes', function () {
    $product = FeeProduct::factory()
        ->forClasses([MemberClass::Professional, MemberClass::Fellow])
        ->create();

    $prof    = Member::factory()->create(['class' => MemberClass::Professional->value, 'status' => 'active']);
    $fellow  = Member::factory()->create(['class' => MemberClass::Fellow->value,       'status' => 'active']);
    $student = Member::factory()->create(['class' => MemberClass::Student->value,      'status' => 'active']);
    $assoc   = Member::factory()->create(['class' => MemberClass::Associate->value,    'status' => 'active']);

    $result = app(BillingRunService::class)->run($product, '2026', User::factory()->create());

    expect($result->eligibleMembers)->toBe(2);  // prof + fellow
    expect($result->invoicesCreated)->toBe(2);
    expect(ArInvoice::where('customer_id', $prof->customer_id)->count())->toBe(1);
    expect(ArInvoice::where('customer_id', $fellow->customer_id)->count())->toBe(1);
    expect(ArInvoice::where('customer_id', $student->customer_id)->count())->toBe(0);
    expect(ArInvoice::where('customer_id', $assoc->customer_id)->count())->toBe(0);
});

it('treats a null applies_to_classes as "all classes"', function () {
    $product = FeeProduct::factory()->create(['applies_to_classes' => null]);
    Member::factory()->create(['class' => MemberClass::Student->value,      'status' => 'active']);
    Member::factory()->create(['class' => MemberClass::Professional->value, 'status' => 'active']);

    $result = app(BillingRunService::class)->run($product, '2026', User::factory()->create());
    expect($result->eligibleMembers)->toBe(2);
});

it('refuses to run if the product is inactive', function () {
    $product = FeeProduct::factory()->create(['is_active' => false]);
    expect(fn () => app(BillingRunService::class)->run($product, '2026', User::factory()->create()))
        ->toThrow(\DomainException::class);
});
