<?php

use App\Enums\ArInvoiceStatus;
use App\Models\ArInvoice;
use App\Models\Member;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
});

function makeOpenInvoice(int $customerId, float $total = 500.00): ArInvoice {
    $arGl = \App\Models\GlAccount::ofType('receivable')->first()
        ?: \App\Models\GlAccount::ofType('asset')->orderBy('code')->first();
    $creator = \App\Models\User::factory()->create();

    return ArInvoice::create([
        'reference'        => 'INV-' . uniqid(),
        'customer_id'      => $customerId,
        'status'           => ArInvoiceStatus::Approved->value,
        'invoice_date'     => now()->subDays(1)->toDateString(),
        'due_date'         => now()->addDays(30)->toDateString(),
        'subtotal'         => $total,
        'tax_amount'       => 0,
        'total'            => $total,
        'amount_received'  => 0,
        'currency'         => 'GHS',
        'ar_gl_account_id' => $arGl->id,
        'created_by'       => $creator->id,
    ]);
}

it('renders the dashboard with the outstanding total for the logged-in member only', function () {
    $a = Member::factory()->create();
    $b = Member::factory()->create();

    makeOpenInvoice($a->customer_id, 500.00);
    makeOpenInvoice($a->customer_id, 250.00);
    makeOpenInvoice($b->customer_id, 9999.00);  // someone else's bill, must NOT count

    $resp = $this->actingAs($a, 'member')->get('/portal');
    $resp->assertOk();
    $resp->assertInertia(fn ($p) => $p
        ->component('Portal/Dashboard')
        ->where('outstanding_total', 750)
        ->where('member.member_no', $a->member_no)
        ->has('open_invoices', 2)
    );
});

it('the fees page only lists the logged-in member\'s invoices', function () {
    $a = Member::factory()->create();
    $b = Member::factory()->create();

    $aInv = makeOpenInvoice($a->customer_id, 100.00);
    $bInv = makeOpenInvoice($b->customer_id, 999.00);

    $resp = $this->actingAs($a, 'member')->get('/portal/fees');
    $resp->assertOk();
    $resp->assertInertia(fn ($p) => $p
        ->component('Portal/Fees/Index')
        ->where('invoices.data.0.reference', $aInv->reference)
        ->has('invoices.data', 1));
});

it('refuses to start a payment for another member\'s invoice (IDOR)', function () {
    $a = Member::factory()->create();
    $b = Member::factory()->create();

    $bInv = makeOpenInvoice($b->customer_id, 500.00);

    $this->actingAs($a, 'member')
        ->post(route('portal.fees.pay', $bInv->id))
        ->assertForbidden();
});
