<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\ArInvoiceStatus;
use App\Enums\GlAccountType;
use App\Enums\JournalEntryStatus;
use App\Enums\JournalSourceType;
use App\Models\ArInvoice;
use App\Models\ArInvoiceLine;
use App\Models\Customer;
use App\Models\GlAccount;
use App\Models\User;
use App\Services\Finance\PostingService;
use App\Services\Finance\Posting\PostingDocument;
use App\Services\Finance\Posting\PostingLine;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * AR invoice lifecycle. Mirrors VendorInvoiceService on the receivables side.
 * Accrual JE posts on create: Dr AR, Cr Income (per line). Cancellation
 * reverses the accrual. Write-off posts a separate bad-debt JE.
 */
class ArInvoiceService
{
    public function __construct(
        private readonly JournalPostingService $journal,
        private readonly SequenceService $sequences,
        private readonly PostingService $posting,
    ) {
    }

    public function create(array $data, User $creator): ArInvoice
    {
        if (empty($data['lines'])) {
            throw new DomainException('Invoice must have at least one line.');
        }

        return DB::transaction(function () use ($data, $creator) {
            $customer = Customer::findOrFail($data['customer_id']);
            $arGl     = $this->resolveArGl($customer);

            $lines = collect($data['lines'])->values()->map(function ($l, $i) {
                $this->assertIncomeGl((int) $l['gl_account_id']);

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

            $invoice = ArInvoice::create([
                'reference'           => $this->nextReference(),
                'customer_id'         => $customer->id,
                'customer_invoice_no' => $data['customer_invoice_no'] ?? null,
                'status'              => ArInvoiceStatus::Draft->value,
                'invoice_date'        => $data['invoice_date'],
                'due_date'            => $data['due_date'] ?? null,
                'subtotal'            => $subtotal,
                'tax_amount'          => $taxAmount,
                'total'               => $total,
                'amount_received'     => 0,
                'currency'            => $data['currency'] ?? 'GHS',
                'ar_gl_account_id'    => $arGl->id,
                'notes'               => $data['notes'] ?? null,
                'created_by'          => $creator->id,
            ]);

            foreach ($lines as $line) {
                ArInvoiceLine::create(array_merge($line, ['ar_invoice_id' => $invoice->id]));
            }

            $postingLines = [];
            $postingLines[] = PostingLine::debit(
                amount: (float) $total,
                accountId: (int) $arGl->id,
                narration: 'Accounts Receivable',
            );
            foreach ($lines as $line) {
                $postingLines[] = PostingLine::credit(
                    amount: (float) $line['line_total'] + (float) $line['tax_amount'],
                    accountId: (int) $line['gl_account_id'],
                    narration: $line['description'] ?? null,
                );
            }

            $je = $this->posting->post(new PostingDocument(
                sourceType: JournalSourceType::ArInvoice,
                sourceId: $invoice->id,
                purpose: '',
                date: $invoice->invoice_date->format('Y-m-d'),
                narration: "Accrual: {$customer->code} invoice " . ($invoice->customer_invoice_no ?? $invoice->reference),
                lines: $postingLines,
            ), $creator);

            $invoice->accrual_journal_entry_id = $je->id;
            $invoice->save();

            return $invoice->fresh(['lines', 'accrualJournalEntry']);
        });
    }

    public function submit(ArInvoice $invoice): ArInvoice
    {
        if ($invoice->status !== ArInvoiceStatus::Draft) {
            throw new DomainException("Invoice {$invoice->reference} is not in draft.");
        }
        $invoice->status = ArInvoiceStatus::PendingApproval;
        $invoice->save();
        return $invoice;
    }

    public function approve(ArInvoice $invoice, User $approver): ArInvoice
    {
        if ($invoice->status !== ArInvoiceStatus::PendingApproval) {
            throw new DomainException("Invoice {$invoice->reference} is not pending approval.");
        }
        if ($approver->id === $invoice->created_by) {
            throw new DomainException('Invoice creator cannot self-approve.');
        }
        $invoice->status      = ArInvoiceStatus::Approved;
        $invoice->approved_by = $approver->id;
        $invoice->approved_at = now();
        $invoice->save();
        return $invoice;
    }

    public function cancel(ArInvoice $invoice, User $by, string $reason): ArInvoice
    {
        if ($invoice->status === ArInvoiceStatus::Cancelled) {
            return $invoice;
        }
        if ($invoice->allocations()->exists()) {
            throw new DomainException(
                "Cannot cancel invoice {$invoice->reference}: it has allocated receipts. Void the receipts first."
            );
        }

        return DB::transaction(function () use ($invoice, $by, $reason) {
            if ($invoice->accrualJournalEntry && $invoice->accrualJournalEntry->status === JournalEntryStatus::Posted) {
                $this->journal->reverse($invoice->accrualJournalEntry, $by, "Cancel: {$reason}");
            }
            $invoice->status       = ArInvoiceStatus::Cancelled;
            $invoice->cancelled_by = $by->id;
            $invoice->cancelled_at = now();
            $invoice->save();

            return $invoice->fresh();
        });
    }

    /**
     * Write off the invoice's OUTSTANDING amount as bad debt.
     * Allowed only on Approved or PartiallyPaid invoices — paid invoices have
     * nothing to write off, draft/cancelled have nothing accrued. Posts:
     *   Dr Bad Debt Expense (code 5600), Cr AR GL (invoice.ar_gl_account_id).
     * Status flips to WrittenOff. amount_received stays as-is — operators
     * inspecting a WrittenOff invoice see total / received / written_off separately.
     */
    public function writeOff(ArInvoice $invoice, User $by, string $reason): ArInvoice
    {
        if (! in_array($invoice->status, [ArInvoiceStatus::Approved, ArInvoiceStatus::PartiallyPaid], true)) {
            throw new DomainException(
                "Invoice {$invoice->reference} status is {$invoice->status->value}; only Approved or PartiallyPaid can be written off."
            );
        }

        $outstanding = round($invoice->outstandingAmount(), 2);
        if ($outstanding <= 0.005) {
            throw new DomainException(
                "Invoice {$invoice->reference} has no outstanding balance to write off."
            );
        }

        $badDebtGl = GlAccount::where('code', '5600')->first();
        if (! $badDebtGl) {
            throw new DomainException('Bad Debt Expense GL (code 5600) missing. Run ChartOfAccountsSeeder.');
        }

        return DB::transaction(function () use ($invoice, $by, $reason, $outstanding, $badDebtGl) {
            $je = $this->posting->post(new PostingDocument(
                sourceType: JournalSourceType::ArInvoice,
                sourceId: $invoice->id,
                purpose: 'write_off',
                date: now()->format('Y-m-d'),
                narration: "Write-off: {$invoice->reference} — {$reason}",
                lines: [
                    PostingLine::debit(amount: (float) $outstanding, accountId: (int) $badDebtGl->id, narration: "Bad debt: {$invoice->reference}"),
                    PostingLine::credit(amount: (float) $outstanding, accountId: (int) $invoice->ar_gl_account_id, narration: "Clear AR for {$invoice->reference}"),
                ],
            ), $by);

            $invoice->write_off_journal_entry_id = $je->id;
            $invoice->status              = ArInvoiceStatus::WrittenOff;
            $invoice->written_off_by      = $by->id;
            $invoice->written_off_at      = now();
            $invoice->written_off_reason  = $reason;
            $invoice->save();

            return $invoice->fresh();
        });
    }

    private function resolveArGl(Customer $customer): GlAccount
    {
        if ($customer->default_ar_gl_account_id) {
            return GlAccount::findOrFail($customer->default_ar_gl_account_id);
        }
        $fallback = GlAccount::where('code', '1200')->first();
        if (! $fallback) {
            throw new DomainException('Default AR GL code 1200 is missing. Run ChartOfAccountsSeeder.');
        }
        return $fallback;
    }

    private function assertIncomeGl(int $glId): void
    {
        $gl = GlAccount::findOrFail($glId);
        if ($gl->type !== GlAccountType::Income) {
            throw new DomainException("GL account {$gl->code} is not an income account (line gl_account must be type=income).");
        }
    }

    private function nextReference(): string
    {
        $year = now()->format('Y');
        return sprintf('ARI-%s-%04d', $year, $this->sequences->next("ar_invoice:{$year}"));
    }
}
