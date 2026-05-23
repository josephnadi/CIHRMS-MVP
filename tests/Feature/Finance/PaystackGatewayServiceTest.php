<?php

declare(strict_types=1);

use App\Exceptions\Finance\PaystackException;
use App\Services\Finance\PaystackGatewayService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.paystack.url'        => 'https://api.paystack.co',
        'services.paystack.secret_key' => 'sk_test_secret',
    ]);
    $this->svc = app(PaystackGatewayService::class);
});

it('initializeTransaction converts GHS to pesewas and returns authorization data', function () {
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/abc123',
                'access_code'       => 'ac_abc',
                'reference'         => 'pst_ref_001',
            ],
        ], 200),
    ]);

    $result = $this->svc->initializeTransaction([
        'email'     => 'cust@example.com',
        'amount'    => 250.50,         // GHS
        'reference' => 'PI-2026-000001',
    ]);

    expect($result['authorization_url'])->toBe('https://checkout.paystack.com/abc123');
    expect($result['reference'])->toBe('pst_ref_001');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.paystack.co/transaction/initialize'
            && $request['amount'] === 25050  // 250.50 GHS * 100 = 25050 pesewas
            && $request['email']  === 'cust@example.com'
            && $request->hasHeader('Authorization', 'Bearer sk_test_secret');
    });
});

it('initializeTransaction throws PaystackException on non-2xx', function () {
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response([
            'status'  => false,
            'message' => 'Invalid email',
        ], 422),
    ]);

    expect(fn () => $this->svc->initializeTransaction([
        'email' => 'bad', 'amount' => 100, 'reference' => 'X',
    ]))->toThrow(PaystackException::class, 'Invalid email');
});

it('verifyTransaction returns the full transaction object', function () {
    Http::fake([
        'api.paystack.co/transaction/verify/pst_ref_001' => Http::response([
            'status' => true,
            'data'   => [
                'status'    => 'success',
                'amount'    => 25050,
                'reference' => 'pst_ref_001',
                'paid_at'   => '2026-05-23T10:30:00Z',
                'channel'   => 'mobile_money',
            ],
        ], 200),
    ]);

    $tx = $this->svc->verifyTransaction('pst_ref_001');

    expect($tx['status'])->toBe('success');
    expect($tx['amount'])->toBe(25050);
});

it('verifyTransaction throws PaystackException when API status is false', function () {
    Http::fake([
        'api.paystack.co/transaction/verify/pst_ref_bad' => Http::response([
            'status' => false, 'message' => 'Transaction reference not found',
        ], 404),
    ]);

    expect(fn () => $this->svc->verifyTransaction('pst_ref_bad'))
        ->toThrow(PaystackException::class, 'reference not found');
});

it('refundTransaction converts GHS to pesewas and returns refund data', function () {
    Http::fake([
        'api.paystack.co/refund' => Http::response([
            'status' => true,
            'data' => [
                'id'           => 99888777,
                'transaction'  => ['reference' => 'pst_ref_001'],
                'amount'       => 25050,
                'currency'     => 'GHS',
                'status'       => 'pending',
                'refunded_at'  => null,
                'merchant_note'=> 'Customer cancelled',
            ],
        ], 200),
    ]);

    $result = $this->svc->refundTransaction('pst_ref_001', 250.50, 'Customer cancelled');

    expect($result['id'])->toBe(99888777);
    expect($result['status'])->toBe('pending');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.paystack.co/refund'
            && $request['transaction']   === 'pst_ref_001'
            && $request['amount']        === 25050
            && $request['merchant_note'] === 'Customer cancelled';
    });
});

it('refundTransaction throws PaystackException on non-2xx', function () {
    Http::fake([
        'api.paystack.co/refund' => Http::response([
            'status'  => false,
            'message' => 'Transaction not refundable',
        ], 422),
    ]);

    expect(fn () => $this->svc->refundTransaction('pst_bad', 100.0, 'no'))
        ->toThrow(PaystackException::class, 'not refundable');
});
