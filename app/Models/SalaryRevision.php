<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryRevision extends Model
{
    protected $fillable = [
        'reference', 'scope', 'percentage', 'effective_from',
        'grade_overrides', 'affected_count', 'notes', 'applied_by',
    ];

    protected function casts(): array
    {
        return [
            'percentage'      => 'decimal:3',
            'effective_from'  => 'date',
            'grade_overrides' => 'array',
        ];
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }
}
