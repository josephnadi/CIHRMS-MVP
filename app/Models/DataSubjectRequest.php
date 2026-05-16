<?php

namespace App\Models;

use App\Enums\DataSubjectRequestStatus;
use App\Enums\DataSubjectRequestType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DataSubjectRequest extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'reference', 'subject_user_id', 'request_type', 'status',
        'subject_statement', 'rectification_details', 'objection_purpose',
        'submitted_at', 'target_completion_date',
        'acknowledged_at', 'completed_at',
        'assigned_to', 'decided_by',
        'decision_summary', 'rejection_basis',
        'export_path', 'export_sha256', 'export_generated_at',
        'tombstone_log', 'audit_trail',
    ];

    protected function casts(): array
    {
        return [
            'request_type'           => DataSubjectRequestType::class,
            'status'                 => DataSubjectRequestStatus::class,
            'submitted_at'           => 'datetime',
            'target_completion_date' => 'date',
            'acknowledged_at'        => 'datetime',
            'completed_at'           => 'datetime',
            'export_generated_at'    => 'datetime',
            'tombstone_log'          => 'array',
            'audit_trail'            => 'array',
        ];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNotIn('status', [
            DataSubjectRequestStatus::Fulfilled->value,
            DataSubjectRequestStatus::PartiallyFulfilled->value,
            DataSubjectRequestStatus::Rejected->value,
            DataSubjectRequestStatus::Withdrawn->value,
        ]);
    }

    public function isOverdue(): bool
    {
        return ! $this->status->isTerminal()
            && $this->target_completion_date < now()->toDateString();
    }

    public function daysRemaining(): int
    {
        if ($this->status->isTerminal()) return 0;
        $target = CarbonImmutable::parse($this->target_completion_date);
        return (int) now()->startOfDay()->diffInDays($target, false);
    }

    public function appendAuditEntry(string $action, ?int $actorId = null, array $meta = []): void
    {
        $trail = $this->audit_trail ?? [];
        $trail[] = [
            'at'       => now()->toIso8601String(),
            'action'   => $action,
            'actor_id' => $actorId,
            'meta'     => $meta,
        ];
        $this->update(['audit_trail' => $trail]);
    }
}
