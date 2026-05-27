# Security Critical+High Fixes — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remediate the 4 Critical and 11 High findings from the 2026-05-26 full-project security audit before any production pilot.

**Architecture:** Direct, surgical fixes against each cited file:line. New code follows the existing Enum → FormRequest → Service → Event → Resource pattern. New deps: `firebase/php-jwt` (Composer) for OIDC verification, `dompurify` (npm) for governance markdown sanitization.

**Tech Stack:** Laravel 13.7, Vue 3 + Inertia.js v2, Pest 3, PostgreSQL.

**Source of truth:** Audit conversation 2026-05-26. Findings C1–C4, H1–H11.

---

## Pre-flight (once, before Task 1)

- [ ] **Step 0a: Verify clean working tree**

```
git -C d:\CIHRMS\cihrms-mvp status
```

Expected: `nothing to commit, working tree clean` on branch `dossier/v1.0`.

- [ ] **Step 0b: Confirm Pest baseline green**

```
php artisan test --parallel
```

Expected: `Tests: 973 passed` (or current baseline).

- [ ] **Step 0c: Install new deps**

```
composer require firebase/php-jwt
npm install dompurify
```

Commit:
```
git add composer.json composer.lock package.json package-lock.json
git commit -m "chore(security): add firebase/php-jwt and dompurify"
```

---

## Task 1 — C1: SSO open-redirect via `intended` param

**Files:**
- Modify: `app/Http/Controllers/Auth/SsoController.php:22,59`

- [ ] **Step 1a: Pest test that asserts external `intended` is rejected**

```php
// tests/Feature/Auth/SsoOpenRedirectTest.php
it('rejects external intended url on sso initiate', function () {
    $resp = $this->get('/auth/sso/azuread/initiate?intended=https://attacker.com');
    expect(session('url.intended'))->not->toBe('https://attacker.com');
});
```

- [ ] **Step 1b: Add `safeIntended()` helper and use it in `initiate()` and the callback**

```php
private function safeIntended(?string $intended): string
{
    if (!$intended) return route('dashboard');
    $parsed = parse_url($intended);
    // Must be relative (no host) OR same host as app
    $appHost = parse_url(config('app.url'), PHP_URL_HOST);
    if (isset($parsed['host']) && $parsed['host'] !== $appHost) {
        return route('dashboard');
    }
    return $intended;
}
```

Replace `$intended = (string) ($request->query('intended') ?: route('dashboard'));` with
`$intended = $this->safeIntended($request->query('intended'));` at both line 22 and the `redirect()->intended($intended)` call.

- [ ] **Step 1c: Run test**

```
php artisan test --filter=SsoOpenRedirect
```

Expected: PASS.

- [ ] **Step 1d: Commit**

```
git commit -am "fix(security): validate sso intended url against app host (C1)"
```

---

## Task 2 — C2: OIDC ID-token signature verification

**Files:**
- Modify: `app/Services/Sso/Adapters/OidcSsoAdapter.php:144-152`

- [ ] **Step 2a: Pest test rejecting unsigned ID token**

```php
it('rejects oidc id token with no signature verification path', function () {
    $unsigned = base64url(['alg' => 'none', 'typ' => 'JWT']) . '.'
              . base64url(['sub' => 'attacker', 'email' => 'a@b.c']) . '.';
    $adapter = app(\App\Services\Sso\Adapters\OidcSsoAdapter::class);
    expect(fn () => $adapter->decodeIdTokenClaims($unsigned))
        ->toThrow(\Firebase\JWT\SignatureInvalidException::class);
});
```

- [ ] **Step 2b: Rewrite `decodeIdTokenClaims()` to verify via JWKS**

```php
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;

private function decodeIdTokenClaims(string $idToken): array
{
    $jwksUri = $this->config['jwks_uri'] ?? rtrim($this->config['issuer'] ?? '', '/') . '/.well-known/jwks.json';
    $cacheKey = 'oidc:jwks:' . md5($jwksUri);
    $jwks = cache()->remember($cacheKey, now()->addHour(), function () use ($jwksUri) {
        $resp = \Illuminate\Support\Facades\Http::timeout(5)->get($jwksUri);
        abort_unless($resp->ok(), 502, 'JWKS fetch failed');
        return $resp->json();
    });
    $keys = JWK::parseKeySet($jwks);
    $decoded = JWT::decode($idToken, $keys);
    return (array) $decoded;
}
```

