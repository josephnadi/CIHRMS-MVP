<?php

declare(strict_types=1);

use App\Services\Identity\Providers\NiaOfficialProvider;
use Illuminate\Support\Facades\Http;

/**
 * Tests the NIA verification provider's HTTP wire format. We don't hit the
 * real NIA endpoint — the request shape, auth header, and the three response
 * branches (matched / not-matched / transport error) are what matter at this
 * layer. The IdentityVerificationService tests cover the persistence half.
 */

beforeEach(function () {
    $this->provider = new NiaOfficialProvider(
        baseUrl: 'https://api.nia.example',
        apiKey:  'test-key-123',
        timeoutSeconds: 5,
    );
});

it('sends a bearer-authenticated POST with normalized pin + personal payload', function () {
    Http::fake([
        'api.nia.example/*' => Http::response(['matched' => true, 'pin' => 'GHA-123456789-1'], 200),
    ]);

    $result = $this->provider->verify('  gha-123456789-1  ', [
        'full_name'     => 'Ama Asante',
        'date_of_birth' => '1990-04-15',
    ]);

    expect($result->success)->toBeTrue();
    expect($result->expiresAt)->not->toBeNull();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.nia.example/verify'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer test-key-123')
            && $request['pin'] === 'GHA-123456789-1'                  // upper-cased + whitespace trimmed
            && $request['full_name'] === 'Ama Asante'
            && $request['date_of_birth'] === '1990-04-15';
    });
});

it('returns failure with NIA-provided reason when no match', function () {
    Http::fake([
        'api.nia.example/*' => Http::response([
            'matched' => false,
            'reason'  => 'PIN found but biographic data does not match',
        ], 200),
    ]);

    $result = $this->provider->verify('GHA-111111111-1');

    expect($result->success)->toBeFalse();
    expect($result->reason)->toBe('PIN found but biographic data does not match');
});

it('returns failure when NIA returns non-2xx', function () {
    Http::fake([
        'api.nia.example/*' => Http::response(['error' => 'rate_limited'], 429),
    ]);

    $result = $this->provider->verify('GHA-222222222-2');

    expect($result->success)->toBeFalse();
    expect($result->reason)->toContain('429');
});

it('returns failure with transport-error reason on network failure', function () {
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

    $result = $this->provider->verify('GHA-333333333-3');

    expect($result->success)->toBeFalse();
    expect($result->reason)->toContain('transport error');
    expect($result->reason)->toContain('timeout');
});

it('sets verification expiry 12 months out on success', function () {
    Http::fake([
        'api.nia.example/*' => Http::response(['matched' => true], 200),
    ]);

    $result = $this->provider->verify('GHA-444444444-4');

    expect($result->success)->toBeTrue();
    expect($result->expiresAt)->not->toBeNull();
    // Roughly 12 months in the future (allowing for date arithmetic).
    $months = (int) (((new \DateTimeImmutable())->diff($result->expiresAt))->days / 30);
    expect($months)->toBeGreaterThanOrEqual(11);
    expect($months)->toBeLessThanOrEqual(13);
});

it('reports its kind as nia_official', function () {
    expect($this->provider->kind())->toBe('nia_official');
});

it('falls back to generic reason when NIA omits one', function () {
    Http::fake([
        'api.nia.example/*' => Http::response(['matched' => false], 200),
    ]);

    $result = $this->provider->verify('GHA-555555555-5');

    expect($result->success)->toBeFalse();
    expect($result->reason)->toContain('no match');
});
