<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SsoIdentityProvider;
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

        $intended = (string) ($request->query('intended') ?: route('dashboard'));
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

        $intended = $flow['session']['intended'] ?? route('dashboard');
        return redirect()->intended($intended);
    }
}
