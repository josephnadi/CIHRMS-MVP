<?php

namespace App\Services\Sso;

use App\Enums\SsoLoginOutcome;
use App\Enums\SsoProviderType;
use App\Events\SsoLoginCompleted;
use App\Models\SsoIdentityProvider;
use App\Models\SsoLoginAttempt;
use App\Models\User;
use App\Models\UserIdentityLink;
use App\Services\Sso\Contracts\SsoAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Common SSO flow that lives ABOVE the protocol adapters.
 *
 *   1. Adapter delivers an SsoAuthResult with `subject`, `email`, `name`, claims.
 *   2. Find a UserIdentityLink for (provider, subject). If found, log in that user.
 *   3. Else, find a User whose email matches (link-on-first-login policy). If
 *      found, create the link and log them in.
 *   4. Else, if provider has `auto_provision = true`, JIT-create a User with
 *      the configured default_role and link them. Otherwise return
 *      ProvisionFailed.
 *   5. Every attempt — success or failure — writes one `sso_login_attempts` row.
 *
 * The orchestrator NEVER touches `password` on existing accounts. SSO is
 * additive: an existing password user just acquires another way in.
 */
class SsoOrchestrator
{
    /** @param array<string, SsoAdapter> $adapters keyed by SsoProviderType value */
    public function __construct(private readonly array $adapters) {}

    public function adapterFor(SsoIdentityProvider $provider): SsoAdapter
    {
        $type = $provider->type instanceof SsoProviderType ? $provider->type->value : (string) $provider->type;
        $a = $this->adapters[$type] ?? null;
        if (! $a) throw new \RuntimeException("No SSO adapter registered for type '{$type}'.");
        return $a;
    }

    /**
     * Process an SSO callback. Returns the resolved/created User on success or
     * null on failure. Either way, an audit row is written.
     */
    public function processCallback(
        SsoIdentityProvider $provider,
        SsoAuthResult $result,
        Request $request,
    ): ?User {
        if (! $result->success) {
            $this->writeAttempt($provider, null, $result->subject, $result->email,
                $result->outcome, $result->error, $result->claims, $request);
            return null;
        }

        // Belt-and-braces: even on a "success" we re-check email-domain allowlist
        // here in the orchestrator. The adapter does protocol; we do policy.
        if (! $provider->isEmailDomainAllowed($result->email)) {
            $this->writeAttempt($provider, null, $result->subject, $result->email,
                SsoLoginOutcome::ProvisionFailed,
                "Email domain not allowed by provider '{$provider->slug}'",
                $result->claims, $request);
            return null;
        }

        return DB::transaction(function () use ($provider, $result, $request) {
            $link = UserIdentityLink::where('provider_id', $provider->id)
                ->where('external_subject_id', $result->subject)
                ->first();

            if ($link) {
                $user = $link->user;
                if (! $user || $user->trashed()) {
                    $this->writeAttempt($provider, $user?->id, $result->subject, $result->email,
                        SsoLoginOutcome::UserDisabled, 'Linked user is deleted or disabled',
                        $result->claims, $request);
                    return null;
                }
                $this->refreshLink($link, $result);
                $this->writeAttempt($provider, $user->id, $result->subject, $result->email,
                    SsoLoginOutcome::Success, null, $result->claims, $request);
                event(new SsoLoginCompleted($user, $provider, false));
                return $user;
            }

            // No existing link — try email-based attach to existing User
            $user = $result->email ? User::where('email', $result->email)->first() : null;

            if (! $user) {
                // JIT provisioning (only if provider opts in)
                if (! $provider->auto_provision) {
                    $this->writeAttempt($provider, null, $result->subject, $result->email,
                        SsoLoginOutcome::ProvisionFailed,
                        "No matching account and auto-provision disabled for provider '{$provider->slug}'",
                        $result->claims, $request);
                    return null;
                }

                $user = $this->jitCreate($provider, $result);
            }

            UserIdentityLink::create([
                'user_id'             => $user->id,
                'provider_id'         => $provider->id,
                'external_subject_id' => $result->subject,
                'external_email'      => $result->email,
                'last_claims'         => $result->claims,
                'linked_at'           => now(),
                'last_login_at'       => now(),
            ]);

            $this->writeAttempt($provider, $user->id, $result->subject, $result->email,
                SsoLoginOutcome::Success, null, $result->claims, $request);
            event(new SsoLoginCompleted($user, $provider, true));

            return $user;
        });
    }

    private function refreshLink(UserIdentityLink $link, SsoAuthResult $result): void
    {
        $link->update([
            'external_email' => $result->email,
            'last_claims'    => $result->claims,
            'last_login_at'  => now(),
        ]);

        // Best-effort sync of changed display fields onto the User row.
        $user = $link->user;
        if ($user && $result->name && $user->name !== $result->name) {
            $user->forceFill(['name' => $result->name])->save();
        }
    }

    private function jitCreate(SsoIdentityProvider $provider, SsoAuthResult $result): User
    {
        return User::create([
            'name'     => $result->name ?: ($result->email ?: 'SSO User'),
            'email'    => $result->email ?: sprintf('sso-%s-%s@example.invalid',
                $provider->slug, substr(hash('sha256', $result->subject), 0, 12)),
            'password' => bcrypt(bin2hex(random_bytes(16))),    // unguessable; user must use SSO
            'role'     => $provider->default_role ?: 'employee',
        ]);
    }

    private function writeAttempt(
        SsoIdentityProvider $provider,
        ?int $userId,
        ?string $subject,
        ?string $email,
        SsoLoginOutcome $outcome,
        ?string $error,
        array $claims,
        Request $request,
    ): void {
        SsoLoginAttempt::create([
            'provider_id'         => $provider->id,
            'user_id'             => $userId,
            'external_subject_id' => $subject,
            'external_email'      => $email,
            'outcome'             => $outcome->value,
            'error'               => $error,
            'claims_snapshot'     => $claims,
            'ip_address'          => $request->ip(),
            'user_agent'          => $request->userAgent(),
        ]);
    }
}