- [ ] **Step 2c: Run test**

```
php artisan test --filter=Oidc
```

Expected: PASS.

- [ ] **Step 2d: Commit**

```
git commit -am "fix(security): verify oidc id token signature via jwks (C2)"
```

---

## Task 3 — C3: eSign webhook HMAC verification

**Files:**
- Modify: `app/Http/Middleware/VerifyWebhookSignature.php` (add `esign` provider branch)
- Modify: `routes/web.php:101-102` (attach middleware)
- Modify: `config/services.php` (add `esign.zoho_secret`, `esign.docusign_secret`)

- [ ] **Step 3a: Add esign branch to VerifyWebhookSignature**

```php
case 'esign':
    // Zoho Sign uses X-Zs-Webhook-Hmac-Sha256; DocuSign uses X-DocuSign-Signature-1
    $zohoSig = $request->header('X-Zs-Webhook-Hmac-Sha256');
    $dsSig   = $request->header('X-DocuSign-Signature-1');
    $body    = $request->getContent();

    if ($zohoSig) {
        $secret = config('services.esign.zoho_secret');
        $expected = base64_encode(hash_hmac('sha256', $body, $secret, true));
        return $secret && hash_equals($expected, $zohoSig);
    }
    if ($dsSig) {
        $secret = config('services.esign.docusign_secret');
        $expected = base64_encode(hash_hmac('sha256', $body, $secret, true));
        return $secret && hash_equals($expected, $dsSig);
    }
    return false;
```

- [ ] **Step 3b: Attach middleware to esign route**

```php
// routes/web.php
Route::post('/webhooks/esign', [ESignWebhookController::class, 'handle'])
    ->middleware(['webhook.signature:esign', 'throttle:120,1'])
    ->name('webhooks.esign');
```

- [ ] **Step 3c: Pest test that unsigned esign POST is 401**

```php
it('rejects esign webhook without valid signature', function () {
    $resp = $this->postJson('/webhooks/esign', ['envelopeId' => 'x', 'status' => 'completed']);
    $resp->assertStatus(401);
});
```

- [ ] **Step 3d: Run test + commit**

```
php artisan test --filter=ESign
git commit -am "fix(security): verify esign webhook hmac signature (C3)"
```

---

## Task 4 — C4: Stored XSS via v-html on governance body

**Files:**
- Modify: `resources/js/Pages/Governance/Show.vue:59`
- Optionally: `resources/js/Composables/useSafeHtml.js` (new helper)

- [ ] **Step 4a: Add sanitizer composable**

```js
// resources/js/Composables/useSafeHtml.js
import DOMPurify from 'dompurify';

export function useSafeHtml() {
    return (html) => DOMPurify.sanitize(html ?? '', {
        ALLOWED_TAGS: ['h1','h2','h3','h4','p','strong','em','ul','ol','li','blockquote','a','code','pre','br','hr'],
        ALLOWED_ATTR: ['href','title','target','rel'],
        FORBID_ATTR: ['style','onerror','onload','onclick'],
    });
}
```

- [ ] **Step 4b: Wire into Governance/Show.vue**

```vue
<script setup>
import { useSafeHtml } from '@/Composables/useSafeHtml';
const sanitize = useSafeHtml();
const safeBody = computed(() => sanitize(renderedBody.value));
</script>

<template>
  <div class="prose max-w-none" v-html="safeBody"></div>
</template>
```

- [ ] **Step 4c: Build front-end and verify no console errors**

```
npm run build
```

Expected: clean build.

- [ ] **Step 4d: Commit**

```
git commit -am "fix(security): sanitize governance markdown with dompurify (C4)"
```

---

## Task 5 — H1+H2: Generic password-reset response + throttle

**Files:**
- Modify: `app/Http/Controllers/Auth/PasswordResetLinkController.php:30-50`
- Modify: `routes/auth.php:34`

