<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\ApPaymentStatus;
use App\Enums\JournalEntryStatus;
use App\Enums\JournalSourceType;
use App\Enums\VendorInvoiceStatus;
use App\Events\ApPaymentProcessed;
use App\Models\ApPayment;
use App\Models\ApPaymentInvoiceAllocation;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OrgBankAccount;
use App\Models\User;
use App\Models\VendorInvoice;
use DomainException;
use Illuminate\Support\Facades\DB;

class ApPaymentService
{
    public function __construct(
        private readonly JournalPostingService $journal,
        private readonly SequenceService $sequences,
    ) {
    }

    public function record(array $data, User $creator): ApPayment
    {
        $allocations = $data['allocations'] ?? [];
        if (empty($allocations)) {
            throw new DomainException('Payment must have at least one invoice allocation.');
        }

        $allocSum = array_sum(array_map(fn ($a) => (float) $a['allocated_amount'], $allocations));
        $amount   = (float) $data['amount'];

        if (abs($allocSum - $amount) > 0.005) {
            throw new DomainException(sprintf(
                'Sum of allocations (%.2f) does not equal payment amount (%.2f).', $allocSum, $amount,
            ));
        }

        return DB::transaction(function () use ($data, $allocations, $creator, $amount) {
            $bank = OrgBankAccount::with('glAccount')->findOrFail($data['org_bank_account_id']);

            $invoices = [];
            foreach ($allocations as $a) {
                $inv = VendorInvoice::lockForUpdate()->findOrFail($a['vendor_invoice_id']);
                if (! in_array($inv->status, [VendorInvoiceStatus::Approved, VendorInvoiceStatus::PartiallyPaid], true)) {
                    throw new DomainException(
                        "Invoice {$inv->reference} status is {$inv->status->value}; only Approved or PartiallyPaid can be paid."
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

            $payment = ApPayment::create([
                'reference'           => $this->nextReference(),
                'vendor_id'           => $data['vendor_id'],
                'status'              => ApPaymentStatus::Pending->value,
                'payment_date'        => $data['payment_date'],
                'amount'              => $amount,
                'currency'            => $data['currency'] ?? 'GHS',
                'org_bank_account_id' => $bank->id,
                'narration'           => $data['narration'] ?? null,
                'created_by'          => $creator->id,
            ]);

            foreach ($allocations as $a) {
                ApPaymentInvoiceAllocation::create([
                    'ap_payment_id'     => $payment->id,
                    'vendor_invoice_id' => $a['vendor_invoice_id'],
                    'allocated_amount'  => $a['allocated_amount'],
                ]);

                $inv = $invoices[$a['vendor_invoice_id']];
                $inv->amount_paid = (float) $inv->amount_paid + (float) $a['allocated_amount'];
                $inv->status = abs($inv->amount_paid - (float) $inv->total) < 0.005
                    ? VendorInvoiceStatus::Paid
                    : VendorInvoiceStatus::PartiallyPaid;
                $inv->save();
            }

            $je = JournalEntry::create([
                'reference'   => $this->nextJournalReference(),
                'entry_date'  => $payment->payment_date,
                'narration'   => "AP Payment: {$payment->reference}",
                'status'      => JournalEntryStatus::Draft->value,
                'source_type' => JournalSourceType::ApPayment->value,
                'source_id'   => $payment->id,
                'created_by'  => $creator->id,
            ]);

            $lineNo = 1;
            foreach ($allocations as $a) {
                $inv = $invoices[$a['vendor_invoice_id']];
                JournalLine::create([
                    'journal_entry_id' => $je->id,
                    'line_no'          => $lineNo++,
                    'gl_account_id'    => $inv->ap_gl_account_id,
                    'debit_amount'     => $a['allocated_amount'],
                    'credit_amount'    => 0,
                    'narration'        => "Clear AP for {$inv->reference}",
                ]);
            }
            JournalLine::create([
                'journal_entry_id' => $je->id,
                'line_no'          => $lineNo,
                'gl_account_id'    => $bank->gl_account_id,
                'debit_amount'     => 0,
                'credit_amount'    => $amount,
                'narration'        => "Cash out: {$bank->bank_name}",
            ]);

            $this->journal->post($je->fresh('lines.glAccount'));

            $payment->journal_entry_id = $je->id;
            $payment->status           = ApPaymentStatus::Processed;
            $payment->processed_at     = now();
            $payment->processed_by     = $creator->id;
            $payment->save();

            ApPaymentProcessed::dispatch($payment->fresh(['allocations']));

            return $payment->fresh(['allocations', 'journalEntry']);
        });
    }

    public function void(ApPayment $payment, User $by, string $reason): ApPayment
    {
        if ($payment->status !== ApPaymentStatus::Processed) {
            throw new DomainException("Payment {$payment->reference} is not processed; cannot void.");
        }

        return DB::transaction(function () use ($payment, $by, $reason) {
            if ($payment->journalEntry) {
                $this->journal->reverse($payment->journalEntry, $by, "Void: {$reason}");
            }

            // F4-R-era hardening (I4): re-fetch the linked AP invoices with
            // lockForUpdate before mutating amount_paid, so concurrent voids /
            // payment-records can't lose state. Mirrors ArReceiptService::void().
            $invoiceIds = $payment->allocations->pluck('vendor_invoice_id')->all();
            VendorInvoice::whereIn('id', $invoiceIds)->lockForUpdate()->get();

            foreach ($payment->allocations as $alloc) {
                $inv = $alloc->invoice;
                $inv->amount_paid = (float) $inv->amount_paid - (float) $alloc->allocated_amount;
                if ($inv->amount_paid < 0) $inv->amount_paid = 0;
                $inv->status = $inv->amount_paid > 0
                    ? VendorInvoiceStatus::PartiallyPaid
                    : VendorInvoiceStatus::Approved;
                $inv->save();
            }

            $payment->status    = ApPaymentStatus::Voided;
            $payment->voided_at = now();
            $payment->voided_by = $by->id;
            $payment->save();

            return $payment->fresh();
        });
    }

    private function nextReference(): string
    {
        $year = now()->format('Y');
        return sprintf('APP-%s-%04d', $year, $this->sequences->next("app_payment:{$year}"));
    }

    private function nextJournalReference(): string
    {
        $year = now()->format('Y');
        return sprintf('JE-%s-%06d', $year, $this->sequences->next("journal:{$year}"));
    }
}
