<?php

namespace App\Models;

use App\Enums\UssdState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UssdSession extends Model
{
    protected $fillable = [
        'session_id', 'phone', 'shortcode', 'state',
        'employee_id', 'context', 'last_input', 'last_response',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'state'      => UssdState::class,
            'context'    => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function pushContext(string $key, mixed $value): void
    {
        $ctx = $this->context ?? [];
        $ctx[$key] = $value;
        $this->update(['context' => $ctx]);
    }

    public function getContext(string $key, mixed $default = null): mixed
    {
        return ($this->context ?? [])[$key] ?? $default;
    }
}
