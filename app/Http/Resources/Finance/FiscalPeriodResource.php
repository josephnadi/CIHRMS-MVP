<?php

declare(strict_types=1);

namespace App\Http\Resources\Finance;

use App\Models\FiscalPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FiscalPeriod */
class FiscalPeriodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'period_no'  => $this->period_no,
            'name'       => $this->name,
            'starts_on'  => $this->starts_on?->toDateString(),
            'ends_on'    => $this->ends_on?->toDateString(),
            'status'     => ['value' => $this->status->value, 'label' => $this->status->label()],
            'closed_at'  => $this->closed_at?->toIso8601String(),
            'locked_at'  => $this->locked_at?->toIso8601String(),
        ];
    }
}
