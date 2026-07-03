# CIHRMS Deployment — Hetzner Cloud + Dokploy (dev-first)

**Date:** 2026-07-03
**Status:** Approved design, pre-implementation
**Scope:** Stand up `dev.cihrms.org` on a single Hetzner server running Dokploy, deploying from GitHub with a self-hosted PostgreSQL database. Production (`cihrms.org`) is explicitly deferred to a later project.

---

## 1. Goal & context

Take CIHRMS (`josephnadi/CIHRMS-MVP`, app in `cihrms-mvp/`) from local-only to a live, internet-reachable **dev/staging** environment, proving the entire deploy pipeline before investing in production.

- **App stack:** Laravel 13.7 / PHP 8.3, Vue 3 + Inertia + Vite, Sanctum, Inertia SSR not used.
- **Runtime needs:** web (nginx + php-fpm), a queue worker (`queue:work`, DB queue), a scheduler (`schedule:run` per minute), PostgreSQL.
- **Local vs prod DB:** SQLite is local-only by project convention; server uses PostgreSQL.

### Decisions locked during brainstorming
| # | Decision | Choice |
|---|----------|--------|
| 1 | Topology | **One server, dev only for now.** Prove pipeline on `dev.cihrms.org`; add prod later. |
| 2 | Server | **Hetzner CPX21** — 3 vCPU / 4 GB, Ubuntu 22.04. |
| 3 | Database | **Dokploy managed PostgreSQL** service (own volume + backups UI), self-hosted on same box. |
| 4 | Runtime | **docker-compose, 3 services** (web / worker / scheduler) from one shared image. Cache/session/queue stay on Postgres — **no Redis** yet. |
| 5 | Deploy trigger | Dokploy tracks a dedicated **`develop`** branch; `main` stays clean for future prod. |

---

## 2. Architecture

```
Hetzner CPX21 (3 vCPU / 4GB, Ubuntu 22.04)
└── Dokploy (installs Traefik reverse proxy + Let's Encrypt)
    ├── Postgres 16          ← Dokploy managed database (own volume + scheduled backups)
    └── Project: cihrms-dev  (docker-compose, source = GitHub `develop`)
        ├── web        nginx + php-fpm   ← Traefik routes dev.cihrms.org → :80 (auto-HTTPS)
        ├── worker     php artisan queue:work
        └── scheduler  loop: php artisan schedule:run every 60s
        volumes:
        └── app_storage → /var/www/html/storage/app   (persists uploads across redeploys)
```

- All three app services run the **same image**; only the container command differs.
- The DB is a **separate Dokploy managed service**, reached over the internal Docker network — not part of the app compose file.
- Traefik (bundled with Dokploy) terminates TLS and issues Let's Encrypt certs automatically per attached domain.

### Persistence note (critical)
File uploads — documents, CVs, whistleblower attachments, IPPD/GIFMIS/GhIPSS statutory exports — write to `storage/app` (confirmed in `WhistleblowerSubmissionService`, `Ippd/Gifmis/GhIpssExportCommand`, `OAuthFlow`). Without a persistent volume mapped at `/var/www/html/storage/app`, **every redeploy wipes uploads**. The volume mapping is mandatory, not optional.

---

## 3. Repo artifacts to add

All under `cihrms-mvp/`, created on a feature branch, then merged to a new `develop` branch.

| File | Purpose |
|------|---------|
| `Dockerfile` | Multi-stage build: (1) Node — `npm ci && npm run build` (Vite assets); (2) Composer — `composer install --no-dev --optimize-autoloader`; (3) PHP 8.3-fpm runtime with nginx + the built app. |
| `docker-compose.yml` | Three services (`web`, `worker`, `scheduler`) from the shared image; `app_storage` volume; reads env from Dokploy-injected variables. |
| `docker/nginx.conf` | Serves `public/`, passes PHP to php-fpm on 9000, sets client_max_body_size for uploads. |
| `docker/entrypoint.sh` | On boot: wait for DB, `php artisan migrate --force`, `config:cache`, `route:cache`, `view:cache`, `storage:link`. Worker/scheduler skip migrate. |
| `.dockerignore` | Excludes `vendor/`, `node_modules/`, `.env*`, `database/database.sqlite`, `tests/`, `.git/`. |
| `.env.production.example` | Annotated template for the Dokploy env (see §4). |
| `docs/ops/deploy-dokploy.md` | The operator runbook — the §5 steps with exact commands and values. |

### PHP extensions required in the runtime image
From dependencies: `pdo_pgsql`, `bcmath`, `gd` (dompdf/tcpdf image ops), `zip`, `intl`, `mbstring`, `xml`, `dom`, `fileinfo`, `openssl`, `curl`. (No `redis` ext needed while on DB drivers.)

---

## 4. Environment configuration

Set as **Dokploy secrets/env** (never baked into the image). Derived from `.env.example` with production hardening applied.

