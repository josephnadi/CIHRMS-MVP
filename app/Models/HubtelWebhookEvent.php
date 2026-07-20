<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HubtelWebhookEvent extends Model
{
    protected $fillable = [
        'hubtel_event_id', 'client_reference', 'status_text', 'payload', 'signature', 'processed_at',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'datetime'];
    }
}
