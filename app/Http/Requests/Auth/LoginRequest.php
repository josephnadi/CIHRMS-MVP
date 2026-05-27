<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'     => ['required', 'string'],
            'staff_id' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * Lookup is by (name + staff_id) — CIHRMS does not use email for sign-in
     * because staff are addressed by their issued staff number. Once the
     * candidate row is located, the supplied password is verified against
     * the bcrypt hash. Both lookup-miss and bad-password produce the same
     * generic error to avoid leaking which half is wrong.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $user = \App\Models\User::where('name', $this->name)
            ->where('staff_id', $this->staff_id)
            ->first();

        if (! $user || ! Hash::check((string) $this->password, (string) $user->password)) {
            RateLimiter::hit($this->throttleKey());
            RateLimiter::hit($this->globalStaffIdKey(), 900);  // 15-min window

            throw ValidationException::withMessages([
                'staff_id' => trans('auth.failed'),
            ]);
        }

        Auth::login($user, $this->boolean('remember'));

        RateLimiter::clear($this->throttleKey());
        RateLimiter::clear($this->globalStaffIdKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * Two layers:
     *   1. Per-(staff_id + IP), 5 attempts in the default 60s decay — protects
     *      a single staff_id from a single source.
     *   2. Per-staff_id globally, 10 attempts in 15 min — protects a single
     *      staff_id from credential-stuffing across a botnet (M5 audit fix).
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        $ipLimited     = RateLimiter::tooManyAttempts($this->throttleKey(), 5);
        $globalLimited = RateLimiter::tooManyAttempts($this->globalStaffIdKey(), 10);

        if (! $ipLimited && ! $globalLimited) {
            return;
        }

        event(new Lockout($this));

        $seconds = max(
            RateLimiter::availableIn($this->throttleKey()),
            RateLimiter::availableIn($this->globalStaffIdKey()),
        );

        throw ValidationException::withMessages([
            'staff_id' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Per-(staff_id + IP) throttle key — Layer 1.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('staff_id')).'|'.$this->ip());
    }

    /**
     * Per-staff_id global throttle key — Layer 2. Catches credential stuffing
     * across rotating source IPs that would otherwise reset Layer 1.
     */
    public function globalStaffIdKey(): string
    {
        return 'login_staff_global:' . Str::transliterate(Str::lower($this->string('staff_id')));
    }
}
