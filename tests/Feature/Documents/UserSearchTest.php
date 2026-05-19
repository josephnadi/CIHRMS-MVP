<?php

use App\Models\User;

it('returns no results for queries under 2 characters', function () {
    $user = User::factory()->create([
        'role'        => 'employee',
        'permissions' => ['documents.view'],
    ]);

    $this->actingAs($user)
        ->getJson(route('documents.users.search', ['q' => 'a']))
        ->assertOk()
        ->assertJsonPath('data', []);
});

it('returns matching users by name', function () {
    $user = User::factory()->create([
        'role'        => 'employee',
        'permissions' => ['documents.view'],
    ]);
    User::factory()->create(['name' => 'Akua Mensah',   'staff_id' => 'GH-001']);
    User::factory()->create(['name' => 'Akwesi Boateng','staff_id' => 'GH-002']);
    User::factory()->create(['name' => 'Mary Johnson',  'staff_id' => 'GH-003']);

    $response = $this->actingAs($user)
        ->getJson(route('documents.users.search', ['q' => 'Akw']))
        ->assertOk();

    $names = collect($response->json('data'))->pluck('name')->all();
    expect($names)->toContain('Akwesi Boateng');
    expect($names)->not->toContain('Mary Johnson');
});

it('returns matching users by staff_id', function () {
    $user = User::factory()->create([
        'role'        => 'employee',
        'permissions' => ['documents.view'],
    ]);
    User::factory()->create(['name' => 'Test Person', 'staff_id' => 'GH-HR-821']);

    $response = $this->actingAs($user)
        ->getJson(route('documents.users.search', ['q' => 'HR-821']))
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('staff_id')->all();
    expect($ids)->toContain('GH-HR-821');
});

it('excludes the requesting user from results', function () {
    $user = User::factory()->create([
        'name'        => 'Searcher User',
        'role'        => 'employee',
        'permissions' => ['documents.view'],
    ]);

    $response = $this->actingAs($user)
        ->getJson(route('documents.users.search', ['q' => 'Searcher']))
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->not->toContain($user->id);
});

it('forbids the search to users without documents.view', function () {
    $user = User::factory()->create([
        'role'        => 'employee',
        'permissions' => [],
    ]);

    $this->actingAs($user)
        ->getJson(route('documents.users.search', ['q' => 'something']))
        ->assertForbidden();
});
