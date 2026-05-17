<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('serves the OpenAPI yaml spec at /api/v1/openapi.yaml', function () {
    $response = $this->get('/api/v1/openapi.yaml');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('yaml');
    expect($response->getContent())->toContain('openapi: 3.1.0');
    expect($response->getContent())->toContain('CIHRMS Public API');
});

it('rejects /api/v1/me without a token', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('returns the caller profile via /api/v1/me with a Sanctum token', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonStructure([
            'user'  => ['id', 'name', 'email', 'role'],
            'token' => ['id', 'name', 'abilities'],
        ]);
});

it('returns 403 for /api/v1/employees without employees.view permission', function () {
    $user = User::factory()->create(['role' => 'employee']);
    Sanctum::actingAs($user);

    // Default `employee` role does not hold `employees.view`.
    $this->getJson('/api/v1/employees')->assertForbidden();
});
