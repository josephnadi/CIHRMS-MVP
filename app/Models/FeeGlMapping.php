<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeGlMapping extends Model
{
    protected $fillable = [
        'fee_code', 'label', 'income_gl_account_id', 'clearing_gl_account_id',
        'is_deferred', 'recognition_months', 'deferred_gl_account_id', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_deferred' => 'bool', 'is_active' => 'bool', 'recognition_months' => 'int'];
    }

    public static function forCode(string $feeCode): ?self
    {
        return static::where('fee_code', $feeCode)->where('is_active', true)->first();
    }

    public function incomeAccount(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'income_gl_account_id');
    }

    public function clearingAccount(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'clearing_gl_account_id');
    }

    public function deferredAccount(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'deferred_gl_account_id');
    }
}
