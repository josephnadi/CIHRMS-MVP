<?php

declare(strict_types=1);

use App\Models\SkillCatalogItem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
});

it('learning.manage user can add a catalog skill', function () {
    $u = User::factory()->create(['role' => 'hr_admin']);

    $this->actingAs($u)
        ->post('/learning/skills', [
            'name'        => 'Financial Reporting',
            'category'    => 'technical',
            'description' => 'IFRS-compliant statutory reporting',
        ])
        ->assertRedirect();

    expect(SkillCatalogItem::where('name', 'Financial Reporting')->exists())->toBeTrue();
});

it('rejects duplicate skill names with a 422-style session error', function () {
    $u = User::factory()->create(['role' => 'hr_admin']);
    SkillCatalogItem::create(['name' => 'Python']);

    $this->actingAs($u)
        ->post('/learning/skills', ['name' => 'Python'])
        ->assertSessionHasErrors('name');

    expect(SkillCatalogItem::where('name', 'Python')->count())->toBe(1);
});

it('rejects empty skill name', function () {
    $u = User::factory()->create(['role' => 'hr_admin']);

    $this->actingAs($u)
        ->post('/learning/skills', ['name' => ''])
        ->assertSessionHasErrors('name');
});

it('rejects unknown category', function () {
    $u = User::factory()->create(['role' => 'hr_admin']);

    $this->actingAs($u)
        ->post('/learning/skills', ['name' => 'X', 'category' => 'not_a_category'])
        ->assertSessionHasErrors('category');
});

it('blocks employees without learning.manage', function () {
    $u = User::factory()->create(['role' => 'employee']);

    $this->actingAs($u)
        ->post('/learning/skills', ['name' => 'X'])
        ->assertForbidden();

    expect(SkillCatalogItem::count())->toBe(0);
});

it('catalog skills appear in the skills matrix even with zero employees', function () {
    $u = User::factory()->create(['role' => 'hr_admin']);
    SkillCatalogItem::create(['name' => 'Procurement Compliance', 'category' => 'compliance']);

    $response = $this->actingAs($u)->get('/learning/skills-matrix');

    $response->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Learning/SkillsMatrix')
            ->where('matrix.skills', fn ($skills) => collect($skills)->contains(
                fn ($s) => $s['name'] === 'Procurement Compliance' && $s['count'] === 0,
            ))
        );
});
