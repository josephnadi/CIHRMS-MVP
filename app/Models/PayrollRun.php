<?php

namespace App\Models;

use App\Enums\PayrollRunStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PayrollRun extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Auto-populate the human-readable reference on insert if the caller
     * didn't supply one. Format: PR-{YYYY}-{MM}-{6-hex} — unique per run.
     * Production code paths (PayrollService::createDraft) already generate
     * a reference; this fallback keeps the model robust for direct
     * ::create() calls (e.g. seeders, ad-hoc tests).
     */
    protected static function booted(): void
    {
        static::creating(function (self $run) {
            if (empty($run->reference)) {
                $year  = (int) ($run->period_year  ?? now()->year);
                $month = (int) ($run->period_month ?? now()->month);
                $run->reference = sprintf(
                    'PR-%04d-%02d-%s',
                    $year,
                    $month,
                    strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
                );
            }
        });
    }

    protected $fillable = [
        'reference',
        'period_year', 'period_month', 'period_start', 'period_end',
        'status', 'department_id',
        'created_by', 'approved_by', 'reversed_by',
        'locked_at', 'approved_at', 'paid_at', 'reversed_at', 'reason',
        'gross_total', 'net_total', 'paye_total',
        'ssnit_tier1_employee_total', 'ssnit_tier1_employer_total',
        'nhia_total', 'tier2_employer_total', 'tier3_total',
        'voluntary_deductions_total',
        'lines_count', 'skipped_count',
    ];

    protected function casts(): array
    {
        return [
            'status'        => PayrollRunStatus::class,
            'period_start'  => 'date',
            'period_end'    => 'date',
            'locked_at'     => 'datetime',
            'approved_at'   => 'datetime',
            'paid_at'       => 'datetime',
            'reversed_at'   => 'datetime',

            'gross_total'                => 'decimal:2',
            'net_total'                  => 'decimal:2',
            'paye_total'                 => 'decimal:2',
            'ssnit_tier1_employee_total' => 'decimal:2',
            'ssnit_tier1_employer_total' => 'decimal:2',
            'nhia_total'                 => 'decimal:2',
            'tier2_employer_total'       => 'decimal:2',
            'tier3_total'                => 'decimal:2',
            'voluntary_deductions_total' => 'decimal:2',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(StatutoryReturn::class);
    }

    public function periodLabel(): string
    {
        return sprintf('%04d-%02d', $this->period_year, $this->period_month);
    }

    public function scopeForPeriod(Builder $query, int $year, int $month): Builder
    {
        return $query->where('period_year', $year)->where('period_month', $month);
    }
}
