<?php

namespace App\Models;

use App\Enums\DocumentEventType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentEvent extends Model
{
    use HasFactory;

    protected $fillable = ['document_id', 'actor_id', 'type', 'payload', 'occurred_at'];

    protected $casts = [
        'type'        => DocumentEventType::class,
        'payload'     => 'array',
        'occurred_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
