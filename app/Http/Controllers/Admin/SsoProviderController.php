<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SsoProviderType;
use App\Http\Controllers\Controller;
use App\Models\SsoIdentityProvider;
use App\Models\SsoLoginAttempt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SsoProviderController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->hasPermission('sso.manage'), 403);

        $providers = SsoIdentityProvider::ordered()->get();
        $recentAttempts = SsoLoginAttempt::with(['provider:id,name,slug', 'user:id,name'])
            ->latest()
            ->limit(50)
            ->get();

        // Auth-health analytics for the redesigned page
        $sevenDaysAgo = now()->subDays(7);
        $attempts7d   = SsoLoginAttempt::where('created_at', '>=', $sevenDaysAgo)->count();
        $success7d    = SsoLoginAttempt::where('created_at', '>=', $sevenDaysAgo)
            ->where('outcome', 'success')->count();

        $stats = [
            'providers_active' => SsoIdentityProvider::active()->count(),
            'providers_total'  => SsoIdentityProvider::count(),
            'attempts_today'   => SsoLoginAttempt::whereDate('created_at', today())->count(),
            'success_today'    => SsoLoginAttempt::whereDate('created_at', today())
                ->where('outcome', 'success')->count(),
            'failed_today'     => SsoLoginAttempt::whereDate('created_at', today())
                ->where('outcome', '!=', 'success')->count(),
            'attempts_7d'      => $attempts7d,
            'success_rate_7d'  => $attempts7d > 0 ? (int) round(($success7d / $attempts7d) * 100) : 100,
            'links_total'      => \App\Models\UserIdentityLink::count(),
        ];

        // Outcome composition (last 30 days)
        $outcomeBreakdown = SsoLoginAttempt::query()
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('outcome, COUNT(*) as c')
            ->groupBy('outcome')
            ->pluck('c', 'outcome')
            ->all();

        // 7-day attempts trend (cross-DB safe — group in PHP)
        $trendRows = SsoLoginAttempt::query()
            ->where('created_at', '>=', $sevenDaysAgo->copy()->startOfDay())
            ->get(['created_at', 'outcome']);

        $attemptsTrend = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = now()->subDays($i)->startOfDay();
            $dayRows = $trendRows->filter(fn ($r) => $r->created_at?->isSameDay($day));
            $attemptsTrend[] = [
                'label'   => $day->format('D'),
                'date'    => $day->toDateString(),
                'value'   => $dayRows->count(),
                'success' => $dayRows->where('outcome', 'success')->count(),
            ];
        }

        return Inertia::render('Admin/Sso/Providers', [
            'providers'        => $providers->map(fn ($p) => $this->present($p)),
            'recent_attempts'  => $recentAttempts->map(fn ($a) => [
                'id'           => $a->id,
                'provider'     => $a->provider?->name,
                'user'         => $a->user?->name,
                'email'        => $a->external_email,
                'outcome'      => $a->outcome?->value,
                'error'        => $a->error,
                'ip'           => $a->ip_address,
                'created_at'   => optional($a->created_at)->toIso8601String(),
            ]),
            'stats'            => $stats,
            'outcomeBreakdown' => $outcomeBreakdown,
            'attemptsTrend'    => $attemptsTrend,
            'activeModule'     => 'sso',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('sso.manage'), 403);

        $data = $request->validate([
            'slug'           => ['required', 'string', 'max:64', 'unique:identity_providers,slug', 'regex:/^[a-z0-9-]+$/'],
            'name'           => ['required', 'string', 'max:255'],
            'type'           => ['required', Rule::enum(SsoProviderType::class)],
            'auto_provision' => ['boolean'],
            'default_role'   => ['nullable', 'string', 'max:32'],
            'config'         => ['required', 'array'],
            'claim_mapping'  => ['nullable', 'array'],
            'allowed_email_domains' => ['nullable', 'array'],
            'button_label'   => ['nullable', 'string', 'max:64'],
            'button_icon'    => ['nullable', 'string', 'max:64'],
        ]);

        SsoIdentityProvider::create($data + ['is_active' => true]);

        return back()->with('success', 'SSO provider registered.');
    }

    public function update(Request $request, SsoIdentityProvider $provider): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('sso.manage'), 403);

        $data = $request->validate([
            'name'           => ['sometimes', 'string', 'max:255'],
            'auto_provision' => ['sometimes', 'boolean'],
            'default_role'   => ['sometimes', 'nullable', 'string', 'max:32'],
            'config'         => ['sometimes', 'array'],
            'claim_mapping'  => ['sometimes', 'nullable', 'array'],
            'allowed_email_domains' => ['sometimes', 'nullable', 'array'],
            'button_label'   => ['sometimes', 'nullable', 'string', 'max:64'],
            'button_icon'    => ['sometimes', 'nullable', 'string', 'max:64'],
            'is_active'      => ['sometimes', 'boolean'],
        ]);

        $provider->update($data);
        return back()->with('success', "Provider {$provider->slug} updated.");
    }

    public function destroy(Request $request, SsoIdentityProvider $provider): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('sso.manage'), 403);
        $provider->delete();
        return back()->with('success', "Provider {$provider->slug} archived.");
    }

    private function present(SsoIdentityProvider $p): array
    {
        return [
            'id'             => $p->id,
            'slug'           => $p->slug,
            'name'           => $p->name,
            'type'           => $p->type?->value,
            'type_label'     => $p->type?->label(),
            'is_active'      => (bool) $p->is_active,
            'auto_provision' => (bool) $p->auto_provision,
            'default_role'   => $p->default_role,
            'button_label'   => $p->button_label,
            'button_icon'    => $p->button_icon,
            'allowed_email_domains' => $p->allowed_email_domains,
            'callback_url'   => url('/auth/sso/' . $p->slug . '/callback'),
        ];
    }
}
