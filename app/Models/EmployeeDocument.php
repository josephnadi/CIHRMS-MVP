<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDocument extends Model
{
    use HasFactory;

    protected $fillable = ['employee_id', 'title', 'file_path', 'mime_type'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
