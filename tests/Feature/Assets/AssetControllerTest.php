<?php

declare(strict_types=1);

use App\Models\Asset;
use App\Models\Employee;
use App\Models\User;

use function Pest\Laravel\actingAs;

beforeEach(fn () => $this->seed(\Database\Seeders\RolePermissionSeeder::class));

it('allows hr_admin to register an asset', function () {
    $hr = User::factory()->create(['role' => 'hr_admin']);

    actingAs($hr)->post('/assets', [
        'asset_tag' => 'AST-CTRL-001', 'name' => 'Test Laptop', 'category' => 'laptop',
    ])->assertRedirect();

    expect(Asset::where('asset_tag', 'AST-CTRL-001')->exists())->toBeTrue();
});

it('forbids a regular employee from registering (RBAC deny)', function () {
    $emp = User::factory()->create(['role' => 'employee']);

    actingAs($emp)->post('/assets', [
        'asset_tag' => 'AST-CTRL-002', 'name' => 'Test', 'category' => 'laptop',
    ])->assertForbidden();
});

it('lets a manager assign an asset', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $emp = Employee::factory()->create();
    $asset = Asset::create(['asset_tag' => 'AST-CTRL-003', 'name' => 'Test', 'category' => 'laptop']);

    actingAs($manager)->post("/assets/{$asset->id}/assign", [
        'employee_id' => $emp->id,
    ])->assertRedirect();

    expect($asset->fresh()->current_status->value)->toBe('assigned');
});

it('lets an authenticated user view their own assets page', function () {
    $emp = Employee::factory()->create();

    actingAs($emp->user)->get('/assets/my')->assertOk();
});
