<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GlAccountType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class GlAccount extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'gl_accounts';

    protected $fillable = [
        'code', 'name', 'type', 'parent_id', 'is_active', 'currency', 'description',
    ];

    protected $attributes = [
        'currency'   => 'GHS',
        'is_active'  => true,
    ];

    protected function casts(): array
    {
        return [
            'type'      => GlAccountType::class,
            'is_active' => 'bool',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function balance(): HasOne
    {
        return $this->hasOne(GlAccountBalance::class, 'gl_account_id');
    }

    public function bankAccount(): HasOne
    {
        return $this->hasOne(OrgBankAccount::class, 'gl_account_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeOfType(Builder $q, GlAccountType|string $type): Builder
    {
        return $q->where('type', $type instanceof GlAccountType ? $type->value : $type);
    }

    public function scopeRoots(Builder $q): Builder
    {
        return $q->whereNull('parent_id');
    }
}
