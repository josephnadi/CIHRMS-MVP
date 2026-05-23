<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ArInvoiceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ArInvoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'ar_invoices';

    protected $fillable = [
        'reference', 'customer_id', 'customer_invoice_no', 'status',
        'invoice_date', 'due_date',
        'subtotal', 'tax_amount', 'total', 'amount_received',
        'currency', 'ar_gl_account_id', 'notes',
        'accrual_journal_entry_id', 'write_off_journal_entry_id',
        'created_by', 'approved_by', 'approved_at',
        'cancelled_by', 'cancelled_at',
        'written_off_by', 'written_off_at', 'written_off_reason',
    ];

    protected $attributes = ['amount_received' => 0, 'currency' => 'GHS'];

    protected function casts(): array
    {
        return [
            'status'          => ArInvoiceStatus::class,
            'invoice_date'    => 'date',
            'due_date'        => 'date',
            'subtotal'        => 'decimal:2',
            'tax_amount'      => 'decimal:2',
            'total'           => 'decimal:2',
            'amount_received' => 'decimal:2',
            'approved_at'     => 'datetime',
            'cancelled_at'    => 'datetime',
            'written_off_at'  => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ArInvoiceLine::class, 'ar_invoice_id')->orderBy('line_no');
    }

    public function arGlAccount(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'ar_gl_account_id');
    }

    public function accrualJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'accrual_journal_entry_id');
    }

    public function writeOffJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'write_off_journal_entry_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ArReceiptInvoiceAllocation::class, 'ar_invoice_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Open == Approved or PartiallyPaid (eligible for receipts). */
    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', [
            ArInvoiceStatus::Approved->value,
            ArInvoiceStatus::PartiallyPaid->value,
        ]);
    }

    /** Eligible for write-off — same set as scopeOpen() at present. */
    public function scopeWriteable(Builder $q): Builder
    {
        return $q->whereIn('status', [
            ArInvoiceStatus::Approved->value,
            ArInvoiceStatus::PartiallyPaid->value,
        ]);
    }

    public function outstandingAmount(): float
    {
        return (float) $this->total - (float) $this->amount_received;
    }
}
