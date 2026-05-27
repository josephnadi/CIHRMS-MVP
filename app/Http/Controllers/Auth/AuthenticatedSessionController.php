<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\SsoIdentityProvider;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status'           => session('status'),
            'ssoProviders'     => Schema::hasTable('identity_providers')
                ? SsoIdentityProvider::active()->ordered()->get()->map(fn ($p) => [
                    'slug'         => $p->slug,
                    'name'         => $p->name,
                    'button_label' => $p->button_label ?: "Sign in with {$p->name}",
                    'button_icon'  => $p->button_icon ?: 'login',
                ])->all()
                : [],
        ]);
    }

    /**
     * Handle an incoming authentication request.
     *
     * Every successful login lands on the unified /dashboard, which renders
     * role-specific content via permissions. We deliberately skip
     * `redirect()->intended()` so users don't get dropped back onto a deep
     * link they were trying to reach when they got bounced to login — the
     * dashboard is the canonical landing surface for all roles.
     */
    public function store(LoginRequest $request, TwoFactorService $totp): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        // Drop any leftover 2FA-fresh marker from a previous session.
        $totp->clearFresh($request->user());

        return redirect()->route('dashboard');
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request, TwoFactorService $totp): RedirectResponse
    {
        if ($user = $request->user()) {
            $totp->clearFresh($user);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
