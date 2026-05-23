<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\ArInvoiceStatus;
use App\Enums\ArReceiptStatus;
use App\Enums\JournalEntryStatus;
use App\Enums\JournalSourceType;
use App\Models\ArInvoice;
use App\Models\ArReceipt;
use App\Models\ArReceiptInvoiceAllocation;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OrgBankAccount;
use App\Models\User;
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

            $je = JournalEntry::create([
                'reference'   => $this->nextJournalReference(),
                'entry_date'  => $receipt->receipt_date,
                'narration'   => "AR Receipt: {$receipt->reference}",
                'status'      => JournalEntryStatus::Draft->value,
                'source_type' => JournalSourceType::ArReceipt->value,
                'source_id'   => $receipt->id,
                'created_by'  => $creator->id,
            ]);

            // Dr Bank — single line for the total
            JournalLine::create([
                'journal_entry_id' => $je->id,
                'line_no'          => 1,
                'gl_account_id'    => $bank->gl_account_id,
                'debit_amount'     => $amount,
                'credit_amount'    => 0,
                'narration'        => "Cash in: {$bank->bank_name}",
            ]);
            // Cr AR — one line per allocated invoice
            $lineNo = 2;
            foreach ($allocations as $a) {
                $inv = $invoices[$a['ar_invoice_id']];
                JournalLine::create([
                    'journal_entry_id' => $je->id,
                    'line_no'          => $lineNo++,
                    'gl_account_id'    => $inv->ar_gl_account_id,
                    'debit_amount'     => 0,
                    'credit_amount'    => $a['allocated_amount'],
                    'narration'        => "Settle AR for {$inv->reference}",
                ]);
            }

            $this->journal->post($je->fresh('lines.glAccount'));

            $receipt->journal_entry_id = $je->id;
            $receipt->status           = ArReceiptStatus::Processed;
            $receipt->processed_at     = now();
            $receipt->processed_by     = $creator->id;
            $receipt->save();

            return $receipt->fresh(['allocations', 'journalEntry']);
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

    private function nextJournalReference(): string
    {
        $year = now()->format('Y');
        return sprintf('JE-%s-%06d', $year, $this->sequences->next("journal:{$year}"));
    }
}
