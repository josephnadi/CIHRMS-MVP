<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhistleblowerSubject extends Model
{
    protected $fillable = ['report_id', 'subject_label', 'linked_employee_id', 'role_context'];

    protected function casts(): array
    {
        return [
            'subject_label' => 'encrypted',
            'role_context'  => 'encrypted',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(WhistleblowerReport::class, 'report_id');
    }

    public function linkedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'linked_employee_id');
    }
}
