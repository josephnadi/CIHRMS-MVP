<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BankStatementLine extends Model
{
    use HasFactory;

    protected $table = 'bank_statement_lines';

    protected $fillable = [
        'bank_statement_id', 'line_no', 'transaction_date', 'value_date',
        'description', 'reference', 'amount', 'running_balance', 'line_hash',
        'matched_type', 'matched_id', 'confidence', 'reconciled_at',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'value_date'       => 'date',
            'amount'           => 'decimal:2',
            'running_balance'  => 'decimal:2',
            'reconciled_at'    => 'datetime',
        ];
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function matched(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeUnreconciled(Builder $q): Builder
    {
        return $q->whereNull('reconciled_at');
    }

    public function scopeReconciled(Builder $q): Builder
    {
        return $q->whereNotNull('reconciled_at');
    }

    public function isDebit(): bool
    {
        return (float) $this->amount < 0;
    }

    public function isCredit(): bool
    {
        return (float) $this->amount > 0;
    }
}
