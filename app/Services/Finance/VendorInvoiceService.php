<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\GlAccountType;
use App\Enums\JournalEntryStatus;
use App\Enums\JournalSourceType;
use App\Enums\VendorInvoiceStatus;
use App\Events\VendorInvoiceCreated;
use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorInvoice;
use App\Models\VendorInvoiceLine;
use DomainException;
use Illuminate\Support\Facades\DB;

class VendorInvoiceService
{
    public function __construct(
        private readonly JournalPostingService $journal,
        private readonly SequenceService $sequences,
    ) {
    }

    public function create(array $data, User $creator): VendorInvoice
    {
        if (empty($data['lines'])) {
            throw new DomainException('Invoice must have at least one line.');
        }

        return DB::transaction(function () use ($data, $creator) {
            $vendor = Vendor::findOrFail($data['vendor_id']);
            $apGl   = $this->resolveApGl($vendor);

            $lines = collect($data['lines'])->values()->map(function ($l, $i) {
                $this->assertExpenseGl((int) $l['gl_account_id']);

                $qty       = (float) ($l['quantity'] ?? 1);
                $unit      = (float) ($l['unit_price'] ?? 0);
                $taxRate   = (float) ($l['tax_rate'] ?? 0);
                $lineTotal = round($qty * $unit, 2);
                $taxAmount = round($lineTotal * $taxRate, 2);

                return [
                    'line_no'       => $i + 1,
                    'description'   => $l['description'] ?? '',
                    'quantity'      => $qty,
                    'unit_price'    => $unit,
                    'line_total'    => $lineTotal,
                    'tax_rate'      => $taxRate,
                    'tax_amount'    => $taxAmount,
                    'gl_account_id' => (int) $l['gl_account_id'],
                ];
            });

            $subtotal  = $lines->sum('line_total');
            $taxAmount = $lines->sum('tax_amount');
            $total     = $subtotal + $taxAmount;

            $invoice = VendorInvoice::create([
                'reference'         => $this->nextReference(),
                'vendor_id'         => $vendor->id,
                'vendor_invoice_no' => $data['vendor_invoice_no'] ?? null,
                'status'            => VendorInvoiceStatus::Draft->value,
                'invoice_date'      => $data['invoice_date'],
                'due_date'          => $data['due_date'] ?? null,
                'subtotal'          => $subtotal,
                'tax_amount'        => $taxAmount,
                'total'             => $total,
                'amount_paid'       => 0,
                'currency'          => $data['currency'] ?? 'GHS',
                'ap_gl_account_id'  => $apGl->id,
                'notes'             => $data['notes'] ?? null,
                'created_by'        => $creator->id,
            ]);

            foreach ($lines as $line) {
                VendorInvoiceLine::create(array_merge($line, ['vendor_invoice_id' => $invoice->id]));
            }

            $je = JournalEntry::create([
                'reference'   => $this->nextJournalReference(),
                'entry_date'  => $invoice->invoice_date,
                'narration'   => "Accrual: {$vendor->code} invoice " . ($invoice->vendor_invoice_no ?? $invoice->reference),
                'status'      => JournalEntryStatus::Draft->value,
                'source_type' => JournalSourceType::VendorInvoice->value,
                'source_id'   => $invoice->id,
                'created_by'  => $creator->id,
            ]);

            $lineNo = 1;
            foreach ($lines as $line) {
                JournalLine::create([
                    'journal_entry_id' => $je->id,
                    'line_no'          => $lineNo++,
                    'gl_account_id'    => $line['gl_account_id'],
                    'debit_amount'     => $line['line_total'] + $line['tax_amount'],
                    'credit_amount'    => 0,
                    'narration'        => $line['description'],
                ]);
            }
            JournalLine::create([
                'journal_entry_id' => $je->id,
                'line_no'          => $lineNo,
                'gl_account_id'    => $apGl->id,
                'debit_amount'     => 0,
                'credit_amount'    => $total,
                'narration'        => 'Accounts Payable',
            ]);

            $this->journal->post($je->fresh('lines.glAccount'));
            $invoice->accrual_journal_entry_id = $je->id;
            $invoice->save();

            VendorInvoiceCreated::dispatch($invoice->fresh(['lines', 'accrualJournalEntry']));

            return $invoice->fresh(['lines', 'accrualJournalEntry']);
        });
    }

    public function submit(VendorInvoice $invoice): VendorInvoice
    {
        if ($invoice->status !== VendorInvoiceStatus::Draft) {
            throw new DomainException("Invoice {$invoice->reference} is not in draft.");
        }
        $invoice->status = VendorInvoiceStatus::PendingApproval;
        $invoice->save();
        return $invoice;
    }

    public function approve(VendorInvoice $invoice, User $approver): VendorInvoice
    {
        if ($invoice->status !== VendorInvoiceStatus::PendingApproval) {
            throw new DomainException("Invoice {$invoice->reference} is not pending approval.");
        }
        if ($approver->id === $invoice->created_by) {
            throw new DomainException('Invoice creator cannot self-approve.');
        }
        $invoice->status      = VendorInvoiceStatus::Approved;
        $invoice->approved_by = $approver->id;
        $invoice->approved_at = now();
        $invoice->save();
        return $invoice;
    }

    public function cancel(VendorInvoice $invoice, User $by, string $reason): VendorInvoice
    {
        if ($invoice->status === VendorInvoiceStatus::Cancelled) {
            return $invoice;
        }
        if ($invoice->allocations()->exists()) {
            throw new DomainException(
                "Cannot cancel invoice {$invoice->reference}: it has allocated payments. Void the payments first."
            );
        }

        return DB::transaction(function () use ($invoice, $by, $reason) {
            if ($invoice->accrualJournalEntry && $invoice->accrualJournalEntry->status->value === 'posted') {
                $this->journal->reverse($invoice->accrualJournalEntry, $by, "Cancel: {$reason}");
            }
            $invoice->status       = VendorInvoiceStatus::Cancelled;
            $invoice->cancelled_by = $by->id;
            $invoice->cancelled_at = now();
            $invoice->save();

            return $invoice->fresh();
        });
    }

    private function resolveApGl(Vendor $vendor): GlAccount
    {
        if ($vendor->default_ap_gl_account_id) {
            return GlAccount::findOrFail($vendor->default_ap_gl_account_id);
        }
        $fallback = GlAccount::where('code', '2100')->first();
        if (! $fallback) {
            throw new DomainException('Default AP GL code 2100 is missing. Run ChartOfAccountsSeeder.');
        }
        return $fallback;
    }

    private function assertExpenseGl(int $glId): void
    {
        $gl = GlAccount::findOrFail($glId);
        if ($gl->type !== GlAccountType::Expense) {
            throw new DomainException("GL account {$gl->code} is not an expense account (line gl_account must be type=expense).");
        }
    }

    private function nextReference(): string
    {
        $year = now()->format('Y');
        return sprintf('API-%s-%04d', $year, $this->sequences->next("ap_invoice:{$year}"));
    }

    private function nextJournalReference(): string
    {
        $year = now()->format('Y');
        return sprintf('JE-%s-%06d', $year, $this->sequences->next("journal:{$year}"));
    }
}
