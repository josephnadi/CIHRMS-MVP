<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrgBankAccountPurpose;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrgBankAccount extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'org_bank_accounts';

    protected $fillable = [
        'gl_account_id', 'bank_name', 'branch', 'account_name', 'account_number',
        'sort_code', 'swift', 'currency', 'purpose', 'opening_balance', 'is_active', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'purpose'         => OrgBankAccountPurpose::class,
            'opening_balance' => 'decimal:2',
            'is_active'       => 'bool',
        ];
    }

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'gl_account_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeForPurpose(Builder $q, OrgBankAccountPurpose|string $purpose): Builder
    {
        return $q->where('purpose', $purpose instanceof OrgBankAccountPurpose ? $purpose->value : $purpose);
    }
}
