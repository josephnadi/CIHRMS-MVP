<?php

declare(strict_types=1);

use App\Enums\AssetAuditStatus;
use App\Models\AssetAudit;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

it('creates the three asset-audit tables', function () {
    expect(Schema::hasTable('asset_audits'))->toBeTrue();
    expect(Schema::hasTable('asset_audit_lines'))->toBeTrue();
    expect(Schema::hasTable('asset_audit_events'))->toBeTrue();
    expect(Schema::hasColumns('asset_audits', [
        'reference', 'status', 'scope_type', 'scope_value',
        'total_lines', 'counted_lines', 'discrepancy_lines', 'opened_by',
    ]))->toBeTrue();
    expect(Schema::hasColumns('asset_audit_lines', [
        'asset_audit_id', 'asset_id', 'expected_status', 'expected_location',
        'expected_holder_employee_id', 'result', 'observed_location',
        'is_discrepancy', 'resolution_action',
    ]))->toBeTrue();
});

it('casts status enum and defaults to in_progress', function () {
    $u = AssetAudit::create([
        'reference'  => 'ASA-TEST-1',
        'scope_type' => 'all',
        'opened_by'  => User::factory()->create()->id,
        'opened_at'  => now(),
    ]);
    expect($u->status)->toBe(AssetAuditStatus::InProgress);
});