```dotenv
APP_NAME="CIHRMS"
APP_ENV=production
APP_KEY=            # generate once: php artisan key:generate --show
APP_DEBUG=false
APP_URL=https://dev.cihrms.org

# Behind Traefik
APP_TRUSTED_PROXIES=*
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true

# Database — Dokploy managed Postgres (internal hostname from the DB service)
DB_CONNECTION=pgsql
DB_HOST=<dokploy-postgres-internal-host>
DB_PORT=5432
DB_DATABASE=cihrms
DB_USERNAME=cihrms
DB_PASSWORD=<generated>

# Drivers stay on database (no Redis yet)
SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database

# Dev-box only: seed demo login accounts (password=password, must-change on first login)
CIHRMS_ALLOW_DEMO_SEEDERS=true

# Mail: log driver acceptable on dev; real provider required before prod
MAIL_MAILER=log
```

**Security caveat on `CIHRMS_ALLOW_DEMO_SEEDERS=true`:** this seeds public-knowledge accounts (`admin@cihrms.local` / `password`, etc.) on an internet-facing box. All are flagged `password_must_change`, and privileged roles have 2FA pre-confirmed. Acceptable for a dev/demo environment; the admin password **must** be changed immediately after first login. This flag will be **absent/false** for real production.

---

## 5. Rollout steps (current state → live)

### Phase 0 — Prereqs
- Hetzner Cloud account + project. Namecheap holds `cihrms.org`. GitHub access confirmed.

### Phase 1 — Provision server
1. Create **CPX21**, Ubuntu 22.04, Hetzner **EU location** (Falkenstein/Nuremberg — good latency to Ghana; US Ashburn is the alternative). Attach SSH key.
2. Harden: create non-root sudo user; `ufw allow 22,80,443`; enable unattended-upgrades.
3. Install Dokploy via its one-line installer (pulls Traefik + panel Postgres).

### Phase 2 — DNS (Namecheap)
4. **A record** `dev` → server IP  →  `dev.cihrms.org`.
5. **A record** `panel` → server IP  →  reach Dokploy at `panel.cihrms.org` over HTTPS (recommended over raw IP:3000).
6. Leave `cihrms.org` root + `www` untouched (prod is a later project).

### Phase 3 — Prepare repo
7. Feature branch: add all §3 artifacts.
8. Verify the image **builds locally** (`docker build`) before pushing.
9. Create **`develop`** branch from the current clean state; this is Dokploy's tracked branch.

### Phase 4 — Wire up Dokploy
10. Set the Dokploy panel domain; connect GitHub (GitHub App or deploy key) to `josephnadi/CIHRMS-MVP`.
11. Create the **managed PostgreSQL** database; copy internal connection details into the env (§4).
12. Create a **Compose** application: source GitHub `develop`, compose path `cihrms-mvp/docker-compose.yml`.
13. Add all **env secrets** (§4) incl. a once-generated `APP_KEY`.
14. Attach domain `dev.cihrms.org` to the `web` service, port 80, enable Let's Encrypt.
15. Add the **persistent volume** for `storage/app`.

### Phase 5 — First deploy & seed
16. Deploy; watch build logs. Entrypoint auto-runs `migrate --force`.
17. One-time seed via Dokploy web-service terminal: `php artisan db:seed --force`.
18. Smoke test: load `https://dev.cihrms.org`; log in as `admin@cihrms.local`; **change password**; click through modules; confirm `worker` and `scheduler` containers are healthy and processing.

### Phase 6 — Operational baseline
19. Enable **scheduled Postgres backups** in Dokploy (volume or S3-compatible bucket).
20. Enable **auto-deploy webhook** for pushes to `develop`.
21. Record prod go-live follow-ups (out of scope here): real mail provider, real SMS/identity/Paystack/GhIPSS creds, `cihrms.org` root domain, a deliberate separate production environment/branch, and dropping `CIHRMS_ALLOW_DEMO_SEEDERS`.

---

## 6. Boundaries & responsibilities

- **Claude implements:** the repo artifacts (Dockerfile, compose, nginx, entrypoint, dockerignore, env template) and the `docs/ops/deploy-dokploy.md` runbook; local build verification.
- **Operator (you) performs:** all Hetzner/Namecheap/Dokploy console actions (Claude cannot reach those). Claude supplies exact commands and values.

## 7. Risks & mitigations
| Risk | Mitigation |
|------|------------|
| Uploads lost on redeploy | Mandatory `app_storage` persistent volume (§2). |
| Public demo creds exposed on internet | Force admin password change on first login; drop the flag for prod. |
| Build fails on server | Verify `docker build` locally first (Phase 3.8). |
| 4 GB RAM pressure during Vite+Composer build | Build happens in image build stage; add swap if OOM observed; CPX21 has headroom for dev. |
| DB data loss | Dokploy scheduled backups (Phase 6.19). |
| Migrations run concurrently by 3 services | Only `web` entrypoint runs `migrate`; worker/scheduler skip it. |

## 8. Non-goals (this iteration)
Production environment, `cihrms.org` root domain, Redis, real third-party integration credentials, horizontal scaling, CDN, external object storage.
