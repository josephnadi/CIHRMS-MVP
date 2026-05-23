<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Exceptions\Finance\PaystackException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreRefundRequest;
use App\Models\PaymentIntent;
use App\Services\Finance\RefundService;
use DomainException;
use Illuminate\Http\RedirectResponse;

class RefundController extends Controller
{
    public function __construct(private readonly RefundService $refunds)
    {
    }

    public function store(StoreRefundRequest $request, PaymentIntent $paymentIntent): RedirectResponse
    {
        try {
            $this->refunds->refund($paymentIntent, $request->user(), $request->validated('reason'));
        } catch (DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        } catch (PaystackException $e) {
            return back()->withErrors(['reason' => 'Paystack: ' . $e->getMessage()]);
        }

        return back()->with('success', 'Refund initiated. Settlement confirmation will arrive via webhook.');
    }
}
