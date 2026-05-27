<?php

declare(strict_types=1);

namespace App\Http\Resources\Billing;

use App\Models\Member;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Member
 */
class MemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $class  = is_object($this->class)  ? $this->class->value  : (string) $this->class;
        $status = is_object($this->status) ? $this->status->value : (string) $this->status;

        return [
            'id'             => $this->id,
            'member_no'      => $this->member_no,
            'class'          => $class,
            'status'         => $status,
            'name'           => $this->name,
            'email'          => $this->email,
            'phone'          => $this->phone,
            'address'        => $this->address,
            'date_of_birth'  => $this->date_of_birth?->toDateString(),
            'chartered_at'   => $this->chartered_at?->toIso8601String(),
            'lapsed_at'      => $this->lapsed_at?->toIso8601String(),
            'customer_id'    => $this->customer_id,
            'created_at'     => $this->created_at?->toIso8601String(),
        ];
    }
}
