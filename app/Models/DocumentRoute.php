<?php

namespace App\Models;

use App\Enums\DocumentRouteAction;
use App\Enums\DocumentRouteStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentRoute extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id', 'version_id', 'sequence',
        'from_user_id', 'to_user_id',
        'action_required', 'status',
        'due_at', 'acted_at', 'comment',
    ];

    protected $casts = [
        'action_required' => DocumentRouteAction::class,
        'status'          => DocumentRouteStatus::class,
        'due_at'          => 'datetime',
        'acted_at'        => 'datetime',
        'sequence'        => 'integer',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'version_id');
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }
}
