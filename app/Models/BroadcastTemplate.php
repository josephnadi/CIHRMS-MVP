<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BroadcastAudienceType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BroadcastTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'audience_type', 'sms_body', 'mail_subject', 'mail_body',
        'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'audience_type' => BroadcastAudienceType::class,
            'is_active'     => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
