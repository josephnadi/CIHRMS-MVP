<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Grade extends Model
{
    use SoftDeletes;

    protected $fillable = ['code', 'name', 'level', 'min_step', 'max_step', 'description'];

    public function steps(): HasMany
    {
        return $this->hasMany(GradeStep::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function baseSalaryFor(int $step, \DateTimeInterface|string $date): ?float
    {
        $date = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : $date;

        $row = $this->steps()
            ->where('step', $step)
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->orderByDesc('effective_from')
            ->first();

        return $row ? (float) $row->base_salary : null;
    }
}
