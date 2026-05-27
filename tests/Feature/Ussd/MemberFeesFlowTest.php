<?php

use App\Enums\ArInvoiceStatus;
use App\Enums\MemberStatus;
use App\Models\ArInvoice;
use App\Models\Member;
use App\Models\MemberPhonePin;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();

    // The /webhooks/ussd route is HMAC-signed by Hubtel; bypass that here
    // since the signature is independently covered by the webhook middleware
    // suite (PR #60 / Bundle 7 added it for Slack/SMS/USSD across the board).
    $this->withoutMiddleware(\App\Http\Middleware\VerifyWebhookSignature::class);
});

function memberWithPin(string $phone, string $pin = '1234'): Member {
    $member = Member::factory()->create(['phone' => $phone, 'status' => MemberStatus::Active->value]);
    MemberPhonePin::create([
        'member_id'       => $member->id,
        'phone'           => $phone,
        'pin_hash'        => Hash::make($pin),
        'failed_attempts' => 0,
    ]);
    return $member;
}

function openInvoiceFor(int $customerId, float $total): ArInvoice {
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

it('shows the new welcome menu including the member-fees option', function () {
    $resp = $this->postJson('/webhooks/ussd', [
        'sessionId' => 'sess-1',
        'msisdn'    => '+233200000300',
        'shortcode' => '*920*HR#',
        'text'      => '',
    ]);
    $resp->assertOk();
    $body = $resp->getContent();
    expect($body)->toStartWith('CON ');
    expect($body)->toContain('CIHRM member fees');
});

it('walks the full pay flow: option 2 → PIN → main menu → fee select → confirm', function () {
    User::factory()->create(['role' => 'super_admin']);  // for resolveSystemUser
    $member = memberWithPin('+233200000301');
    $invoice = openInvoiceFor($member->customer_id, 500.00);

    \Illuminate\Support\Facades\Http::fake([
        'api.paystack.co/transaction/initialize' => \Illuminate\Support\Facades\Http::response([
            'status' => true,
            'data'   => [
                'authorization_url' => 'https://checkout.paystack.com/abc',
                'access_code'       => 'ac_test',
                'reference'         => 'pstk_init_1',
            ],
        ], 200),
    ]);

    $resp = function (string $text) {
        return $this->postJson('/webhooks/ussd', [
            'sessionId' => 'sess-pay',
            'msisdn'    => '+233200000301',
            'shortcode' => '*920*HR#',
            'text'      => $text,
        ])->getContent();
    };

    // Step 1: welcome screen
    expect($resp(''))->toContain('CIHRM member fees');
    // Step 2: pick option 2 → PIN prompt
    expect($resp('2'))->toContain('Enter your 4-digit PIN');
    // Step 3: correct PIN → main menu
    expect($resp('2*1234'))->toContain('My outstanding fees');
    // Step 4: pick "Pay a fee" → list of invoices
    expect($resp('2*1234*2'))->toContain($invoice->reference);
    // Step 5: pick invoice 1 → confirm
    expect($resp('2*1234*2*1'))->toContain('1. Yes');
    // Step 6: confirm yes → payment link SMS'd
    $final = $resp('2*1234*2*1*1');
    expect($final)->toStartWith('END ');
    expect($final)->toContain('Payment link sent by SMS');

    // PaymentIntent persisted
    expect(\App\Models\PaymentIntent::where('ar_invoice_id', $invoice->id)->count())->toBe(1);
});

it('rejects a wrong PIN', function () {
    memberWithPin('+233200000302', '5678');

    $resp = function (string $text) {
        return $this->postJson('/webhooks/ussd', [
            'sessionId' => 'sess-bad',
            'msisdn'    => '+233200000302',
            'shortcode' => '*920*HR#',
            'text'      => $text,
        ])->getContent();
    };

    expect($resp(''))->toContain('CIHRM member fees');         // welcome
    expect($resp('2'))->toContain('Enter your 4-digit PIN');    // pick member flow
    $bad = $resp('2*0000');                                     // wrong PIN
    expect($bad)->toStartWith('END ');
    expect($bad)->toContain('Wrong PIN');
});

it('rejects member-fee flow for a phone with no member account', function () {
    $resp = $this->postJson('/webhooks/ussd', [
        'sessionId' => 'sess-orphan',
        'msisdn'    => '+233299999999',
        'shortcode' => '*920*HR#',
        'text'      => '2',
    ])->getContent();

    expect($resp)->toStartWith('END ');
    expect($resp)->toContain('No member account is linked');
});

it('shows outstanding total on option 1 with no payable invoices', function () {
    memberWithPin('+233200000303');

    $resp = function (string $text) {
        return $this->postJson('/webhooks/ussd', [
            'sessionId' => 'sess-out',
            'msisdn'    => '+233200000303',
            'shortcode' => '*920*HR#',
            'text'      => $text,
        ])->getContent();
    };

    expect($resp(''))->toContain('CIHRM member fees');
    expect($resp('2'))->toContain('Enter your 4-digit PIN');
    expect($resp('2*1234'))->toContain('My outstanding fees');
    $final = $resp('2*1234*1');
    expect($final)->toStartWith('END ');
    expect($final)->toContain('Outstanding: GHS 0.00');
});

it('falls through to legacy staff path when input is a staff id directly', function () {
    // The legacy USSD flow let users enter a staff ID without picking a menu first.
    // Ensure backward compatibility: input that's neither '0', '1', nor '2' goes
    // straight into the existing onStaffId() pipeline. We don't have a real staff
    // here so we expect the staff "unknown" message — proving the legacy branch
    // was reached.
    $resp = $this->postJson('/webhooks/ussd', [
        'sessionId' => 'sess-legacy',
        'msisdn'    => '+233200000304',
        'shortcode' => '*920*HR#',
        'text'      => 'NOSUCHSTAFFID',
    ])->getContent();

    expect($resp)->toStartWith('END ');
    expect($resp)->toContain('Unknown Staff ID');
});