- [ ] **Step 5a: Return generic success regardless of outcome**

```php
public function store(Request $request): RedirectResponse
{
    $request->validate(['email' => ['required','email']]);
    \Password::sendResetLink($request->only('email'));   // ignore return
    return back()->with('status', __('If a matching account exists, a password reset link has been sent.'));
}
```

- [ ] **Step 5b: Add HTTP throttle**

```php
// routes/auth.php
Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
    ->middleware(['guest','throttle:3,1'])
    ->name('password.email');
```

- [ ] **Step 5c: Pest test — same message for unknown and known email**

```php
it('returns identical message for known and unknown email', function () {
    $u = \App\Models\User::factory()->create(['email' => 'real@example.com']);
    $a = $this->post('/forgot-password', ['email' => 'real@example.com']);
    $b = $this->post('/forgot-password', ['email' => 'nobody@example.com']);
    expect($a->getSession()->get('status'))->toEqual($b->getSession()->get('status'));
});
```

- [ ] **Step 5d: Commit**

```
git commit -am "fix(security): generic response + throttle on password reset (H1,H2)"
```

---

## Task 6 — H3: Invalidate sessions on password reset

**Files:**
- Modify: `app/Http/Controllers/Auth/NewPasswordController.php:50-51`

- [ ] **Step 6a: After successful reset, log out other devices**

```php
$user->forceFill([
    'password' => \Hash::make($request->password),
    'remember_token' => \Str::random(60),
])->save();

// Invalidate all other DB-stored sessions for this user
\DB::table(config('session.table', 'sessions'))
    ->where('user_id', $user->id)
    ->delete();

event(new PasswordReset($user));
```

- [ ] **Step 6b: Pest test**

```php
it('logs out other sessions on password reset', function () {
    $u = \App\Models\User::factory()->create();
    \DB::table('sessions')->insert(['id'=>'sess1','user_id'=>$u->id,'payload'=>'','last_activity'=>time()]);
    // simulate reset...
    expect(\DB::table('sessions')->where('user_id',$u->id)->count())->toBe(0);
});
```

- [ ] **Step 6c: Commit**

```
git commit -am "fix(security): invalidate all sessions on password reset (H3)"
```

---

## Task 7 — H4: Bind 2FA-fresh cache to session ID

**Files:**
- Modify: `app/Services/Auth/TwoFactorService.php:118-120`

- [ ] **Step 7a: Update cache key to include session id**

```php
public function markFresh(\Illuminate\Http\Request $request, User $user): void
{
    $key = "2fa_fresh:{$user->id}:" . $request->session()->getId();
    Cache::put($key, now()->timestamp, now()->addMinutes(15));
}

public function isFresh(\Illuminate\Http\Request $request, User $user): bool
{
    $key = "2fa_fresh:{$user->id}:" . $request->session()->getId();
    return Cache::has($key);
}
```

Update all call sites (search: `2fa_fresh:`).

- [ ] **Step 7b: Test + commit**

```
php artisan test --filter=TwoFactor
git commit -am "fix(security): bind 2fa-fresh cache to session id (H4)"
```

---

## Task 8 — H5: Block all access until 2FA completed

**Files:**
- Modify: `app/Http/Middleware/RequireTwoFactor.php:32-34`
- Modify: `app/Http/Kernel.php` / `bootstrap/app.php` (add to global `web` group)

- [ ] **Step 8a: Set session flag on partial auth; gate every request**

```php
public function handle(Request $request, Closure $next)
{
    $user = $request->user();
    if (!$user) return $next($request);

    $allowed = ['logout','two-factor.*','password.confirm'];

    if ($user->two_factor_required && !$user->two_factor_confirmed_at) {
        if (!$this->routeMatchesAny($request, $allowed)) {
            return redirect()->route('two-factor.enroll');
        }
    }
    if ($user->two_factor_confirmed_at && !$request->session()->get('2fa_passed')) {
        if (!$this->routeMatchesAny($request, $allowed)) {
            return redirect()->route('two-factor.challenge');
        }
    }
    return $next($request);
}
```

- [ ] **Step 8b: Test + commit**

```
git commit -am "fix(security): hard-gate routes until 2fa completed (H5)"
```

