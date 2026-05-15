<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstablishmentCeiling extends Model
{
    protected $fillable = [
        'department_id', 'grade_id', 'fiscal_year',
        'approved_headcount', 'approval_reference',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_year'        => 'integer',
            'approved_headcount' => 'integer',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }
}
