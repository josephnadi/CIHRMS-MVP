# CIHRMS Production Deploy

Reference deployment artefacts for production hardening (Phase 6 of the implementation spec).

## Components

### `supervisor/`
Supervisor unit files for the queue workers and Laravel scheduler.

| File | Purpose |
|---|---|
| `cihrms-queue-default.conf` | Worker for the `default` queue — drains every Laravel notification, the N1 `SendSmsJob`, N2 module listeners (loan/benefit/attendance/payroll/offboarding/asset/document notifications), and the Paystack webhook job. **Without this, those side-effects silently queue and never fire.** |
| `cihrms-queue-analytics.conf` | Worker for the `analytics` queue (2 processes — high-volume, parallelisable). |
| `cihrms-scheduler.conf` | Replaces `* * * * * php artisan schedule:run` cron with a long-lived process. |

Audit log writes do **not** need a queue worker. `App\Http\Middleware\AuditTrail`
uses `dispatchAfterResponse()`, which runs the write in the same PHP-FPM
process after the response is flushed.

Install:
```bash
sudo cp deploy/supervisor/*.conf /etc/supervisor/conf.d/
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start cihrms-queue-default:* cihrms-queue-analytics:* cihrms-scheduler
```

## Production `.env` checklist

```bash
APP_ENV=production
APP_DEBUG=false
APP_URL=https://cihrms.example.gov.gh

# HTTPS-only cookies (requires APP_URL on https)
SESSION_SECURE_COOKIE=true

# Behind a load balancer? Trust the LB's forwarded headers
APP_TRUSTED_PROXIES=*

# Real queue + sessions + cache (not sync / file)
QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database

# Sentry — set DSN to activate the 'sentry' log channel
SENTRY_LARAVEL_DSN=https://...@sentry.io/...
SENTRY_TRACES_SAMPLE_RATE=0.1
LOG_CHANNEL=stack
LOG_STACK=single,sentry

# Daily backup destination
BACKUP_FILESYSTEM_DISK=s3
BACKUP_ARCHIVE_PASSWORD=<long random string>
AWS_BUCKET=cihrms-backups
```

## Optional packages to install in prod

```bash
composer require sentry/sentry-laravel
composer require spatie/laravel-backup
php artisan vendor:publish --provider="Spatie\Backup\BackupServiceProvider"
```

After installing `spatie/laravel-backup`, schedule it in `routes/console.php`:
```php
Schedule::command('backup:clean')->daily()->at('01:00');
Schedule::command('backup:run')->daily()->at('02:00');
Schedule::command('backup:monitor')->daily()->at('03:00');
```

## PHP 8.5 note

`laravel/pao` (used only in `dev` for the AI agent runtime) calls
`stream_filter_remove()` in a way that PHP 8.5 now rejects. The package
is excluded from Laravel's auto-discovery via `composer.json` →
`extra.laravel.dont-discover` so artisan boots cleanly on PHP 8.5 in
production. The dev experience on PHP 8.4 is unchanged.
