<?php

declare(strict_types=1);

namespace App\Http\Resources\Finance;

use App\Models\JournalLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin JournalLine */
class JournalLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'line_no'       => $this->line_no,
            'gl_account'    => $this->whenLoaded('glAccount', fn () => ['id' => $this->glAccount->id, 'code' => $this->glAccount->code, 'name' => $this->glAccount->name]),
            'debit_amount'  => (float) $this->debit_amount,
            'credit_amount' => (float) $this->credit_amount,
            'narration'     => $this->narration,
        ];
    }
}
