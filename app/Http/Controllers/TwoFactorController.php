<?php

namespace App\Http\Controllers;

use App\Services\Auth\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Inertia\Inertia;
use Inertia\Response;

class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactorService $totp) {}

    public function enroll(Request $request): Response
    {
        $user = $request->user();

        // Generate a pending secret in the session — only persists on confirm.
        $secret = $request->session()->get('2fa_pending_secret');
        if (! $secret) {
            $secret = $this->totp->generateSecret();
            $request->session()->put('2fa_pending_secret', $secret);
        }

        return Inertia::render('Auth/TwoFactorEnroll', [
            'secret'           => $secret,
            'provisioning_uri' => $this->totp->provisioningUri($secret, $user->email ?: $user->staff_id ?? 'user'),
            'already_enrolled' => (bool) $user->two_factor_confirmed_at,
        ]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'digits:6'],
        ]);

        $secret = $request->session()->pull('2fa_pending_secret');
        if (! $secret) {
            return redirect()->route('two-factor.enroll')->with('error', 'Enrolment expired. Restart the process.');
        }

        $user = $request->user();
        $user->update(['two_factor_secret' => Crypt::encryptString($secret)]);

        if (! $this->totp->verifyCode($user, $data['code'])) {
            $user->update(['two_factor_secret' => null]);
            return redirect()->route('two-factor.enroll')->withErrors(['code' => 'Invalid code. Try again.']);
        }

        $recovery = $this->totp->generateRecoveryCodes();
        $user->update([
            'two_factor_recovery_codes' => Crypt::encryptString(json_encode($recovery)),
            'two_factor_confirmed_at'   => now(),
        ]);

        $this->totp->markFresh($user);

        return redirect()->route('dashboard')->with([
            'success'        => 'Two-factor authentication enabled. Save your recovery codes.',
            'recovery_codes' => $recovery,
        ]);
    }

    public function challengeForm(Request $request): Response
    {
        return Inertia::render('Auth/TwoFactorChallenge', [
            'intended' => $request->query('intended'),
        ]);
    }

    public function challenge(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code'     => ['nullable', 'digits:6'],
            'recovery' => ['nullable', 'string', 'min:6', 'max:32'],
            'intended' => ['nullable', 'string', 'url'],
        ]);

        $user = $request->user();

        $ok = false;
        if (! empty($data['code']))      $ok = $this->totp->verifyCode($user, $data['code']);
        if (! $ok && ! empty($data['recovery'])) $ok = $this->totp->consumeRecoveryCode($user, $data['recovery']);

        if (! $ok) {
            return back()->withErrors(['code' => 'Invalid code or recovery code.']);
        }

        $this->totp->markFresh($user);

        return redirect()->to($data['intended'] ?? route('dashboard'));
    }

    public function disable(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->two_factor_required && ! $user->isSuperAdmin()) {
            return back()->with('error', 'Two-factor is required for your role and cannot be disabled.');
        }

        $user->update([
            'two_factor_secret'         => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at'   => null,
            'two_factor_last_used_at'   => null,
        ]);

        return back()->with('success', 'Two-factor authentication disabled.');
    }
}
