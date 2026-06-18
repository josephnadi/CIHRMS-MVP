<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\ArInvoiceStatus;
use App\Enums\ArReceiptStatus;
use App\Enums\JournalSourceType;
use App\Models\ArInvoice;
use App\Models\ArReceipt;
use App\Models\ArReceiptInvoiceAllocation;
use App\Models\OrgBankAccount;
use App\Models\User;
use App\Services\Finance\PostingService;
use App\Services\Finance\Posting\PostingDocument;
use App\Services\Finance\Posting\PostingLine;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * AR receipt processing. Mirrors ApPaymentService on the receivables side.
 * On record(): Dr Bank, Cr AR (per allocated invoice). Updates each
 * invoice's amount_received and flips status to Paid or PartiallyPaid.
 *
 * F3 forward-fix: void() uses lockForUpdate when rolling back amount_received
 * to close the F2 ApPaymentService gap.
 */
class ArReceiptService
{
    public function __construct(
        private readonly JournalPostingService $journal,
        private readonly SequenceService $sequences,
        private readonly PostingService $posting,
    ) {
    }

    public function record(array $data, User $creator): ArReceipt
    {
        $allocations = $data['allocations'] ?? [];
        if (empty($allocations)) {
            throw new DomainException('Receipt must have at least one invoice allocation.');
        }

        $allocSum = array_sum(array_map(fn ($a) => (float) $a['allocated_amount'], $allocations));
        $amount   = (float) $data['amount'];

        if (abs($allocSum - $amount) > 0.005) {
            throw new DomainException(sprintf(
                'Sum of allocations (%.2f) does not equal receipt amount (%.2f).', $allocSum, $amount,
            ));
        }

        return DB::transaction(function () use ($data, $allocations, $creator, $amount) {
            $bank = OrgBankAccount::with('glAccount')->findOrFail($data['org_bank_account_id']);

            $invoices = [];
            foreach ($allocations as $a) {
                $inv = ArInvoice::lockForUpdate()->findOrFail($a['ar_invoice_id']);
                if (! in_array($inv->status, [ArInvoiceStatus::Approved, ArInvoiceStatus::PartiallyPaid], true)) {
                    throw new DomainException(
                        "Invoice {$inv->reference} status is {$inv->status->value}; only Approved or PartiallyPaid can receive payment."
                    );
                }
                if ((float) $a['allocated_amount'] > $inv->outstandingAmount() + 0.005) {
                    throw new DomainException(sprintf(
                        'Allocation %.2f exceeds outstanding %.2f on invoice %s.',
                        $a['allocated_amount'], $inv->outstandingAmount(), $inv->reference,
                    ));
                }
                $invoices[$inv->id] = $inv;
            }

            $receipt = ArReceipt::create([
                'reference'           => $this->nextReference(),
                'customer_id'         => $data['customer_id'],
                'status'              => ArReceiptStatus::Pending->value,
                'receipt_date'        => $data['receipt_date'],
                'amount'              => $amount,
                'currency'            => $data['currency'] ?? 'GHS',
                'org_bank_account_id' => $bank->id,
                'external_ref'        => $data['external_ref'] ?? null,
                'narration'           => $data['narration'] ?? null,
                'created_by'          => $creator->id,
            ]);

            foreach ($allocations as $a) {
                ArReceiptInvoiceAllocation::create([
                    'ar_receipt_id'    => $receipt->id,
                    'ar_invoice_id'    => $a['ar_invoice_id'],
                    'allocated_amount' => $a['allocated_amount'],
                ]);

                $inv = $invoices[$a['ar_invoice_id']];
                $inv->amount_received = (float) $inv->amount_received + (float) $a['allocated_amount'];
                $inv->status = abs($inv->amount_received - (float) $inv->total) < 0.005
                    ? ArInvoiceStatus::Paid
                    : ArInvoiceStatus::PartiallyPaid;
                $inv->save();
            }

            $postingLines = [];
            $postingLines[] = PostingLine::debit(
                amount: (float) $amount,
                accountId: (int) $bank->gl_account_id,
                narration: "Cash in: {$bank->bank_name}",
            );
            foreach ($allocations as $a) {
                $inv = $invoices[$a['ar_invoice_id']];
                $postingLines[] = PostingLine::credit(
                    amount: (float) $a['allocated_amount'],
                    accountId: (int) $inv->ar_gl_account_id,
                    narration: "Settle AR for {$inv->reference}",
                );
            }

            $je = $this->posting->post(new PostingDocument(
                sourceType: JournalSourceType::ArReceipt,
                sourceId: $receipt->id,
                purpose: '',
                date: $receipt->receipt_date->format('Y-m-d'),
                narration: "AR Receipt: {$receipt->reference}",
                lines: $postingLines,
            ), $creator);

            $receipt->journal_entry_id = $je->id;
            $receipt->status           = ArReceiptStatus::Processed;
            $receipt->processed_at     = now();
            $receipt->processed_by     = $creator->id;
            $receipt->save();

            return $receipt->fresh(['allocations', 'journalEntry']);
        });
    }

