<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetDepreciationSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'asset_id', 'as_of_date', 'book_value',
        'method', 'useful_life_years', 'salvage_value',
    ];

    protected function casts(): array
    {
        return [
            'as_of_date'    => 'date',
            'book_value'    => 'decimal:2',
            'salvage_value' => 'decimal:2',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
