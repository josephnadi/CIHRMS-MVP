<?php

namespace App\Models;

use App\Enums\IdentityProviderKind;
use App\Enums\IdentityVerificationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class IdentityVerification extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'employee_id', 'provider', 'ghana_card_number', 'ghana_card_hash',
        'status', 'verified_at', 'verified_by', 'expires_at',
        'evidence_path', 'raw_response', 'failure_reason',
    ];

    protected $hidden = ['ghana_card_number'];

    protected function casts(): array
    {
        return [
            'provider'          => IdentityProviderKind::class,
            'status'            => IdentityVerificationStatus::class,
            'verified_at'       => 'datetime',
            'expires_at'        => 'datetime',
            'raw_response'      => 'array',
            'ghana_card_number' => 'encrypted',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query
            ->where('status', IdentityVerificationStatus::Verified->value)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now()));
    }

    public function isUsable(): bool
    {
        return $this->status === IdentityVerificationStatus::Verified
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public static function hashCardNumber(string $cardNumber): string
    {
        // Normalize Ghana Card format (GHA-XXXXXXXXX-X) before hashing.
        $normalized = strtoupper(preg_replace('/\s+/', '', trim($cardNumber)));
        return hash('sha256', $normalized);
    }
}
