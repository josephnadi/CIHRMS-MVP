<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PolicyCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Policy extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'policies';

    protected $fillable = [
        'title', 'slug', 'category', 'summary',
        'owner_user_id', 'is_active', 'current_version_id',
    ];

    protected function casts(): array
    {
        return [
            'category'   => PolicyCategory::class,
            'is_active'  => 'boolean',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PolicyVersion::class)->orderBy('version_number');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(PolicyVersion::class, 'current_version_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
