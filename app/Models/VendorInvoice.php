<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VendorInvoiceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class VendorInvoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'vendor_invoices';

    protected $fillable = [
        'reference', 'vendor_id', 'vendor_invoice_no', 'status',
        'invoice_date', 'due_date', 'subtotal', 'tax_amount', 'total', 'amount_paid',
        'currency', 'ap_gl_account_id', 'notes', 'accrual_journal_entry_id',
        'created_by', 'approved_by', 'approved_at', 'cancelled_by', 'cancelled_at',
    ];

    protected $attributes = ['amount_paid' => 0, 'currency' => 'GHS'];

    protected function casts(): array
    {
        return [
            'status'       => VendorInvoiceStatus::class,
            'invoice_date' => 'date',
            'due_date'     => 'date',
            'subtotal'     => 'decimal:2',
            'tax_amount'   => 'decimal:2',
            'total'        => 'decimal:2',
            'amount_paid'  => 'decimal:2',
            'approved_at'  => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(VendorInvoiceLine::class, 'vendor_invoice_id')->orderBy('line_no');
    }

    public function apGlAccount(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'ap_gl_account_id');
    }

    public function accrualJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'accrual_journal_entry_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ApPaymentInvoiceAllocation::class, 'vendor_invoice_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', [
            VendorInvoiceStatus::Approved->value,
            VendorInvoiceStatus::PartiallyPaid->value,
        ]);
    }

    public function outstandingAmount(): float
    {
        return (float) $this->total - (float) $this->amount_paid;
    }
}
