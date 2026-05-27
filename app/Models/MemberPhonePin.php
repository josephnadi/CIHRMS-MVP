<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

/**
 * PIN tied to a Member for USSD authentication. Mirrors StaffPhonePin
 * for Employees — same lockout policy (5 attempts → 15-min lock) and
 * the same `pin_hash` storage shape.
 */
class MemberPhonePin extends Model
{
    protected $fillable = [
        'member_id', 'phone', 'pin_hash', 'pin_expires_at',
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

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
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
