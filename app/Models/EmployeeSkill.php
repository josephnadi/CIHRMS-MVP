<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeSkill extends Model
{
    protected $fillable = ['employee_id', 'name', 'level', 'expires_at'];

    protected $casts = [
        'expires_at' => 'date',
    ];

    public const LEVELS = ['beginner', 'intermediate', 'advanced', 'expert'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
