<?php

namespace App\Http\Middleware;

use App\Models\SsoIdentityProvider;
use App\Services\AnnouncementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Schema;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user'        => $user,
                'role'        => $user?->role,
                'roles'       => fn () => $user?->allRoleSlugs() ?? [],
                'permissions' => fn () => $user?->allPermissions() ?? [],
                'managedDepartmentIds' => fn () => $user?->managedDepartmentIds()->all() ?? [],
            ],
            'notifications' => fn () => $request->user()
                ?->unreadNotifications()
                ->latest()
                ->limit(10)
                ->get()
                ->map(fn ($n) => [
                    'id'      => $n->id,
                    'message' => $n->data['message'] ?? null,
                    'kind'    => $n->data['kind']    ?? null,
                    'time'    => $n->created_at->diffForHumans(),
                ]) ?? [],
            'notificationCount' => fn () => $request->user()?->unreadNotifications()->count() ?? 0,
            'announcementTicker' => fn () => $request->user()
                ? app(AnnouncementService::class)->ticker($request->user())->values()->all()
                : [],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error'   => fn () => $request->session()->get('error'),
                'info'    => fn () => $request->session()->get('info'),
                'warning' => fn () => $request->session()->get('warning'),
            ],
            'ssoProviders' => fn () => Schema::hasTable('identity_providers')
                ? SsoIdentityProvider::active()->ordered()->get()->map(fn ($p) => [
                    'slug'         => $p->slug,
                    'name'         => $p->name,
                    'button_label' => $p->button_label ?: "Sign in with {$p->name}",
                    'button_icon'  => $p->button_icon ?: 'login',
                ])->all()
                : [],

            // ── i18n state for the Vue layer (Phase 4 / WS20) ──
            // Active locale, supported set for the LocaleSwitcher, and
            // pre-loaded translations for the shell so chrome strings
            // translate without a round-trip on first paint.
            'i18n' => fn () => [
                'locale'    => App::getLocale(),
                'fallback'  => (string) config('i18n.fallback', 'en'),
                'supported' => (array) config('i18n.supported', []),
                'lines'     => [
                    'common'  => $this->loadLang('common'),
                    'leave'   => $this->loadLang('leave'),
                    'payroll' => $this->loadLang('payroll'),
                ],
            ],
        ];
    }

    /**
     * Load translations for `lang/{locale}/{file}.php`, merging the fallback
     * locale's strings underneath so a missing key in the user's locale
     * silently inherits from English.
     *
     * @return array<string, string>
     */
    private function loadLang(string $file): array
    {
        $locale   = App::getLocale();
        $fallback = (string) config('i18n.fallback', 'en');

        $primary = base_path("lang/{$locale}/{$file}.php");
        $second  = base_path("lang/{$fallback}/{$file}.php");

        $lines = [];
        if (is_file($second))  $lines = require $second;
        if (is_file($primary) && $primary !== $second) {
            $lines = array_merge($lines, require $primary);
        }
        return is_array($lines) ? $lines : [];
    }
}
