# Chapter 40 — Security

> CIHRMS's security posture is the union of Laravel's defaults, a handful of CIHRMS-specific middleware (`audit`, `2fa`, `permission`, `webhook.signature`, `paystack.signature`), Eloquent's `'encrypted'` cast on sensitive columns, the tamper-evident audit chain from Chapter 24, and the RBAC evaluator from Chapter 39. Nothing in the list is exotic. The point of this chapter is to walk an engineer through *which* defaults are in place, which controls are CIHRMS-specific, where the load-bearing code lives, and — equally important — the places the posture is aspirational rather than shipped. Pen test and CSA registration are both Phase 4 items; both are flagged honestly in 40.13.

This chapter is a peer-to-peer code walk. It cites middleware aliases, config keys, file paths. It does not editorialise about security; it tells you what runs and where.

---

## 40.1  Authentication — Sanctum sessions on a staff-ID identifier

Authentication breaks one Laravel convention and accepts the rest. The break: the login identifier is the issued **staff ID**, not the email address. The rest — bcrypt hashing, session cookies, CSRF on state-changing routes, the `auth` middleware on the route group — runs as Laravel ships it.

The relevant config is `config/auth.php`:

```php
'defaults' => [
    'guard'     => env('AUTH_GUARD', 'web'),
    'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
],

'guards' => [
    'web' => [
        'driver'   => 'session',
        'provider' => 'users',
    ],
],

'providers' => [
    'users' => [
        'driver' => 'eloquent',
        'model'  => env('AUTH_MODEL', User::class),
    ],
],
```

The web app uses the `web` guard — Laravel Sanctum's cookie-based session driver, the same one a stock Laravel install gives you. There is no separate API token issued to the browser. The session cookie is set on login (over HTTPS in production, plain HTTP in local dev with `SESSION_SECURE_COOKIE=false`), Sanctum recognises it on the next request, and the user record is resolved through the Eloquent provider.

The `config/sanctum.php` file is missing from the repo. That is not a bug — Sanctum's defaults are all served by the package's own published config in `vendor/laravel/sanctum/config/sanctum.php`, and the session-cookie-on-the-same-domain pattern needs no override. The day we issue Personal Access Tokens to a non-browser client and need to whitelist a stateful domain, `php artisan vendor:publish --tag=sanctum-config` will materialise it; until then the package defaults are correct.

The login form posts to `POST /login`, handled by `App\Http\Controllers\Auth\AuthenticatedSessionController::store`, which type-hints `App\Http\Requests\Auth\LoginRequest`. The validator is small:

```php
public function rules(): array
{
    return [
        'name'     => ['required', 'string'],
        'staff_id' => ['required', 'string'],
        'password' => ['required', 'string'],
    ];
}
```

Three fields: `name`, `staff_id`, `password`. The `name` field is the user's display name as recorded on the `users` row, which together with the staff ID is what makes the lookup unambiguous on installations where two people might share a common Ghanaian first or last name. The `authenticate()` method underneath is also small:

```php
$user = \App\Models\User::where('name', $this->name)
    ->where('staff_id', $this->staff_id)
    ->first();

if (! $user || ! Hash::check((string) $this->password, (string) $user->password)) {
    RateLimiter::hit($this->throttleKey());

    throw ValidationException::withMessages([
        'staff_id' => trans('auth.failed'),
    ]);
}

Auth::login($user, $this->boolean('remember'));
RateLimiter::clear($this->throttleKey());
```

Two points worth noting:

1. **The lookup miss and the bad-password branch raise the same error message** (`trans('auth.failed')`). That is on purpose. An attacker probing for valid staff IDs cannot tell from the response whether the staff ID exists with the wrong password or doesn't exist at all. The error is attached to the `staff_id` field for display, but the message is generic.
2. **Throttle is per (lowercased staff_id, ip) tuple.** `throttleKey()` returns `Str::transliterate(Str::lower($this->string('staff_id')).'|'.$this->ip())`. After five failed attempts the user gets `auth.throttle` with a seconds-until-retry. This is `ensureIsNotRateLimited()` calling the framework's `RateLimiter` with a 5-attempt cap. The `event(new Lockout($this))` fires once per lockout so a downstream listener (none today) could observe the event for SIEM purposes. The lockout key combines staff ID with IP rather than IP alone so a shared-IP NAT (every Ghanaian institute has one) does not lock the whole institute out when one user fumbles their password.

**Email is not part of authentication.** The `email` column is still on the `users` row — it is the channel for password-reset links, notifications, and Inertia avatar lookups via Gravatar — but it is not consulted at login. A user who changes their email address does not lose their ability to sign in. The staff ID is the identity; everything else is correspondence.

**`config/auth.php` keeps Laravel's password broker defaults.** Reset tokens live in `password_reset_tokens`, expire in 60 minutes, and are throttled at 60 seconds between requests per address. Password confirmation (the "are you sure?" reprompt for sensitive actions) has a default timeout of 10800 seconds (3 hours) — overridable via `AUTH_PASSWORD_TIMEOUT`, never overridden in production. Note that we do *not* lean on `password.confirm` middleware in routes; the `2fa:fresh` middleware (40.4 below) is the freshness gate we actually use for destructive actions, because TOTP is materially stronger than re-typing the same password.

