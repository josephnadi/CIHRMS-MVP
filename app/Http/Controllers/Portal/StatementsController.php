<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ArInvoice;
use App\Models\ArReceipt;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StatementsController extends Controller
{
    public function index(Request $request): Response
    {
        $member = $request->user('member');

        $invoices = ArInvoice::query()
            ->where('customer_id', $member->customer_id)
            ->orderByDesc('invoice_date')
            ->get(['id', 'reference', 'invoice_date', 'total', 'amount_received', 'currency', 'status']);

        $receipts = ArReceipt::query()
            ->where('customer_id', $member->customer_id)
            ->orderByDesc('receipt_date')
            ->get(['id', 'reference', 'receipt_date', 'amount', 'currency', 'status', 'external_ref']);

        return Inertia::render('Portal/Statements/Index', [
            'invoices' => $invoices->map(fn ($i) => [
                'reference'       => $i->reference,
                'invoice_date'    => $i->invoice_date?->toDateString(),
                'total'           => (float) $i->total,
                'amount_received' => (float) $i->amount_received,
                'currency'        => $i->currency,
                'status'          => is_object($i->status) ? $i->status->value : (string) $i->status,
            ])->values(),
            'receipts' => $receipts->map(fn ($r) => [
                'reference'    => $r->reference,
                'receipt_date' => $r->receipt_date?->toDateString(),
                'amount'       => (float) $r->amount,
                'currency'     => $r->currency,
                'status'       => is_object($r->status) ? $r->status->value : (string) $r->status,
                'external_ref' => $r->external_ref,
            ])->values(),
        ]);
    }
}
