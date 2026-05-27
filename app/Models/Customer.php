<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomerStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'customers';

    protected $fillable = [
        'code', 'name', 'tax_id', 'status', 'email', 'phone', 'address',
        'default_income_gl_account_id', 'default_ar_gl_account_id', 'default_bank_account_id',
        'notes',
    ];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['status' => CustomerStatus::class];
    }

    public function defaultIncomeGl(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'default_income_gl_account_id');
    }

    public function defaultArGl(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'default_ar_gl_account_id');
    }

    public function defaultBankAccount(): BelongsTo
    {
        return $this->belongsTo(OrgBankAccount::class, 'default_bank_account_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(ArInvoice::class, 'customer_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(ArReceipt::class, 'customer_id');
    }

    /**
     * Optional 1:1 link back to the CIHRM Member that owns this Customer.
     * Most commercial customers won't have a member row; institute members
     * will. Lets the portal and notification listeners resolve member-side
     * profile data without forcing AR to know about members. (M1 fix.)
     */
    public function member(): HasOne
    {
        return $this->hasOne(Member::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', CustomerStatus::Active->value);
    }
}
