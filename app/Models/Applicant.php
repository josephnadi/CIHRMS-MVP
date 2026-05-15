<?php

namespace App\Models;

use App\Enums\ApplicantStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Applicant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'job_posting_id', 'name', 'email', 'cv_path', 'status',
        'esign_provider', 'esign_envelope_id', 'esign_status',
        'esign_sent_at', 'esign_completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status'              => ApplicantStatus::class,
            'esign_sent_at'       => 'datetime',
            'esign_completed_at'  => 'datetime',
        ];
    }

    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }

    public function scopeShortlisted(Builder $query): Builder
    {
        return $query->where('status', ApplicantStatus::Shortlisted);
    }
}
