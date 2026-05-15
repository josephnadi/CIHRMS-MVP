<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveBalanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'type'          => $this->type,
            'year'          => $this->year,
            'total_days'    => (float) $this->total_days,
            'used_days'     => (float) $this->used_days,
            'remaining_days' => $this->remainingDays(),
        ];
    }
}