**API authentication** uses Sanctum Personal Access Tokens against `/api/v1/*`. Tokens carry scopes (the `api.scope` middleware alias in `bootstrap/app.php`). The API throttle is bound on the same call: `$middleware->throttleApi('60,1')` — 60 requests per minute per token. Tokens are issued by the user under `/settings/api-tokens` and recorded in `api_token_metadata` for last-used and IP attribution.

---

## 40.2  Password lifecycle — bcrypt, `password_must_change`, the reset command

Passwords are hashed by Laravel's default driver, which is **bcrypt** on this codebase. Two pieces of evidence:

- `.env.example` carries `BCRYPT_ROUNDS=12` (line 16).
- The User model casts `'password' => 'hashed'` (`app/Models/User.php` line 211), which delegates to `Hash::driver()` — and there is no `config/hashing.php` overriding the default, so the framework uses bcrypt with 12 rounds.

This is sufficient and was deliberate: Argon2id was evaluated and would mean accepting a per-login PHP-FPM cost variance that bcrypt does not have. The TRD document at `docs/TRD.md` line 175 lists Argon2id as the requirement; that line is **aspirational** and the codebase does not match it today. Fixing the TRD or fixing the codebase is on the next docs sweep — the bcrypt path is the one that ships.

### The `password_must_change` flag

A column on `users` (`bool`, nullable, default `false`), added by the P6 migration and cast via the User model:

```php
// app/Models/User.php — line 219
'password_must_change' => 'bool',
```

Two writers set the flag:

1. **HR-created accounts.** When `Admin\UserController::store` creates a user with a temporary password, the controller sets `password_must_change = true` on insert. The intent is that the next login redirects the user to the password-change form before they touch any other page.
2. **The `users:issue-password-resets` command** (`app/Console/Commands/IssuePasswordResets.php`). Run after the V2 audit's PR #34, which made `password` a required column on login (it had previously been nullable on rows seeded for testing). The command finds users with `NULL` or empty password hashes (and optionally users with the dev-default `password` literal), forces `password_must_change = true`, and either prints a Laravel password-reset URL per user or sends a reset email per user. Usage is documented in the command's docblock; the dry-run mode is the right first call.

### The enforcement middleware

`App\Http\Middleware\ForcePasswordChange` is appended to the web group in `bootstrap/app.php`:

```php
$middleware->web(append: [
    \App\Http\Middleware\SetUserLocale::class,
    \App\Http\Middleware\HandleInertiaRequests::class,
    \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
    \App\Http\Middleware\ForcePasswordChange::class,
]);
```

The middleware itself is 44 lines (`app/Http/Middleware/ForcePasswordChange.php`). The check:

```php
if ($user && $user->password_must_change && ! $this->isAllowedRoute($request)) {
    if ($request->expectsJson()) {
        abort(403, 'Password change required before continuing.');
    }
    return redirect()
        ->route('profile.edit')
        ->withFragment('security')
        ->with('error', 'Please set a new password before continuing.');
}
```

The allowed-route list is exhaustive: `profile.edit`, `profile.update`, `profile.personal`, `profile.password`, `logout`, `password.confirm`. Anything else short-circuits to `/profile#security` with a flash error. The JSON branch returns 403 so Inertia visits that happen to be JSON-only (a partial reload, a deferred prop fetch) cannot escape the wall.

The wall comes off when the user posts to `profile.password` and the controller flips `password_must_change` to `false`. Until then every navigation lands on the profile edit form.

### What `password` hashes look like

Bcrypt strings begin `$2y$12$…`. They are 60 characters. They cannot be queried (no plaintext-comparison index, no `LIKE` query that would help). `Hash::check($plain, $hash)` is the only call that matters; `User::factory()->create(['password' => 'password'])` runs the same hashing on insert because of the `'hashed'` cast.

The `remember_token` column is the standard Laravel "remember me" token — random 60 chars, regenerated on login. It is in the `$hidden` array on the User model alongside `password`, `two_factor_secret`, and `two_factor_recovery_codes` (`User.php` line 31).

---

## 40.3  Two-factor — TOTP, enrolment, fresh challenge

CIHRMS's two-factor implementation is TOTP-only, hand-rolled (no `pragmarx/google2fa` dependency), and lives in two files: `App\Services\Auth\TwoFactorService` (the RFC 6238 implementation) and `App\Http\Middleware\RequireTwoFactor` (alias `2fa`, the gate). The controller surface is `App\Http\Controllers\TwoFactorController`.

### Service

`TwoFactorService::PERIOD = 30` (30-second time-step), `DIGITS = 6` (6-digit codes), `ALGORITHM = 'sha1'` (Google Authenticator / Authy / 1Password compatible), `RECOVERY_CODE_COUNT = 10` (10 single-use recovery codes per user). The secret is generated as 20 random bytes base-32 encoded (`generateSecret()`), stored in `users.two_factor_secret` encrypted via `Crypt::encryptString` at the controller level (the column itself is not cast `'encrypted'` because the encryption is done explicitly in `TwoFactorController::confirm` and `TwoFactorController::disable` — see code below).

`verifyCode()` tolerates ±1 time-step of clock skew (a 90-second total window), iterating `[-1, 0, 1]` against the user's current time bucket and calling `hash_equals` for constant-time comparison:

```php
foreach ([-1, 0, 1] as $offset) {
    if (hash_equals($this->generateCode($secret, $now + $offset), $code)) {
        $user->update(['two_factor_last_used_at' => now()]);
        return true;
    }
}
return false;
```

