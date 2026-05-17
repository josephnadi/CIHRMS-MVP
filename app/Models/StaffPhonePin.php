<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

class StaffPhonePin extends Model
{
    protected $fillable = [
        'employee_id', 'phone', 'pin_hash', 'pin_expires_at',
        'last_used_at', 'failed_attempts', 'locked_until',
    ];

    protected $hidden = ['pin_hash'];

    protected function casts(): array
    {
        return [
            'pin_expires_at'  => 'datetime',
            'last_used_at'    => 'datetime',
            'locked_until'    => 'datetime',
            'failed_attempts' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function verify(string $pin): bool
    {
        return Hash::check($pin, $this->pin_hash);
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function recordFailure(int $maxAttempts = 5, int $lockMinutes = 15): void
    {
        $this->failed_attempts++;
        if ($this->failed_attempts >= $maxAttempts) {
            $this->locked_until    = now()->addMinutes($lockMinutes);
            $this->failed_attempts = 0;
        }
        $this->save();
    }

    public function recordSuccess(): void
    {
        $this->update([
            'failed_attempts' => 0,
            'locked_until'    => null,
            'last_used_at'    => now(),
        ]);
    }
}
