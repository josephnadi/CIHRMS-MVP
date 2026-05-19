<?php

namespace App\Models;

use App\Enums\IncidentCategory;
use App\Enums\IncidentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class IncidentReport extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'employee_id',
        'category',
        'title',
        'body',
        'status',
        'closed_at',
        'closed_by_id',
        'resolution_note',
    ];

    protected function casts(): array
    {
        return [
            'category'  => IncidentCategory::class,
            'status'    => IncidentStatus::class,
            'closed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }

    /** All assignment pivot rows including soft-removed. */
    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'incident_report_assignees')
            ->withPivot(['assigned_at', 'assigned_by_id', 'removed_at']);
    }

    /** Only currently-active assignees (the privacy circle membership). */
    public function currentAssignees(): BelongsToMany
    {
        return $this->assignees()->wherePivotNull('removed_at');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(IncidentReportMessage::class)->orderBy('created_at');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(IncidentReportAttachment::class, 'attachable');
    }

    /** True iff $user is the submitter or a current assignee. */
    public function isInCircle(User $user): bool
    {
        if ($this->employee && $this->employee->user_id === $user->id) {
            return true;
        }
        return $this->currentAssignees()->where('users.id', $user->id)->exists();
    }

    /** Scope to reports visible to the given user (submitter or current assignee). */
    public function scopeVisibleTo(Builder $q, ?User $user): Builder
    {
        if (! $user) return $q->whereRaw('1=0');

        return $q->where(function (Builder $q) use ($user) {
            $q->whereHas('employee', fn ($e) => $e->where('user_id', $user->id))
              ->orWhereHas('currentAssignees', fn ($a) => $a->where('users.id', $user->id));
        });
    }
}
