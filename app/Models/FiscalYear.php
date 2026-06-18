<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FiscalPeriodStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalYear extends Model
{
    protected $table = 'fiscal_years';

    protected $fillable = ['year', 'status', 'starts_on', 'ends_on'];

    protected function casts(): array
    {
        return [
            'year'      => 'integer',
            'status'    => FiscalPeriodStatus::class,
            'starts_on' => 'date',
            'ends_on'   => 'date',
        ];
    }

    public function periods(): HasMany
    {
        return $this->hasMany(FiscalPeriod::class, 'fiscal_year_id')->orderBy('period_no');
    }
}
