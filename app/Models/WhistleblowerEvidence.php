<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhistleblowerEvidence extends Model
{
    protected $table = 'whistleblower_evidence';

    protected $fillable = [
        'report_id', 'original_filename', 'storage_path',
        'mime_type', 'size_bytes', 'caption', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'caption'    => 'encrypted',
            'size_bytes' => 'integer',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(WhistleblowerReport::class, 'report_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
