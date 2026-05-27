<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Enums\ArInvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\ArInvoice;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $member = $request->user('member');

        $openStatuses = [ArInvoiceStatus::Approved->value, ArInvoiceStatus::PartiallyPaid->value];

        $invoices = ArInvoice::query()
            ->where('customer_id', $member->customer_id)
            ->whereIn('status', $openStatuses)
            ->orderBy('invoice_date')
            ->limit(5)
            ->get(['id', 'reference', 'invoice_date', 'due_date', 'total', 'amount_received', 'currency']);

        $outstandingTotal = (float) ArInvoice::query()
            ->where('customer_id', $member->customer_id)
            ->whereIn('status', $openStatuses)
            ->sum(\DB::raw('total - amount_received'));

        return Inertia::render('Portal/Dashboard', [
            'member' => [
                'member_no' => $member->member_no,
                'name'      => $member->name,
                'class'     => is_object($member->class) ? $member->class->value : (string) $member->class,
            ],
            'outstanding_total' => $outstandingTotal,
            'currency'          => 'GHS',
            'open_invoices'     => $invoices->map(fn ($i) => [
                'id'              => $i->id,
                'reference'       => $i->reference,
                'invoice_date'    => $i->invoice_date?->toDateString(),
                'due_date'        => $i->due_date?->toDateString(),
                'total'           => (float) $i->total,
                'outstanding'     => (float) ($i->total - $i->amount_received),
                'currency'        => $i->currency,
            ])->values(),
        ]);
    }
}
