<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OnboardingArea;
use App\Enums\OnboardingTaskStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingTask extends Model
{
    protected $fillable = [
        'onboarding_case_id', 'area', 'label', 'status', 'is_required',
        'responsible_user_id', 'completed_by', 'completed_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'area'         => OnboardingArea::class,
            'status'       => OnboardingTaskStatus::class,
            'is_required'  => 'bool',
            'completed_at' => 'datetime',
        ];
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(OnboardingCase::class, 'onboarding_case_id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
