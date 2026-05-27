<?php

declare(strict_types=1);

namespace App\Http\Resources\Billing;

use App\Models\FeeProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FeeProduct
 */
class FeeProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $cycle = is_object($this->billing_cycle) ? $this->billing_cycle->value : (string) $this->billing_cycle;

        return [
            'id'                   => $this->id,
            'code'                 => $this->code,
            'name'                 => $this->name,
            'description'          => $this->description,
            'amount'               => (float) $this->amount,
            'currency'             => $this->currency,
            'billing_cycle'        => $cycle,
            'applies_to_classes'   => $this->applies_to_classes,
            'gl_income_account_id' => $this->gl_income_account_id,
            'gl_income_account'    => $this->whenLoaded('incomeGl', fn () => [
                'id'   => $this->incomeGl?->id,
                'code' => $this->incomeGl?->code,
                'name' => $this->incomeGl?->name,
            ]),
            'is_active'            => (bool) $this->is_active,
            'created_at'           => $this->created_at?->toIso8601String(),
        ];
    }
}