`consumeRecoveryCode()` pulls the encrypted JSON array of recovery codes off `users.two_factor_recovery_codes`, locates the code with `array_search` (constant-string match, not regex), unsets the entry, and writes the shorter list back encrypted. Recovery codes are *single-use*.

`markFresh()` writes a cache key `2fa_fresh:{user_id}` with a TTL of `FRESH_TTL_SECONDS = 300` — **five minutes**. `isFresh()` is `Cache::has(...)` on the same key. (Worth flagging: Chapter 39.8 references the freshness window as "default 15 minutes"; the code says five. The code is authoritative — `app/Services/Auth/TwoFactorService.php` line 30. A docs fix should land in the next sweep.)

### Enrolment

The flow at `GET /two-factor/enroll`:

1. `TwoFactorController::enroll` generates a pending secret if one is not already in the session and renders `Auth/TwoFactorEnroll.vue`. The Vue page paints a QR code from the `provisioning_uri` and a six-digit input.
2. The user scans the QR with their authenticator app (Google Authenticator / Authy / 1Password / Microsoft Authenticator — anything that speaks `otpauth://totp/`).
3. The user types the first generated code. `POST /two-factor/confirm` validates `digits:6`, pulls the pending secret out of the session, encrypts it with `Crypt::encryptString`, writes `two_factor_secret`, verifies the code with `TwoFactorService::verifyCode`, and on success generates ten recovery codes and stores them encrypted in `two_factor_recovery_codes` and stamps `two_factor_confirmed_at`. The codes are flashed once via `with(['recovery_codes' => $recovery])` and the user is asked to save them. There is no second chance to see them.

The provisioning URI format the service emits:

```
otpauth://totp/CIHRMS:{label}?secret={base32}&issuer=CIHRMS&algorithm=SHA1&digits=6&period=30
```

`{label}` is the user's email if present, falling back to staff ID, falling back to the literal `'user'`. The `issuer=CIHRMS` is what authenticator apps display next to the code so the user knows which account they're looking at.

### Challenge — the `2fa:fresh` gate

Sensitive actions (payroll approve, loan disburse, off-boarding settlement approve, calibration apply, AG report generate, AI write actions, SSO provider mutations, USSD PIN issuance, the full F2-F5 money-touching write surface) gate behind `2fa:fresh`. The middleware enforces:

```php
if ($mode === 'fresh') {
    if (! $user->two_factor_confirmed_at) {
        return redirect()->route('two-factor.enroll')
            ->with('error', 'You must enrol in two-factor authentication before performing this action.');
    }
    if (! $this->totp->isFresh($user)) {
        return redirect()->route('two-factor.challenge', ['intended' => $request->fullUrl()]);
    }
}
```

So: if you haven't enrolled, you're bounced to enrolment. If you have enrolled but your last successful TOTP challenge was more than five minutes ago, you're bounced to `/two-factor/challenge?intended=<originalUrl>`. On success the user is redirected back to where they came from. On failure (wrong code, expired recovery code) the form re-renders with `withErrors(['code' => 'Invalid code or recovery code.'])`.

The challenge endpoint accepts *either* a six-digit code (`code` field) *or* a recovery code (`recovery` field). The recovery-code path is what saves a user whose phone is dead.

### Required mode

`2fa` without arguments (or `2fa:required`, same thing) is the **enrolment wall** for roles flagged `users.two_factor_required = true`. The seeder sets the flag on `super_admin`, `ceo`, `hr_admin`, and `finance_officer` rows. A flagged user who has not enrolled (`two_factor_confirmed_at IS NULL`) is redirected to `/two-factor/enroll` on every request until they do. The flag is per-user, not per-role, so an admin can opt an individual contributor into 2FA without changing the role catalogue.

### Disable

A user can disable their own 2FA via `POST /two-factor/disable` — *unless* their role is flagged `two_factor_required` and they are not super_admin, in which case the controller refuses with a flash error. The disable clears `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at`, and `two_factor_last_used_at` in one update.

### Two-factor caveats worth knowing

- **No backup-channel SMS.** TOTP only. Recovery codes are the backup.
- **No WebAuthn / FIDO2.** Phase 4 considered it; the install base for Ghanaian institutional staff makes it a Phase 5 conversation. The TOTP path covers every smartphone, including the ones running 4-year-old Android builds.
- **The fresh window is application-wide.** A finance officer who passes 2FA to approve an AP invoice has the same freshness for the next five minutes if they also need to post a manual journal entry. The window is not per-route. This was a deliberate decision — five minutes is short enough that the trade-off is comfortable.
- **`auth.password_timeout` is unused for sensitive actions.** Where Laravel ships the `password.confirm` middleware for sensitive routes, we ship `2fa:fresh` instead. The TOTP factor is materially stronger than re-typing the same password, and the prompt is a code rather than a credential that might be cached in a password manager and approved by muscle memory.

---

## 40.4  CSRF — Laravel default, one documented exception

The `bootstrap/app.php` block:

```php
$middleware->validateCsrfTokens(except: [
    'auth/sso/*/callback',
]);
```

CSRF is the framework default: a cookie + header pair on every POST/PUT/PATCH/DELETE issued from a session. Laravel ships an `X-CSRF-TOKEN` header via the Inertia adapter; the Vue side reads `usePage().props.csrf` and submits it automatically through `useForm().post`. There is nothing CIHRMS-specific to learn here.

The one documented exception is the SAML ACS callback. The comment in `bootstrap/app.php` explains it:

