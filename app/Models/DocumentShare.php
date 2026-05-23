<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentShareAudience;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentShare extends Model
{
    protected $fillable = [
        'document_id', 'audience_type', 'audience_id',
        'granted_by', 'granted_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'audience_type' => DocumentShareAudience::class,
            'granted_at'    => 'datetime',
            'expires_at'    => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    /** Resolve the audience target (User, Department, or null for org-wide). */
    public function audience(): ?Model
    {
        return match ($this->audience_type) {
            DocumentShareAudience::User         => $this->audience_id ? User::find($this->audience_id) : null,
            DocumentShareAudience::Department   => $this->audience_id ? Department::find($this->audience_id) : null,
            DocumentShareAudience::Organization => null,
        };
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where(function ($w) {
            $w->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }
}
