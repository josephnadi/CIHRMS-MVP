<?php

use App\Models\ApiTokenMetadata;
use App\Models\User;

it('exposes /api/v1/health without authentication', function () {
    $response = $this->getJson('/api/v1/health');

    $response->assertStatus(200);
    $response->assertJsonStructure(['service', 'version', 'time', 'database', 'api' => ['version']]);
    expect($response->json('api.version'))->toBe('v1');
});

it('serves the OpenAPI spec as YAML at /api/v1/openapi.yaml', function () {
    $response = $this->get('/api/v1/openapi.yaml');

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toContain('yaml');
    expect($response->getContent())->toContain('openapi: 3.1.0');
    expect($response->getContent())->toContain('CIHRMS Public API');
});

it('serves the OpenAPI spec as JSON at /api/v1/openapi.json', function () {
    $response = $this->getJson('/api/v1/openapi.json');

    $response->assertStatus(200);
    $response->assertJsonStructure(['openapi', 'info', 'paths', 'components']);
});

it('refuses /api/v1/employees without a Sanctum token', function () {
    $this->getJson('/api/v1/employees')->assertStatus(401);
});

it('refuses /api/v1/employees when token lacks employees:read scope', function () {
    $user  = User::factory()->create();
    // Issue a token with only payroll:read — missing employees:read
    $token = $user->createToken('test', ['payroll:read'])->plainTextToken;

    $this->getJson('/api/v1/employees', ['Authorization' => "Bearer {$token}"])
         ->assertStatus(403)
         ->assertJson(['required' => 'employees:read']);
});

it('allows /api/v1/employees with employees:read scope', function () {
    // Pin the role to one that holds `employees.view` (the controller also
    // gates on RBAC, not just Sanctum scope — factory random role would fail).
    $user  = User::factory()->create(['role' => 'hr_admin']);
    $token = $user->createToken('test', ['employees:read'])->plainTextToken;

    $this->getJson('/api/v1/employees', ['Authorization' => "Bearer {$token}"])
         ->assertStatus(200)
         ->assertJsonStructure(['data', 'meta']);
});

it('blocks a token after it has been revoked via metadata', function () {
    $user  = User::factory()->create();
    $issued = $user->createToken('test', ['employees:read']);
    $token  = $issued->plainTextToken;

    // Record metadata + revoke
    ApiTokenMetadata::create([
        'token_id'    => $issued->accessToken->id,
        'revoked_at'  => now(),
    ]);

    $this->getJson('/api/v1/employees', ['Authorization' => "Bearer {$token}"])
         ->assertStatus(401);
});

it('blocks a token whose sidecar expiry has passed', function () {
    $user  = User::factory()->create();
    $issued = $user->createToken('test', ['employees:read']);

    ApiTokenMetadata::create([
        'token_id'   => $issued->accessToken->id,
        'expires_at' => now()->subDay(),
    ]);

    $this->getJson('/api/v1/employees', ['Authorization' => "Bearer {$issued->plainTextToken}"])
         ->assertStatus(401);
});

it('returns token introspection at /api/v1/me', function () {
    $user  = User::factory()->create(['name' => 'Test User']);
    $token = $user->createToken('partner-token', ['payroll:read', 'employees:read'])->plainTextToken;

    $response = $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$token}"]);

    $response->assertStatus(200);
    expect($response->json('user.name'))->toBe('Test User');
    expect($response->json('token.abilities'))->toContain('payroll:read');
    expect($response->json('token.abilities'))->toContain('employees:read');
});
