<?php
/*
 |  End-to-end smoke for M1 + M2 + M3.
 |  Run with:  php artisan tinker --execute="require 'scripts/smoke-billing.php';"
 */

use App\Enums\ArInvoiceStatus;
use App\Enums\BillingCycle;
use App\Enums\MemberClass;
use App\Models\ArInvoice;
use App\Models\FeeProduct;
use App\Models\GlAccount;
use App\Models\Member;
use App\Models\MemberPhonePin;
use App\Models\User;
use App\Services\Billing\BillingRunService;
use App\Services\Billing\MemberRegistrationService;
use App\Services\Messaging\Ussd\UssdSessionHandler;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;

function line(string $s): void { echo str_pad('── '.$s.' ', 78, '─')."\n"; }
function ok(string $s): void { echo "  ✓ $s\n"; }
function dump_($v): void { echo '  '.(is_string($v) ? $v : json_encode($v)).PHP_EOL; }

line('M1: register member + fee product + run billing');

$systemUser = User::where('role','super_admin')->orderBy('id')->first()
    ?? User::factory()->create(['role'=>'super_admin']);

$reg = app(MemberRegistrationService::class);
$member = $reg->register([
    'name'    => 'Akua Mensah',
    'email'   => 'akua-smoke@cihrm.gh',
    'phone'   => '+233200000301',
    'class'   => MemberClass::Professional,
    'address' => 'Accra',
], operator: $systemUser);
ok("member id={$member->id} member_no={$member->member_no} customer_id={$member->customer_id}");

$member->update(['password' => Hash::make('PortalPass!9')]);
ok('portal password set');

$revGl = GlAccount::where('type','revenue')->orderBy('code')->first();
$product = FeeProduct::firstOrCreate(
    ['code' => 'DUES-2026'],
    [
        'name' => 'Annual Member Dues 2026',
        'description' => 'Smoke fee product',
        'amount' => 500.00,
        'currency' => 'GHS',
        'billing_cycle' => BillingCycle::Annual->value,
        'applies_to_classes' => [MemberClass::Professional->value],
        'gl_income_account_id' => $revGl->id,
        'is_active' => true,
    ]
);
ok("fee product id={$product->id} code={$product->code}");

$result = app(BillingRunService::class)->run($product, '2026', $systemUser);
ok("billing run ref={$result->reference} invoices=".count($result->createdInvoiceIds));
$invoice = ArInvoice::find($result->createdInvoiceIds[0] ?? null);
if (!$invoice) { dump_('NO INVOICE — abort'); return; }
ok("invoice id={$invoice->id} ref={$invoice->reference} total={$invoice->total} status={$invoice->status->value}");

// Approve the Draft so it shows as outstanding.
$invoice->update(['status' => ArInvoiceStatus::Approved->value]);
ok("invoice approved");

line('M2: simulate paystack webhook → notification + receipt');

\App\Models\PaymentIntent::where('ar_invoice_id', $invoice->id)->delete();
$intent = app(\App\Services\Finance\PaymentIntentService::class)->createForInvoice(
    invoice: $invoice,
    amount: (float) $invoice->total,
    creator: $systemUser,
    callbackUrl: null,
);
ok("intent ref={$intent->reference}");

$payload = json_encode([
    'event' => 'charge.success',
    'data'  => [
        'reference' => $intent->reference,
        'amount'    => (int) round($intent->amount * 100),
        'status'    => 'success',
        'paid_at'   => now()->toIso8601String(),
        'channel'   => 'card',
        'currency'  => 'GHS',
    ],
]);
$sig = hash_hmac('sha512', $payload, config('services.paystack.secret_key', 'sk_test_smoke'));

$resp = Http::withHeaders([
    'x-paystack-signature' => $sig,
    'Content-Type'         => 'application/json',
])->withBody($payload, 'application/json')->post('http://127.0.0.1:8000/webhooks/paystack');

ok("webhook POST status={$resp->status()}");
$invoice->refresh();
ok("invoice status now={$invoice->status->value} amount_received={$invoice->amount_received}");
$receipt = \App\Models\ArReceipt::where('customer_id', $member->customer_id)->latest()->first();
ok($receipt ? "receipt ref={$receipt->reference} amount={$receipt->amount}" : 'NO RECEIPT');

line('M3 USSD: walk member-fee menu (create a fresh invoice first)');

// Create a fresh open invoice for the USSD demo
$result2 = app(BillingRunService::class)->run($product, '2027', $systemUser);
$inv2 = ArInvoice::find($result2->createdInvoiceIds[0] ?? null);
if ($inv2) {
    $inv2->update(['status' => ArInvoiceStatus::Approved->value]);
    ok("USSD demo invoice ref={$inv2->reference}");
}

MemberPhonePin::where('member_id', $member->id)->delete();
MemberPhonePin::create([
    'member_id' => $member->id,
    'phone'     => $member->phone,
    'pin_hash'  => Hash::make('1234'),
]);
ok('member PIN seeded (1234)');

Http::fake([
    'api.paystack.co/transaction/initialize' => Http::response([
        'status' => true,
        'data'   => [
            'authorization_url' => 'https://checkout.paystack.com/SMOKE',
            'access_code'       => 'ac_smoke',
            'reference'         => 'pstk_smoke_'.uniqid(),
        ],
    ], 200),
]);

\App\Models\UssdSession::where('phone', $member->phone)->delete();
$h = app(UssdSessionHandler::class);
$sid = 'smoke-'.uniqid();

foreach (['' => 'welcome', '2' => 'pick member', '2*1234' => 'PIN', '2*1234*2' => 'pay menu', '2*1234*2*1' => 'select inv', '2*1234*2*1*1' => 'confirm'] as $text => $label) {
    $resp = $h->handle($sid, $member->phone, '*920*HR#', (string) $text);
    ok("USSD [$label] → ".substr($resp, 0, 80).(strlen($resp) > 80 ? '…' : ''));
}

line('M3 API: scope-gated endpoints');

$apiUser = User::factory()->create(['role'=>'finance_officer']);
PersonalAccessToken::where('tokenable_id', $apiUser->id)->delete();
$tok = $apiUser->createToken('smoke', ['members:read','invoices:read','gateway:create'])->plainTextToken;
ok('token minted with members:read + invoices:read + gateway:create');

foreach ([
    ['GET',  "/api/v1/members?class=professional"],
    ['GET',  "/api/v1/members/{$member->id}"],
    ['GET',  "/api/v1/members/{$member->id}/invoices?open=1"],
] as [$m, $url]) {
    $r = Http::withToken($tok)->acceptJson()->{strtolower($m)}('http://127.0.0.1:8000'.$url);
    ok("$m $url → {$r->status()} (".strlen($r->body())." bytes)");
}

// Negative scope test
$badTok = User::factory()->create(['role'=>'employee'])->createToken('bad', ['payroll:read'])->plainTextToken;
$bad = Http::withToken($badTok)->acceptJson()->get('http://127.0.0.1:8000/api/v1/members');
ok("GET /api/v1/members (wrong scope) → {$bad->status()} (expect 403)");

line('DONE');
echo "\nMember: {$member->member_no} ({$member->email})  portal pw: PortalPass!9\n";
echo "Login URL: http://127.0.0.1:8000/portal/login\n";
echo "PIN for USSD: 1234   phone: {$member->phone}\n";
