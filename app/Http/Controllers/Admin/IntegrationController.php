<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Integrations\OAuth\OAuthFlow;
use App\Integrations\OAuth\TokenStore;
use App\Models\Integration;
use App\Models\IntegrationEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationController extends Controller
{
    public function __construct(
        protected OAuthFlow $flow,
        protected TokenStore $tokens,
    ) {}

    public function index(): Response
    {
        $registered = collect((array) config('integrations.drivers'))
            ->map(function ($cfg, $providerKey) {
                $integration = Integration::where('provider', $providerKey)->first();

                return [
                    'provider'      => $providerKey,
                    'capability'    => $this->capabilityFor($providerKey),
                    'display_name'  => $cfg['display_name'] ?? Str::headline($providerKey),
                    'logo'          => $cfg['logo'] ?? null,
                    'driver_ready'  => ! empty($cfg['class']) && class_exists($cfg['class']),
                    'configured'    => ! empty($cfg['client_id']) || ! empty($cfg['access_token']) || ! empty($cfg['webhook_url']),
                    'is_enabled'    => (bool) $integration?->is_enabled,
                    'connected_at'  => $integration?->connected_at?->toIso8601String(),
                    'token_present' => (bool) $integration?->tokens()->exists(),
                ];
            })
            ->values();

        $recentEvents = IntegrationEvent::with('integration:id,provider,display_name')
            ->latest('id')
            ->limit(20)
            ->get(['id', 'integration_id', 'direction', 'event_type', 'status', 'error', 'created_at']);

        return Inertia::render('Admin/Integrations/Index', [
            'integrations'  => $registered,
            'recentEvents'  => $recentEvents,
            'capabilityMap' => config('integrations.capabilities'),
            'featureFlags'  => config('integrations.feature_flags'),
            'activeModule'  => 'integrations',
        ]);
    }

    public function connect(Request $request, string $provider): RedirectResponse
    {
        $cfg = config("integrations.drivers.{$provider}");
        abort_unless($cfg, 404, 'Unknown provider.');

        $capability = $this->capabilityFor($provider);

        // Provider has no OAuth flow (e.g. webhook-only Teams) — flip the flag and persist.
        if (empty($cfg['authorize_url'])) {
            Integration::updateOrCreate(
                ['provider' => $provider],
                [
                    'capability'   => $capability,
                    'display_name' => $cfg['display_name'] ?? Str::headline($provider),
                    'logo'         => $cfg['logo'] ?? null,
                    'is_enabled'   => true,
                    'connected_by' => $request->user()?->id,
                    'connected_at' => now(),
                ],
            );

            return back()->with('success', "{$cfg['display_name']} enabled.");
        }

        $state = Str::random(40);
        Session::put("oauth_state:{$provider}", [
            'state'      => $state,
            'capability' => $capability,
            'user_id'    => $request->user()?->id,
        ]);

        return redirect()->away(
            $this->flow->authorizationUrl($provider, $state)
        );
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $stored = Session::pull("oauth_state:{$provider}");
        abort_unless($stored && hash_equals($stored['state'], (string) $request->query('state')), 419, 'Invalid OAuth state.');

        $this->flow->exchangeCode(
            provider:   $provider,
            capability: $stored['capability'],
            code:       (string) $request->query('code'),
            userId:     $stored['user_id'],
        );

        return redirect()->route('admin.integrations.index')->with('success', "Connected {$provider}.");
    }

    public function disconnect(string $provider): RedirectResponse
    {
        $integration = Integration::where('provider', $provider)->firstOrFail();
        $integration->tokens()->delete();
        $integration->update(['is_enabled' => false, 'connected_at' => null, 'connected_by' => null]);

        return back()->with('success', "Disconnected {$integration->display_name}.");
    }

    protected function capabilityFor(string $provider): string
    {
        // Use the manager's primary-capability resolver so multi-capability providers
        // (ms_graph, google) get the same badge whether or not they're the active default.
        foreach ((array) config('integrations.capabilities') as $capability => $configured) {
            if ($configured === $provider) {
                return $capability;
            }
        }

        $declared = (array) config("integrations.drivers.{$provider}.capability_classes", []);
        if (! empty($declared)) {
            return (string) array_key_first($declared);
        }

        return match (true) {
            str_contains($provider, 'crm')    => 'crm',
            str_contains($provider, 'sign')   => 'esign',
            str_contains($provider, 'whats'),
            str_contains($provider, 'slack'),
            str_contains($provider, 'teams')  => 'messaging',
            str_contains($provider, 'graph'),
            str_contains($provider, 'google') => 'files',
            default                           => 'unknown',
        };
    }
}
