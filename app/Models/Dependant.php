<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DependantRelationship;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Dependant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'employee_id', 'full_name', 'relationship',
        'date_of_birth', 'national_id', 'gender', 'is_covered',
    ];

    protected function casts(): array
    {
        return [
            'relationship'  => DependantRelationship::class,
            'date_of_birth' => 'date',
            'is_covered'    => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
