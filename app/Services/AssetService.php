<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AssetCategory;
use App\Enums\AssetStatus;
use App\Enums\AssignmentConditionOnReturn;
use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceType;
use App\Events\AssetAssigned;
use App\Events\AssetMaintenanceCompleted;
use App\Events\AssetMaintenanceLogged;
use App\Events\AssetMarkedLost;
use App\Events\AssetRetired;
use App\Events\AssetReturned;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetDepreciationSnapshot;
use App\Models\AssetMaintenance;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class AssetService
{
    public function register(array $data): Asset
    {
        $data['current_status'] ??= AssetStatus::InStock->value;
        return Asset::create($data);
    }

    public function assign(
        Asset $asset,
        Employee $employee,
        User $by,
        ?\DateTimeInterface $dueBackAt = null,
        ?string $notes = null,
    ): AssetAssignment {
        if ($asset->current_status === AssetStatus::Assigned) {
            throw new DomainException("Asset {$asset->asset_tag} is already assigned.");
        }
        if (in_array($asset->current_status, [AssetStatus::Retired, AssetStatus::Lost], true)) {
            throw new DomainException("Cannot assign retired/lost asset {$asset->asset_tag}.");
        }

        return DB::transaction(function () use ($asset, $employee, $by, $dueBackAt, $notes) {
            $assignment = AssetAssignment::create([
                'asset_id'    => $asset->id,
                'employee_id' => $employee->id,
                'assigned_at' => now(),
                'assigned_by' => $by->id,
                'due_back_at' => $dueBackAt,
                'notes'       => $notes,
            ]);

            $asset->update([
                'current_status'        => AssetStatus::Assigned,
                'current_assignment_id' => $assignment->id,
            ]);

            AssetAssigned::dispatch($assignment, $by);
            return $assignment;
        });
    }

    public function returnAsset(
        AssetAssignment $assignment,
        User $to,
        AssignmentConditionOnReturn $condition,
        ?string $notes = null,
    ): AssetAssignment {
        if ($assignment->returned_at !== null) {
            throw new DomainException('Assignment already closed.');
        }

        return DB::transaction(function () use ($assignment, $to, $condition, $notes) {
            $assignment->update([
                'returned_at'         => now(),
                'returned_to'         => $to->id,
                'condition_on_return' => $condition,
                'notes'               => $notes ?? $assignment->notes,
            ]);

            $assignment->asset->update([
                'current_status'        => AssetStatus::InStock,
                'current_assignment_id' => null,
            ]);

            if ($condition === AssignmentConditionOnReturn::Damaged) {
                $this->logMaintenance(
                    $assignment->asset,
                    MaintenanceType::Repair,
                    $to,
                    ['notes' => "Auto-opened: returned damaged from assignment #{$assignment->id}"],
                );
            }

            AssetReturned::dispatch($assignment, $to);
            return $assignment->fresh();
        });
    }

    public function logMaintenance(
        Asset $asset,
        MaintenanceType $type,
        User $recordedBy,
        array $data = [],
    ): AssetMaintenance {
        return DB::transaction(function () use ($asset, $type, $recordedBy, $data) {
            $maintenance = AssetMaintenance::create([
                'asset_id'     => $asset->id,
                'type'         => $type,
                'status'       => MaintenanceStatus::Open,
                'started_at'   => $data['started_at'] ?? now(),
                'cost'         => $data['cost'] ?? null,
                'vendor'       => $data['vendor'] ?? null,
                'notes'        => $data['notes'] ?? null,
                'recorded_by'  => $recordedBy->id,
            ]);

            $asset->update(['current_status' => AssetStatus::Maintenance]);

            AssetMaintenanceLogged::dispatch($maintenance, $recordedBy);
            return $maintenance;
        });
    }

    public function completeMaintenance(
        AssetMaintenance $maintenance,
        User $by,
        ?float $cost = null,
        ?string $notes = null,
    ): AssetMaintenance {
        if ($maintenance->status === MaintenanceStatus::Completed) {
            throw new DomainException('Maintenance already completed.');
        }

        return DB::transaction(function () use ($maintenance, $by, $cost, $notes) {
            $maintenance->update([
                'status'       => MaintenanceStatus::Completed,
                'completed_at' => now(),
                'cost'         => $cost ?? $maintenance->cost,
                'notes'        => $notes ?? $maintenance->notes,
            ]);

            $maintenance->asset->update(['current_status' => AssetStatus::InStock]);

            AssetMaintenanceCompleted::dispatch($maintenance, $by);
            return $maintenance->fresh();
        });
    }

    public function retire(Asset $asset, User $by, string $reason): Asset
    {
        if ($asset->current_status === AssetStatus::Retired) {
            throw new DomainException('Asset already retired.');
        }

        $asset->update([
            'current_status' => AssetStatus::Retired,
            'notes'          => trim(($asset->notes ?? '') . "\nRetired by user #{$by->id} ({$reason})"),
        ]);

        AssetRetired::dispatch($asset, $by);
        return $asset;
    }

    public function markLost(Asset $asset, User $by, string $reason): Asset
    {
        if ($asset->current_status === AssetStatus::Lost) {
            throw new DomainException('Asset already marked lost.');
        }

        $asset->update([
            'current_status' => AssetStatus::Lost,
            'notes'          => trim(($asset->notes ?? '') . "\nMarked lost by user #{$by->id} ({$reason})"),
        ]);

        AssetMarkedLost::dispatch($asset, $by);
        return $asset;
    }

    public function regenerateDepreciationSnapshot(Asset $asset, \DateTimeInterface $asOfDate): AssetDepreciationSnapshot
    {
        $rules = config('assets.depreciation.' . $asset->category->value)
            ?? config('assets.depreciation.other');

        $purchaseCost = (float) ($asset->purchase_cost ?? 0);
        $salvageValue = round($purchaseCost * (float) $rules['salvage_pct'], 2);
        $usefulLife = (int) $rules['useful_life_years'];

        $purchaseDate = $asset->purchase_date
            ? CarbonImmutable::instance($asset->purchase_date)
            : CarbonImmutable::instance($asOfDate);
        $asOf = CarbonImmutable::instance($asOfDate);

        $monthsElapsed = max(0, $purchaseDate->diffInMonths($asOf));
        $monthsOfLife = $usefulLife * 12;
        $depreciableBase = $purchaseCost - $salvageValue;

        $bookValue = $monthsElapsed >= $monthsOfLife
            ? $salvageValue
            : round($purchaseCost - ($depreciableBase * $monthsElapsed / $monthsOfLife), 2);

        return AssetDepreciationSnapshot::updateOrCreate(
            ['asset_id' => $asset->id, 'as_of_date' => $asOf->toDateString()],
            [
                'book_value'         => $bookValue,
                'method'             => 'straight_line',
                'useful_life_years'  => $usefulLife,
                'salvage_value'      => $salvageValue,
            ]
        );
    }
}
