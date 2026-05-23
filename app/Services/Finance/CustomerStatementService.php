<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\ArInvoiceStatus;
use App\Enums\ArReceiptStatus;
use App\Models\ArInvoice;
use App\Models\ArReceipt;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Read-only customer statement builder. Returns a date-range view of
 * invoices and receipts for a single customer, with a running balance
 * computed against the opening balance (outstanding from invoices dated
 * BEFORE the from-date). Cached per (customer, from, to) for 60s.
 */
class CustomerStatementService
{
    /**
     * @return array{
     *   customer: array<string,mixed>,
     *   period: array{from:string, to:string},
     *   opening_balance: float,
     *   lines: list<array<string,mixed>>,
     *   closing_balance: float,
     *   aging: array{current:float, 30:float, 60:float, 90_plus:float},
     * }
     */
    public function generate(Customer $customer, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $fromDate = $from->toDateString();
        $toDate   = $to->toDateString();
        $cacheKey = "ar.statement.{$customer->id}.{$fromDate}.{$toDate}";

        return Cache::remember($cacheKey, 60, function () use ($customer, $from, $to, $fromDate, $toDate) {
            $opening = $this->openingBalance($customer, $from);

            $invoices = ArInvoice::query()
                ->where('customer_id', $customer->id)
                ->whereBetween('invoice_date', [$fromDate, $toDate])
                ->whereNotIn('status', [ArInvoiceStatus::Cancelled->value])
                ->orderBy('invoice_date')
                ->orderBy('id')
                ->get(['id', 'reference', 'invoice_date', 'total', 'status']);

            $receipts = ArReceipt::query()
                ->where('customer_id', $customer->id)
                ->whereBetween('receipt_date', [$fromDate, $toDate])
                ->where('status', ArReceiptStatus::Processed->value)
                ->orderBy('receipt_date')
                ->orderBy('id')
                ->get(['id', 'reference', 'receipt_date', 'amount']);

            // Merge + sort by date (then by reference for stable ordering on same day).
            $events = $invoices->map(fn ($i) => [
                'sort_date'   => $i->invoice_date->format('Y-m-d'),
                'date'        => $i->invoice_date->format('Y-m-d'),
                'reference'   => $i->reference,
                'type'        => 'invoice',
                'debit'       => (float) $i->total,
                'credit'      => 0.0,
                'description' => $i->status->label() . ' invoice',
            ])->concat($receipts->map(fn ($r) => [
                'sort_date'   => $r->receipt_date->format('Y-m-d'),
                'date'        => $r->receipt_date->format('Y-m-d'),
                'reference'   => $r->reference,
                'type'        => 'receipt',
                'debit'       => 0.0,
                'credit'      => (float) $r->amount,
                'description' => 'Receipt',
            ]))->sortBy(['sort_date', 'reference'])->values();

            $running = $opening;
            $lines = [];
            foreach ($events as $e) {
                $running += $e['debit'] - $e['credit'];
                $lines[] = [
                    'date'            => $e['date'],
                    'reference'       => $e['reference'],
                    'type'            => $e['type'],
                    'debit'           => round($e['debit'], 2),
                    'credit'          => round($e['credit'], 2),
                    'running_balance' => round($running, 2),
                    'description'     => $e['description'],
                ];
            }

            return [
                'customer'        => $this->customerDto($customer),
                'period'          => ['from' => $fromDate, 'to' => $toDate],
                'opening_balance' => round($opening, 2),
                'lines'           => $lines,
                'closing_balance' => round($running, 2),
                'aging'           => $this->aging($customer),
            ];
        });
    }

    /**
     * Aging buckets — current / 30 / 60 / 90+ — computed across this
     * customer's outstanding (Approved or PartiallyPaid) invoices.
     *
     * @return array{current:float, 30:float, 60:float, 90_plus:float}
     */
    public function aging(Customer $customer): array
    {
        $today = CarbonImmutable::today();

        $rows = ArInvoice::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', [ArInvoiceStatus::Approved->value, ArInvoiceStatus::PartiallyPaid->value])
            ->get(['due_date', 'total', 'amount_received']);

        $buckets = ['current' => 0.0, '30' => 0.0, '60' => 0.0, '90_plus' => 0.0];

        foreach ($rows as $r) {
            $outstanding = (float) $r->total - (float) $r->amount_received;
            if ($outstanding <= 0.005) continue;

            if (! $r->due_date || $r->due_date->greaterThanOrEqualTo($today)) {
                $buckets['current'] += $outstanding;
            } else {
                $daysOverdue = (int) $today->diffInDays($r->due_date, true);
                if ($daysOverdue <= 30)     $buckets['30']      += $outstanding;
                elseif ($daysOverdue <= 60) $buckets['60']      += $outstanding;
                else                         $buckets['90_plus'] += $outstanding;
            }
        }

        return array_map(fn ($v) => round($v, 2), $buckets);
    }

    /** Outstanding from invoices dated BEFORE the period start. */
    private function openingBalance(Customer $customer, CarbonImmutable $from): float
    {
        // Invoices dated before $from contribute their total as a debit.
        $invoiceDebits = (float) ArInvoice::query()
            ->where('customer_id', $customer->id)
            ->where('invoice_date', '<', $from->toDateString())
            ->whereNotIn('status', [ArInvoiceStatus::Cancelled->value])
            ->sum('total');

        // Receipts dated before $from contribute their amount as a credit.
        $receiptCredits = (float) ArReceipt::query()
            ->where('customer_id', $customer->id)
            ->where('receipt_date', '<', $from->toDateString())
            ->where('status', ArReceiptStatus::Processed->value)
            ->sum('amount');

        return round($invoiceDebits - $receiptCredits, 2);
    }

    /** @return array<string,mixed> */
    private function customerDto(Customer $customer): array
    {
        return [
            'id'     => $customer->id,
            'code'   => $customer->code,
            'name'   => $customer->name,
            'email'  => $customer->email,
            'phone'  => $customer->phone,
            'tax_id' => $customer->tax_id,
        ];
    }
}
