<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\JournalEntryStatus;
use App\Enums\JournalSourceType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class JournalEntry extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'journal_entries';

    protected $fillable = [
        'reference', 'entry_date', 'narration', 'status', 'source_type', 'source_id',
        'posted_at', 'posted_by', 'reversed_at', 'reversed_by', 'reversal_of_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status'      => JournalEntryStatus::class,
            'source_type' => JournalSourceType::class,
            'entry_date'  => 'date',
            'posted_at'   => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'journal_entry_id')->orderBy('line_no');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function scopePosted(Builder $q): Builder
    {
        return $q->where('status', JournalEntryStatus::Posted->value);
    }

    public function isBalanced(): bool
    {
        $totals = $this->lines->reduce(function (array $acc, JournalLine $l) {
            $acc['dr'] += (float) $l->debit_amount;
            $acc['cr'] += (float) $l->credit_amount;
            return $acc;
        }, ['dr' => 0.0, 'cr' => 0.0]);

        return abs($totals['dr'] - $totals['cr']) < 0.005;
    }
}
