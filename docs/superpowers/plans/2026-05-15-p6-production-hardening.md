# P6 — Production Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the production-readiness checklist from spec §12: unblock PHP 8.5 tests, throttle public endpoints, gate first-login passwords, wire Sentry, wire backups, lock down cookies.

**Architecture:** Mostly config + tiny middleware additions. No new domain modules.

**Reference spec:** [docs/superpowers/specs/2026-05-15-cihrms-end-to-end-wiring-design.md §12](../specs/2026-05-15-cihrms-end-to-end-wiring-design.md)

---

## Pre-existing P6 work already in place

A quick audit shows several items have been completed in earlier work:

| Spec item | Status |
|---|---|
| `QUEUE_CONNECTION=database` (away from `sync`) | ✅ already in `.env.example` |
| `SESSION_DRIVER=database` | ✅ already |
| `same_site=lax` | ✅ default in `config/session.php` |
| `http_only=true` | ✅ default |
| Some throttles (auth password-reset, whistleblower) | ✅ already on routes |
| `barryvdh/laravel-dompdf ^3.1` | ✅ already in composer |

## What this plan adds

| Task | Item |
|---|---|
| 1 | Unblock PHP 8.5 / `laravel/pao` so tests can run locally + on CI matrix |
| 2 | Throttle public + sensitive endpoints (`/careers/{job}/apply`, `/complaints`, `/attendance/clock`) |
| 3 | `SESSION_SECURE_COOKIE` env documentation; production `.env.example` example values |
| 4 | `password_must_change` column on `users` + `ForcePasswordChange` middleware redirecting to `/profile#security` |
| 5 | `sentry/sentry-laravel` install + config + log channel |
| 6 | `spatie/laravel-backup` install + config + daily 02:00 schedule |
| 7 | Update CI workflow to test on PHP 8.4 + 8.5 matrix; `docs/PROJECT_STATE.md` refresh |

---

## TASK 1 — PHP 8.5 / pao unblock

`laravel/pao ^1.0.6` calls `stream_filter_remove()` in a way PHP 8.5 now rejects. Three options in increasing invasiveness:

1. **Bump to a known-good version** — try `composer require --dev laravel/pao:^1.1 -W`; if there's a newer release that handles 8.5, this is the simplest fix.
2. **Pin to a version that works** — if no fix exists upstream, pin and document.
3. **Suppress at call site via composer-patches** — last resort, document upstream issue.

### Step 1: Probe

```powershell
composer outdated laravel/pao
composer info laravel/pao --all 2>&1 | Select-String -Pattern 'versions'
```

### Step 2: Attempt upgrade

```powershell
composer require --dev laravel/pao:^1 -W
```

If composer accepts a newer version, run `composer install` and `vendor/bin/pest --filter=DashboardTimeSeriesTest` to verify. If the new version still has the same bug (or no newer exists), pin the previous working version with a code-comment in `composer.json` explaining why.

### Step 3: Commit

```powershell
git add composer.json composer.lock
git commit -m "chore(deps): unblock laravel/pao for PHP 8.5 local test execution"
```

---

## TASK 2 — Throttles on public + sensitive endpoints

### Step 1: Locate the endpoints

```powershell
Select-String -Path routes/web.php -Pattern "careers\.apply|complaints\.store|attendance\.clock"
```

### Step 2: Add throttle middleware

In `routes/web.php`, modify:

```php
// Public job application — 5 per minute per IP
Route::post('/careers/{job}/apply', [RecruitmentController::class, 'apply'])
    ->middleware('throttle:5,1')
    ->name('careers.apply');

// Public complaint submission — 5 per minute per IP
Route::post('/complaints',  [ComplaintController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('complaints.store');

// Self-clock — 10 per minute per user
Route::post('/attendance/clock', [AttendanceController::class, 'clockSelf'])
    ->middleware('throttle:10,1')
    ->name('attendance.clock');
```

If the routes are already wrapped in another middleware group, prepend the throttle to the existing middleware list rather than overriding.

### Step 3: Verify + commit

