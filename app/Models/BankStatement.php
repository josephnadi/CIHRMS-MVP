<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankStatement extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'bank_statements';

    protected $fillable = [
        'org_bank_account_id', 'statement_date', 'period_start',
        'opening_balance', 'closing_balance', 'currency',
        'file_hash', 'file_name', 'format', 'imported_by',
    ];

    protected $attributes = ['currency' => 'GHS'];

    protected function casts(): array
    {
        return [
            'statement_date'   => 'date',
            'period_start'     => 'date',
            'opening_balance'  => 'decimal:2',
            'closing_balance'  => 'decimal:2',
        ];
    }

    public function orgBankAccount(): BelongsTo
    {
        return $this->belongsTo(OrgBankAccount::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class);
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function scopeForBankAccount(Builder $q, int $bankAccountId): Builder
    {
        return $q->where('org_bank_account_id', $bankAccountId);
    }
}
