<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ArReceiptStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ArReceipt extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'ar_receipts';

    protected $fillable = [
        'reference', 'customer_id', 'status', 'receipt_date',
        'amount', 'currency', 'org_bank_account_id',
        'external_ref', 'narration', 'journal_entry_id',
        'created_by', 'processed_by', 'processed_at',
        'voided_by', 'voided_at',
    ];

    protected $attributes = ['status' => 'pending', 'currency' => 'GHS'];

    protected function casts(): array
    {
        return [
            'status'       => ArReceiptStatus::class,
            'receipt_date' => 'date',
            'amount'       => 'decimal:2',
            'processed_at' => 'datetime',
            'voided_at'    => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(OrgBankAccount::class, 'org_bank_account_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ArReceiptInvoiceAllocation::class, 'ar_receipt_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeProcessed(Builder $q): Builder
    {
        return $q->where('status', ArReceiptStatus::Processed->value);
    }
}
