<?php

use App\Models\User;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('users can authenticate using the login screen', function () {
    // CIHRMS authenticates with name + staff_id + password.
    // See \App\Http\Requests\Auth\LoginRequest::authenticate().
    $user = User::factory()->create([
        'name'     => 'Akua Mensah',
        'staff_id' => 'GH-HR-AUTH-1',
    ]);

    $response = $this->post('/login', [
        'name'     => $user->name,
        'staff_id' => $user->staff_id,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('users can not authenticate with an invalid staff_id', function () {
    $user = User::factory()->create([
        'name'     => 'Akua Mensah',
        'staff_id' => 'GH-HR-AUTH-2',
    ]);

    $this->post('/login', [
        'name'     => $user->name,
        'staff_id' => 'WRONG-STAFF-ID',
        'password' => 'password',
    ]);

    $this->assertGuest();
});

test('users can not authenticate with an invalid password', function () {
    $user = User::factory()->create([
        'name'     => 'Akua Mensah',
        'staff_id' => 'GH-HR-AUTH-3',
    ]);

    $this->post('/login', [
        'name'     => $user->name,
        'staff_id' => $user->staff_id,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('login requires the password field', function () {
    $user = User::factory()->create([
        'name'     => 'Akua Mensah',
        'staff_id' => 'GH-HR-AUTH-4',
    ]);

    $this->post('/login', [
        'name'     => $user->name,
        'staff_id' => $user->staff_id,
    ])->assertSessionHasErrors('password');

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertRedirect('/');
});