```powershell
php artisan route:list --name=careers.apply
git add routes/web.php
git commit -m "feat(security): throttle public career apply / complaint submit / self-clock endpoints"
```

---

## TASK 3 — Secure-cookie env documentation

Production `.env` should set `SESSION_SECURE_COOKIE=true`. Document it in `.env.example` so deploys remember.

### Step 1: Edit `.env.example`

Add after the existing `SESSION_*` block:

```
# Production: set to true. HTTPS only.
SESSION_SECURE_COOKIE=false
```

And add `APP_TRUSTED_PROXIES` comment for behind-load-balancer setups:

```
# Production: set to "*" if behind a trusted load balancer
APP_TRUSTED_PROXIES=
```

### Step 2: Commit

```powershell
git add .env.example
git commit -m "docs(env): document SESSION_SECURE_COOKIE for HTTPS-only production cookies"
```

---

## TASK 4 — password_must_change column + ForcePasswordChange middleware

### Step 1: Migration

`database/migrations/2026_06_01_000001_add_password_must_change_to_users.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'password_must_change')) {
                $table->boolean('password_must_change')->default(false)->after('password');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'password_must_change')) {
                $table->dropColumn('password_must_change');
            }
        });
    }
};
```

### Step 2: User model fillable + cast

Open `app/Models/User.php`. Add `'password_must_change'` to `$fillable`. Add `'password_must_change' => 'boolean'` to `casts()`.

### Step 3: Middleware

`app/Http/Middleware/ForcePasswordChange.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForcePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->password_must_change && ! $this->isAllowedRoute($request)) {
            if ($request->expectsJson()) {
                abort(403, 'Password change required before continuing.');
            }
            return redirect()->route('profile.edit')->with('error', 'Please set a new password before continuing.')
                ->withFragment('security');
        }

        return $next($request);
    }

    private function isAllowedRoute(Request $request): bool
    {
        $allowed = [
            'profile.edit', 'profile.update', 'profile.password',
            'logout', 'password.confirm',
        ];

        $name = $request->route()?->getName();
        return $name !== null && in_array($name, $allowed, true);
    }
}
```

### Step 4: Register middleware

In `bootstrap/app.php`, add `ForcePasswordChange::class` to the `web` middleware group (or chain it after `auth`):

```php
->withMiddleware(function (Middleware $middleware) {
    // Existing aliases...
    $middleware->web(append: [
        \App\Http\Middleware\ForcePasswordChange::class,
    ]);
})
```

If the project uses Laravel 11/13 streamlined `bootstrap/app.php`, follow that pattern. Read the file first.

### Step 5: ProfileController.updatePassword clears the flag

Open `app/Http/Controllers/ProfileController.php`. In the `updatePassword` method, after the password is saved, also set `password_must_change = false`:

```php
$request->user()->update([
    'password' => Hash::make($request->validated('password')),
    'password_must_change' => false,
]);
```

### Step 6: Seeder + commit

In `database/seeders/DatabaseSeeder.php` (or wherever seeded users are created), set `'password_must_change' => true` for all seeded users so the first login forces a change.

```powershell
git add database/migrations/2026_06_01_000001_add_password_must_change_to_users.php app/Models/User.php app/Http/Middleware/ForcePasswordChange.php bootstrap/app.php app/Http/Controllers/ProfileController.php database/seeders/DatabaseSeeder.php
git commit -m "feat(security): password_must_change gate + ForcePasswordChange middleware

Seeded users now have password_must_change=true so the first login
redirects to /profile#security until a new password is set."
```

---

## TASK 5 — Sentry wiring

### Step 1: Install

```powershell
composer require sentry/sentry-laravel
```

### Step 2: Publish config + register channel

```powershell
php artisan vendor:publish --provider="Sentry\Laravel\ServiceProvider"
```

Add to `config/logging.php`:
```php
'sentry' => [
    'driver' => 'sentry',
    'level' => env('LOG_LEVEL', 'error'),
    'bubble' => true,
],
```

In the `stack` driver's channels list, add `'sentry'`:
```php
'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => env('LOG_STACK', 'single')   // existing
        // OR if hard-coded:
        // 'channels' => ['single', 'sentry'],
    ],
    // ...
]
```

