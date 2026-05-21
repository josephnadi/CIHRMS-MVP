<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

class PendingBankChange extends Model
{
    protected $fillable = [
        'employee_id', 'requested_by',
        'old_bank_name', 'old_bank_account', 'old_bank_sort_code',
        'new_bank_name', 'new_bank_account', 'new_bank_sort_code',
        'code_hash', 'code_expires_at', 'failed_attempts',
        'status', 'confirmed_at', 'applied_at', 'rejected_at', 'rejection_reason',
    ];

    protected $hidden = ['code_hash']; // never serialise the hashed code

    protected function casts(): array
    {
        return [
            'code_expires_at'  => 'datetime',
            'confirmed_at'     => 'datetime',
            'applied_at'       => 'datetime',
            'rejected_at'      => 'datetime',
            'failed_attempts'  => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->where('status', 'pending');
    }

    public function isExpired(): bool
    {
        return $this->status === 'pending' && $this->code_expires_at?->isPast();
    }

    public function verifyCode(string $code): bool
    {
        return Hash::check($code, $this->code_hash);
    }
}
