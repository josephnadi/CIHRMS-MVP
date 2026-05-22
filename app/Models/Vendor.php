<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VendorStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vendor extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'vendors';

    protected $fillable = [
        'code', 'name', 'tax_id', 'status', 'email', 'phone', 'address',
        'default_expense_gl_account_id', 'default_ap_gl_account_id', 'default_bank_account_id',
        'notes',
    ];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['status' => VendorStatus::class];
    }

    public function defaultExpenseGl(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'default_expense_gl_account_id');
    }

    public function defaultApGl(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'default_ap_gl_account_id');
    }

    public function defaultBankAccount(): BelongsTo
    {
        return $this->belongsTo(OrgBankAccount::class, 'default_bank_account_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(VendorInvoice::class, 'vendor_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ApPayment::class, 'vendor_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', VendorStatus::Active->value);
    }
}
