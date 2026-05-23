<?php

namespace App\Models;

use App\Enums\AssetOwnerScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WatermarkTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_scope', 'owner_id', 'name', 'type', 'text', 'color',
        'storage_path', 'mime', 'opacity', 'angle_deg', 'font_size_hint',
        'created_by',
    ];

    protected $casts = [
        'owner_scope' => AssetOwnerScope::class,
        'opacity'     => 'float',
        'angle_deg'   => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