```php
// CSRF cannot apply to the SAML ACS — the IdP POSTs the assertion
// directly to our endpoint, so it has no way to include a Laravel
// CSRF token. Signature verification (via onelogin/php-saml in
// SamlSsoAdapter) is what protects this route instead.
```

The protection on `auth/sso/{slug}/callback` is XML signature verification inside `App\Services\Sso\SamlSsoAdapter` — the assertion is signed by the IdP with a certificate the SSO provider record pins to its row. A bad signature is rejected before any user provisioning happens. The exception is the standard SAML-on-Laravel pattern; it is *not* a CSRF wildcard.

Webhook routes also skip CSRF, but they skip it because they bypass the web group entirely — they live on route blocks under `route('webhooks.*')` and lean on `webhook.signature` or `paystack.signature` middleware for authentication. See 40.10 below.

---

## 40.5  XSS — Blade auto-escaping, Vue auto-escaping, two explicit `v-html`s

The web app has two rendering surfaces: Blade for the `app.blade.php` shell that boots Vue (and for the auth pages before the user has a session) and Vue 3 for everything else. Both escape user input by default.

Blade: `{{ $value }}` is HTML-escaped via `e()`. The only places we render raw HTML are the editorial design-system masthead strings (controlled by the engineer, no user input) — and `{!! $value !!}` is grep-clean across the codebase except for those.

