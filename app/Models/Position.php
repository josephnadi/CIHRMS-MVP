<?php

namespace App\Models;

use App\Enums\PositionStatus;
use App\Enums\FundingSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Position extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'title', 'grade_id', 'department_id', 'reports_to_position_id',
        'cost_center', 'funding_source', 'status', 'headcount_ceiling',
        'is_supervisory', 'job_description',
    ];

    protected function casts(): array
    {
        return [
            'status'           => PositionStatus::class,
            'funding_source'   => FundingSource::class,
            'is_supervisory'   => 'bool',
            'headcount_ceiling'=> 'integer',
        ];
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function reportsTo(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'reports_to_position_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Position::class, 'reports_to_position_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(PositionAssignment::class);
    }

    public function currentAssignment(): ?PositionAssignment
    {
        return $this->assignments()
            ->whereNull('end_date')
            ->latest('start_date')
            ->first();
    }

    public function scopeVacant(Builder $query): Builder
    {
        return $query->where('status', PositionStatus::Vacant->value);
    }

    public function scopeFilled(Builder $query): Builder
    {
        return $query->where('status', PositionStatus::Filled->value);
    }
}
