# Chapter 43 — Deployment & Operations

> CIHRMS deploys as a standard Laravel monolith — one PHP app, one database, one storage tree, one cron, one supervisor tree. The build is `composer install` plus `npm install && npm run build`. The boot is `php artisan migrate --force`. The runtime is PHP-FPM behind Nginx with a `supervisord` unit per named queue and a single-line cron driving `schedule:run`. There is no container image checked in, no Kubernetes manifest, no Terraform; the production target — a NITA-hosted box per institute — is provisioned by hand against the runbook in `docs/deployment_production.md`. That document exists; it is a hardening note for the Phase 1 government-readiness work rather than a beginning-to-end runbook, and that gap is on the record. This chapter walks the deploy as it actually happens today, names what is automated (CI build + test) versus what is manual (every production-side action below `git pull`), and lays out the Phase 1 / Phase 4 infrastructure roadmap referenced from Ch 41.

---

## 43.1  What "deploy" means in this codebase, today

There is one production deployment pattern and it has six moving parts. Everything else — the `.env` template, the cron entry, the `supervisord` units, the storage symlink — is in service of these six:

1. **`git pull`** the tagged release into `/var/www/cihrms` (or wherever the institute's operator put it).
2. **`composer install --no-dev --optimize-autoloader --no-interaction`** to refresh PHP dependencies against `composer.lock`.
3. **`npm ci && npm run build`** to compile the Vite bundle into `public/build/`.
4. **`php artisan migrate --force`** to apply any pending migrations against the production DB.
5. **`php artisan config:cache && php artisan route:cache && php artisan view:cache`** to warm the framework caches that Laravel reads from on every request.
6. **`supervisorctl restart cihrms:*`** to recycle the worker pool so the new code loads.

There is no `deploy.sh` in the repo. The institute's operator runs those six steps in order, or wraps them in a Forge "deploy script" if they bought Forge, or uses a vendor's CI/CD if they have one. The codebase is intentionally agnostic about which of those paths is taken — the artifacts it ships (composer.json, package.json, the migrations, the `routes/console.php` schedule, the queue declarations on the listeners) are the contract; the orchestration around them is the operator's choice.

The brief asked the chapter to be honest about what is automated and what is manual. The honest version is:

- **Automated by CI** (GitHub Actions, `.github/workflows/ci.yml`): on every push to `main` and every PR, the workflow runs `composer install`, `npm ci`, `php artisan key:generate`, `php artisan migrate --seed --force`, `npm run build`, `php artisan a11y:audit`, and `vendor/bin/pest`. It runs that twice in a matrix — once against SQLite and once against PostgreSQL 16 in a service container. A green build proves the codebase composes, migrates cleanly on both engines, builds the frontend without errors, passes the static accessibility scan, and passes all 973 Pest tests.
- **Not automated** (everything past the green build): no CD step picks up the artifact and pushes it to a server. No image is built and pushed to a registry. No Docker layer caches. No SSH script. No `php artisan down` / `php artisan up` envelope around the migration. Every action between "PR merged to main" and "the new code is serving requests in production" is something the operator does by hand against the box.

That is normal for an MVP at this scale. It is also a Phase 1 punch-list item — a `.github/workflows/deploy.yml` that, gated on tag push, does `appleboy/ssh-action` against the production box and runs the six steps above is bounded work. The reason it has not shipped is that the buyers on the current roadmap do not yet share a production-deployment shape — one wants Forge, one wants NITA via vendor-managed Ansible, one wants raw `git pull`. Building an opinionated CD that fits none of them is worse than building none and writing the steps down.

---

## 43.2  The production stack — what runs the request

```
┌──────────────────────────────────────────────────────────────────────┐
│  Nginx (TLS termination, static asset serving, FPM upstream)         │
│  /var/www/cihrms/public                                              │
└────────────────┬─────────────────────────────────────────────────────┘
                 │ fastcgi_pass unix:/run/php/php8.3-fpm.sock
                 ▼
┌──────────────────────────────────────────────────────────────────────┐
│  PHP-FPM 8.3 (pm.dynamic, pm.max_children=20 baseline)               │
│  Loads Laravel 13 / app.php; serves Inertia + /api/v1/*              │
└────────────────┬─────────────────────────────────────────────────────┘
                 │
                 ├──► PostgreSQL 16 (single instance, port 5432, TLS)
                 │       — reads/writes for app + sessions + cache +
                 │         jobs + failed_jobs tables
                 │
                 ├──► storage/app/private  (local disk, default)
                 ├──► storage/app/public   (symlinked to public/storage)
                 └──► storage/app/incidents (private incident attachments)

┌──────────────────────────────────────────────────────────────────────┐
│  supervisord — one [program:queue-*] per named queue                 │
│   queue-audit (numprocs=2), queue-identity (1), queue-integrations   │
│   (2), queue-notifications (2), queue-analytics (1), queue-payroll   │
│   (1), queue-default (1)                                             │
└──────────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────────┐
│  cron (system crontab)                                               │
│   * * * * *  cd /var/www/cihrms && php artisan schedule:run \        │
│                >> /dev/null 2>&1                                     │
└──────────────────────────────────────────────────────────────────────┘
```

Three things in that diagram are worth pulling out.

**Single Postgres instance.** No replica, no PgBouncer, no read pool. The same database carries the application rows (`users`, `employees`, `payroll_runs`, etc.), the `sessions` table (because `SESSION_DRIVER=database`), the `cache` table (`CACHE_STORE=database`), and the `jobs` + `failed_jobs` tables (`QUEUE_CONNECTION=database`). That is the entire data tier. For an institute of a few thousand employees this is comfortable; the day it stops being comfortable is the Phase 1 migration to Redis for cache + queue, leaving Postgres to hold only the rows. The case for that move is latency, not throughput, and is treated in Ch 41 §41.9.

**`supervisord`, not Horizon.** Horizon would be the natural answer ("one supervisor process to manage all of them, plus a dashboard"). Horizon needs Redis. Until Redis lands in Phase 1 the answer is the older one: one `supervisorctl` program per queue, each running `php artisan queue:work --queue=<name>`, with `numprocs` tuned per queue. The full set of unit stanzas is in Ch 41 §41.2.4 and reproduced in `docs/deployment_production.md` so the institute's operator does not have to cross-reference. Once Redis + Horizon land, this collapses to a single `[program:horizon]` and the per-queue tuning moves into `config/horizon.php`.

**One cron line.** The Laravel scheduler's contract is "run this once a minute and we will dispatch whatever is due". Nothing else lives in the operator's crontab. The schedule itself — what runs daily 02:00, what runs daily 23:55, the audit chain verifier at 03:00, the integration token refresher every thirty minutes — lives in `routes/console.php` and is part of the codebase, not the operator's responsibility to maintain. The full schedule is in Ch 41 §41.8.

---

## 43.3  The build path

Both build artefacts are produced from the working tree. There is no separate `cihrms-frontend` repo, no separate `cihrms-api` repo, no detached build pipeline.

### 43.3.1  Composer side — PHP

`composer.json` declares `^8.3` for PHP and pins Laravel `^13.7`. The required PHP extensions, taken from CI:

```
bcmath, mbstring, pdo, pdo_sqlite, sqlite3, pdo_pgsql, pgsql, gd, intl
```

`pdo_sqlite` + `sqlite3` are needed even on a production box that runs Postgres, because the test runner inside `composer dev` (and any `php artisan test` invocation an operator runs to verify a deploy) uses `:memory:` SQLite. `pdo_pgsql` + `pgsql` are the production drivers. `bcmath` is used by the payroll calculator for fixed-precision arithmetic on money columns; `mbstring` is the Laravel default; `intl` is needed for the i18n loader; `gd` is needed for the PDF letterhead overlays and the avatar resizer.

A production install runs:

```bash
composer install --no-dev --optimize-autoloader --no-interaction
```

`--no-dev` strips Pest, Mockery, Faker, Pint, Pail, Breeze, and Pao from `vendor/`. `--optimize-autoloader` rebuilds the classmap so resolution is O(1) instead of O(filesystem) — measurable on a cold-start fpm worker. The `post-autoload-dump` script in `composer.json` then runs `php artisan package:discover --ansi` automatically, which bakes the package manifest.

The `composer dev` script (`composer.json` line 54) is for local development only — it boots `php artisan serve`, `queue:listen`, `pail`, and `vite` concurrently under `npx concurrently`. It is *not* a production entry point. The production entry point is FPM + Nginx, not `artisan serve`.

### 43.3.2  Node side — Vite

`package.json` is small by design — `@inertiajs/vue3`, `vue`, `vite`, `laravel-vite-plugin`, `tailwindcss`, and two runtime dependencies (`pdfjs-dist` for the document annotator, `signature_pad` for e-signature capture). Everything else is hand-rolled Vue against the design tokens described in Ch 33.

The production build is:

```bash
npm ci          # install against package-lock.json, faster + reproducible than npm install
npm run build   # vite build — writes hashed bundles into public/build/
```

`vite build` emits content-hashed filenames (`app-abc123.js`, `app-def456.css`) and a `manifest.json` that Laravel's Vite helper reads to inject the right `<script>` and `<link>` tags into `app.blade.php`. Because filenames are hashed, the Nginx config can serve `public/build/*` with `Cache-Control: public, max-age=31536000, immutable` — already in the deploy template referenced from Ch 41 §41.9.

The Vue chunk and the CSS chunk together are the entire frontend. There is no separate vendor bundle, no separate route-split chunks for Inertia pages (Inertia v2 handles that with its own `lazy` import semantics inside `app.js`). The build is bounded by Vite's tree-shake; under one minute on a typical CI runner.

### 43.3.3  Storage layout and the symlink

The default filesystem disk is `local` (`config/filesystems.php` line 16, env `FILESYSTEM_DISK=local`). The two disks that matter at deploy time:

- **`local`** → `storage/app/private`. Default destination for any `Storage::disk('local')` call. Holds the documents library, the payroll source CSVs, the AG-export staging area, anything not meant to be publicly addressable.
- **`public`** → `storage/app/public`. Symlinked to `public/storage` by `php artisan storage:link`. Holds avatars, public letterhead assets, anything the browser is allowed to GET by URL.

There is a third disk, `incidents`, that points at `storage/app/incidents` with `'visibility' => 'private'` and `'throw' => true` — incident attachments are kept off the default disk because they may carry PII that needs distinct retention handling. The disk exists so a future operator can swap it for an S3 bucket with object-lock without touching the writer code (see `IncidentReportService::attachEvidence()`).

The `storage:link` step is mandatory on first deploy and idempotent on subsequent deploys:

```bash
php artisan storage:link
```

Without it, anything written to `storage/app/public` is invisible to the web. With it, `public/storage` resolves to that directory and Nginx serves the files directly.

S3 is wired in `config/filesystems.php` (lines 50–61) and reads `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_URL`, `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT` from `.env`. The `AWS_USE_PATH_STYLE_ENDPOINT=true` flag specifically targets S3-compatible vendors that are not AWS itself — DigitalOcean Spaces, Backblaze B2, MinIO, the on-prem object store an NITA-hosted institute is likely to be issued. Nothing in the application code references S3 directly — every writer goes through `Storage::disk(...)` and reads its disk name from config. Switching to S3 in production is a Phase 4 task (see §43.10) and amounts to flipping `FILESYSTEM_DISK=s3` plus moving the `public` and `incidents` disks behind their own bucket names.

---

## 43.4  Environment variables — what matters

`.env.example` is the canonical list and the file that any new deploy should be cloned from. The variables fall into ten groups; the ones that *must* be set for a working production instance are flagged.

### 43.4.1  Application core (must-set)

```
APP_NAME=cihrms                  # display name; surfaces in mail subjects + the SPA header
APP_ENV=production               # gates Model::preventLazyLoading (off in production)
APP_KEY=                         # MUST be generated by `php artisan key:generate`
APP_DEBUG=false                  # MUST be false in production
APP_URL=https://hrms.<institute>.gov.gh
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
```

`APP_KEY` is loadbearing beyond the usual "encrypts cookies" role: it encrypts the stored TOTP secrets on `users.two_factor_secret` and the Ghana Card numbers on `identity_verifications.ghana_card_number_encrypted` via `Crypt::encryptString`. Rotating it without rotating the encrypted columns first will lock every 2FA user out and render every stored Ghana Card unreadable. The pre-launch checklist in `docs/deployment_production.md §7` covers this; it is the single most expensive operational mistake an operator can make.

### 43.4.2  Database (must-set in production)

```
DB_CONNECTION=pgsql              # SQLite is local-only; production MUST be Postgres
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=cihrms
DB_USERNAME=cihrms
DB_PASSWORD=<rotated>
# DB_SSLMODE=require             # NITA-hosted DBs mandate TLS — uncomment when applicable
```

The `.env.example` ships with `DB_CONNECTION=sqlite` so a developer can clone, run `composer setup`, and have a working dev environment with zero database provisioning. The same file in production *must* be edited to point at Postgres before `php artisan migrate --force` runs. The migration runbook is in `docs/ops/postgres-migration.md` (referenced from the inline comment in `.env.example`); the relevant note is that every migration in the repo has been verified against both engines because CI runs against both (see §43.6).

### 43.4.3  Sessions + cookies (must-set in production)

```
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_SECURE_COOKIE=true       # MUST be true in production; requires HTTPS
APP_TRUSTED_PROXIES=*            # if behind a load balancer; "*" trusts all forwarded headers
```

The `SESSION_DRIVER=database` choice means session rows live in the `sessions` table — same Postgres instance, no separate session store. That table is small (one row per active user, garbage-collected automatically) and fits the single-tier-Postgres posture comfortably. When Redis lands in Phase 1, `SESSION_DRIVER=redis` is the obvious follow-up; it is not strictly required.

### 43.4.4  Broadcast, queue, cache, mail

```
BROADCAST_CONNECTION=log         # no real-time channel — log driver is the default
QUEUE_CONNECTION=database        # production today; redis when Phase 1 lands
CACHE_STORE=database
MAIL_MAILER=smtp                 # production; .env.example ships with `log` for dev
MAIL_HOST=<institute SMTP>
MAIL_PORT=587
MAIL_USERNAME=<rotated>
MAIL_PASSWORD=<rotated>
MAIL_FROM_ADDRESS=hrms@<institute>.gov.gh
MAIL_FROM_NAME="${APP_NAME}"
```

`BROADCAST_CONNECTION=log` is not a stub waiting to be filled in — it is the deliberate, current state. There is no WebSocket layer, no Pusher account, no Reverb process. The chat module, the notifications panel, and the announcement ticker all use polling (Ch 36 §36.10). The `log` broadcast driver writes broadcast events to the standard Laravel log channel and is the cheapest no-op available; a future Phase 4 reopening of real-time would replace it.

### 43.4.5  Identity provider (NIA / KYC / manual)

From `config/identity.php`, populated by these env keys:

```
IDENTITY_PROVIDER=manual_upload  # default until NIA institutional MoU completes
# IDENTITY_PROVIDER=nia_official
# NIA_BASE_URL=https://api.nia.gov.gh
# NIA_API_KEY=<rotated>
# NIA_TIMEOUT=8
# IDENTITY_PROVIDER=third_party_kyc
# KYC_BASE_URL=https://api.uqudo.com
# KYC_API_KEY=<rotated>
# KYC_VENDOR=uqudo
IDENTITY_VALIDITY_MONTHS=12
```

The driver swap is config-time, not code-time — `IdentityVerificationService` resolves the active provider through the `identity.driver` key and never sees the underlying vendor name. An institute that signs the NIA MoU partway through their tenure flips the env key, restarts FPM and the `identity` queue worker, and the next `VerifyEmployeeIdentity` job uses the new provider. The manual-upload fallback path keeps working in parallel for backfill of pre-MoU records.

### 43.4.6  SMS and USSD

From `config/messaging.php`:

```
SMS_DRIVER=log                   # log | hubtel | twilio
SMS_LOG_CHANNEL=stack
# Hubtel
HUBTEL_CLIENT_ID=<rotated>
HUBTEL_CLIENT_SECRET=<rotated>
HUBTEL_SENDER_ID=CIHRMS
HUBTEL_BASE_URL=https://smsc.hubtel.com
HUBTEL_WEBHOOK_SECRET=<rotated>
# Twilio
TWILIO_ACCOUNT_SID=<rotated>
TWILIO_AUTH_TOKEN=<rotated>
TWILIO_FROM_NUMBER=+233...
# USSD
USSD_SHORTCODE=*920*HR#
USSD_WEBHOOK_SECRET=<rotated>
```

`SMS_DRIVER=log` is the documented default and a deliberate one — until the institute has signed a contract with a Ghanaian SMS aggregator (Hubtel is the common choice), SMS notifications no-op into the log channel and the rest of the notification fabric (in-app, email) carries the load. The first text actually sent is configuration, not code.

### 43.4.7  E-signature, file storage, calendar (integrations)

From `config/integrations.php`, the capability-to-driver routing keys:

```
INT_CRM_DRIVER=zoho_crm
INT_FILES_DRIVER=ms_graph        # or `google`
INT_SHEETS_DRIVER=ms_graph
INT_MSG_DRIVER=whatsapp_cloud
INT_CAL_DRIVER=ms_graph
INT_ESIGN_DRIVER=zoho_sign       # or `docusign`
```

Plus the per-driver OAuth secrets — Zoho CRM, Zoho Sign, Microsoft Graph, Google Workspace, WhatsApp Cloud API, Slack, DocuSign. Each driver registry entry in `config/integrations.php` declares which env keys it reads; the full list is too long to inline here. The pattern is uniform: an institute that wants WhatsApp leave-approval notifications sets `FLAG_WA_PAYSLIP=true` plus the Meta OAuth quartet (`WHATSAPP_PHONE_ID`, `WHATSAPP_WABA_ID`, `WHATSAPP_TOKEN`, `WHATSAPP_VERIFY_TOKEN`, `WHATSAPP_APP_SECRET`) and the integration becomes available. Until that flip, the capability silently no-ops via the `IntegrationManager` fallback path described in Ch 28.

### 43.4.8  Payments — Paystack and Mobile Money

From `config/disbursement.php`:

```
# Bank rail (default-on)
GHIPSS_ENABLED=true
GHIPSS_SPONSOR_SORT_CODE=<bank-issued>
GHIPSS_ORIGINATOR_NAME="CIHRM GHANA"
GHIPSS_OUTPUT_DISK=local         # production: s3 with object-lock

# Mobile money — opt-in per provider
MOMO_MTN_ENABLED=false
MOMO_MTN_BASE_URL=https://sandbox.momodeveloper.mtn.com
MOMO_MTN_SUBSCRIPTION_KEY=<rotated>
MOMO_MTN_API_USER=<rotated>
MOMO_MTN_API_KEY=<rotated>
MOMO_MTN_ENVIRONMENT=sandbox

MOMO_VF_ENABLED=false
MOMO_AT_ENABLED=false
```

And for receipts (Paystack hosted-checkout, F4):

```
PAYSTACK_URL=https://api.paystack.co
PAYSTACK_PUBLIC_KEY=<rotated>
PAYSTACK_SECRET_KEY=<rotated>
PAYSTACK_WEBHOOK_SECRET=<rotated>
PAYSTACK_RECEIPT_BANK_PURPOSE=receipts
PAYSTACK_CALLBACK_DEFAULT_URL=https://hrms.<institute>.gov.gh/payments/return
```

The MoMo block defaults all three providers to `enabled=false` — production is bank-rail by default; mobile money is opt-in per institute and per provider. The Paystack block is required only if the institute uses the receipts module; otherwise the public/secret keys can stay blank and the `Payments\PaystackGateway` will refuse to bind, with the UI showing the gateway as unavailable.

### 43.4.9  Observability (currently mostly placeholders)

```
SENTRY_LARAVEL_DSN=
SENTRY_TRACES_SAMPLE_RATE=0.0
LOG_CHANNEL=stack
LOG_LEVEL=warning                # production; .env.example ships with `debug` for dev
LOG_DEPRECATIONS_CHANNEL=null
```

`SENTRY_LARAVEL_DSN` is wired through a `sentry` log channel that the production logging config can stack on top of `stack`. The wiring is in `config/logging.php` (the `sentry` channel definition) but `sentry/sentry-laravel` is *not in `composer.json`* — installing it is the gating action, the env key is the second. Until that install, the variable is inert. This is consistent with the rest of the "observability is mostly placeholders" story below.

### 43.4.10  Backup destination (when the package is installed)

```
BACKUP_ARCHIVE_PASSWORD=<rotated>
BACKUP_FILESYSTEM_DISK=local     # or `s3` once cloud storage is set up
BACKUP_NOTIFY_EMAIL=ops@<institute>.gov.gh
```

These are read by `config/backup.php`, which is a skeleton waiting for `spatie/laravel-backup` to be installed. Until it is, the env keys are placeholders. See §43.7.

---

## 43.5  Queue workers — the supervisor tree

Ch 41 §41.2.4 has the full `supervisord` config. The summary here is operational.

There are six `supervisord` programs in production today — one per named queue, plus the implicit `default`. The audit queue no longer has a worker: `AuditTrail` middleware uses `dispatchAfterResponse()` so the hash-chained write runs in the FPM process itself, after the response is flushed.

| Program | Queue | numprocs | Notes |
|---|---|---|---|
| `queue-identity` | `identity` | 1 | NIA-rate-limited externally; one worker is the natural throttle. |
| `queue-integrations` | `integrations` | 2 | Zoho/Twilio/SignWell/S3 calls; needs its own retry envelope and a slow provider should not block the user. |
| `queue-notifications` | `notifications` | 2 | User-facing; latency matters. Isolated from `analytics` to avoid head-of-line blocking. |
| `queue-analytics` | `analytics` | 1 | Aggregation, allowed to lag. |
| `queue-payroll` | `payroll` | 1 | Heavy + transactional; deliberately single-threaded so two workers do not pick sibling listeners for the same run. `--max-time=7200` instead of the standard 3600. |
| `queue-default` | `default` | 1 | Catch-all. Holds `ProcessPaystackWebhook` and the five `app/Listeners/Incident/*` synchronous listeners until Phase 1 cleanup routes them properly (Ch 41 §41.2). |

The unit template is uniform — `command=php artisan queue:work --queue=<name> --tries=3 --max-time=3600 --sleep=1`, `autostart=true`, `autorestart=true`, `stopwaitsecs=10`, `stdout_logfile=/var/log/cihrms/queue-<name>.log`. The full template ships in `docs/deployment_production.md §2` as a copy-paste.

Operator routines:

- **Restart all workers after a deploy.** `supervisorctl restart cihrms:*` (assuming the programs are grouped under the `cihrms` namespace via `[group:cihrms]`). Queue workers cache the compiled application — they must be recycled after `composer install` or any code change, or they will keep serving the old code on new jobs.
- **Pause a queue without losing rows.** `supervisorctl stop cihrms:queue-payroll`. Jobs accumulate in the `jobs` table; nothing is lost. Restart picks them up in order.
- **Drain before maintenance.** `php artisan queue:work --once --stop-when-empty` against the queue you want to drain, before stopping its worker. Combined with `php artisan down --secret=<token>` to lock the HTTP surface, this is the maintenance-window pattern.
- **Recover failed jobs.** `php artisan queue:failed` lists, `php artisan queue:retry {uuid}` retries one, `php artisan queue:retry all --queue=<name>` retries a queue, `php artisan queue:flush` empties the failed_jobs table (rarely used — failures are forensic evidence and we keep them).

Until Horizon lands, there is no real-time view of "how full is queue-identity right now". The closest thing is `php artisan tinker` plus `DB::table('jobs')->where('queue', '<name>')->count()`. That is acceptable at MVP scale; it is also the strongest argument for shipping Horizon as soon as Redis lands (see §43.10).

---

## 43.6  Continuous integration — what the green build actually proves

`.github/workflows/ci.yml` runs on every push to `main` and every PR. The matrix is two-deep: `db: [sqlite, pgsql]`. Both legs run identically except for the `DB_CONNECTION` env override and the Postgres service container that runs only on the pgsql leg.

The steps, in order:

1. **Checkout** (`actions/checkout@v4`).
2. **Setup PHP 8.4** with `shivammathur/setup-php@v2` — extensions `bcmath, mbstring, pdo, pdo_sqlite, sqlite3, pdo_pgsql, pgsql, gd, intl`, composer:v2.
3. **Setup Node 22** with npm caching.
4. **Cache composer dependencies** against `composer.lock`.
5. **`composer install --prefer-dist --no-interaction --no-progress`**.
6. **`npm ci`**.
7. **Prepare env**: copy `.env.example` to `.env`, `php artisan key:generate`, touch the SQLite file on the sqlite leg.
8. **`php artisan migrate --seed --force`** — every migration applied, every seeder run.
9. **`npm run build`** — Vite build must pass.
10. **`php artisan a11y:audit`** — static WCAG 2.1 AA scan, fails on any `error` severity finding. Static-only; an axe-core browser audit is a planned addition (see Ch 33 + the comment on `ci.yml` line 89).
11. **`vendor/bin/pest --colors=always`** — all 192 test files, 973 tests, 3,405 assertions.

The matrix matters. Running migrations against SQLite alone would let a Postgres-specific syntax error (an enum column, a JSONB-only operator, a `RETURNING` clause that SQLite ignores) slip through. Running migrations against Postgres alone would let a Postgres-specific *assumption* — that `DATE(created_at)` works, that booleans are represented the same way — bake itself in and break dev. The dual-leg matrix is what makes the "every migration verified against both engines" claim in §43.4 true.

PHP 8.4 in CI versus PHP 8.3 as the declared minimum in `composer.json` is intentional. The runtime contract is "we support 8.3 and above"; CI exercises the latest stable. Anything that breaks on 8.4 ships as a CI failure long before a production box ever runs it.

What CI does *not* do, called out for the record:

- **No coverage gate.** Pest can emit coverage; we do not. The argument against (see Ch 42 §42.10) is the standard one — a coverage percentage measures bytes, not behaviour.
- **No static analysis gate.** `larastan/larastan` is in `composer.lock` but not wired into CI. Phase 1 punch-list item per Ch 41 §41.7.
- **No deploy step.** The workflow ends at "green build". The artefact (the working tree at that commit) is not pushed anywhere, not tagged for deploy, not registry-uploaded.
- **No browser/E2E.** Dusk and Cypress are both possible; neither is set up. Phase 4 per Ch 42 §42.9.

The green build proves the codebase composes, migrates cleanly, builds cleanly, scans cleanly, and passes 973 tests. That is the strongest pre-deploy gate a contributor can stand behind today.

---

## 43.7  Backups — the honest version

The runbook in `docs/deployment_production.md §6` recommends `spatie/laravel-backup` for DB + storage dumps to S3, with a 30-daily, 12-monthly retention policy. `config/backup.php` is the skeleton waiting for the package; `.env.example` has the `BACKUP_*` envs ready.

**`spatie/laravel-backup` is not installed.** `composer.json` does not list it, `composer.lock` does not contain it, no `BackupServiceProvider` is registered. The config file is a placeholder so deploy scripts do not choke on a missing path; nothing else.

What that means today, said plainly:

- The application ships no automated backup mechanism.
- An institute deploying CIHRMS in production today gets backups by whatever the underlying VM provides — Postgres `pg_dump` on a system cron, or filesystem snapshots from the hypervisor, or nothing if neither is configured.
- The DB-side recommendation (in lieu of `spatie/laravel-backup`, until it ships) is the standard one:

```bash
0 3 * * * pg_dump -U cihrms -d cihrms -Fc \
    -f /var/backups/cihrms/cihrms-$(date +\%Y\%m\%d).dump \
  && find /var/backups/cihrms -name 'cihrms-*.dump' -mtime +30 -delete
```

- The storage-side recommendation is `rsync` of `storage/app/private`, `storage/app/public`, and `storage/app/incidents` to the same off-box destination — these directories hold the documents library, the payroll source CSVs, the AG export staging area, the incident attachments. They are not in the DB; a Postgres dump alone does not capture them.
- Neither of those crons is in the repo. They are operator-side.

Installing `spatie/laravel-backup` is a half-day of work — `composer require spatie/laravel-backup`, publish the config, add a `Schedule::command('backup:run')` line to `routes/console.php`, wire the S3 disk. The reason it has not happened is that the on-prem box at NITA does not have S3 access out of the box, and shipping a backup config that points at "local disk on the same box" is worse than no config — it gives a false sense of safety. The first institute with object storage available is the one that justifies finishing this. It is on the Phase 4 list.

---

## 43.8  Monitoring — the honest version

There is no application-side monitoring shipped with CIHRMS today.

- **Error tracking.** `SENTRY_LARAVEL_DSN` is in `.env.example`, the `sentry` log channel is wired into `config/logging.php`, but `sentry/sentry-laravel` is not in `composer.json`. Until it is, exceptions land in `storage/logs/laravel.log` and stay there. The fix is `composer require sentry/sentry-laravel` + the env DSN + a sample rate; bounded work, Phase 4.
- **Performance / traces.** Nothing. No APM, no Pulse, no Telescope, no Nightwatch. The `tests/Pest.php` `phpunit.xml` explicitly forces `PULSE_ENABLED=false`, `TELESCOPE_ENABLED=false`, `NIGHTWATCH_ENABLED=false` to keep tests fast — those flags exist in case the packages are installed someday, not because they are installed today.
- **Uptime.** Operator-side. UptimeRobot, BetterStack, or whatever the institute's NOC uses, against the `APP_URL` root.
- **Slow query log.** Postgres-side. `log_min_duration_statement = 1000ms` in `postgresql.conf` is the standard starting point; nothing in the application configures it.
- **Audit chain health.** This one is in the codebase. `php artisan audit:verify-chain --notify` runs daily at 03:00 (Ch 41 §41.8) and notifies every `super_admin` on any mismatch. It is the closest thing to a real monitoring signal the application emits.
- **Queue depth.** Operator-side. `php artisan tinker` plus a `DB::table('jobs')->groupBy('queue')->select('queue', DB::raw('count(*)'))->get()` is the manual check. Horizon, when it lands, replaces it.

The defensible version of "we have no monitoring" is "we have logs, we have a daily audit-chain check, and the surface area that needs monitoring is small". The indefensible version is "we will know when the queue backs up because the help desk gets a ticket". Both are true today. The Phase 4 work (Sentry + Pulse + Horizon) is what moves the application from the second to the first.

---

## 43.9  `docs/deployment_production.md` — what it is and is not

The brief asked the chapter to be honest about whether `docs/deployment_production.md` is a real runbook or a stub. It is neither.

It is a **hardening note for the Phase 1 government-readiness work**. The ten sections it covers:

1. PostgreSQL migration — env keys, schema-compatibility verification.
2. Horizon — the named queues, the suggested worker counts, the supervisor units.
3. Identity provider — the three drivers (`manual_upload`, `nia_official`, `third_party_kyc`) and their env keys.
4. Two-factor enforcement — which roles must have `two_factor_required=true`, the `2fa:fresh` middleware.
5. Tamper-evident audit log — the nightly `audit:verify-chain` cron entry.
6. Backups — the `spatie/laravel-backup` recommendation (not yet installed).
7. Sensitive environment variables — `APP_KEY`, `SESSION_SECURE_COOKIE`, `SESSION_SAME_SITE`.
8. Password requirement and pre-launch reset — the `users:issue-password-resets` artisan command and its dry-run + email + exclude flags. This is the single most operationally important section in the document; without it, every account on the legacy `name+staff_id`-only auth flow is locked out the moment the password-required login ships.
9. CSA / DPC registration — the two organisational prerequisites that block production go-live and are nothing to do with code.
10. Attendance kiosk face-scan limitation, and the bank reconciliation MT940 real-fixture ask. Both are operationally consequential — the kiosk's trust profile and the MT940 parser's bank-coverage gap.

What is **not** in `docs/deployment_production.md`:

- A beginning-to-end deploy script. The six steps in §43.1 of *this* chapter are spelled out *here* and only here.
- The Nginx site config. There is a reference template floating in `docs/ops/nginx.conf.example` (not in scope for this chapter), but the document itself does not include it.
- The FPM pool config. `pm.max_children`, `pm.start_servers`, `request_terminate_timeout` are operator-side.
- The PHP `php.ini` overrides for production (`memory_limit`, `upload_max_filesize`, `post_max_size`, `opcache.*`).
- The TLS / certificate management story. NITA will issue what NITA issues; the document does not anticipate the shape.
- A rollback procedure beyond "git checkout the previous tag and re-run steps 2–6". A proper blue/green is out of scope.

The reason the document looks the way it does is that it grew alongside the Phase 1 hardening PRs (PR #34 was the password-requirement work; PR #44–#55 was the v2 audit). Each PR that introduced an operational concern appended its note. The result is genuinely useful for the items it covers and silent on everything else. Folding it into a proper end-to-end runbook is on the Phase 1 docs punch list; it has not happened because the surface area changes faster than the document can be re-written and the per-PR notes have so far been higher-leverage than a single all-up rewrite.

The honest one-line characterisation: `docs/deployment_production.md` is a **field hardening note**, not a runbook. Treating it as the runbook is how an operator deploys against an incomplete picture; this chapter is the runbook.

---

## 43.10  Forward — the deploy / ops roadmap

The same Phase 1 / Phase 4 split that Ch 41 used applies here. Nothing in this list is novel, all of it is bounded, and most of it is one PR.

**Phase 1 (next).** These three move together because they share a deploy-window:

- **PostgreSQL migration.** Already wired in `config/database.php`; switching is a `.env` flip plus a one-time `pg_dump | psql` cutover from any legacy SQLite production data (if any). The migration runbook is `docs/ops/postgres-migration.md`. The CI pgsql leg already proves every migration applies cleanly to a fresh Postgres 16.
- **Redis for cache + queue.** Already wired in `config/cache.php` line 75 and `config/queue.php` line 67. The change is `CACHE_STORE=redis` and `QUEUE_CONNECTION=redis` plus the Redis instance. Migration is a `--stop-when-empty` drain of the database queue followed by the env flip and a `supervisorctl restart cihrms:*`. The wins are atomic cache ops and `BLPOP`-instead-of-polling on the queues (~milliseconds instead of `--sleep=1` seconds).
- **Horizon.** Pre-requisite Redis. `composer require laravel/horizon`, `config/horizon.php` defines the queue → worker-count map, one `supervisord` unit runs `php artisan horizon` (Horizon spawns the workers internally), `/horizon` is gated to `super_admin`. The seven separate `[program:queue-*]` units collapse to one. Real-time visibility, auto-scaling, slow-job alerts.

**Phase 4 (later).** Things that improve the operational picture but do not block an MVP buyer:

- **S3-compatible storage.** `FILESYSTEM_DISK=s3` in `.env`, plus per-disk routing in `config/filesystems.php` for the `public`, `incidents`, and `local` disks individually. Backups and payslip uploads also pick this up. Gating dependency is the institute having an object store; NITA-issued buckets, DigitalOcean Spaces, MinIO on-prem are all candidates.
- **Sentry (or equivalent error tracker).** `composer require sentry/sentry-laravel`, set `SENTRY_LARAVEL_DSN`, stack the `sentry` channel onto `stack` in `config/logging.php`. The `sentry` channel is already defined; the install + DSN are what is missing.
- **`spatie/laravel-backup` installed.** `composer require spatie/laravel-backup`, publish the config (already populated), add the schedule line, point at the S3 disk. Daily 02:00 DB + storage dump, retention as configured.
- **Pulse (or Telescope) on staging.** Slow-query visibility, request timing, queue throughput. Staging-only because Pulse writes to the DB on every request — adopting it in production needs sampling.
- **Sampled `preventLazyLoading()` in production.** 1% of production requests run with strictness on, violations route to the error tracker. Bounded; per-request flag in `AppServiceProvider::boot()`.
- **A `.github/workflows/deploy.yml`.** Tag-gated, SSH-driven, runs the six steps from §43.1 against the production box. Bounded; deferred until two or more institutes share a deploy-target shape.
- **Larastan in CI as a hard gate.** Already in `composer.lock`; needs a `phpstan.neon` baseline and a CI step.
- **Browser / E2E test layer.** Dusk or Cypress, runs against a built production-mode bundle. Phase 4 per Ch 42 §42.9.

The deferred items are all bounded. The reason none of them has shipped is the same as every other infrastructure punch-list item in this dossier: the module surface (recruitment, payroll, finance, governance) has consistently outranked the infrastructure work in review, and the existing posture meets the throughput and latency envelope of the current buyers. The list is not aspirational; it is scheduled.

---

## 43.11  The honest summary

CIHRMS deploys as a Laravel monolith. The build is `composer install && npm ci && npm run build`. The boot is `php artisan migrate --force`. The runtime is FPM + Nginx, one Postgres, seven `supervisord` queue workers, one cron line. The deploy script is six bash commands the operator runs by hand. CI proves the artefact composes, migrates, builds, scans, and tests cleanly across SQLite and Postgres — and stops there.

What is automated:

- Build (composer + npm + vite) — automated by `composer dev` locally and by `.github/workflows/ci.yml` for verification.
- Test (973 Pest tests, 3,405 assertions, accessibility audit) — automated by CI.
- Schedule (the scheduler entries in `routes/console.php`) — automated by the one-line crontab on the production box.
- Queue processing (seven supervisord workers) — automated.
- Audit chain verification (`audit:verify-chain` at 03:00 daily, notifying `super_admin` on mismatch) — automated.

What is manual:

- Provisioning the box, the database, the Postgres user, the storage tree, the symlink.
- Writing the `.env` against the institute's actual credentials.
- Every `composer install`, `npm run build`, `migrate`, `config:cache`, `supervisorctl restart` after each release.
- Backups (until `spatie/laravel-backup` ships).
- Monitoring (until Sentry, Pulse, Horizon ship).
- Rollback (`git checkout` the previous tag, re-run the six steps).

What is on the gap list, named and dated:

- **Phase 1**: Postgres migration, Redis cache + queue, Horizon. Together. One window.
- **Phase 4**: S3 disk, Sentry, `spatie/laravel-backup` installed, Pulse on staging, sampled `preventLazyLoading()` in production, a `deploy.yml` workflow, larastan in CI, E2E browser tests.
- **`docs/deployment_production.md`**: a hardening note, not a runbook. This chapter is the runbook.

The shape is deliberate. An MVP-stage HRMS for a single institute does not need autoscaling, blue/green, multi-region, or service-mesh anything; it needs a working box, a daily backup the operator trusts, a way to roll forward, and a way to roll back. The current posture provides four of those five (the trust on backups is the gap). The Phase 4 list closes that gap and adds the visibility that turns "we will know when the queue backs up" into "we know what the queue is doing right now". None of it is novel; that is the point.

This is the last chapter of Part II. Part III opens with the gap analysis against the IPPD / GIFMIS / Ghana Card benchmark and the explicit Phase 1 / 2 / 3 / 4 roadmap that frames the deferred work named throughout this Part.
