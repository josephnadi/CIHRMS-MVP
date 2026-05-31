# CIHRMS Production Launch Checklist

A single page covering every external configuration and infrastructure step required to take CIHRMS from "tests green on a developer laptop" to "real-time production functional".

Organised by severity:

- 🔴 **Critical** — the app is broken without these
- 🟡 **Important** — features are degraded without these
- 🟢 **Optional / hardening** — recommended but not blocking

If a launch deadline is tight, do every 🔴 item in order. The 🟡 items can land within 1–2 weeks of launch.

---

## 🔴 Critical

### 1. Default queue worker running

All Laravel notifications, the N1 `SendSmsJob`, every N2 module listener (loans, benefits, attendance corrections, payroll, offboarding, assets, documents), and `ProcessPaystackWebhook` ride the **`default`** queue. The `cihrms-queue-analytics.conf` supervisor unit only covers the `analytics` queue. Without a default-queue worker, notifications silently queue and never fire.

```bash
sudo cp deploy/supervisor/cihrms-queue-default.conf /etc/supervisor/conf.d/
sudo cp deploy/supervisor/cihrms-queue-analytics.conf /etc/supervisor/conf.d/
sudo cp deploy/supervisor/cihrms-scheduler.conf /etc/supervisor/conf.d/
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl start cihrms-queue-default:* cihrms-queue-analytics:* cihrms-scheduler
```

Verify:

```bash
sudo supervisorctl status | grep cihrms
# All three programs should show RUNNING
```

### 2. Cache + session + queue all pointing at the database (or Redis)

In `.env`:

```bash
QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database
```

These are also the templated defaults in `.env.example`. The danger is overriding them with `sync` / `file` / `array` in production:

- `QUEUE_CONNECTION=sync` runs jobs in-process and bypasses N1's retry/backoff
- `SESSION_DRIVER=file` doesn't share state across multiple PHP-FPM workers
- `CACHE_STORE=array` breaks the N1 `SmsDispatchExhausted` per-recipient guard (cache key TTL becomes per-request)

If using Redis: switch all three to `redis` and set `REDIS_HOST` + `REDIS_PORT` + `REDIS_PASSWORD`.

### 3. APP_KEY, APP_URL, migrations, seeders

```bash
cp .env.example .env
# Edit .env: APP_ENV=production, APP_DEBUG=false, APP_URL=https://<your-domain>
php artisan key:generate --force
php artisan migrate --force
php artisan db:seed --class=RolePermissionSeeder --force
php artisan storage:link
```

The `DatabaseSeeder` (full demo data) self-gates on `app()->environment('production')` — it will refuse to run in production. The `RolePermissionSeeder` is the production-safe one.

### 4. Mail driver wired to a real provider

The `.env.example` default is `MAIL_MAILER=log`, which writes every notification email to `storage/logs/laravel.log`. Pick one:

```bash
# SMTP (any provider)
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_SCHEME=tls
MAIL_FROM_ADDRESS=noreply@<your-domain>
MAIL_FROM_NAME="CIHRMS"

# Or Postmark
MAIL_MAILER=postmark
POSTMARK_API_KEY=...

# Or AWS SES
MAIL_MAILER=ses
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=...
```

Verify by sending a test notification:

```bash
php artisan tinker
>>> \App\Models\User::first()->notify(new \App\Notifications\NewEmployeeWelcome(\App\Models\Employee::first()));
# Check the recipient's inbox (or storage/logs/laravel.log if you're still on log driver)
```

### 5. SMS provider credentials

Default is `SMS_DRIVER=log`. Production needs `hubtel` or `twilio`:

```bash
SMS_DRIVER=hubtel
HUBTEL_CLIENT_ID=...
HUBTEL_CLIENT_SECRET=...
HUBTEL_SENDER_ID=CIHRMS   # pre-registered alphanumeric on Hubtel
HUBTEL_WEBHOOK_SECRET=<random>
```

Without these, every outbound SMS row ends up in the `Failed` status and the N1 `SmsDispatchExhausted` alert fires to every `messaging.manage` holder.

Test: trigger an SMS from `/admin/messaging` → "Send test" and watch the row transition Queued → Sent → Delivered within ~10 seconds.

### 6. Paystack credentials + webhook URL registered

```bash
PAYSTACK_PUBLIC_KEY=pk_live_...
PAYSTACK_SECRET_KEY=sk_live_...
PAYSTACK_WEBHOOK_SECRET=<paste from Paystack dashboard>
PAYSTACK_URL=https://api.paystack.co
PAYSTACK_CALLBACK_DEFAULT_URL=https://<your-domain>/portal/fees   # or wherever you want post-pay redirect
```

