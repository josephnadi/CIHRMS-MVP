<?php

namespace App\Models;

use App\Enums\AssetOwnerScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LetterheadTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_scope', 'owner_id', 'name', 'storage_path', 'mime',
        'header_height_mm', 'is_default', 'created_by',
    ];

    protected $casts = [
        'owner_scope'      => AssetOwnerScope::class,
        'is_default'       => 'boolean',
        'header_height_mm' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