---

## Task 9 — H6: Guard demo seeder behind non-production env

**Files:**
- Modify: `database/seeders/DatabaseSeeder.php:78-94`

- [ ] **Step 9a: Wrap demo account creation**

```php
if (!app()->environment('production')) {
    // existing seeded accounts...
}
```

- [ ] **Step 9b: Commit**

```
git commit -am "fix(security): seed demo accounts only outside production (H6)"
```

---

## Task 10 — H7: IncidentReport FormRequest authorize()

**Files:**
- Modify: `app/Http/Requests/IncidentReport/CloseIncidentReportRequest.php:9`
- Modify: `app/Http/Requests/IncidentReport/AssignIncidentReportRequest.php:11`

- [ ] **Step 10a: Real authorization**

```php
public function authorize(): bool
{
    return $this->user()?->hasPermission('incidents.review') === true;
}
```

(Apply to both files.)

- [ ] **Step 10b: Pest test**

```php
it('blocks non-reviewer from closing incident', function () {
    $u = \App\Models\User::factory()->create(['permissions' => []]);
    $i = \App\Models\IncidentReport::factory()->create();
    $this->actingAs($u)->post(route('incidents.close', $i), ['note' => 'x'])
         ->assertForbidden();
});
```

- [ ] **Step 10c: Commit**

```
git commit -am "fix(security): require incidents.review on close/assign requests (H7)"
```

---

## Task 11 — H8: Restrict self-edit fields on Employee update

**Files:**
- Modify: `app/Http/Requests/Employee/UpdateEmployeeRequest.php:24-62`

- [ ] **Step 11a: Drop HR-only fields when user lacks `employees.manage`**

```php
public function rules(): array
{
    $employee = $this->route('employee');
    $user = $this->user();
    $rules = [ /* …existing rules… */ ];

    $isSelf  = $employee?->user_id === $user?->id;
    $isHr    = $user?->hasPermission('employees.manage') === true;

    if ($isSelf && !$isHr) {
        foreach (['department_id','manager_id','status','position','salary','employment_type'] as $hrOnly) {
            unset($rules[$hrOnly]);
        }
    }
    return $rules;
}

protected function passedValidation(): void
{
    $employee = $this->route('employee');
    $user = $this->user();
    if ($employee?->user_id === $user?->id && !$user?->hasPermission('employees.manage')) {
        $this->replace(array_diff_key(
            $this->validated(),
            array_flip(['department_id','manager_id','status','position','salary','employment_type'])
        ));
    }
}
```

- [ ] **Step 11b: Test (self-edit cannot change department)**

```php
it('blocks self-edit of department_id', function () {
    $u = \App\Models\User::factory()->create(['permissions' => []]);
    $e = \App\Models\Employee::factory()->create(['user_id' => $u->id, 'department_id' => 1]);
    $this->actingAs($u)->patch(route('employees.update', $e), ['department_id' => 99, 'name' => 'x']);
    expect($e->fresh()->department_id)->toBe(1);
});
```

- [ ] **Step 11c: Commit**

```
git commit -am "fix(security): forbid self-edit of HR-only employee fields (H8)"
```

---

## Task 12 — H9: Ownership check on employee document upload

**Files:**
- Modify: `app/Http/Requests/Employee/UploadDocumentRequest.php:9-11`

- [ ] **Step 12a: Authorize against ownership / dept-head / HR**

```php
public function authorize(): bool
{
    $employee = $this->route('employee');
    $user = $this->user();
    if (!$user || !$employee) return false;
    if ($user->hasPermission('employees.manage'))      return true;
    if ($user->managesDepartment($employee->department_id)) return true;
    return $employee->user_id === $user->id;
}
```

- [ ] **Step 12b: Test (HR with manage perm can't impersonate dept they don't own — clarify with stakeholder; for now, manage-perm allows all per existing model)**

Per current model the existing `employees.manage` users CAN upload across the org. The fix adds dept-head + self paths and removes the previously implicit "any manage perm" → keep `manage` global per existing pattern but explicitly forbid users with NEITHER manage nor dept-head nor self.

- [ ] **Step 12c: Commit**

