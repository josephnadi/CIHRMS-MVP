<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Member portal login. Distinct from `AuthenticatedSessionController`
 * (staff login). Authenticates against the `member` guard so members
 * never land in `users` and vice versa.
 */
class LoginController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Portal/Login');
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Always check Hash::check against a constant-cost dummy when the
        // member doesn't exist to avoid leaking enumeration via timing.
        $member = \App\Models\Member::where('email', $data['email'])->first();
        $hashOk = $member !== null
            ? Hash::check($data['password'], (string) ($member->password ?? ''))
            : (Hash::check($data['password'], '$2y$10$' . str_repeat('a', 53)) ?: false);

        if (! $member || ! $hashOk) {
            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        if ($member->status !== \App\Enums\MemberStatus::Active) {
            throw ValidationException::withMessages([
                'email' => 'Your account is currently inactive. Contact the institute office.',
            ]);
        }

        Auth::guard('member')->login($member, remember: false);
        $request->session()->regenerate();

        return redirect()->intended(route('portal.home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('member')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.login');
    }
}
