<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Certification extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'employee_id', 'course_id', 'name', 'issuer',
        'credential_id', 'issued_at', 'expires_at',
        'document_path', 'verification_url', 'reminder_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'issued_at'        => 'date',
            'expires_at'       => 'date',
            'reminder_sent_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** Days until expiry — negative when expired, null when no expiry set. */
    public function daysToExpiry(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->expires_at) return null;
            return (int) now()->startOfDay()->diffInDays($this->expires_at->startOfDay(), false);
        });
    }

    public function scopeExpiringWithin(Builder $q, int $days): Builder
    {
        return $q->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays($days)]);
    }

    public function scopeExpired(Builder $q): Builder
    {
        return $q->whereNotNull('expires_at')->where('expires_at', '<', now());
    }

    public function scopeNeedingReminder(\Illuminate\Database\Eloquent\Builder $q, int $daysAhead = 30): \Illuminate\Database\Eloquent\Builder
    {
        return $q->whereNotNull('expires_at')
            ->whereNull('reminder_sent_at')
            ->whereBetween('expires_at', [now()->startOfDay(), now()->addDays($daysAhead)->endOfDay()]);
    }
}
