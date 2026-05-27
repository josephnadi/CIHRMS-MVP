<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Lightweight TOTP (RFC 6238) implementation — no external dependency.
 *
 *   - 30-second time-step
 *   - 6-digit codes
 *   - SHA-1 HMAC (Google Authenticator / Authy / 1Password compatible)
 *   - 10 single-use recovery codes per user
 *
 * The secret is encrypted at rest via the User model cast. Recovery codes
 * are stored encrypted as a JSON array; consumed codes are removed.
 *
 * Fresh-challenge cache: when a privileged action (payroll approve, role
 * elevation) needs proof of recent 2FA, the middleware sets
 * `2fa_fresh:{user_id}` with a 5-minute TTL on successful challenge. The
 * key is cleared on login and logout to prevent cross-session reuse.
 */
class TwoFactorService
{
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const ALGORITHM = 'sha1';
    private const FRESH_TTL_SECONDS = 300; // 5 minutes
    private const RECOVERY_CODE_COUNT = 10;

    public function generateSecret(): string
    {
        // 20 random bytes → base32 — standard for Google Authenticator.
        return $this->base32Encode(random_bytes(20));
    }

    /** @return array<int, string> */
    public function generateRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            // 10-char groups like 7b3f-9a2e for readability
            $codes[] = sprintf(
                '%s-%s',
                Str::lower(Str::random(4)),
                Str::lower(Str::random(4)),
            );
        }
        return $codes;
    }

    /**
     * Verify a 6-digit code against the user's secret.
     * Accepts ±1 time-step skew (90-second total window) to tolerate clock drift.
     */
    public function verifyCode(User $user, string $code): bool
    {
        if (! $user->two_factor_secret) return false;

        $code = preg_replace('/\s+/', '', $code);
        if (! preg_match('/^\d{6}$/', $code)) return false;

        $secret = Crypt::decryptString($user->two_factor_secret);
        $now    = (int) floor(time() / self::PERIOD);

        foreach ([-1, 0, 1] as $offset) {
            if (hash_equals($this->generateCode($secret, $now + $offset), $code)) {
                $user->update(['two_factor_last_used_at' => now()]);
                return true;
            }
        }
        return false;
    }

    public function consumeRecoveryCode(User $user, string $code): bool
    {
        if (! $user->two_factor_recovery_codes) return false;

        $codes = json_decode(Crypt::decryptString($user->two_factor_recovery_codes), true) ?? [];
        $needle = Str::lower(trim($code));

        $idx = array_search($needle, $codes, true);
        if ($idx === false) return false;

        unset($codes[$idx]);
        $user->update([
            'two_factor_recovery_codes' => Crypt::encryptString(json_encode(array_values($codes))),
            'two_factor_last_used_at'   => now(),
        ]);
        return true;
    }

    public function markFresh(User $user): void
    {
        Cache::put($this->freshKey($user), now()->timestamp, self::FRESH_TTL_SECONDS);
    }

    public function isFresh(User $user): bool
    {
        return Cache::has($this->freshKey($user));
    }

    /**
     * Drop the freshness marker for this user. Called on login and logout so
     * that a stale "2FA recently completed" flag from a previous session
     * cannot satisfy a fresh-required action in a new session — closes the
     * cross-session leak documented in the 2026-05-26 audit (H4).
     */
    public function clearFresh(User $user): void
    {
        Cache::forget($this->freshKey($user));
    }

    public function provisioningUri(string $secret, string $accountLabel, string $issuer = 'CIHRMS'): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($accountLabel),
            $secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD,
        );
    }

    private function freshKey(User $user): string
    {
        return "2fa_fresh:{$user->id}";
    }

    /**
     * RFC 6238 HOTP/TOTP core.
     */
    private function generateCode(string $secret, int $counter): string
    {
        $binary  = $this->base32Decode($secret);
        $time    = pack('N*', 0) . pack('N*', $counter); // 8-byte big-endian counter
        $hash    = hash_hmac(self::ALGORITHM, $time, $binary, true);

        $offset  = ord($hash[strlen($hash) - 1]) & 0xF;
        $code    = (ord($hash[$offset])     & 0x7F) << 24
                 | (ord($hash[$offset + 1]) & 0xFF) << 16
                 | (ord($hash[$offset + 2]) & 0xFF) << 8
                 | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($code % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $binary): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $out = '';
        $bits = '';
        foreach (str_split($binary) as $b) {
            $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        }
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $out  .= $alphabet[bindec($chunk)];
        }
        return $out;
    }

    private function base32Decode(string $base32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $base32 = strtoupper(rtrim($base32, '='));
        $bits = '';
        foreach (str_split($base32) as $c) {
            $idx = strpos($alphabet, $c);
            if ($idx === false) continue;
            $bits .= str_pad(decbin($idx), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) $out .= chr(bindec($chunk));
        }
        return $out;
    }
}
