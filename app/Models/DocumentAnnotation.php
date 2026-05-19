<?php

namespace App\Models;

use App\Enums\DocumentAnnotationType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentAnnotation extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id', 'version_id', 'route_id', 'user_id',
        'type', 'page', 'x_pct', 'y_pct', 'w_pct', 'h_pct',
        'rotation', 'data',
    ];

    protected $casts = [
        'type'     => DocumentAnnotationType::class,
        'data'     => 'array',
        'page'     => 'integer',
        'rotation' => 'integer',
        'x_pct'    => 'float',
        'y_pct'    => 'float',
        'w_pct'    => 'float',
        'h_pct'    => 'float',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
