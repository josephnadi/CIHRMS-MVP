<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

it('deletes all stored sessions for the user on successful password reset', function () {
    config(['session.driver' => 'database']);

    $user = User::factory()->create(['email' => 'victim@example.com']);
    $token = Password::createToken($user);

    DB::table('sessions')->insert([
        [
            'id'            => 'sess-1',
            'user_id'       => $user->id,
            'ip_address'    => '127.0.0.1',
            'user_agent'    => 'phpunit',
            'payload'       => 'x',
            'last_activity' => time(),
        ],
        [
            'id'            => 'sess-2',
            'user_id'       => $user->id,
            'ip_address'    => '127.0.0.1',
            'user_agent'    => 'attacker',
            'payload'       => 'y',
            'last_activity' => time(),
        ],
    ]);

    $resp = $this->post('/reset-password', [
        'token'                 => $token,
        'email'                 => 'victim@example.com',
        'password'              => 'CorrectHorse123!Battery',
        'password_confirmation' => 'CorrectHorse123!Battery',
    ]);

    $resp->assertRedirect(route('login'));
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});
