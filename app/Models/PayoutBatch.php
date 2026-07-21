<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PayoutBatchStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayoutBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference', 'source_type', 'source_id', 'status',
        'total_amount', 'currency', 'requires_high_approval',
        'created_by', 'released_by', 'approved_by', 'released_at',
    ];

    protected function casts(): array
    {
        return [
            'status'                 => PayoutBatchStatus::class,
            'total_amount'           => 'decimal:2',
            'requires_high_approval' => 'boolean',
            'released_at'            => 'datetime',
        ];
    }

    public function disbursements(): HasMany
    {
        return $this->hasMany(Disbursement::class);
    }

    public function maker(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
