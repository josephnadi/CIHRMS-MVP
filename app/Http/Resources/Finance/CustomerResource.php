<?php

declare(strict_types=1);

namespace App\Http\Resources\Finance;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Customer */
class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'      => $this->id,
            'code'    => $this->code,
            'name'    => $this->name,
            'tax_id'  => $this->tax_id,
            'status'  => ['value' => $this->status->value, 'label' => $this->status->label()],
            'email'   => $this->email,
            'phone'   => $this->phone,
            'address' => $this->address,
            'notes'   => $this->notes,
            'default_income_gl_account_id' => $this->default_income_gl_account_id,
            'default_ar_gl_account_id'     => $this->default_ar_gl_account_id,
            'default_bank_account_id'      => $this->default_bank_account_id,
            'default_income_gl' => $this->whenLoaded('defaultIncomeGl', fn () => [
                'id'   => $this->defaultIncomeGl?->id,
                'code' => $this->defaultIncomeGl?->code,
                'name' => $this->defaultIncomeGl?->name,
            ]),
            'default_ar_gl' => $this->whenLoaded('defaultArGl', fn () => [
                'id'   => $this->defaultArGl?->id,
                'code' => $this->defaultArGl?->code,
                'name' => $this->defaultArGl?->name,
            ]),
        ];
    }
}
