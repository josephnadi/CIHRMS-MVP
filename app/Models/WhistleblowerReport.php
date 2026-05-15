<?php

namespace App\Models;

use App\Enums\WhistleblowerCategory;
use App\Enums\WhistleblowerSeverity;
use App\Enums\WhistleblowerStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WhistleblowerReport extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'case_number', 'tracking_token_hash',
        'category', 'severity', 'status',
        'subject_summary', 'incident_date',
        'description', 'desired_outcome', 'incident_location',
        'submitter_contact', 'is_anonymous', 'submitter_user_id',
        'assigned_investigator_id', 'triaged_at', 'triaged_by',
        'closed_at', 'closed_by', 'closure_summary',
        'received_at', 'intake_source',
    ];

    /**
     * The tracking_token_hash is never returned in API responses; the original
     * code is never stored at all (one-way hash only). The investigator UI sees
     * neither and instead identifies cases by `case_number`.
     */
    protected $hidden = ['tracking_token_hash'];

    protected function casts(): array
    {
        return [
            'category'           => WhistleblowerCategory::class,
            'severity'           => WhistleblowerSeverity::class,
            'status'             => WhistleblowerStatus::class,
            'is_anonymous'       => 'bool',
            'incident_date'      => 'date',
            'triaged_at'         => 'datetime',
            'closed_at'          => 'datetime',
            'received_at'        => 'datetime',

            // Encrypted-at-rest fields. Decrypted automatically on read by the model;
            // the DB row contains ciphertext, never plaintext.
            'description'        => 'encrypted',
            'desired_outcome'    => 'encrypted',
            'incident_location'  => 'encrypted',
            'submitter_contact'  => 'encrypted',
            'closure_summary'    => 'encrypted',
        ];
    }

    public function investigator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_investigator_id');
    }

    public function triager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triaged_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitter_user_id');
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(WhistleblowerSubject::class, 'report_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(WhistleblowerEvidence::class, 'report_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(WhistleblowerAction::class, 'report_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhistleblowerMessage::class, 'report_id');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNotIn('status', [
            WhistleblowerStatus::ClosedSubstantiated->value,
            WhistleblowerStatus::ClosedUnsubstantiated->value,
            WhistleblowerStatus::ClosedReferred->value,
            WhistleblowerStatus::Withdrawn->value,
        ]);
    }

    public function scopeAssignedTo(Builder $q, User $user): Builder
    {
        return $q->where('assigned_investigator_id', $user->id);
    }

    /** One-way hash a plaintext tracking code for lookup. Never stores the code. */
    public static function hashTrackingCode(string $code): string
    {
        return hash('sha256', strtoupper(preg_replace('/[\s-]+/', '', trim($code))));
    }

    public static function findByTrackingCode(string $code): ?self
    {
        $hash = static::hashTrackingCode($code);
        return static::where('tracking_token_hash', $hash)->first();
    }
}
