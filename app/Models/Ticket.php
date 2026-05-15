<?php

namespace App\Models;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ticket extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'employee_id',
        'assigned_to',
        'title',
        'description',
        'priority',
        'status',
        'due_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'due_at'      => 'datetime',
            'resolved_at' => 'datetime',
            'priority'    => TicketPriority::class,
            'status'      => TicketStatus::class,
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', TicketStatus::Open);
    }

    public function scopeByPriority(Builder $query, TicketPriority $priority): Builder
    {
        return $query->where('priority', $priority);
    }

    public function isOverdue(): bool
    {
        return $this->due_at && $this->due_at->isPast()
            && $this->status !== TicketStatus::Resolved
            && $this->status !== TicketStatus::Closed;
    }
}
