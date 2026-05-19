<?php

namespace App\Models;

use App\Enums\DocumentConfidentiality;
use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Document extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid', 'ref_no', 'title', 'description', 'owner_id',
        'current_version_id', 'status', 'confidentiality',
        'parallel_routing', 'tags',
    ];

    protected $casts = [
        'status'           => DocumentStatus::class,
        'confidentiality'  => DocumentConfidentiality::class,
        'parallel_routing' => 'boolean',
        'tags'             => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (Document $doc) {
            $doc->uuid ??= (string) Str::uuid();
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderBy('version_no');
    }

    public function routes(): HasMany
    {
        return $this->hasMany(DocumentRoute::class)->orderBy('sequence');
    }

    public function annotations(): HasMany
    {
        return $this->hasMany(DocumentAnnotation::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(DocumentEvent::class)->orderBy('occurred_at');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