Vue: `{{ value }}` and `v-bind` are HTML-escaped. The two `v-html` directives in the codebase are both for AI-generated markdown that has been rendered server-side through a sanitiser (the Anthropic provider's markdown path). User-entered chat messages, announcements, ticket descriptions — all go through `{{ }}` or a `<textarea>` rather than a rich-text input. The chat editor (Chapter 14) takes plain text only; the announcement composer (Chapter 17) takes plain text plus a small allowed-tag whitelist that strips through `HtmlSanitizer` before insert.

The defence in depth here is that even where rich text is accepted, the storage column is escaped on read and the Vue layer treats anything coming off the wire as a string. A user pasting `<script>alert(1)</script>` into a ticket description sees that string back; no browser executes it.

There is no CSP header set today. The framework defaults do not include one. See 40.11.

---

## 40.6  SQL injection — Eloquent + bound parameters

Every query in the codebase goes through Eloquent (`Model::query()`, `where`, `whereIn`, `firstOrFail`, `findOrFail`) or the query builder (`DB::table(...)->where(...)`). Both bind parameters; neither concatenates user input into SQL strings.

The exceptions worth knowing about:

- **`DB::raw(...)` is grep-rare.** It appears in a handful of places — aggregation SELECTs in `DashboardService` (`DB::raw('COUNT(*) as total')`), an `ORDER BY` expression in `Pagination` for the alphabetised employee list — never with user input. Where it appears, the argument is a literal string written by the engineer.
- **`Employee::scopeVisibleTo` uses `whereRaw('1=0')`** as a "deny all" fast path for the unauthenticated case. The `1=0` is a literal, not interpolated.
- **No `selectRaw` with `{$variable}` interpolation** anywhere in `app/`. The grep is clean.
- **The audit log payload is JSON-cast on write.** A user posting `{"sql": "DROP TABLE users"}` produces a JSON blob in the `payload` column; the column is `json` (or `jsonb` on Postgres) and is never interpreted as SQL.

The mitigation is the framework. The reason the mitigation is intact is the convention from Chapter 37: writes go through Services, Services use Eloquent, Eloquent uses PDO bind parameters. The audit chapter (24) and the RBAC chapter (39) both depend on this discipline being uniform.

---

## 40.7  Rate limiting

Two tiers: framework throttles per route (configured in `routes/web.php` and `routes/auth.php`) and the login-attempt throttle in `LoginRequest::ensureIsNotRateLimited`.

### Per-route throttles in the routes file

The grep result for `throttle:`:

| Route group | Throttle | Why |
|---|---|---|
| Careers public-apply | `5,1` (5/min) | Unauthenticated, captcha-free; bot-protection. |
| Whistleblower public-submit | `6,1` | Unauthenticated; deliberately low. |
| Paystack webhook | `120,1` | High volume from a single trusted source; signature is the real gate. |
| Kiosk attendance | `60,1` | Shared device, multiple users in quick succession. |
| DPA public submission | `10,1` | Unauthenticated form; low. |
| AI assistant | `30,1` per user | Per-call LLM cost; throttled at the route. |
| Attendance self-clock | `10,1` per user | Stops a user from cron-clocking. |
| SSO initiate / callback | `30,1` | Unauthenticated initiate path. |
| Email verification | `6,1` | Standard Laravel default for verification resend. |
| API (all `/api/v1/*`) | `60,1` per token | `$middleware->throttleApi('60,1')` in `bootstrap/app.php`. |

### Login throttle

`LoginRequest::ensureIsNotRateLimited` caps login attempts at **5 per key**. The key is `Str::transliterate(Str::lower($staff_id).'|'.$ip())`. On the sixth attempt within the window the framework returns `auth.throttle` with a `seconds`/`minutes` interpolation. A successful login `RateLimiter::clear`s the key.

### Signed-URL throttle (implicit)

Email verification (`GET verify-email/{id}/{hash}`) is `signed` + `throttle:6,1` — the signature is the primary gate, the throttle is the secondary. Same shape for the "resend verification email" endpoint.

### What is *not* rate-limited

- The general authenticated web surface. Authenticated users can hammer the dashboard at will; the cost of doing so is the same as if a real user did.
- The `2fa:challenge` POST. A user who has lost their TOTP secret and is typing wrong codes is not throttled at the route level. They are throttled implicitly by the 30-second TOTP window itself (six wrong codes in a minute is six different time-bucket attempts), but the failure path does not increment a counter. This is on the Phase 4 hardening list.

---

## 40.8  Secret handling — `.env` only, never committed

`.env` is in `.gitignore`. `.env.example` is committed and tracks the *shape* of every environment variable (DB host, mail credentials, Paystack keys, AWS keys, Sentry DSN, backup archive password). The example file ships with empty values for secrets and dev-default values for non-secrets. Production deploy is "copy `.env.example` to `.env` on the server, fill it in, run `php artisan config:cache`".

The relevant secret groups in `.env.example`:

- **`APP_KEY`** — Laravel's encryption root key. Generated by `php artisan key:generate` on install. Rotating it invalidates every encrypted column (Ghana Card numbers, 2FA secrets, whistleblower bodies, integration tokens, webhook signing secrets, SSO IdP configs) and every session cookie, so the runbook (Chapter 44, planned) treats it as a one-time write per deploy.
- **`MAIL_*`** — SMTP credentials.
- **`AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY`** — for S3 object storage where used (payslip uploads, backup destination if remote).
- **`PAYSTACK_SECRET_KEY` / `PAYSTACK_WEBHOOK_SECRET`** — Paystack F4 integration.
- **`SENTRY_LARAVEL_DSN`** — error reporting; empty disables Sentry.
- **`BACKUP_ARCHIVE_PASSWORD`** — `spatie/laravel-backup` archive password.

No other secrets live in code. Webhook signing secrets for integrations (Slack, WhatsApp Business, Zoho, MS Graph, Google, Hubtel) live in `config/integrations.php` keyed via `env(...)`. Biometric device shared secrets live in the database — `BiometricDevice::shared_secret` is cast `'encrypted'` (40.9 below).

### Practical hygiene

- **`.env` is never logged.** `config/logging.php` writes to `storage/logs/` only the framework-emitted messages; `env()` is never serialised into a log line in our code.
- **`config:cache` flattens secrets into `bootstrap/cache/config.php`.** That file is regenerated on deploy and is also gitignored.
- **Failed-job payloads in `failed_jobs.payload` can carry secrets.** A `ProcessPaystackWebhook` job that throws because the API key is invalid will serialise the API key into the failed-job row. The Phase 1 cleanup includes a `failed_jobs:redact` command to scrub these; the present mitigation is operational (only `super_admin` can read `failed_jobs` via the `database` queue's UI, and no UI exists).

---

## 40.9  Sensitive field handling — `'encrypted'` casts, salary policy gate

CIHRMS uses Eloquent's `'encrypted'` and `'encrypted:array'` casts on every column that holds a piece of regulated or sensitive data. The casts wrap `Crypt::encryptString` / `Crypt::decryptString` around `getAttribute` / `setAttribute`, so the model handles them as plain strings while the underlying column stores ciphertext.

The complete inventory (grep `'encrypted'` across `app/Models/`):

| Model | Column | Cast | Why |
|---|---|---|---|
| `IdentityVerification` | `ghana_card_number` | `'encrypted'` | DPA Act 843 §20; sensitive personal data. Also `$hidden`. |
| `IdentityVerification` | `ghana_card_hash` | (plain SHA-256, not encrypted) | Used for duplicate detection without decrypting. |
| `BiometricDevice` | `shared_secret` | `'encrypted'` | HMAC verifier shared secret. |
| `IntegrationToken` | `access_token` | `'encrypted'` | OAuth bearer (Zoho / MS Graph). |
| `IntegrationToken` | `refresh_token` | `'encrypted'` | OAuth refresh. |
| `SsoIdentityProvider` | `config` | `'encrypted:array'` | IdP per-tenant config, may include client secrets / IdP signing material reference. |
| `WhistleblowerReport` | `description`, `desired_outcome`, `incident_location`, `submitter_contact`, `closure_summary` | `'encrypted'` | Whistleblower Act 720 §10. |
| `WhistleblowerSubject` | `subject_label`, `role_context` | `'encrypted'` | Identifies accused subject. |
| `WhistleblowerAction` | `notes` | `'encrypted'` | Investigator commentary. |
| `WhistleblowerEvidence` | `caption` | `'encrypted'` | Evidence label. |
| `WhistleblowerMessage` | `body` | `'encrypted'` | Two-way chat with reporter. |
| `WebhookSubscription` | `signing_secret` | `'encrypted'` | Outbound webhook HMAC secret. |

A few things follow from this list:

- **Ghana Card number is encrypted *and* hashed.** The encrypted column is the source of truth (`ghana_card_number`, also `$hidden` on the model). The hash column (`ghana_card_hash`) is a SHA-256 of the canonicalised card number, used so the verifier can look for duplicate Ghana Cards across the institute without ever decrypting them. See `IdentityVerificationService` lines 36-37 and the duplicate check at line 74.
- **Whistleblower bodies are encrypted at the column level, not at the row level.** The row's existence is still visible to anyone with `audit.view` (because the audit log records the route name `whistleblower.submit`), but the *content* requires decryption, which requires the `APP_KEY`, which lives in `.env`. A DBA reading the database directly without the application key sees ciphertext.
- **Bank account numbers are *not* encrypted.** `employees.bank_account` is a plain string. This is on the Phase 2 backlog per the TRD's T8 ("Field-level encryption for bank account / Ghana Card / SSNIT #" — Ghana Card has landed, the others have not). The mitigation today is layered: the salary policy gate (below) covers the field on the wire; the AG report pack does not include the bank account in the employee export; the disbursement files write the bank account out to the GhIPSS payload and that payload is stored on disk with `0600` permissions inside `storage/`.

### The salary policy gate

`Employee::salary` is a plain numeric column on the row. Whether it appears on the wire depends on the viewer's permission:

```php
// app/Http/Resources/EmployeeResource.php — line 13
$canSeeSalary = $user?->can('viewSalary', $this->resource) ?? false;
// ...
'salary' => $this->when($canSeeSalary, fn () => $this->salary),
```

`$this->when($canSeeSalary, ...)` is JsonResource's conditional-include — when the predicate is false, the `salary` key is *not present in the output*, not "present and null". The Vue layer never sees the field; the DOM never paints it; the value never lands on the wire. `EmployeePolicy::viewSalary` is the predicate, gated on `employees.view_salary` permission (held by HR admin, finance officer, super_admin, CEO).

### Encrypted-column gotchas

- **You can't `WHERE` against an encrypted column.** The hash sibling column is the workaround. Where one doesn't exist (whistleblower descriptions, IdP config), search is done by decrypting in PHP rather than in SQL — fine on a per-row read, infeasible on a bulk scan, which is why none of the encrypted columns are surfaced as filterable in the UI.
- **Rotating `APP_KEY` invalidates every encrypted column.** Laravel's `key:generate --show` produces a new key; the recovery path is `Crypt::previousKeys()` (Laravel 11+) to decrypt-with-old, re-encrypt-with-new, applied per column per row. The runbook chapter (44) will document this. Today it's an emergency-only procedure.

---

## 40.10  Webhook signature verification — HMAC-SHA256 (most) and HMAC-SHA512 (Paystack)

Two middlewares cover the inbound webhook surface: `webhook.signature` (alias for `App\Http\Middleware\VerifyWebhookSignature`) and `paystack.signature` (alias for `App\Http\Middleware\VerifyPaystackSignature`). Both bind to routes; both reject with the appropriate status when the signature is wrong.

### `webhook.signature:<provider>`

A switch on the `$provider` argument, eight providers today:

| Provider | Header | Scheme |
|---|---|---|
| `whatsapp` | `X-Hub-Signature-256` | `sha256=` + HMAC-SHA256 over raw body, secret from `integrations.webhooks.whatsapp.app_secret`. GET handshake echoes `hub_verify_token`. |
| `zoho` | `X-Zoho-Webhook-Token` | Constant-time equality with `integrations.webhooks.zoho.shared_secret`. |
| `ms_graph` | (body `clientState`) | `clientState` field on each value matches `integrations.webhooks.ms_graph.client_state`. Subscription validation echoes `validationToken`. |
| `google` | `X-Goog-Channel-Token` | Constant-time equality with `integrations.webhooks.google.channel_token`. |
| `slack` | `X-Slack-Signature` | `v0=` + HMAC-SHA256 over `v0:{timestamp}:{body}`. **5-minute replay window** via `X-Slack-Request-Timestamp`. |
| `biometric` | `X-Biometric-Signature` + `X-Device-Code` + `X-Biometric-Timestamp` | `sha256=` + HMAC-SHA256 over `{timestamp}.{body}`. Per-device shared secret read from `biometric_devices.shared_secret` (encrypted column). 5-minute replay window. |
| `hubtel_sms` | `X-Hubtel-Signature` | `sha256=` + HMAC-SHA256 over raw body, secret from `messaging.sms.hubtel.webhook_secret`. |
| `hubtel_ussd` | `X-Hubtel-Signature` | Same scheme, secret from `messaging.ussd.webhook_secret`. |

Every comparison uses `hash_equals` (constant-time). Every failure logs an `IntegrationEvent` with `status = STATUS_FAILED` and `event_type = 'webhook.signature_invalid'` so a forensic walk later can see *that* an invalid request arrived (the payload is captured as `$request->json()->all()` or the first 2000 bytes of raw content). The route gets a 401 JSON response.

Two of the providers (Slack, biometric) carry **explicit replay protection** — a timestamp header that must be within five minutes of `time()`. The others do not, because their provider profiles do not include a timestamp in the signed material. The Paystack profile (below) also has no replay protection — Paystack's idempotency strategy is the duplicate-detection on the `integration_events.external_id` unique index, not a per-request timestamp.

### `paystack.signature`

Separate middleware because Paystack uses HMAC-**SHA512** rather than SHA256:

```php
// app/Http/Middleware/VerifyPaystackSignature.php
$computed = hash_hmac('sha512', $request->getContent(), $secret);
if (! hash_equals($computed, $signature)) {
    return response()->json(['error' => 'invalid_signature'], 400);
}
```

The secret is `services.paystack.webhook_secret` (env var `PAYSTACK_WEBHOOK_SECRET`). Failure is a 400 JSON. The same `hash_equals` constant-time discipline applies.

The middleware reads `$request->getContent()` — the *raw* request body — because Laravel's JSON parsing reorders keys, which would invalidate the signature. This is why the middleware must run before anything else mutates the request, which is why it is applied at the route level rather than as a middleware-group append.

### Outbound webhook signing

Outbound webhooks (per `webhook_subscriptions`) are signed with `X-CIHRMS-Signature: sha256=<hex>` over the raw delivery body. The secret is per-subscription, stored encrypted in `webhook_subscriptions.signing_secret`. The signing happens inside `App\Listeners\FanOutWebhooks` and `App\Services\Webhooks\WebhookDispatcher`. The TRD calls out the retry envelope (exponential backoff, max 8 attempts) which is configured on the dispatcher.

---

## 40.11  Security headers — what is set, what is not

The honest answer: **CSP is not set today.** `Content-Security-Policy`, `Strict-Transport-Security`, `X-Frame-Options`, `X-Content-Type-Options`, and `Referrer-Policy` are not produced by any middleware in `app/Http/Middleware/`. The grep is clean.

What *is* set:

- **`SESSION_SECURE_COOKIE`** in `config/session.php` defaults from `.env` — `false` in dev, **must be set to `true` in production** (so the session cookie is only sent over HTTPS). The `.env.example` comment makes this explicit: *"Production: set SESSION_SECURE_COOKIE=true (requires HTTPS)."*
- **`'http_only' => env('SESSION_HTTP_ONLY', true)`** in `config/session.php`. The session cookie is unreadable by JavaScript.
- **`'same_site' => env('SESSION_SAME_SITE', 'lax')`**. SameSite=Lax — the framework default and what Inertia expects. Cross-origin POST without a CSRF token will not carry the cookie.
- **TLS termination at the reverse proxy.** The runbook (Chapter 44) calls for nginx/Apache to terminate TLS 1.2+ and forward to PHP-FPM over a local socket. `APP_TRUSTED_PROXIES` in `.env.example` is documented for this layout.

What is *not* set, and why it matters:

- **`Content-Security-Policy`** would prevent inline script execution and restrict allowed origins for scripts/styles/images. Today the Vue bundle, the Tailwind CSS, and Inertia's bootstrap JSON all need to be reachable; a CSP that allowed `'self'` and the Vite asset host would be straightforward. The TRD lists CSP as Phase 7 (`T7 — Per-tenant CSP + signed-CSP report endpoint`). Adding a baseline CSP header is one middleware away; the work is "build the list of allowed origins, set the header, observe the report-uri for breakages, tighten". On the Phase 4 hardening list.
- **`Strict-Transport-Security`** would force HTTPS at the browser level after the first visit. Today the deploy is HTTPS-only at the reverse proxy and the session cookie is `Secure` — but a man-in-the-middle on the first visit can still serve plain HTTP. Adding `Strict-Transport-Security: max-age=63072000; includeSubDomains; preload` at the reverse proxy is the right place; the application-level alternative is a middleware. Either way, Phase 4.
- **`X-Frame-Options: DENY`** would prevent clickjacking. Today an institute's HR page could in principle be framed inside an attacker's page. The mitigation is the same as CSP (the `frame-ancestors` directive subsumes `X-Frame-Options`). Phase 4.
- **`X-Content-Type-Options: nosniff`** would prevent MIME-sniffing-based confusion attacks. One line of nginx config or one middleware. Phase 4.

A single "security headers" middleware that sets all of these (with sensible CSP report-only first, then tightening) is on the cleanup list. It is bounded work; it is not in the MVP because the production deploy is single-institute behind a reverse proxy with the operator controlling both endpoints.

---

## 40.12  Audit trail as a security control

The audit chain is its own chapter (Chapter 24); the security-specific implication summarised here:

- **Every authenticated mutating HTTP request lands in `audit_logs` via `AuditTrail` middleware** (`app/Http/Middleware/AuditTrail.php`). The middleware runs *after* the response is generated, only fires on `POST`/`PUT`/`PATCH`/`DELETE`, only when `$request->user()` is non-null. The payload is `except`-stripped of `password`, `password_confirmation`, `current_password`, `token`, `_token`; uploaded files are reduced to `{name, mime, size}` descriptors; strings are truncated to 500 characters.
- **The dispatched `WriteAuditLog` job writes the row, locks the chain tail, computes `chain_position = latest + 1` and `previous_hash = latest.row_hash`, then SHA-256s the canonical JSON.** Tamper-evidence is enforced at write time, not at audit time.
- **The `audit:verify-chain` command** walks the chain in `chain_position` order, asserts each row's `previous_hash` matches the prior row's `row_hash`, re-hashes the canonical JSON with `hash_equals` constant-time comparison. Run nightly at 03:00 via the scheduler.
- **The chain detects, it does not prevent.** A determined DBA can `UPDATE` an `audit_logs` row; the chain breaks on the next verification, the broken-chain notification fires, and the chapter on Audit (24.4) explains the recovery. The protection is *audit-ability*, not write-prevention. WORM-mode database privileges are Phase 3.

The relevant takeaway for this chapter: the audit chain is the *primary security record* of who did what. If an investigator asks "did the finance officer reverse this payroll run?", the answer is in `audit_logs`, sealed, with the `2fa:fresh` challenge that preceded the reversal also recorded as its own row.

---

## 40.13  Honest gaps

Listed here so an engineer reading this chapter does not have to guess what is shipped vs. what is documented:

- **No third-party penetration test yet.** Phase 4. The TRD names "annual third-party pen-test" as a target; today the codebase has been reviewed in-house, fuzzed locally, and exercised by 973/973 passing tests, but no external red-team report exists. Remediation of pen-test findings will be tracked through `incident_reports` per Chapter 27 conventions.
- **No CSA registration.** Phase 4. The Cybersecurity Act 2020 (Act 1038) §35 requires designated CII operators to register with the Cyber Security Authority. CIHRMS does not yet self-designate; the audit chain is built to the §41 record-keeping standard so registration is unblocked when an institute chooses to make the call.
- **No CSP / HSTS / X-Frame-Options today.** Phase 4. The application is deployed behind a reverse proxy that can set these headers at the edge; nothing in the app contradicts a strict CSP, but the header itself is not present yet.
- **Password is bcrypt, not Argon2id.** The TRD documents Argon2id; the codebase ships bcrypt with 12 rounds. Either the TRD updates or the codebase migrates. Bcrypt-12 is acceptable security; Argon2id would be modestly better.
- **Bank account numbers are plain.** `employees.bank_account` is a plain string. The salary policy gates *salary*; bank account number is gated by `employees.view` (lower bar). Phase 2 encrypts the bank account column with a `'encrypted'` cast and a backfill migration.
- **2FA fresh window is application-wide, not per-route.** Five minutes once granted. Phase 4 may tighten per-route (90 seconds for payroll reverse, five minutes for ordinary approvals).
- **`auth/sso/{slug}/callback` CSRF-exempt.** Documented; protected by SAML XML signature instead. Not an actual gap, but worth knowing.
- **No automated dependency scanning in CI yet.** TRD's "P7" entry. `composer audit` and `npm audit` run on demand; CI will run them on every PR with an auto-PR on critical CVE — Phase 4.
- **Failed-job payloads can include secrets.** A `ProcessPaystackWebhook` that throws with the API key in scope serialises that key into `failed_jobs.payload`. The interim mitigation is operator hygiene (read `failed_jobs` over SSH, delete after diagnosis); the planned fix is `failed_jobs:redact`.
- **No `Sec-Fetch-*` enforcement.** The framework's CSRF token is sufficient for the present threat model; Phase 4's CSP work will revisit.
- **No WebAuthn / FIDO2.** TOTP is the only 2FA. Phase 5.
- **TwoFactorService fresh window inconsistency.** Chapter 39.8 references "default 15 minutes"; the code says 300 seconds (5 min) in `TwoFactorService::FRESH_TTL_SECONDS`. The code is authoritative — Chapter 39 documentation will be corrected.

None of these is a blocker for the MVP. They are listed so the engineer who notices them does not have to wonder whether someone else has seen them.

---

## 40.14  Defence-in-depth summary

The security posture is layered. A request hitting a sensitive endpoint passes through (in order):

1. **TLS** at the reverse proxy (operator-configured, TLS 1.2+, `Secure` cookie).
2. **CSRF token validation** for state-changing methods (except SAML ACS).
3. **`auth` middleware** — Sanctum session resolved to a User row.
4. **`ForcePasswordChange`** — bounced to `/profile#security` if `password_must_change = true`.
5. **`role` / `permission` middleware** — RBAC coarse gate (Ch 39).
6. **`2fa` (required mode)** — bounced to `/two-factor/enroll` if role requires it and not enrolled.
7. **`2fa:fresh`** — bounced to `/two-factor/challenge` if last challenge older than 5 minutes.
8. **Route binding** — model fetched, 404 if missing.
9. **`audit` middleware** — wraps the request for audit logging on success or failure.
10. **Controller** — thin, delegates to FormRequest.
11. **FormRequest::authorize()** — RBAC fine gate (per-row, Ch 39).
12. **Service** — wrapped in `DB::transaction`; dispatches domain events on success.
13. **Resource** — sensitive fields gated by policy (`salary`, others).
14. **Inertia render** — JSON or HTML.
15. **`AuditTrail` post-response** — dispatches `WriteAuditLog` to `audit` queue.
16. **`WriteAuditLog` job** — locks chain tail, writes row, computes hash.

A request that passes all sixteen layers has been: authenticated, password-current, role-authorised, 2FA-enrolled, 2FA-fresh, permission-authorised, row-authorised, transactionally committed, response-shaped to the viewer's permission level, and sealed into a tamper-evident chain. A request that fails any one of them gets a 4xx and is — depending on the failure point — also captured in `failed_jobs`, `integration_events`, or the audit log itself.

The cost of this stack is real: every authenticated mutating request pays a row insert into `audit_logs` and a hash computation. The payload-stripping happens in middleware; the hash happens on the queue worker; the request-response cycle stays inside its single transaction and returns. The audit chapter has the numbers (Ch 24); the queues chapter (41, planned) will have the throughput envelope.

---

## 40.15  What lives in the next chapters

Chapter 41 walks the five named queues (`audit`, `analytics`, `notifications`, `integrations`, `identity`) and the retry semantics that the `WriteAuditLog`, `ProcessPaystackWebhook`, and `VerifyEmployeeIdentity` jobs depend on for the security claims above to hold under load. Chapter 44 (operational runbook) covers `APP_KEY` rotation, the `users:issue-password-resets` operational drill, the `audit:verify-chain --notify` schedule, the `php artisan down --secret=` rollout pattern, and the production hardening checklist (TLS termination, `SESSION_SECURE_COOKIE=true`, `APP_TRUSTED_PROXIES`, the security-headers reverse-proxy config) that the present chapter has flagged but does not own.

A reader who has read Chapters 24 (audit), 39 (RBAC), and 40 (security) has the complete picture of who can do what, how that is enforced, and how the trace of every action is sealed. The remaining four chapters of Part II (41 queues, 42 frontend, 43 testing, 44 operations) make the implementation underneath each of those claims tangible.
