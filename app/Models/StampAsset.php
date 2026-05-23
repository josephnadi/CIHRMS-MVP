<?php

namespace App\Models;

use App\Enums\AssetOwnerScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StampAsset extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_scope', 'owner_id', 'name', 'storage_path', 'mime',
        'default_w_pct', 'default_h_pct', 'created_by',
    ];

    protected $casts = [
        'owner_scope'   => AssetOwnerScope::class,
        'default_w_pct' => 'float',
        'default_h_pct' => 'float',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
