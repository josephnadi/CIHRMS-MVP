<?php

namespace App\Models;

use App\Enums\JobPostingStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class JobPosting extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['title', 'description', 'closes_at', 'status'];

    protected function casts(): array
    {
        return [
            'closes_at' => 'date',
            'status'    => JobPostingStatus::class,
        ];
    }

    public function applicants(): HasMany
    {
        return $this->hasMany(Applicant::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', JobPostingStatus::Open);
    }

    public function isExpired(): bool
    {
        return $this->closes_at && $this->closes_at->isPast();
    }
}
