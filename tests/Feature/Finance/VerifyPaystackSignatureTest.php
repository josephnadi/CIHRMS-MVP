<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config(['services.paystack.webhook_secret' => 'whsec_test_value']);

    Route::post('/_test/paystack-sig', fn () => response('ok', 200))
        ->middleware('paystack.signature');
});

function paystackSig(string $body, string $secret): string
{
    return hash_hmac('sha512', $body, $secret);
}

it('passes when signature matches HMAC-SHA512 of body', function () {
    $body = json_encode(['event' => 'charge.success', 'data' => ['id' => 1]]);
    $sig  = paystackSig($body, 'whsec_test_value');

    $this->call('POST', '/_test/paystack-sig', [], [], [],
        ['HTTP_X-Paystack-Signature' => $sig, 'CONTENT_TYPE' => 'application/json'],
        $body)
        ->assertOk();
});

it('rejects when signature is missing', function () {
    $this->call('POST', '/_test/paystack-sig', [], [], [],
        ['CONTENT_TYPE' => 'application/json'],
        '{"x":1}')
        ->assertStatus(400);
});

it('rejects when signature is wrong', function () {
    $this->call('POST', '/_test/paystack-sig', [], [], [],
        ['HTTP_X-Paystack-Signature' => 'totally-bogus-sig', 'CONTENT_TYPE' => 'application/json'],
        '{"x":1}')
        ->assertStatus(400);
});

it('uses constant-time comparison (hash_equals)', function () {
    $body = '{"x":1}';
    $good = paystackSig($body, 'whsec_test_value');
    $bad  = substr($good, 0, -1) . 'X';

    $this->call('POST', '/_test/paystack-sig', [], [], [],
        ['HTTP_X-Paystack-Signature' => $bad, 'CONTENT_TYPE' => 'application/json'],
        $body)
        ->assertStatus(400);
});
