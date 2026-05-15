<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveBalance extends Model
{
    use HasFactory;

    protected $fillable = ['employee_id', 'type', 'year', 'total_days', 'used_days'];

    protected function casts(): array
    {
        return [
            'total_days' => 'decimal:1',
            'used_days'  => 'decimal:1',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function remainingDays(): float
    {
        return (float) $this->total_days - (float) $this->used_days;
    }
}
