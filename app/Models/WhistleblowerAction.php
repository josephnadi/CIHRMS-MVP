<?php

namespace App\Models;

use App\Enums\InvestigationActionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhistleblowerAction extends Model
{
    protected $fillable = [
        'report_id', 'investigator_id', 'action_type',
        'notes', 'meta', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'action_type' => InvestigationActionType::class,
            'notes'       => 'encrypted',
            'meta'        => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(WhistleblowerReport::class, 'report_id');
    }

    public function investigator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'investigator_id');
    }
}
