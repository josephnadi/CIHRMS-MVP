<?php

use App\Enums\SsoLoginOutcome;
use App\Enums\SsoProviderType;
use App\Models\SsoIdentityProvider;
use App\Models\SsoLoginAttempt;
use App\Models\User;
use App\Models\UserIdentityLink;
use App\Services\Sso\SsoAuthResult;
use App\Services\Sso\SsoOrchestrator;
use Illuminate\Http\Request;

beforeEach(function () {
    /** @var SsoOrchestrator $sso */
    $this->sso = app(SsoOrchestrator::class);

    $this->provider = SsoIdentityProvider::create([
        'slug'           => 'test-idp',
        'name'           => 'Test IdP',
        'type'           => SsoProviderType::Oidc->value,
        'is_active'      => true,
        'auto_provision' => false,
        'default_role'   => 'employee',
        'config'         => ['client_id' => 'x'],
    ]);
});

it('matches an existing user by email and creates an identity link', function () {
    $existing = User::factory()->create(['email' => 'kwame@example.gov.gh', 'role' => 'employee']);

    $result = SsoAuthResult::ok('sub-001', 'kwame@example.gov.gh', 'Kwame Mensah', ['sub' => 'sub-001']);
    $user   = $this->sso->processCallback($this->provider, $result, Request::create('/cb'));

    expect($user?->id)->toBe($existing->id);
    expect(UserIdentityLink::where('provider_id', $this->provider->id)
        ->where('user_id', $existing->id)->count())->toBe(1);

    expect(SsoLoginAttempt::where('outcome', 'success')->count())->toBe(1);
});

it('reuses an existing link on subsequent logins without creating a new user', function () {
    $existing = User::factory()->create(['email' => 'ama@example.gov.gh']);

    $this->sso->processCallback($this->provider,
        SsoAuthResult::ok('sub-002', 'ama@example.gov.gh', 'Ama', ['sub' => 'sub-002']),
        Request::create('/cb'));

    $userCountBefore = User::count();
    $this->sso->processCallback($this->provider,
        SsoAuthResult::ok('sub-002', 'ama@example.gov.gh', 'Ama Updated', ['sub' => 'sub-002']),
        Request::create('/cb'));

    expect(User::count())->toBe($userCountBefore); // no duplicate
    expect(User::find($existing->id)->name)->toBe('Ama Updated'); // display synced
});

it('refuses to provision when auto_provision is OFF and no user matches', function () {
    $result = SsoAuthResult::ok('sub-003', 'newcomer@example.gov.gh', 'Newcomer', ['sub' => 'sub-003']);
    $user = $this->sso->processCallback($this->provider, $result, Request::create('/cb'));

    expect($user)->toBeNull();
    expect(SsoLoginAttempt::where('outcome', 'provision_failed')->count())->toBe(1);
});

it('JIT-provisions a new user when auto_provision is ON', function () {
    $this->provider->update(['auto_provision' => true]);

    $result = SsoAuthResult::ok('sub-004', 'jit@example.gov.gh', 'JIT User', ['sub' => 'sub-004']);
    $user = $this->sso->processCallback($this->provider, $result, Request::create('/cb'));

    expect($user)->not->toBeNull();
    expect($user->email)->toBe('jit@example.gov.gh');
    expect($user->name)->toBe('JIT User');
    expect(UserIdentityLink::where('user_id', $user->id)->count())->toBe(1);
});

it('enforces the allowed-email-domains policy', function () {
    $this->provider->update([
        'auto_provision' => true,
        'allowed_email_domains' => ['cihrm.gov.gh'],
    ]);

    $bad  = SsoAuthResult::ok('sub-005', 'someone@gmail.com',     'Outsider',  ['sub' => 'sub-005']);
    $good = SsoAuthResult::ok('sub-006', 'someone@cihrm.gov.gh',  'Insider',   ['sub' => 'sub-006']);

    expect($this->sso->processCallback($this->provider, $bad,  Request::create('/cb')))->toBeNull();
    expect($this->sso->processCallback($this->provider, $good, Request::create('/cb')))->not->toBeNull();
});

it('writes an audit entry on failed adapter results', function () {
    $result = SsoAuthResult::failure(SsoLoginOutcome::InvalidState, 'state mismatch');
    $this->sso->processCallback($this->provider, $result, Request::create('/cb'));

    expect(SsoLoginAttempt::where('outcome', 'invalid_state')->count())->toBe(1);
});
