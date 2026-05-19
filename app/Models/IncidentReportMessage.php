<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class IncidentReportMessage extends Model
{
    protected $fillable = ['incident_report_id', 'author_id', 'body'];

    public function report(): BelongsTo
    {
        return $this->belongsTo(IncidentReport::class, 'incident_report_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(IncidentReportAttachment::class, 'attachable');
    }
}
