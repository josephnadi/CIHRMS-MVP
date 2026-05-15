<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'action',
    'route_name',
    'method',
    'path',
    'ip_address',
    'user_agent',
    'payload',
    'chain_position',
    'previous_hash',
    'row_hash',
])]
class AuditLog extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Canonical representation used as the input to the SHA-256 chain hash.
     * Stable across runs — any field change here is a chain-breaking change.
     */
    public function canonicalJson(): string
    {
        return json_encode([
            'id'             => $this->id,
            'chain_position' => $this->chain_position,
            'user_id'        => $this->user_id,
            'action'         => $this->action,
            'route_name'     => $this->route_name,
            'method'         => $this->method,
            'path'           => $this->path,
            'ip_address'     => $this->ip_address,
            'user_agent'     => $this->user_agent,
            'payload'        => $this->payload,
            'created_at'     => optional($this->created_at)?->toIso8601String(),
            'previous_hash'  => $this->previous_hash,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function computeHash(): string
    {
        return hash('sha256', $this->canonicalJson());
    }
}
