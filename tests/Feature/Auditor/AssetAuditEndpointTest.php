<?php

declare(strict_types=1);

use App\Enums\AssetAuditStatus;
use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\AssetAudit;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(fn () => (new RolePermissionSeeder())->run());

it('auditor can list, employee cannot', function () {
    $this->actingAs(User::factory()->create(['role' => 'auditor']))
        ->get('/auditor/asset-audits')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Auditor/AssetAudits/Index'));

    $this->actingAs(User::factory()->create(['role' => 'employee']))
        ->get('/auditor/asset-audits')->assertForbidden();
});

it('opens an audit via POST and snapshots assets', function () {
    Asset::factory()->count(3)->create(['current_status' => AssetStatus::InStock->value]);
    $this->actingAs(User::factory()->create(['role' => 'auditor']))
        ->post('/auditor/asset-audits', ['scope_type' => 'all'])->assertRedirect();

    $audit = AssetAudit::latest()->first();
    expect($audit->total_lines)->toBe(3);
    expect($audit->status)->toBe(AssetAuditStatus::InProgress);
});

it('counts a line and applies a resolution over HTTP', function () {
    Asset::factory()->create(['current_status' => AssetStatus::InStock->value]);
    $auditor = User::factory()->create(['role' => 'auditor']);
    $this->actingAs($auditor)->post('/auditor/asset-audits', ['scope_type' => 'all']);
    $audit = AssetAudit::latest()->first();
    $line  = $audit->lines()->first();

    $this->actingAs($auditor)
        ->post("/auditor/asset-audits/{$audit->id}/lines/{$line->id}/count", ['result' => 'missing'])
        ->assertRedirect();
    expect($line->fresh()->is_discrepancy)->toBeTrue();

    $this->actingAs($auditor)
        ->post("/auditor/asset-audits/{$audit->id}/lines/{$line->id}/resolve", ['action' => 'marked_lost'])
        ->assertRedirect();
    expect($line->fresh()->asset->current_status)->toBe(AssetStatus::Lost);
});

it('completes an audit', function () {
    Asset::factory()->create(['current_status' => AssetStatus::InStock->value]);
    $auditor = User::factory()->create(['role' => 'auditor']);
    $this->actingAs($auditor)->post('/auditor/asset-audits', ['scope_type' => 'all']);
    $audit = AssetAudit::latest()->first();

    $this->actingAs($auditor)->post("/auditor/asset-audits/{$audit->id}/complete")->assertRedirect();
    expect($audit->fresh()->status)->toBe(AssetAuditStatus::Completed);
});

it('cancel requires a reason', function () {
    Asset::factory()->create(['current_status' => AssetStatus::InStock->value]);
    $auditor = User::factory()->create(['role' => 'auditor']);
    $this->actingAs($auditor)->post('/auditor/asset-audits', ['scope_type' => 'all']);
    $audit = AssetAudit::latest()->first();

    $this->actingAs($auditor)->post("/auditor/asset-audits/{$audit->id}/cancel", [])
        ->assertSessionHasErrors(['reason']);
});
