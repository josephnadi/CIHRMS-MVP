<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    RateLimiter::clear('login_staff_global:staff-x');
});

it('locks out after 10 failed attempts on the same staff_id across rotating IPs', function () {
    User::factory()->create(['name' => 'Real', 'staff_id' => 'STAFF-X', 'password' => bcrypt('correct')]);

    for ($i = 0; $i < 10; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])->post('/login', [
            'name'     => 'Real',
            'staff_id' => 'STAFF-X',
            'password' => 'wrong',
        ]);
    }

    // 11th from yet another IP — Layer 1 (IP throttle) hasn't fired (only 1 attempt per IP),
    // but Layer 2 (global staff_id) should now block.
    $resp = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])->post('/login', [
        'name'     => 'Real',
        'staff_id' => 'STAFF-X',
        'password' => 'wrong',
    ]);

    $resp->assertSessionHasErrors('staff_id');
    expect(strtolower((string) collect($resp->getSession()->get('errors')->all())->first()))
        ->toContain('too many');
});

it('clears the global staff_id counter on a successful login', function () {
    User::factory()->create(['name' => 'Real', 'staff_id' => 'STAFF-X', 'password' => bcrypt('correct')]);

    // Burn 3 failures
    for ($i = 0; $i < 3; $i++) {
        $this->post('/login', ['name' => 'Real', 'staff_id' => 'STAFF-X', 'password' => 'wrong']);
    }

    // Successful login resets both counters
    $this->post('/login', ['name' => 'Real', 'staff_id' => 'STAFF-X', 'password' => 'correct'])
        ->assertRedirect();

    expect(RateLimiter::attempts('login_staff_global:staff-x'))->toBe(0);
});
