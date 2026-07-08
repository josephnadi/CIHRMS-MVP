<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MemberClass;
use App\Enums\MemberStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A CIHRM member or student — the billable party for the Billing & Fees
 * module (M1). Each Member 1:1 maps to a `Customer` row so the existing
 * AR pipeline (invoices, receipts, allocations, GL postings) stays
 * unchanged. Member rows hold the institute-specific profile data
 * (class, charter date, Ghana Card hash); Customer holds the AR identity.
 *
 * Extending `Authenticatable` so the member-portal guard introduced in
 * M2 can hash + verify passwords against the `password` column.
 * `Notifiable` added in M2 so PaymentReceived (and friends) can be sent
 * via $member->notify(...) — Foundation\Auth\User does not include it
 * by default.
 */
class Member extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'external_user_id',
        'member_no',
        'student_no',
        'class',
        'status',
        'name',
        'email',
        'phone',
        'address',
        'date_of_birth',
        'ghana_card_number_hash',
        'customer_id',
        'chartered_at',
        'lapsed_at',
        'password',
        'remember_token',
        'notes',
    ];

    protected $hidden = ['password', 'remember_token', 'ghana_card_number_hash'];

    protected function casts(): array
    {
        return [
            'class'         => MemberClass::class,
            'status'        => MemberStatus::class,
            'date_of_birth' => 'date',
            'chartered_at'  => 'datetime',
            'lapsed_at'     => 'datetime',
            'password'      => 'hashed',
        ];
    }

    // ── Relations ─────────────────────────────────────────────────────

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(FeeAssignment::class);
    }

    /**
     * All AR invoices for this member, reached through their linked
     * Customer. Lets the portal display "your fees" without the AR
     * module needing to know members exist.
     */
    public function invoices(): HasManyThrough
    {
        return $this->hasManyThrough(
            ArInvoice::class,
            Customer::class,
            'id',          // customers.id (FK on this Member)
            'customer_id', // ar_invoices.customer_id
            'customer_id', // members.customer_id (local key on Member)
            'id',          // customers.id
        );
    }

    // ── Scopes ────────────────────────────────────────────────────────

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', MemberStatus::Active->value);
    }

    public function scopeOfClass(Builder $q, MemberClass $class): Builder
    {
        return $q->where('class', $class->value);
    }
}
