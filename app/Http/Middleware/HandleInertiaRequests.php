<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
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
                    'time'    => $n->created_at->diffForHumans(),
                ]) ?? [],
            'notificationCount' => fn () => $request->user()?->unreadNotifications()->count() ?? 0,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error'   => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
