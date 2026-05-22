<?php

declare(strict_types=1);

namespace App\Http\Resources\Finance;

use App\Models\GlAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GlAccount */
class GlAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'code'       => $this->code,
            'name'       => $this->name,
            'type'       => [
                'value' => $this->type->value,
                'label' => $this->type->label(),
            ],
            'parent_id'  => $this->parent_id,
            'is_active'  => $this->is_active,
            'currency'   => $this->currency,
            'description'=> $this->description,
            'balance'    => $this->whenLoaded('balance', fn () => (float) ($this->balance?->balance ?? 0)),
            'children'   => GlAccountResource::collection($this->whenLoaded('children')),
        ];
    }
}
