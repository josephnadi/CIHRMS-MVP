<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApPaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApPayment extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'ap_payments';

    protected $fillable = [
        'reference', 'vendor_id', 'status', 'payment_date', 'amount', 'currency',
        'org_bank_account_id', 'narration', 'journal_entry_id', 'disbursement_id',
        'created_by', 'processed_by', 'processed_at', 'voided_by', 'voided_at',
    ];

    protected $attributes = ['currency' => 'GHS', 'status' => 'pending'];

    protected function casts(): array
    {
        return [
            'status'       => ApPaymentStatus::class,
            'payment_date' => 'date',
            'amount'       => 'decimal:2',
            'processed_at' => 'datetime',
            'voided_at'    => 'datetime',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(OrgBankAccount::class, 'org_bank_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ApPaymentInvoiceAllocation::class, 'ap_payment_id');
    }

    public function scopeProcessed(Builder $q): Builder
    {
        return $q->where('status', ApPaymentStatus::Processed->value);
    }
}
