<?php

namespace App\Http\Middleware;

use App\Services\AnnouncementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Inertia\Inertia;
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
        // Staff (web guard) → User with employee relation eagerly loaded so
        // the avatar accessor doesn't lazy-load on every Inertia response.
        // Portal (member guard) → Member, which has no employee relation
        // and a much smaller projection. Distinguished by `auth.kind` so the
        // Vue layer can render the right chrome.
        // Explicitly resolve via named guards. The bare `$request->user()`
        // returns whichever guard was named on the route's `auth:` middleware,
        // so on portal routes it would return a Member and explode on
        // `->loadMissing('employee')`. Named lookups are guard-safe.
        $user   = $request->user('web');
        if ($user) {
            $user = $user->loadMissing('employee');
        }
        $member = $request->user('member');

        return [
            ...parent::share($request),
            'auth' => [
                'kind'        => $user ? 'staff' : ($member ? 'member' : 'guest'),
                'user'        => $user,
                'member'      => $member ? [
                    'id'        => $member->id,
                    'member_no' => $member->member_no,
                    'name'      => $member->name,
                    'class'     => is_object($member->class) ? $member->class->value : (string) $member->class,
                    'email'     => $member->email,
                ] : null,
                'role'        => $user?->role,
                'roles'       => fn () => $user?->allRoleSlugs() ?? [],
                'permissions' => fn () => $user?->allPermissions() ?? [],
                'managedDepartmentIds' => fn () => $user?->managedDepartmentIds()->all() ?? [],
            ],
            // Deferred — sent in a follow-up Inertia request after the page paints.
            // Notification bell + announcement ticker update a moment later instead
            // of blocking every navigation on 3 extra DB queries.
            'notifications' => Inertia::defer(fn () => $request->user()
                ?->unreadNotifications()
                ->latest()
                ->limit(10)
                ->get()
                ->map(fn ($n) => [
                    'id'      => $n->id,
                    'message' => $n->data['message'] ?? null,
                    'kind'    => $n->data['kind']    ?? null,
                    'time'    => $n->created_at->diffForHumans(),
                ]) ?? []),
            'notificationCount' => Inertia::defer(
                fn () => $request->user()?->unreadNotifications()->count() ?? 0
            ),
            'announcementTicker' => Inertia::defer(fn () => $request->user()
                ? app(AnnouncementService::class)->ticker($request->user())->values()->all()
                : []),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error'   => fn () => $request->session()->get('error'),
                'info'    => fn () => $request->session()->get('info'),
                'warning' => fn () => $request->session()->get('warning'),
            ],

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
