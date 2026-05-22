<?php

declare(strict_types=1);

namespace App\Http\Resources\Finance;

use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Vendor */
class VendorResource extends JsonResource
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
            'default_expense_gl_account_id' => $this->default_expense_gl_account_id,
            'default_ap_gl_account_id'      => $this->default_ap_gl_account_id,
            'default_bank_account_id'       => $this->default_bank_account_id,
            'default_expense_gl' => $this->whenLoaded('defaultExpenseGl', fn () => [
                'id' => $this->defaultExpenseGl?->id,
                'code' => $this->defaultExpenseGl?->code,
                'name' => $this->defaultExpenseGl?->name,
            ]),
            'default_ap_gl' => $this->whenLoaded('defaultApGl', fn () => [
                'id' => $this->defaultApGl?->id,
                'code' => $this->defaultApGl?->code,
                'name' => $this->defaultApGl?->name,
            ]),
        ];
    }
}
