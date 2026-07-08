<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalCollection extends Model
{
    public const STATUS_POSTED   = 'posted';
    public const STATUS_UNMAPPED = 'unmapped';
    public const STATUS_ERROR    = 'error';
    public const STATUS_FLAGGED  = 'flagged';

    protected $fillable = [
        'source', 'source_id', 'external_ref', 'external_user_id', 'member_id',
        'fee_code', 'amount', 'currency', 'paid_at', 'method', 'gateway_ref',
        'payload', 'status', 'status_note', 'journal_entry_id',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'amount' => 'decimal:2', 'paid_at' => 'datetime'];
    }

    public function member(): BelongsTo       { return $this->belongsTo(Member::class); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
}
