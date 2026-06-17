<?php

declare(strict_types=1);

namespace App\Http\Resources\Finance;

use App\Models\PostingAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PostingAccount */
class PostingRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'slug'          => $this->slug,
            'domain'        => $this->domain,
            'description'   => $this->description,
            'locked'        => $this->locked,
            'gl_account_id' => $this->gl_account_id,
            'gl_account'    => $this->whenLoaded('glAccount', fn () => [
                'id'   => $this->glAccount->id,
                'code' => $this->glAccount->code,
                'name' => $this->glAccount->name,
                'type' => $this->glAccount->type->value,
            ]),
        ];
    }
}
