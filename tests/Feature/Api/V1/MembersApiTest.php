<?php

use App\Enums\ArInvoiceStatus;
use App\Enums\MemberClass;
use App\Models\ArInvoice;
use App\Models\Member;
use App\Models\PaymentIntent;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
});

function tokenWith(array $abilities = []): array {
    $user  = User::factory()->create();
    $token = $user->createToken('partner-test', $abilities)->plainTextToken;
    return ['user' => $user, 'token' => $token];
}

function makeApiInvoice(int $customerId, float $total = 500.00): ArInvoice {
    $arGl = \App\Models\GlAccount::ofType('receivable')->first()
        ?: \App\Models\GlAccount::ofType('asset')->orderBy('code')->first();
    return ArInvoice::create([
        'reference'        => 'INV-' . uniqid(),
        'customer_id'      => $customerId,
        'status'           => ArInvoiceStatus::Approved->value,
        'invoice_date'     => now()->toDateString(),
        'subtotal'         => $total,
        'tax_amount'       => 0,
        'total'            => $total,
        'amount_received'  => 0,
        'currency'         => 'GHS',
        'ar_gl_account_id' => $arGl->id,
        'created_by'       => User::factory()->create()->id,
    ]);
}

it('refuses GET /api/v1/members without the members:read scope', function () {
    $auth = tokenWith(['payroll:read']);  // wrong scope

    $this->getJson('/api/v1/members', ['Authorization' => "Bearer {$auth['token']}"])
         ->assertStatus(403)
         ->assertJson(['required' => 'members:read']);
});

it('lists members for a token with members:read', function () {
    Member::factory()->count(3)->create();
    $auth = tokenWith(['members:read']);

    $resp = $this->getJson('/api/v1/members', ['Authorization' => "Bearer {$auth['token']}"]);
    $resp->assertOk()
         ->assertJsonStructure(['data', 'meta', 'links']);
    expect(count($resp->json('data')))->toBeGreaterThanOrEqual(3);
});

it('filters members by class', function () {
    Member::factory()->create(['class' => MemberClass::Professional->value]);
    Member::factory()->create(['class' => MemberClass::Student->value]);
    Member::factory()->create(['class' => MemberClass::Fellow->value]);

    $auth = tokenWith(['members:read']);
    $resp = $this->getJson('/api/v1/members?class=student', ['Authorization' => "Bearer {$auth['token']}"]);
    $resp->assertOk();

    $classes = array_map(fn ($r) => $r['class'], $resp->json('data'));
    expect($classes)->toEqual(['student']);
});

it('refuses /api/v1/members/{id}/invoices without invoices:read scope', function () {
    $member = Member::factory()->create();
    $auth   = tokenWith(['members:read']);  // missing invoices:read

    $this->getJson("/api/v1/members/{$member->id}/invoices", ['Authorization' => "Bearer {$auth['token']}"])
         ->assertStatus(403);
});

it('returns invoices when both scopes are present', function () {
    $member = Member::factory()->create();
    makeApiInvoice($member->customer_id, 500.00);
    makeApiInvoice($member->customer_id, 250.00);

    $auth = tokenWith(['members:read', 'invoices:read']);

    $resp = $this->getJson("/api/v1/members/{$member->id}/invoices",
        ['Authorization' => "Bearer {$auth['token']}"]);

    $resp->assertOk()
         ->assertJsonStructure(['data', 'meta' => ['member_id', 'count', 'filter']]);
    expect($resp->json('meta.count'))->toBe(2);
});

it('filters invoices by ?open=1', function () {
    $member  = Member::factory()->create();
    $open    = makeApiInvoice($member->customer_id, 500.00);
    // Mint a Paid invoice by force-updating status.
    $paid = makeApiInvoice($member->customer_id, 100.00);
    $paid->update(['status' => ArInvoiceStatus::Paid->value, 'amount_received' => 100.00]);

    $auth = tokenWith(['members:read', 'invoices:read']);
    $resp = $this->getJson("/api/v1/members/{$member->id}/invoices?open=1",
        ['Authorization' => "Bearer {$auth['token']}"]);

    $resp->assertOk();
    expect($resp->json('meta.count'))->toBe(1);
    expect($resp->json('data.0.reference'))->toBe($open->reference);
});

it('mints a Paystack payment intent for an invoice via API', function () {
    User::factory()->create(['role' => 'super_admin']);  // resolveSystemUser

    $member  = Member::factory()->create();
    $member->customer->update(['email' => 'paymember@example.gh']);
    $invoice = makeApiInvoice($member->customer_id, 500.00);

    \Illuminate\Support\Facades\Http::fake([
        'api.paystack.co/transaction/initialize' => \Illuminate\Support\Facades\Http::response([
            'status' => true,
            'data'   => [
                'authorization_url' => 'https://checkout.paystack.com/test',
                'access_code'       => 'ac_test',
                'reference'         => 'pstk_partner_1',
            ],
        ], 200),
    ]);

    $auth = tokenWith(['members:read', 'gateway:create']);

    $resp = $this->postJson(
        "/api/v1/members/{$member->id}/payment-intents",
        ['ar_invoice_id' => $invoice->id],
        ['Authorization' => "Bearer {$auth['token']}"],
    );

    $resp->assertStatus(201)
         ->assertJsonStructure(['data' => ['reference', 'authorization_url', 'amount', 'currency']]);
    expect($resp->json('data.authorization_url'))->toBe('https://checkout.paystack.com/test');
    expect(PaymentIntent::where('ar_invoice_id', $invoice->id)->count())->toBe(1);
});

it('rejects payment-intent minting for an invoice belonging to another member (IDOR)', function () {
    User::factory()->create(['role' => 'super_admin']);
    $memberA = Member::factory()->create();
    $memberB = Member::factory()->create();
    $bInvoice = makeApiInvoice($memberB->customer_id, 500.00);

    $auth = tokenWith(['members:read', 'gateway:create']);

    $this->postJson(
        "/api/v1/members/{$memberA->id}/payment-intents",
        ['ar_invoice_id' => $bInvoice->id],
        ['Authorization' => "Bearer {$auth['token']}"],
    )->assertStatus(403);
});
