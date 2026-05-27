<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SsoIdentityProvider;
use App\Services\Auth\TwoFactorService;
use App\Services\Sso\SsoOrchestrator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SsoController extends Controller
{
    public function __construct(private readonly SsoOrchestrator $sso) {}

    /** Send the user to the IdP. */
    public function initiate(Request $request, string $slug): RedirectResponse
    {
        $provider = SsoIdentityProvider::active()->where('slug', $slug)->firstOrFail();
        $adapter  = $this->sso->adapterFor($provider);

        $intended = self::safeIntended($request->query('intended'));
        $bundle   = $adapter->initiate($provider, $intended);

        // Stash the session payload + provider id under a single namespaced key
        // so the callback can find them after the IdP round-trip.
        $request->session()->put('sso.flow', [
            'provider_id' => $provider->id,
            'session'     => $bundle['session'],
        ]);

        return redirect()->away($bundle['redirect_url']);
    }

    /** Handle the IdP's response. */
    public function callback(Request $request, string $slug): RedirectResponse
    {
        $provider = SsoIdentityProvider::active()->where('slug', $slug)->firstOrFail();
        $adapter  = $this->sso->adapterFor($provider);

        $flow = $request->session()->pull('sso.flow', []);
        if (($flow['provider_id'] ?? null) !== $provider->id) {
            return redirect()->route('login')->withErrors(['sso' => 'SSO session expired. Try again.']);
        }

        $result = $adapter->handleCallback($provider, $request->all(), $flow['session'] ?? []);
        $user   = $this->sso->processCallback($provider, $result, $request);

        if (! $user) {
            return redirect()->route('login')->withErrors([
                'sso' => $result->error ?: 'SSO sign-in failed.',
            ]);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        // Drop any leftover 2FA-fresh marker from a previous session — mirrors
        // the password-login flow (H4 audit fix). Without this an SSO login
        // could inherit a previous session's "recently challenged" status.
        app(TwoFactorService::class)->clearFresh($user);

        $intended = self::safeIntended($flow['session']['intended'] ?? null);
        return redirect()->intended($intended);
    }

    /**
     * Constrain the post-login redirect target to the application host (or a
     * relative path). External hosts are dropped to prevent open-redirect /
     * phishing pipelines via `?intended=https://attacker.com`.
     */
    public static function safeIntended(?string $intended): string
    {
        if (!is_string($intended) || $intended === '') {
            return route('dashboard');
        }
        // Protocol-relative URLs (`//attacker.com/x`) parse with no `host`
        // key but still cause the browser to navigate cross-origin. Reject
        // them explicitly before parse_url's host check would let them slip.
        if (str_starts_with($intended, '//') || str_starts_with($intended, '\\\\')) {
            return route('dashboard');
        }
        $parsed = @parse_url($intended);
        if ($parsed === false) {
            return route('dashboard');
        }
        $host = $parsed['host'] ?? null;
        if ($host !== null) {
            $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
            if ($host !== $appHost) {
                return route('dashboard');
            }
        }
        return $intended;
    }
}
