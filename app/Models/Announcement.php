<?php

namespace App\Models;

use App\Enums\AnnouncementSeverity;
use App\Enums\AnnouncementType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    use HasFactory;

    protected $fillable = [
        'type', 'severity', 'title', 'body', 'icon', 'link_url',
        'audience_role', 'pinned', 'is_active', 'starts_at', 'ends_at',
        'created_by',
    ];

    protected $casts = [
        'type'       => AnnouncementType::class,
        'severity'   => AnnouncementSeverity::class,
        'pinned'     => 'boolean',
        'is_active'  => 'boolean',
        'starts_at'  => 'datetime',
        'ends_at'    => 'datetime',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActiveNow(Builder $q): Builder
    {
        $now = now();
        return $q->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
    }

    public function scopeForRole(Builder $q, ?string $role): Builder
    {
        return $q->where(fn ($w) => $w->whereNull('audience_role')->orWhere('audience_role', $role));
    }
}