### Step 3: Add env vars

In `.env.example`:
```
SENTRY_LARAVEL_DSN=
SENTRY_TRACES_SAMPLE_RATE=0.1
SENTRY_SEND_DEFAULT_PII=false
```

### Step 4: Verify + commit

```powershell
php artisan sentry:test --dsn=__SAMPLE_DSN__ 2>&1 || echo "(sentry test expected to fail without a real DSN)"
git add composer.json composer.lock config/sentry.php config/logging.php .env.example
git commit -m "feat(observability): wire sentry/sentry-laravel + log channel"
```

---

## TASK 6 — spatie/laravel-backup

### Step 1: Install

```powershell
composer require spatie/laravel-backup
php artisan vendor:publish --provider="Spatie\Backup\BackupServiceProvider"
```

### Step 2: Configure `config/backup.php`

Edit the config to:
- Source `db` driver: sqlite (dev) + pgsql (prod) — let Laravel pick from `DB_CONNECTION`.
- Source `files`: `storage/app/`
- Destination: `local` disk in dev; `s3` in production via env
- Notifications: log channel in dev, slack/mail in production

Most of `backup.php` is sensible by default. Just make sure:
```php
'backup' => [
    'name' => env('APP_NAME', 'cihrms'),
    'source' => [
        'files' => [
            'include' => [base_path('storage/app')],
            'exclude' => [storage_path('app/public/avatars'), storage_path('app/public/documents')],
        ],
        'databases' => ['sqlite'], // adjust per env
    ],
    'destination' => [
        'filename_prefix' => 'cihrms-',
        'disks' => ['local'],
    ],
],
```

### Step 3: Schedule daily

Append to `routes/console.php`:
```php
// Daily database + storage backup at 02:00
Schedule::command('backup:clean')->daily()->at('01:00');
Schedule::command('backup:run')->daily()->at('02:00');
```

### Step 4: Verify + commit

```powershell
php artisan backup:list 2>&1
git add composer.json composer.lock config/backup.php routes/console.php
git commit -m "feat(operations): spatie/laravel-backup + daily 02:00 schedule"
```

---

## TASK 7 — CI matrix + PROJECT_STATE + push

### Step 1: Update CI workflow

Open `.github/workflows/ci.yml`. Change the PHP version from a single `'8.4'` to a matrix:

```yaml
strategy:
  matrix:
    php-version: ['8.4', '8.5']

steps:
  - uses: actions/checkout@v4
  - name: Setup PHP ${{ matrix.php-version }}
    uses: shivammathur/setup-php@v2
    with:
      php-version: ${{ matrix.php-version }}
      ...
```

This validates that Task 1's pao fix actually works on PHP 8.5.

### Step 2: PROJECT_STATE

Update `docs/PROJECT_STATE.md` § 1 headline: "All five phases delivered" → "All six phases delivered, production-ready." Update §5 gaps list — strike the PHP 8.5 blocker.

### Step 3: Commit + push

```powershell
git add .github/workflows/ci.yml docs/PROJECT_STATE.md
git commit -m "ci+docs: PHP 8.4 + 8.5 matrix; PROJECT_STATE — P6 production hardening complete"
git push origin main
```

---

## Manual smoke checklist

1. `php artisan migrate` runs cleanly.
2. Seed users → log in → redirected to `/profile#security` until password changed.
3. Hit `POST /careers/{job}/apply` 6 times in a minute → 429 on the 6th.
4. `vendor/bin/pest` runs locally on PHP 8.5.
5. `php artisan backup:run` writes a backup archive.
6. CI green on both 8.4 and 8.5.

---

## Self-review checklist

- ✅ Each task ends with a commit
- ✅ No new domain modules added; all changes are config + tiny middleware
- ✅ Sentry + spatie/backup are dev-time installs that can be no-ops in dev (no DSN, log destination only)
- ✅ Backwards-compatible: password_must_change column defaults to false, so existing seeded users without it are unaffected until the seeder sets it
- ✅ CI matrix update is the only spec-mandated test that exercises both PHP versions
