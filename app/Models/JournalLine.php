<?php

declare(strict_types=1);

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalLine extends Model
{
    public $timestamps = false;
    protected $table = 'journal_lines';

    protected $fillable = [
        'journal_entry_id', 'line_no', 'gl_account_id', 'debit_amount', 'credit_amount', 'narration',
    ];

    protected function casts(): array
    {
        return [
            'debit_amount'  => 'decimal:2',
            'credit_amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $line) {
            $dr = (float) $line->debit_amount;
            $cr = (float) $line->credit_amount;

            if ($dr > 0 && $cr > 0) {
                throw new DomainException('A journal line must hold either debit or credit (not both).');
            }
            if ($dr <= 0 && $cr <= 0) {
                throw new DomainException('A journal line must have a positive debit or credit amount.');
            }
        });
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'gl_account_id');
    }
}
