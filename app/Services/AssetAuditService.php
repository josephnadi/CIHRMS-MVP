<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AssetAuditStatus;
use App\Enums\AssetStatus;
use App\Events\AssetAuditOpened;
use App\Models\Asset;
use App\Models\AssetAudit;
use App\Models\AssetAuditEvent;
use App\Models\User;
use App\Services\Finance\SequenceService;
use DomainException;
use Illuminate\Support\Facades\DB;

class AssetAuditService
{
    /** Statuses a physically-present asset can have (retired/lost are not expected). */
    private const EXPECTED_STATUSES = [
        AssetStatus::InStock->value,
        AssetStatus::Assigned->value,
        AssetStatus::Maintenance->value,
    ];

    public function __construct(private readonly SequenceService $sequences)
    {
    }

    public function open(array $data, User $actor): AssetAudit
    {
        $scopeType  = $data['scope_type'] ?? 'all';
        $scopeValue = $data['scope_value'] ?? null;

        return DB::transaction(function () use ($scopeType, $scopeValue, $data, $actor) {
            $audit = AssetAudit::create([
                'reference'   => $this->nextReference(),
                'status'      => AssetAuditStatus::InProgress->value,
                'scope_type'  => $scopeType,
                'scope_value' => $scopeValue,
                'notes'       => $data['notes'] ?? null,
                'opened_by'   => $actor->id,
                'opened_at'   => now(),
            ]);

            $query = Asset::query()
                ->with('currentAssignment')
                ->whereIn('current_status', self::EXPECTED_STATUSES);

            if ($scopeType === 'category' && $scopeValue !== null) {
                $query->where('category', $scopeValue);
            } elseif ($scopeType === 'location' && $scopeValue !== null) {
                $query->where('location', $scopeValue);
            }

            $count = 0;
            foreach ($query->cursor() as $asset) {
                $audit->lines()->create([
                    'asset_id'                    => $asset->id,
                    'expected_status'             => $asset->current_status->value,
                    'expected_location'           => $asset->location,
                    'expected_holder_employee_id' => $asset->currentAssignment?->employee_id,
                    'result'                      => 'pending',
                ]);
                $count++;
            }

            $audit->update(['total_lines' => $count]);
            $this->recordEvent($audit, $actor, 'opened', null, "Snapshot {$count} assets ({$scopeType})");
            AssetAuditOpened::dispatch($audit->fresh());

            return $audit->fresh(['lines']);
        });
    }

    protected function recordEvent(AssetAudit $audit, ?User $actor, string $action, ?int $lineId = null, ?string $detail = null): void
    {
        AssetAuditEvent::create([
            'asset_audit_id'      => $audit->id,
            'asset_audit_line_id' => $lineId,
            'actor_id'            => $actor?->id,
            'action'              => $action,
            'detail'              => $detail,
            'created_at'          => now(),
        ]);
    }

    protected function nextReference(): string
    {
        $n = $this->sequences->next('asset_audit');
        return 'ASA-' . now()->format('Y') . '-' . str_pad((string) $n, 5, '0', STR_PAD_LEFT);
    }
}
