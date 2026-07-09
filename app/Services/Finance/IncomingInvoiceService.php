<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\IncomingInvoiceStatus;
use App\Events\IncomingInvoiceApproved;
use App\Events\IncomingInvoicePosted;
use App\Events\IncomingInvoiceReturned;
use App\Events\IncomingInvoiceSubmitted;
use App\Events\IncomingInvoiceVetted;
use App\Models\IncomingInvoice;
use App\Models\IncomingInvoiceEvent;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class IncomingInvoiceService
{
    public function __construct(
        private readonly SequenceService $sequences,
        private readonly VendorInvoiceService $vendorInvoices,
    ) {
    }

    public function create(array $data, User $creator): IncomingInvoice
    {
        return DB::transaction(function () use ($data, $creator) {
            $inv = IncomingInvoice::create([
                'reference'         => $this->nextReference(),
                'status'            => IncomingInvoiceStatus::Draft->value,
                'department_id'     => $creator->employee?->department_id,
                'vendor_name'       => $data['vendor_name'],
                'vendor_invoice_no' => $data['vendor_invoice_no'] ?? null,
                'invoice_date'      => $data['invoice_date'],
                'currency'          => $data['currency'] ?? 'GHS',
                'amount'            => $data['amount'],
                'description'       => $data['description'] ?? null,
                'created_by'        => $creator->id,
            ]);

            foreach ($data['attachments'] ?? [] as $a) {
                $inv->attachments()->create([
                    'path'          => $a['path'],
                    'original_name' => $a['original_name'],
                    'mime'          => $a['mime'] ?? null,
                    'size'          => $a['size'] ?? 0,
                    'uploaded_by'   => $creator->id,
                ]);
            }

            $this->recordEvent($inv, $creator, 'created', null, IncomingInvoiceStatus::Draft->value);

            return $inv->fresh(['attachments', 'events']);
        });
    }

    public function update(IncomingInvoice $inv, array $data, User $actor): IncomingInvoice
    {
        if (! in_array($inv->status, [IncomingInvoiceStatus::Draft, IncomingInvoiceStatus::Returned], true)) {
            throw new DomainException('Only draft or returned invoices can be edited.');
        }

        $inv->update([
            'vendor_name'       => $data['vendor_name'],
            'vendor_invoice_no' => $data['vendor_invoice_no'] ?? null,
            'invoice_date'      => $data['invoice_date'],
            'currency'          => $data['currency'] ?? $inv->currency,
            'amount'            => $data['amount'],
            'description'       => $data['description'] ?? null,
        ]);

        foreach ($data['attachments'] ?? [] as $a) {
            $inv->attachments()->create([
                'path'          => $a['path'],
                'original_name' => $a['original_name'],
                'mime'          => $a['mime'] ?? null,
                'size'          => $a['size'] ?? 0,
                'uploaded_by'   => $actor->id,
            ]);
        }

        return $inv->fresh(['attachments', 'events']);
    }

    public function submit(IncomingInvoice $inv, User $actor): IncomingInvoice
    {
        if (! in_array($inv->status, [IncomingInvoiceStatus::Draft, IncomingInvoiceStatus::Returned], true)) {
            throw new DomainException('Only draft or returned invoices can be submitted.');
        }

        $from = $inv->status->value;
        $inv->update([
            'status'       => IncomingInvoiceStatus::Submitted->value,
            'submitted_by' => $actor->id,
            'submitted_at' => now(),
        ]);

        $this->recordEvent($inv, $actor, 'submitted', $from, IncomingInvoiceStatus::Submitted->value);
        IncomingInvoiceSubmitted::dispatch($inv->fresh());

        return $inv->fresh();
    }

    public function vetAccept(IncomingInvoice $inv, User $auditor, ?string $notes = null): IncomingInvoice
    {
        if ($inv->status !== IncomingInvoiceStatus::Submitted) {
            throw new DomainException('Only submitted invoices can be vetted.');
        }
        if ($auditor->id === $inv->created_by) {
            throw new DomainException('Dual-control violation: the submitter cannot vet their own invoice.');
        }

        $inv->update([
            'status'        => IncomingInvoiceStatus::Vetted->value,
            'vetted_by'     => $auditor->id,
            'vetted_at'     => now(),
            'vetting_notes' => $notes,
        ]);

        $this->recordEvent($inv, $auditor, 'vetted', IncomingInvoiceStatus::Submitted->value, IncomingInvoiceStatus::Vetted->value, $notes);
        IncomingInvoiceVetted::dispatch($inv->fresh());

        return $inv->fresh();
    }

    public function vetReturn(IncomingInvoice $inv, User $auditor, string $reason): IncomingInvoice
    {
        if ($inv->status !== IncomingInvoiceStatus::Submitted) {
            throw new DomainException('Only submitted invoices can be returned by the auditor.');
        }

        return $this->markReturned($inv, $auditor, $reason, IncomingInvoiceStatus::Submitted->value);
    }

    public function ceoApprove(IncomingInvoice $inv, User $ceo): IncomingInvoice
    {
        if ($inv->status !== IncomingInvoiceStatus::Vetted) {
            throw new DomainException('Only vetted invoices can be approved.');
        }

        $inv->update([
            'status'      => IncomingInvoiceStatus::Approved->value,
            'approved_by' => $ceo->id,
            'approved_at' => now(),
        ]);

        $this->recordEvent($inv, $ceo, 'approved', IncomingInvoiceStatus::Vetted->value, IncomingInvoiceStatus::Approved->value);
        IncomingInvoiceApproved::dispatch($inv->fresh());

        return $inv->fresh();
    }

    public function ceoReturn(IncomingInvoice $inv, User $ceo, string $reason): IncomingInvoice
    {
        if ($inv->status !== IncomingInvoiceStatus::Vetted) {
            throw new DomainException('Only vetted invoices can be returned by the CEO.');
        }

        return $this->markReturned($inv, $ceo, $reason, IncomingInvoiceStatus::Vetted->value);
    }

    public function post(IncomingInvoice $inv, array $data, User $poster): IncomingInvoice
    {
        if ($inv->status !== IncomingInvoiceStatus::Approved) {
            throw new DomainException('Only CEO-approved invoices can be posted.');
        }

        // Reconcile the Finance-coded line total against the face amount the CEO
        // approved: the posted GL accrual must equal what was signed off, so a
        // mis-keyed line can't inflate the accrual past the approved value.
        // Tolerance absorbs per-line rounding only.
        $postedTotal = collect($data['lines'])->reduce(function (float $carry, array $line): float {
            $lineTotal = round((float) ($line['quantity'] ?? 1) * (float) ($line['unit_price'] ?? 0), 2);
            $tax       = round($lineTotal * (float) ($line['tax_rate'] ?? 0), 2);
            return $carry + $lineTotal + $tax;
        }, 0.0);

        if (abs($postedTotal - (float) $inv->amount) > 0.01) {
            throw new DomainException(sprintf(
                'Posted line total (%.2f) does not match the CEO-approved amount (%.2f).',
                $postedTotal,
                (float) $inv->amount,
            ));
        }

        return DB::transaction(function () use ($inv, $data, $poster) {
            $vendorInvoice = $this->vendorInvoices->create([
                'vendor_id'         => $data['vendor_id'],
                'vendor_invoice_no' => $inv->vendor_invoice_no,
                'invoice_date'      => $inv->invoice_date->format('Y-m-d'),
                'currency'          => $inv->currency,
                'notes'             => 'Promoted from incoming invoice ' . $inv->reference,
                'lines'             => $data['lines'],
            ], $poster);

            $inv->update([
                'status'            => IncomingInvoiceStatus::Posted->value,
                'posted_by'         => $poster->id,
                'posted_at'         => now(),
                'vendor_invoice_id' => $vendorInvoice->id,
            ]);

            $this->recordEvent($inv, $poster, 'posted', IncomingInvoiceStatus::Approved->value, IncomingInvoiceStatus::Posted->value, "VendorInvoice #{$vendorInvoice->id}");
            IncomingInvoicePosted::dispatch($inv->fresh());

            return $inv->fresh();
        });
    }

    protected function markReturned(IncomingInvoice $inv, User $actor, string $reason, string $from): IncomingInvoice
    {
        $inv->update([
            'status'        => IncomingInvoiceStatus::Returned->value,
            'returned_by'   => $actor->id,
            'returned_at'   => now(),
            'return_reason' => $reason,
        ]);

        $this->recordEvent($inv, $actor, 'returned', $from, IncomingInvoiceStatus::Returned->value, $reason);
        IncomingInvoiceReturned::dispatch($inv->fresh());

        return $inv->fresh();
    }

    protected function recordEvent(IncomingInvoice $inv, ?User $actor, string $action, ?string $from, ?string $to, ?string $comment = null): void
    {
        IncomingInvoiceEvent::create([
            'incoming_invoice_id' => $inv->id,
            'actor_id'            => $actor?->id,
            'action'              => $action,
            'from_status'         => $from,
            'to_status'           => $to,
            'comment'             => $comment,
            'created_at'          => now(),
        ]);
    }

    protected function nextReference(): string
    {
        $n = $this->sequences->next('incoming_invoice');
        return 'INV-' . now()->format('Y') . '-' . str_pad((string) $n, 5, '0', STR_PAD_LEFT);
    }
}
