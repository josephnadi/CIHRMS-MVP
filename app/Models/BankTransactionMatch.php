<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankTransactionMatch extends Model
{
    public $timestamps = false;

    protected $table = 'bank_transaction_matches';

    protected $fillable = [
        'bank_statement_line_id', 'matched_type', 'matched_id',
        'confidence', 'matched_by', 'matched_at',
        'unmatched_at', 'unmatched_by', 'unmatched_reason',
    ];

    protected function casts(): array
    {
        return [
            'matched_at'   => 'datetime',
            'unmatched_at' => 'datetime',
        ];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(BankStatementLine::class, 'bank_statement_line_id');
    }

    public function matcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }

    public function unmatcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unmatched_by');
    }
}
