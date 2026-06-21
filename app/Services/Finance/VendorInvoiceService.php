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
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorInvoice;
use App\Models\VendorInvoiceLine;
use App\Services\Finance\Posting\PostingDocument;
use App\Services\Finance\Posting\PostingLine;
use App\Services\Finance\PostingService;
use DomainException;
use Illuminate\Support\Facades\DB;

class VendorInvoiceService
{
    public function __construct(
        private readonly JournalPostingService $journal,
        private readonly SequenceService $sequences,
        private readonly PostingService $posting,
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

            $postingLines = [];
            foreach ($lines as $line) {
                $postingLines[] = PostingLine::debit(
                    amount: (float) $line['line_total'] + (float) $line['tax_amount'],
                    accountId: (int) $line['gl_account_id'],
                    narration: $line['description'] ?? null,
                );
            }
            $postingLines[] = PostingLine::credit(
                amount: (float) $total,
                accountId: (int) $apGl->id,
                narration: 'Accounts Payable',
            );

            $je = $this->posting->post(new PostingDocument(
                sourceType: JournalSourceType::VendorInvoice,
                sourceId: $invoice->id,
                purpose: '',
                date: $invoice->invoice_date->format('Y-m-d'),
                narration: "Accrual: {$vendor->code} invoice " . ($invoice->vendor_invoice_no ?? $invoice->reference),
                lines: $postingLines,
            ), $creator);

            $invoice->accrual_journal_entry_id = $je->id;
            $invoice->save();

            VendorInvoiceCreated::dispatch($invoice->fresh(['lines', 'accrualJournalEntry']));

            return $invoice->fresh(['lines', 'accrualJournalEntry']);
        });
    }

    /**
     * Edit a DRAFT vendor invoice. The accrual posts on create, so a draft is
     * already on the GL — reverse the old accrual, replace the lines, and post a
     * fresh accrual under a versioned source_purpose (the original keeps the ''
     * purpose on the idempotency index). Locked once it leaves draft.
     */
    public function update(VendorInvoice $invoice, array $data, User $editor): VendorInvoice
    {
        if ($invoice->status !== VendorInvoiceStatus::Draft) {
            throw new DomainException("Only draft invoices can be edited; {$invoice->reference} is {$invoice->status->value}.");
        }
        if (empty($data['lines'])) {
            throw new DomainException('Invoice must have at least one line.');
        }

        return DB::transaction(function () use ($invoice, $data, $editor) {
            if ($invoice->accrualJournalEntry && $invoice->accrualJournalEntry->status === JournalEntryStatus::Posted) {
                $this->journal->reverse($invoice->accrualJournalEntry, $editor, "Edit: {$invoice->reference}");
            }

            $vendor = Vendor::findOrFail($data['vendor_id'] ?? $invoice->vendor_id);
            $apGl   = $this->resolveApGl($vendor);

            $lines     = $this->buildLines($data['lines']);
            $subtotal  = $lines->sum('line_total');
            $taxAmount = $lines->sum('tax_amount');
            $total     = $subtotal + $taxAmount;

            $invoice->lines()->delete();
            foreach ($lines as $line) {
                VendorInvoiceLine::create(array_merge($line, ['vendor_invoice_id' => $invoice->id]));
            }

            $invoice->update([
                'vendor_id'         => $vendor->id,
                'vendor_invoice_no' => $data['vendor_invoice_no'] ?? null,
                'invoice_date'      => $data['invoice_date'] ?? $invoice->invoice_date,
                'due_date'          => $data['due_date'] ?? null,
                'subtotal'          => $subtotal,
                'tax_amount'        => $taxAmount,
                'total'             => $total,
                'currency'          => $data['currency'] ?? $invoice->currency,
                'ap_gl_account_id'  => $apGl->id,
                'notes'             => $data['notes'] ?? null,
            ]);

            $priorAccruals = JournalEntry::where('source_type', JournalSourceType::VendorInvoice->value)
                ->where('source_id', $invoice->id)
                ->count();

            $postingLines = [];
            foreach ($lines as $line) {
                $postingLines[] = PostingLine::debit(
                    amount: (float) $line['line_total'] + (float) $line['tax_amount'],
                    accountId: (int) $line['gl_account_id'],
                    narration: $line['description'] ?? null,
                );
            }
            $postingLines[] = PostingLine::credit(amount: (float) $total, accountId: (int) $apGl->id, narration: 'Accounts Payable');

            $je = $this->posting->post(new PostingDocument(
                sourceType: JournalSourceType::VendorInvoice,
                sourceId: $invoice->id,
                purpose: "reissue-{$priorAccruals}",
                date: $invoice->invoice_date->format('Y-m-d'),
                narration: "Accrual (edit): {$vendor->code} invoice " . ($invoice->vendor_invoice_no ?? $invoice->reference),
                lines: $postingLines,
            ), $editor);

            $invoice->accrual_journal_entry_id = $je->id;
            $invoice->save();

            return $invoice->fresh(['lines', 'accrualJournalEntry']);
        });
    }

    /**
     * Delete a DRAFT vendor invoice: reverse its accrual and soft-delete.
     * Approved+ invoices use cancel, never delete.
     */
    public function delete(VendorInvoice $invoice, User $by): void
    {
        if ($invoice->status !== VendorInvoiceStatus::Draft) {
            throw new DomainException("Only draft invoices can be deleted; {$invoice->reference} is {$invoice->status->value}.");
        }
        if ($invoice->allocations()->exists()) {
            throw new DomainException("Cannot delete invoice {$invoice->reference}: it has allocated payments.");
        }

        DB::transaction(function () use ($invoice, $by) {
            if ($invoice->accrualJournalEntry && $invoice->accrualJournalEntry->status === JournalEntryStatus::Posted) {
                $this->journal->reverse($invoice->accrualJournalEntry, $by, "Delete draft: {$invoice->reference}");
            }
            $invoice->delete();
        });
    }

    /** Shared line normaliser used by create() and update(). */
    private function buildLines(array $rawLines): \Illuminate\Support\Collection
    {
        return collect($rawLines)->values()->map(function ($l, $i) {
            $this->assertExpenseGl((int) $l['gl_account_id']);

            $qty       = (float) ($l['quantity'] ?? 1);
            $unit      = (float) ($l['unit_price'] ?? 0);
            $taxRate   = (float) ($l['tax_rate'] ?? 0);
            $lineTotal = round($qty * $unit, 2);

            return [
                'line_no'       => $i + 1,
                'description'   => $l['description'] ?? '',
                'quantity'      => $qty,
                'unit_price'    => $unit,
                'line_total'    => $lineTotal,
                'tax_rate'      => $taxRate,
                'tax_amount'    => round($lineTotal * $taxRate, 2),
                'gl_account_id' => (int) $l['gl_account_id'],
            ];
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
}
