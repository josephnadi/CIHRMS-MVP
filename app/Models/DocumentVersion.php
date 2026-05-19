<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id', 'version_no', 'original_name', 'mime', 'size',
        'storage_path', 'sha256', 'uploaded_by', 'uploaded_at', 'notes',
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
        'size'        => 'integer',
        'version_no'  => 'integer',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
