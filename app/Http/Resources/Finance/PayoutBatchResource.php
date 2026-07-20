<?php

declare(strict_types=1);

namespace App\Http\Resources\Finance;

use App\Models\PayoutBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PayoutBatch */
class PayoutBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->id,
            'reference'              => $this->reference,
            'status'                 => $this->status->value,
            'status_label'           => $this->status->label(),
            'total_amount'           => (float) $this->total_amount,
            'currency'               => $this->currency,
            'requires_high_approval' => (bool) $this->requires_high_approval,
            'created_by'             => $this->created_by,
            'released_by'            => $this->released_by,
            'released_at'            => $this->released_at?->format('Y-m-d H:i'),
            'disbursements_count'    => $this->whenCounted('disbursements'),
            'created_at'             => $this->created_at?->format('Y-m-d H:i'),
        ];
    }
}
