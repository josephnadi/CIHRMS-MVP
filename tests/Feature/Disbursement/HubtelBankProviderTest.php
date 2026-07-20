<?php

declare(strict_types=1);

use App\Enums\DisbursementChannel;
use App\Enums\DisbursementStatus;
use App\Models\Disbursement;
use App\Services\Disbursement\Providers\HubtelBankProvider;
use Illuminate\Support\Facades\Http;

function hubtelProvider(): HubtelBankProvider
{
    return new HubtelBankProvider(
        baseUrl: 'https://payout.hubtel.test',
        clientId: 'cid',
        clientSecret: 'secret',
        merchantAccount: '12345',
        callbackUrl: 'https://app.test/webhooks/hubtel',
        timeoutSeconds: 5,
    );
}

function hubtelDisbursement(array $overrides = []): Disbursement
{
    return Disbursement::factory()->create(array_merge([
        'payroll_run_id'     => null,
        'payroll_line_id'    => null,
        'channel'             => DisbursementChannel::HubtelBank->value,
        'status'             => DisbursementStatus::Pending->value,
        'net_to_recipient'   => 1500.00,
        'beneficiary_account'=> '0551234567',
        'beneficiary_name'   => 'Ama Mensah',
    ], $overrides));
}

it('sends a transfer and returns Sent with the provider reference + idempotency key', function () {
    Http::fake([
        '*/transactions/*/send' => Http::response(['Data' => ['TransactionId' => 'HUB-TX-9']], 200),
    ]);

    $d = hubtelDisbursement();
    $result = hubtelProvider()->send($d);

    expect($result->status)->toBe(DisbursementStatus::Sent)
        ->and($result->providerReference)->toBe('HUB-TX-9');

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/send')
        && $req->hasHeader('Idempotency-Key', "HUBTEL-{$d->id}")
    );
});

it('returns Failed on a 4xx without throwing', function () {
    Http::fake(['*' => Http::response(['message' => 'invalid account'], 422)]);

    $result = hubtelProvider()->send(hubtelDisbursement());

    expect($result->status)->toBe(DisbursementStatus::Failed)
        ->and($result->failureReason)->toContain('422');
});

// NOTE: split into three `it()` blocks rather than three sequential
// Http::fake() calls inside one test. Laravel's Http::fake(['*' => ...])
// APPENDS to the fake's stub collection rather than replacing it, and
// resolution takes the *first* matching stub (Factory::fake() merges,
// PendingRequest::buildStubHandler() does ->filter()->first()) — since a
// '*' pattern matches unconditionally, a second/third Http::fake(['*' =>
// ...]) call within the same test is silently ignored and the first
// fake's response keeps being returned. Verified empirically. Splitting
// into separate tests (fresh Http instance per test) exercises the same
// three status mappings without hitting that footgun.
it('maps refreshStatus Paid to Settled', function () {
    $d = hubtelDisbursement(['provider_reference' => 'HUB-TX-9', 'status' => DisbursementStatus::Sent->value]);

    Http::fake(['*' => Http::response(['Data' => ['Status' => 'Paid']], 200)]);
    expect(hubtelProvider()->refreshStatus($d)->status)->toBe(DisbursementStatus::Settled);
});

it('maps refreshStatus Failed to Failed', function () {
    $d = hubtelDisbursement(['provider_reference' => 'HUB-TX-9', 'status' => DisbursementStatus::Sent->value]);

    Http::fake(['*' => Http::response(['Data' => ['Status' => 'Failed']], 200)]);
    expect(hubtelProvider()->refreshStatus($d)->status)->toBe(DisbursementStatus::Failed);
});

it('maps refreshStatus Pending to Sent', function () {
    $d = hubtelDisbursement(['provider_reference' => 'HUB-TX-9', 'status' => DisbursementStatus::Sent->value]);

    Http::fake(['*' => Http::response(['Data' => ['Status' => 'Pending']], 200)]);
    expect(hubtelProvider()->refreshStatus($d)->status)->toBe(DisbursementStatus::Sent);
});

it('rejects a disbursement with no beneficiary account', function () {
    $result = hubtelProvider()->send(hubtelDisbursement(['beneficiary_account' => null]));
    expect($result->status)->toBe(DisbursementStatus::Failed);
});