    /**
     * Record a receipt and FIFO-allocate it across the customer's open
     * invoices in invoice_date ASC order until the amount is exhausted.
     * Used by M3 USSD top-ups and any other inbound payment that does
     * not target a specific invoice up-front. Falls back to leaving the
     * receipt unallocated (one-line, no allocations row) when the
     * customer has no open invoices — that's a stand-alone receipt the
     * finance team can allocate manually later.
     *
     * @param  array<string, mixed>  $data  same shape as record() but
     *                                       MUST omit the 'allocations' key
     */
    public function recordWithFifoAllocation(array $data, User $creator): ArReceipt
    {
        if (!empty($data['allocations'])) {
            throw new DomainException('recordWithFifoAllocation cannot be called with explicit allocations — use record() instead.');
        }
        if (empty($data['customer_id'])) {
            throw new DomainException('recordWithFifoAllocation requires customer_id.');
        }

        $amount = (float) $data['amount'];

        return DB::transaction(function () use ($data, $creator, $amount) {
            // Walk the customer's open invoices, oldest-first, under a row lock
            // so two concurrent payments can't double-allocate against the same
            // remaining balance.
            $invoices = ArInvoice::query()
                ->where('customer_id', $data['customer_id'])
                ->whereIn('status', [
                    \App\Enums\ArInvoiceStatus::Approved->value,
                    \App\Enums\ArInvoiceStatus::PartiallyPaid->value,
                ])
                ->orderBy('invoice_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $remaining   = $amount;
            $allocations = [];
            foreach ($invoices as $inv) {
                if ($remaining < 0.01) break;
                $outstanding = $inv->outstandingAmount();
                if ($outstanding < 0.01) continue;

                $take = min($remaining, $outstanding);
                $allocations[] = [
                    'ar_invoice_id'    => $inv->id,
                    'allocated_amount' => round($take, 2),
                ];
                $remaining -= $take;
            }

            // No open invoices? Drop a stand-alone receipt with no allocations.
            // The journal still balances: Dr Bank for the full amount, Cr a
            // suspense/clearing account would be the right move, but for the
            // M2 surface (Paystack always targets an invoice) we never hit
            // this branch in practice. M3 will revisit when wallet-style
            // top-ups need handling.
            if (empty($allocations)) {
                throw new DomainException("Customer has no open invoices to allocate {$amount} against.");
            }

            return $this->record(array_merge($data, ['allocations' => $allocations]), $creator);
        });
    }

    public function void(ArReceipt $receipt, User $by, string $reason): ArReceipt
    {
        if ($receipt->status !== ArReceiptStatus::Processed) {
            throw new DomainException("Receipt {$receipt->reference} is not processed; cannot void.");
        }

        return DB::transaction(function () use ($receipt, $by, $reason) {
            if ($receipt->journalEntry) {
                $this->journal->reverse($receipt->journalEntry, $by, "Void: {$reason}");
            }

            // F3 forward-fix: lockForUpdate per invoice before mutating amount_received.
            // Closes the F2 gap where ApPaymentService::void() mutated invoices without locking.
            foreach ($receipt->allocations as $alloc) {
                $inv = ArInvoice::lockForUpdate()->findOrFail($alloc->ar_invoice_id);
                $inv->amount_received = (float) $inv->amount_received - (float) $alloc->allocated_amount;
                if ($inv->amount_received < 0) $inv->amount_received = 0;
                $inv->status = $inv->amount_received > 0
                    ? ArInvoiceStatus::PartiallyPaid
                    : ArInvoiceStatus::Approved;
                $inv->save();
            }

            $receipt->status    = ArReceiptStatus::Voided;
            $receipt->voided_at = now();
            $receipt->voided_by = $by->id;
            $receipt->save();

            return $receipt->fresh();
        });
    }

    private function nextReference(): string
    {
        $year = now()->format('Y');
        return sprintf('ARC-%s-%04d', $year, $this->sequences->next("ar_receipt:{$year}"));
    }
}