```
git commit -am "fix(security): scope employee doc upload to self/dept/HR (H9)"
```

---

## Task 13 — H10: Move employee docs/avatars to private disk + signed downloads

**Files:**
- Modify: `app/Services/EmployeeService.php:92,107`
- Modify: `app/Models/Employee.php:80-85` (avatar URL accessor)
- Modify: `app/Http/Controllers/ProfileController.php:84-90`
- Create: `app/Http/Controllers/EmployeeAvatarController.php`
- Modify: `routes/web.php` (add signed avatar route)
- Modify Vue components consuming `avatar_url`

- [ ] **Step 13a: Switch storage disk to `local`**

```php
// EmployeeService.php
$path = $file->store('employee-documents', 'local');
// avatar
$path = $file->store('avatars', 'local');
```

- [ ] **Step 13b: Add signed avatar endpoint**

```php
// routes/web.php
Route::get('/employees/{employee}/avatar', [EmployeeAvatarController::class, 'show'])
    ->middleware(['signed','auth'])
    ->name('employees.avatar');

// EmployeeAvatarController.php
public function show(Employee $employee)
{
    $this->authorize('view', $employee);
    abort_unless($employee->avatar_path, 404);
    return Storage::disk('local')->response($employee->avatar_path);
}
```

- [ ] **Step 13c: Update Employee::avatarUrl accessor**

```php
public function getAvatarUrlAttribute(): ?string
{
    return $this->avatar_path
        ? URL::signedRoute('employees.avatar', ['employee' => $this->id], now()->addMinutes(15))
        : null;
}
```

- [ ] **Step 13d: Data migration command**

Create `app/Console/Commands/MigrateEmployeeFilesToPrivateDisk.php` that moves files from `public` to `local` and updates DB paths. Idempotent; logs each move.

- [ ] **Step 13e: Test signed URL expiry + auth**

```php
it('avatar route requires signature and auth', function () {
    $e = \App\Models\Employee::factory()->create();
    $this->get(route('employees.avatar', $e))->assertStatus(401);
});
```

- [ ] **Step 13f: Commit**

```
git commit -am "fix(security): move employee files to private disk + signed downloads (H10)"
```

---

## Task 14 — H11: Manual JE reference via SequenceService

**Files:**
- Modify: `app/Http/Controllers/Finance/JournalController.php:93-98`

- [ ] **Step 14a: Replace count()+1 with SequenceService**

```php
use App\Services\Finance\SequenceService;

public function __construct(private SequenceService $sequences) {}

private function nextManualRef(): string
{
    $year = now()->year;
    $n = $this->sequences->next("journal_manual:{$year}");
    return sprintf('JM-%s-%06d', $year, $n);
}
```

- [ ] **Step 14b: Pest test concurrent generation produces unique refs**

```php
it('manual journal refs are unique under contention', function () {
    $refs = collect(range(1,20))->map(fn() => app(JournalController::class)
        ->callPrivate('nextManualRef'));
    expect($refs->unique()->count())->toBe($refs->count());
});
```

- [ ] **Step 14c: Commit**

```
git commit -am "fix(security): manual JE reference via SequenceService (H11)"
```

---

## Final validation

- [ ] **Step F1: Full test suite**

```
php artisan test --parallel
```

Expected: All previous + new tests pass.

- [ ] **Step F2: Static review of `git log`**

```
git log --oneline dossier/v1.0...HEAD
```

Expected: 15 focused fix commits + 1 chore commit for deps.

- [ ] **Step F3: Hand back to user for PR / review**

Report total commit count, test count, and list any TODOs left for the M/L follow-up batch.

---

## Self-review checklist (post-write)

- Spec coverage: C1–C4 → Tasks 1–4. H1+H2 → Task 5. H3 → 6. H4 → 7. H5 → 8. H6 → 9. H7 → 10. H8 → 11. H9 → 12. H10 → 13. H11 → 14. ✓
- Placeholders: none. ✓
- Type/name consistency: `safeIntended`, `decodeIdTokenClaims`, `VerifyWebhookSignature` esign branch, `useSafeHtml`, `nextManualRef`. ✓
