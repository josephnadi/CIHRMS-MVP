<?php

declare(strict_types=1);

use App\Enums\DisbursementStatus;
use App\Models\Disbursement;
use App\Models\GlAccount;
use App\Models\HubtelWebhookEvent;
use App\Models\OrgBankAccount;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\PostingAccountSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new PostingAccountSeeder())->run();
    User::factory()->create(['role' => 'super_admin']); // actor-less posting fallback
    OrgBankAccount::factory()->create([
        'purpose'       => 'payroll', 'is_active' => true,
        'gl_account_id' => GlAccount::where('code', '1110')->value('id'),
    ]);
});

function hubtelSign(array $payload): array
{
    $secret = 'test-secret';
    config()->set('services.hubtel.webhook_secret', $secret);
    $body = json_encode($payload);
    return [$body, hash_hmac('sha256', $body, $secret)];
}

function sentDisbursement(string $ref): Disbursement
{
    return Disbursement::factory()->create([
        'channel'            => 'hubtel_bank',
        'status'             => DisbursementStatus::Sent->value,
        'provider_reference' => $ref,
        'gross_amount'       => 1000.00,
        'net_to_recipient'   => 1000.00,
        'final_settlement_id'=> null,
    ]);
}

it('settles a disbursement on a signed success webhook', function () {
    $d = sentDisbursement('HUB-TX-1');
    [$body, $sig] = hubtelSign(['Data' => ['TransactionId' => 'HUB-TX-1', 'Status' => 'Paid', 'ClientReference' => "PAYOUT-{$d->id}"]]);

    $this->call('POST', '/webhooks/hubtel', [], [], [], ['HTTP_X-Hubtel-Signature' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)
        ->assertOk();

    // process the queued job synchronously if needed; assert terminal state
    expect($d->fresh()->status)->toBe(DisbursementStatus::Settled);
});

it('fails a disbursement on a signed failure webhook', function () {
    $d = sentDisbursement('HUB-TX-2');
    [$body, $sig] = hubtelSign(['Data' => ['TransactionId' => 'HUB-TX-2', 'Status' => 'Failed', 'ClientReference' => "PAYOUT-{$d->id}"]]);

    $this->call('POST', '/webhooks/hubtel', [], [], [], ['HTTP_X-Hubtel-Signature' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();

    expect($d->fresh()->status)->toBe(DisbursementStatus::Failed);
});

it('rejects a bad signature', function () {
    $d = sentDisbursement('HUB-TX-3');
    config()->set('services.hubtel.webhook_secret', 'test-secret');
    $body = json_encode(['Data' => ['TransactionId' => 'HUB-TX-3', 'Status' => 'Paid']]);

    $this->call('POST', '/webhooks/hubtel', [], [], [], ['HTTP_X-Hubtel-Signature' => 'wrong', 'CONTENT_TYPE' => 'application/json'], $body)
        ->assertStatus(400);

    expect($d->fresh()->status)->toBe(DisbursementStatus::Sent);
});

it('is idempotent on a duplicate event', function () {
    $d = sentDisbursement('HUB-TX-4');
    [$body, $sig] = hubtelSign(['Data' => ['TransactionId' => 'HUB-TX-4', 'Status' => 'Paid', 'ClientReference' => "PAYOUT-{$d->id}"]]);

    $this->call('POST', '/webhooks/hubtel', [], [], [], ['HTTP_X-Hubtel-Signature' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
    $this->call('POST', '/webhooks/hubtel', [], [], [], ['HTTP_X-Hubtel-Signature' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();

    expect(HubtelWebhookEvent::where('hubtel_event_id', 'HUB-TX-4')->count())->toBe(1);
});