On the **Paystack dashboard**:

1. Settings → API Keys & Webhooks
2. Add webhook URL: `https://<your-domain>/webhooks/paystack`
3. Paste the same secret as `PAYSTACK_WEBHOOK_SECRET`

Without the webhook registered, M2 member-portal payments charge successfully but the AR invoice never reconciles. Member sees "paid" on Paystack but "outstanding" in the portal.

### 7. First admin password reset

Seeded fixed accounts (`ADMIN-001`, `CEO-001`, `HR-001`, etc.) start with `password='password'` and `password_must_change=true`. Either:

```bash
# Reset to a strong password via tinker (one-time, before first login)
php artisan tinker
>>> $u = \App\Models\User::where('staff_id','ADMIN-001')->first();
>>> $u->update(['password' => bcrypt('<strong-password>'), 'password_must_change' => false]);
```

Or: log in as ADMIN-001 with password `password`, change it via the forced-change wall on first login. Note that the global 2FA gate (H5 / PR #60) means the first login goes password change → 2FA enrolment → dashboard. Plan ~2 minutes for the first-admin gauntlet.

---

## 🟡 Important

### 8. Hubtel webhook URLs registered (SMS delivery + USSD)

On the **Hubtel dashboard**:

- Delivery receipts callback → `https://<your-domain>/webhooks/sms`
- USSD callback (if using M3 USSD member fees) → `https://<your-domain>/webhooks/ussd`
- Use the same secrets as `HUBTEL_WEBHOOK_SECRET` and `USSD_WEBHOOK_SECRET` in `.env`

Without delivery receipts, SMS rows stay at `Sent` and never advance to `Delivered`. Without the USSD callback, the `*920*HR#` menu doesn't work.

Also set `USSD_SHORTCODE` in `.env` to the short code your network operator assigned (e.g. `*920*HR#`).

### 9. Sentry error reporting

```bash
composer require sentry/sentry-laravel
# In .env:
SENTRY_LARAVEL_DSN=https://...@sentry.io/...
SENTRY_TRACES_SAMPLE_RATE=0.1
LOG_CHANNEL=stack
LOG_STACK=single,sentry
```

Without it, production exceptions only land in `storage/logs/*` — fine for a single-host pilot, painful at scale.

### 10. Backups configured

```bash
composer require spatie/laravel-backup
php artisan vendor:publish --provider="Spatie\Backup\BackupServiceProvider"

# In .env:
BACKUP_FILESYSTEM_DISK=s3
BACKUP_ARCHIVE_PASSWORD=<long random string>
BACKUP_NOTIFY_EMAIL=ops@<your-domain>
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=...
AWS_BUCKET=cihrms-backups
```

Schedule in `routes/console.php`:

```php
Schedule::command('backup:clean')->daily()->at('01:00');
Schedule::command('backup:run')->daily()->at('02:00');
Schedule::command('backup:monitor')->daily()->at('03:00');
```

### 11. GitHub branch protection on `main`

In the GitHub repo settings → Branches → Add rule for `main`:

- Require a pull request before merging
- Require status checks to pass before merging:
  - `Tests (postgres)`
  - `Tests (sqlite)`
  - `Build` (npm run build job)
- Require linear history
- Include administrators

This catches issues like PR #51 which merged with a failing build because no protection was in place.

### 12. 2FA onboarding for non-demo users

The global 2FA gate (`2fa:required`) means every new privileged user (super_admin / CEO / hr_admin / finance_officer / manager / auditor) must enrol on first login. Demo seeders pre-confirm 2FA via PR #62, but real production users don't get that shortcut.

Document this in the HR onboarding runbook:

> When you create a new admin via /admin/users, hand them their temp password AND warn them they'll need an authenticator app (Google Authenticator, 1Password, Authy, etc.) on the first login. The flow is: temp password → forced change → 2FA enrolment → dashboard.

### 13. HTTPS termination + trusted proxies

If running behind nginx / Cloudflare / AWS ALB:

```bash
SESSION_SECURE_COOKIE=true
APP_TRUSTED_PROXIES=*
```

Without `APP_TRUSTED_PROXIES`, signed URLs (used for password resets, AR statements, signed receipts) generate with `http://` schemas even when the user is on `https://`, and Inertia's CSRF checks may reject XHR requests routed through the LB.

### 14. Identity verification provider (Ghana Card)

Default is `IDENTITY_PROVIDER=mock` (verifications always succeed). For real verification:

```bash
IDENTITY_PROVIDER=nia
NIA_BASE_URL=https://api.nia.gov.gh
NIA_API_KEY=<from NIA contract>
NIA_TIMEOUT=30
IDENTITY_VALIDITY_MONTHS=12
```

Falls back to manual HR review if creds are missing.

---

## 🟢 Optional / Hardening

### 15. Statutory payroll integrations (GHIPSS / IPPD / GIFMIS)

If running on Ghana government infrastructure, configure these to automate disbursement file generation + statutory returns + GL posting on payroll approval. See the per-block envs in `.env.example` under "Statutory payroll".

If skipped, payroll runs calculate correctly but the disbursement file must be exported manually.

### 16. Messaging delivery channels (Slack / Teams / WhatsApp)

`MessagingDispatcher` routes leave requests / incident notifications to Slack / Teams / WhatsApp **in addition to** the standard DB + mail. Users get DB + mail without these creds; the extra channels are bonuses.

See `.env.example` under "Integrations" for the env-var groups. Each driver also has a feature flag (`FLAG_SLACK_LEAVE`, `FLAG_TEAMS_TICKETS`, `FLAG_WA_PAYSLIP`) so you can opt in piecemeal.

### 17. eSign providers (Zoho Sign / DocuSign)

For external-signature flows on the Documents module. Default `INT_ESIGN_DRIVER=mock` always returns "signed" without round-tripping. Configure `ZOHO_SIGN_*` or `DOCUSIGN_*` and switch the driver to enable real signing.

Webhooks: `/webhooks/esign` with the matching `ZOHO_SIGN_WEBHOOK_SECRET` or `DOCUSIGN_CONNECT_KEY`.

### 18. Mobile-money providers (MTN / Vodafone / AirtelTigo)

Alternative to Paystack for direct MoMo charge. Configure per-provider envs under "Mobile-money providers" in `.env.example`. Each has its own `MOMO_*_ENABLED=true|false` flag.

### 19. AI agent runtime

For AI assistant features:

```bash
AI_ENABLED=true
AI_DRIVER=anthropic
ANTHROPIC_API_KEY=sk-ant-...
ANTHROPIC_MODEL=claude-sonnet-4-6
```

The rest of the app works without it.

### 20. Dependabot vulnerabilities

GitHub flagged 5 vulnerabilities (1 high, 4 moderate) on `main` as of the last check. Worth a sweep before launch:

```bash
# https://github.com/josephnadi/CIHRMS-MVP/security/dependabot
gh api repos/josephnadi/CIHRMS-MVP/dependabot/alerts --paginate | jq '.[] | {id, severity, package: .security_advisory.cve_id, summary: .security_advisory.summary}'
```

Most can be auto-resolved by accepting Dependabot's PR; review each before merging.

---

## Smoke test after launch

After every 🔴 item is checked off, run this end-to-end smoke from any seeded admin account:

```bash
# 1. SMS plumbing
# Visit /admin/messaging, "Send test" to your own phone, watch the row.
# Within ~10s: Queued → Sent. Within ~1m: Sent → Delivered.

# 2. Mail
# Trigger a leave request from your account; line manager (you, in the test)
# should receive a mail within ~30s. Check inbox.

# 3. Notifications fan-out (N2)
# As super_admin, approve a small loan via /loans → applicant + their
# manager both see in-app notifications immediately (bell badge increments).

# 4. Member fees (M1/M2/M3)
# Register a member in /admin/members, create a fee, run a billing batch,
# log in as the member at /portal/login, click Pay, complete the Paystack
# test payment. Member receives payment-confirmation mail + SMS. AR
# invoice flips to Paid.

# 5. Scheduler
# Wait 5 minutes. Check /admin/messaging for any rows that should have
# been swept. Tail storage/logs/laravel.log for "messaging:sweep-stuck-sms"
# log line.

# 6. Audit chain
php artisan audit:verify-chain
# Should print "Chain verified: <N> entries, no breaks."
```

If all six pass, you're real-time functional.

---

## Maintenance windows

Once live:

- **Weekly:** check Sentry for new error patterns
- **Daily:** verify the supervisor processes are still running (`supervisorctl status`)
- **Monthly:** run `php artisan audit:verify-chain` (already auto-scheduled at 03:00 daily, but worth a manual sanity check)
- **Per payroll run:** watch queue depth during PayrollRunPaid fan-out. If it grows faster than it drains, bump `numprocs` in `cihrms-queue-default.conf`
