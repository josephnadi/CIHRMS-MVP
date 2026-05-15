<?php

namespace App\Models;

use App\Enums\ComplaintStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Complaint extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['reference', 'submitted_by', 'details', 'status'];

    protected function casts(): array
    {
        return [
            'status' => ComplaintStatus::class,
        ];
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', ComplaintStatus::Open);
    }
}
