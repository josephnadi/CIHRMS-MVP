<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Exceptions\Finance\PaystackException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\BulkRefundRequest;
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

    public function bulkStore(BulkRefundRequest $request): RedirectResponse
    {
        $ids    = $request->validated('intent_ids');
        $reason = $request->validated('reason');
        $user   = $request->user();

        $succeeded = 0;
        $skipped   = [];
        $failed    = [];

        foreach (PaymentIntent::whereIn('id', $ids)->with(['receipt.journalEntry', 'receipt.allocations'])->get() as $intent) {
            try {
                $this->refunds->refund($intent, $user, $reason);
                $succeeded++;
            } catch (DomainException $e) {
                $skipped[] = "{$intent->reference}: {$e->getMessage()}";
            } catch (PaystackException $e) {
                $failed[] = "{$intent->reference}: Paystack {$e->getMessage()}";
            }
        }

        $msg = "Bulk refund — {$succeeded} initiated";
        if ($skipped) {
            $msg .= ', ' . count($skipped) . ' skipped (' . implode('; ', array_slice($skipped, 0, 3)) . ')';
        }
        if ($failed) {
            return back()
                ->with('success', $msg)
                ->withErrors(['bulk_refund' => 'Paystack errors: ' . implode('; ', $failed)]);
        }

        return back()->with('success', $msg . '.');
    }
}
