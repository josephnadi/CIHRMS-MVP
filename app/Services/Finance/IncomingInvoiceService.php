<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\IncomingInvoiceStatus;
use App\Events\IncomingInvoiceSubmitted;
use App\Models\IncomingInvoice;
use App\Models\IncomingInvoiceEvent;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class IncomingInvoiceService
{
    public function __construct(private readonly SequenceService $sequences)
    {
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
