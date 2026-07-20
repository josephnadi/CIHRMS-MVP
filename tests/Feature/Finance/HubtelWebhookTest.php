<?php

declare(strict_types=1);

use App\Enums\DisbursementStatus;
use App\Enums\JournalSourceType;
use App\Models\Disbursement;
use App\Models\GlAccount;
use App\Models\HubtelWebhookEvent;
use App\Models\JournalEntry;
use App\Models\OrgBankAccount;
use App\Models\User;
use App\Services\Finance\HubtelWebhookProcessor;
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

    // and assert the settlement GL entry was actually posted, not just the
    // status flip — a status-only change with no JE would still leave books
    // out of sync with reality.
    expect(JournalEntry::where('source_type', JournalSourceType::Disbursement->value)
        ->where('source_id', $d->id)
        ->where('source_purpose', 'settlement')
        ->count())->toBe(1);
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

it('is idempotent at the processor level when the same event is processed twice', function () {
    $d = sentDisbursement('HUB-TX-5');
    $event = HubtelWebhookEvent::create([
        'hubtel_event_id'  => 'HUB-TX-5',
        'client_reference' => "PAYOUT-{$d->id}",
        'status_text'      => 'Paid',
        'payload'          => ['Data' => ['TransactionId' => 'HUB-TX-5', 'Status' => 'Paid', 'ClientReference' => "PAYOUT-{$d->id}"]],
        'signature'        => 'irrelevant-for-processor-level-test',
    ]);

    $processor = app(HubtelWebhookProcessor::class);

    $processor->process($event);
    $d->refresh();
    expect($d->status)->toBe(DisbursementStatus::Settled);
    $settledAtFirst = $d->settled_at;
    $jeCountFirst = JournalEntry::where('source_type', JournalSourceType::Disbursement->value)
        ->where('source_id', $d->id)
        ->where('source_purpose', 'settlement')
        ->count();
    expect($jeCountFirst)->toBe(1);

    // Re-process the SAME event (e.g. a redelivered queue job). The
    // `processed_at` guard at the top of process() should make this a no-op:
    // no status churn, no settled_at drift, no duplicate settlement JE.
    $processor->process($event->fresh());
    $d->refresh();

    expect($d->status)->toBe(DisbursementStatus::Settled)
        ->and($d->settled_at->equalTo($settledAtFirst))->toBeTrue()
        ->and(JournalEntry::where('source_type', JournalSourceType::Disbursement->value)
            ->where('source_id', $d->id)
            ->where('source_purpose', 'settlement')
            ->count())->toBe($jeCountFirst);
});

it('keeps an already-settled disbursement Settled when a later signed Failed webhook arrives', function () {
    $d = sentDisbursement('HUB-TX-6');

    [$firstBody, $firstSig] = hubtelSign(['Data' => ['TransactionId' => 'HUB-TX-6', 'Status' => 'Paid', 'ClientReference' => "PAYOUT-{$d->id}"]]);
    $this->call('POST', '/webhooks/hubtel', [], [], [], ['HTTP_X-Hubtel-Signature' => $firstSig, 'CONTENT_TYPE' => 'application/json'], $firstBody)
        ->assertOk();

    $d->refresh();
    expect($d->status)->toBe(DisbursementStatus::Settled);
    $settledAt = $d->settled_at;

    // A later/duplicate webhook — different TransactionId, e.g. a delayed
    // retry or correction from the provider — reports Failed for the same
    // disbursement. It must NOT reopen an already-settled row: the
    // settlement GL entry is already posted, so flipping status to Failed
    // would create a books-say-paid / status-says-failed mismatch.
    [$secondBody, $secondSig] = hubtelSign(['Data' => ['TransactionId' => 'HUB-TX-6-RETRY', 'Status' => 'Failed', 'ClientReference' => "PAYOUT-{$d->id}"]]);
    $this->call('POST', '/webhooks/hubtel', [], [], [], ['HTTP_X-Hubtel-Signature' => $secondSig, 'CONTENT_TYPE' => 'application/json'], $secondBody)
        ->assertOk();

    $d->refresh();
    expect($d->status)->toBe(DisbursementStatus::Settled)
        ->and($d->settled_at->equalTo($settledAt))->toBeTrue()
        ->and($d->failed_at)->toBeNull();

    expect(JournalEntry::where('source_type', JournalSourceType::Disbursement->value)
        ->where('source_id', $d->id)
        ->where('source_purpose', 'settlement')
        ->count())->toBe(1);
});
