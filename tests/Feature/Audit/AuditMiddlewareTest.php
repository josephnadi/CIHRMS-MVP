<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Guards the contract that authenticated mutating requests write an
 * audit_logs row without a queue worker — by means of
 * dispatchAfterResponse() in App\Http\Middleware\AuditTrail.
 *
 * Regression: until this test existed, the middleware queued to the `audit`
 * queue and nothing drained it in dev, so the audit trail silently captured
 * zero events.
 */

it('writes an audit_logs row on an authenticated POST request', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/dashboard')
        ->post('/locale', ['locale' => 'en'])
        ->assertRedirect();

    $log = AuditLog::latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($user->id)
        ->and($log->method)->toBe('POST')
        ->and($log->path)->toBe('/locale')
        ->and($log->payload)->toMatchArray(['locale' => 'en'])
        ->and($log->row_hash)->not->toBeEmpty()
        ->and($log->chain_position)->toBe(1);
});

it('strips sensitive fields from the captured payload', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/dashboard')
        ->post('/locale', [
            'locale'   => 'en',
            'password' => 'should-not-be-logged',
            '_token'   => 'csrf-should-not-be-logged',
        ])
        ->assertRedirect();

    $log = AuditLog::latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->payload)->not->toHaveKey('password')
        ->and($log->payload)->not->toHaveKey('_token')
        ->and($log->payload)->toHaveKey('locale');
});

it('does not audit GET requests', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard');

    expect(AuditLog::count())->toBe(0);
});

it('does not audit unauthenticated requests', function () {
    $this->post('/locale', ['locale' => 'en']);

    expect(AuditLog::count())->toBe(0);
});

/**
 * Regression for the "audit trail does not function" bug.
 *
 * Tests run with QUEUE_CONNECTION=sync (phpunit.xml) which masks the prod
 * behaviour. Force the `database` driver mid-test to prove that the
 * middleware writes audit rows WITHOUT a queue worker running — which is
 * exactly the dev/prod path that was silently dropping events.
 */
it('writes audit_logs even when QUEUE_CONNECTION=database and no worker runs', function () {
    Config::set('queue.default', 'database');

    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/dashboard')
        ->post('/locale', ['locale' => 'en'])
        ->assertRedirect();

    expect(DB::table('jobs')->where('queue', 'audit')->count())->toBe(0)
        ->and(AuditLog::count())->toBe(1);
});
