<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class IncidentReportAttachment extends Model
{
    public $timestamps = false;
    protected $fillable = [
        'attachable_type', 'attachable_id',
        'file_path', 'original_name', 'mime_type', 'size_bytes',
        'uploaded_by_id',
    ];
    protected $casts = ['created_at' => 'datetime'];

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    /** Walk the polymorphic chain back to the owning report. */
    public function reportRoot(): IncidentReport
    {
        $owner = $this->attachable;
        if ($owner instanceof IncidentReport)        return $owner;
        if ($owner instanceof IncidentReportMessage) return $owner->report;
        throw new \LogicException('Attachment is attached to neither IncidentReport nor IncidentReportMessage.');
    }
}
