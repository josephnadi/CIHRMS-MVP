# CIHRMS Hetzner + Dokploy Deployment — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the Docker/CI artifacts and operator runbook that let CIHRMS deploy from GitHub to `dev.cihrms.org` on a Hetzner CPX21 running Dokploy, with a self-hosted PostgreSQL database.

**Architecture:** One multi-stage Docker image (Node asset build → Composer vendor → PHP 8.3-fpm + nginx runtime) is run as three docker-compose services — `web` (nginx+php-fpm), `worker` (`queue:work`), `scheduler` (`schedule:run` loop). PostgreSQL is a Dokploy-managed service reached over the internal Docker network. Uploads persist via a named volume mapped at `storage/app`.

**Tech Stack:** Laravel 13.7 / PHP 8.3, Vue 3 + Inertia + Vite, PostgreSQL 16, Docker Compose, Dokploy + Traefik (Let's Encrypt).

**Spec:** `docs/superpowers/specs/2026-07-03-hetzner-dokploy-deployment-design.md`

## Global Constraints

- All artifacts live under `cihrms-mvp/` (the git repo root; app code is here). Paths below are relative to `cihrms-mvp/`.
- PHP runtime: **8.3** (composer requires `^8.3`; do not use 8.4 in the image even though local CLI is 8.4).
- Node build: **Node 20**, `npm ci` (lockfile `package-lock.json` present).
- Composer: **`--no-dev --optimize-autoloader`** (composer.lock present).
- Health endpoint is **`/up`** (Laravel default, confirmed in `bootstrap/app.php`).
- File uploads write to `storage/app` → this path **MUST** be a persistent volume.
- Only the **`web`** container runs `migrate`; `worker`/`scheduler` must NOT, to avoid concurrent migration races.
- Drivers stay on **database** (session/cache/queue). No Redis.
- Env values are injected by **Dokploy** at deploy time — never bake secrets or a `.env` into the image or commit them.
- Dokploy tracks branch **`develop`**; `main` is the stable base.
- **Local Docker is unavailable on the dev machine**, so the authoritative image build + run verification happens on the **first Dokploy deploy** (Task 6 / Phase 5 of the runbook). Each artifact task below still includes the static checks that ARE runnable locally (shell syntax, YAML/JSON parse) so we catch typos before pushing.

---

### Task 1: `.dockerignore`

**Files:**
- Create: `.dockerignore`

**Interfaces:**
- Produces: a lean build context so the Dockerfile `COPY . .` steps don't pull `vendor/`, `node_modules/`, local env, or the SQLite DB.

- [ ] **Step 1: Create `.dockerignore`**

```gitignore
.git
.gitignore
.github
node_modules
vendor
tests
storage/app/public/*
storage/framework/cache/*
storage/framework/sessions/*
storage/framework/views/*
storage/logs/*
bootstrap/cache/*
.env
.env.*
!.env.production.example
database/database.sqlite
docs
*.md
.phpunit.result.cache
.DS_Store
```

- [ ] **Step 2: Verify no critical path is excluded**

Run: `git check-ignore -v --no-index public/index.php artisan composer.json 2>/dev/null; echo "exit=$?"`
Expected: no output and `exit=1` (meaning none of these app-critical files match ignore rules). `.dockerignore` and `.gitignore` are separate, but this catches accidental glob overreach in the patterns.

- [ ] **Step 3: Commit**

```bash
git add .dockerignore
git commit -m "build: add .dockerignore for lean image build context"
```

---

### Task 2: nginx site config

**Files:**
- Create: `docker/nginx.conf`

**Interfaces:**
- Consumes: nothing.
- Produces: `/etc/nginx/conf.d/default.conf` inside the image (copied by the Dockerfile in Task 4). Serves `public/`, forwards `.php` to php-fpm at `127.0.0.1:9000`.

- [ ] **Step 1: Create `docker/nginx.conf`**

```nginx
server {
    listen 80 default_server;
    server_name _;
    root /var/www/html/public;
    index index.php;

    # Uploads: documents, CVs, statutory exports. Keep in sync with PHP upload limits.
    client_max_body_size 25M;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 120;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

- [ ] **Step 2: Sanity-check for the required directives**

Run: `grep -E 'fastcgi_pass 127.0.0.1:9000|root /var/www/html/public|try_files' docker/nginx.conf | wc -l`
Expected: `3` (all three critical lines present).

- [ ] **Step 3: Commit**

```bash
git add docker/nginx.conf
git commit -m "build: add nginx site config for php-fpm"
```

---

### Task 3: container entrypoint

**Files:**
- Create: `docker/entrypoint.sh`

**Interfaces:**
- Consumes: env vars `CONTAINER_ROLE` (`web`|`worker`|`scheduler`), plus standard Laravel `DB_*` / `APP_*`.
- Produces: the boot sequence. For **web only**: waits for DB, runs `migrate --force`. For **all roles**: caches config/route/view and links storage, then `exec`s the passed command (`$@`).

- [ ] **Step 1: Create `docker/entrypoint.sh`**

```sh
#!/bin/sh
set -e

ROLE="${CONTAINER_ROLE:-web}"
echo "[entrypoint] starting role=${ROLE}"

# Web is the single migration owner. Wait for Postgres, then migrate.
if [ "$ROLE" = "web" ]; then
    echo "[entrypoint] waiting for database..."
    tries=0
    until php artisan migrate:status >/dev/null 2>&1; do
        tries=$((tries + 1))
        if [ "$tries" -ge 30 ]; then
            echo "[entrypoint] database not reachable after 30 tries; continuing anyway"
            break
        fi
        sleep 2
    done
    echo "[entrypoint] running migrations..."
    php artisan migrate --force
fi

# All roles: (re)build framework caches against the injected env. Idempotent.
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link 2>/dev/null || true

echo "[entrypoint] exec: $*"
exec "$@"
```

- [ ] **Step 2: Verify shell syntax**

Run: `sh -n docker/entrypoint.sh && echo "syntax OK"`
Expected: `syntax OK`

- [ ] **Step 3: Verify role-guard logic is present**

Run: `grep -c 'CONTAINER_ROLE\|migrate --force\|exec "\$@"' docker/entrypoint.sh`
Expected: `3` or more (role read, migrate guarded, exec present).

- [ ] **Step 4: Commit**

```bash
git add docker/entrypoint.sh
git commit -m "build: add role-aware container entrypoint (web-only migrations)"
```

---

### Task 4: Dockerfile (multi-stage image)

**Files:**
- Create: `Dockerfile`

**Interfaces:**
- Consumes: `docker/nginx.conf` (Task 2), `docker/entrypoint.sh` (Task 3), `package-lock.json` + `composer.lock`.
- Produces: an image whose default `CMD` starts php-fpm + nginx (web role); `worker`/`scheduler` override the command in compose (Task 5).

- [ ] **Step 1: Create `Dockerfile`**

```dockerfile
# syntax=docker/dockerfile:1

# ---- Stage 1: build front-end assets with Vite ----
FROM node:20-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

# ---- Stage 2: install PHP dependencies ----
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-scripts --no-autoloader --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative

# ---- Stage 3: runtime (php-fpm + nginx) ----
FROM php:8.3-fpm-bookworm AS app

# PHP extensions via mlocati installer (pulls the right system libs automatically).
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/bin/
RUN install-php-extensions \
        pdo_pgsql \
        bcmath \
        gd \
        zip \
        intl \
        exif \
        pcntl \
        opcache

# nginx + tini for clean signal handling.
RUN apt-get update \
    && apt-get install -y --no-install-recommends nginx tini \
    && rm -rf /var/lib/apt/lists/*

# Production PHP config.
RUN { \
        echo "memory_limit=512M"; \
        echo "upload_max_filesize=25M"; \
        echo "post_max_size=26M"; \
        echo "opcache.enable=1"; \
        echo "opcache.validate_timestamps=0"; \
        echo "expose_php=Off"; \
    } > /usr/local/etc/php/conf.d/zz-cihrms.ini

WORKDIR /var/www/html

# App code with optimized autoloader (vendor stage already ran dump-autoload).
COPY --from=vendor /app /var/www/html
# Built assets (public/build) from the node stage.
COPY --from=assets /app/public/build /var/www/html/public/build

COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
RUN rm -f /etc/nginx/sites-enabled/default 2>/dev/null || true
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# Writable dirs for the web server user.
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

# Web-role process manager: start php-fpm in background, nginx in foreground.
RUN printf '#!/bin/sh\nset -e\nphp-fpm -D\nexec nginx -g "daemon off;"\n' > /usr/local/bin/serve-web \
    && chmod +x /usr/local/bin/serve-web

EXPOSE 80
ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/entrypoint"]
CMD ["/usr/local/bin/serve-web"]
```

- [ ] **Step 2: Verify stage wiring references real files**

Run: `grep -E 'COPY docker/(nginx.conf|entrypoint.sh)' Dockerfile && test -f docker/nginx.conf && test -f docker/entrypoint.sh && echo "refs OK"`
Expected: the two COPY lines print, then `refs OK`.

- [ ] **Step 3: Verify PHP version pin and no-dev install**

Run: `grep -E 'php:8.3-fpm|--no-dev' Dockerfile | wc -l`
Expected: `3` (one runtime pin + two `--no-dev` occurrences).

> **Note:** The full `docker build` cannot run here (no local Docker). It runs authoritatively in Task 6 on the server. Do NOT skip Task 6's log review.

- [ ] **Step 4: Commit**

```bash
git add Dockerfile
git commit -m "build: multi-stage Dockerfile (node assets + composer + php-fpm/nginx)"
```

---

### Task 5: docker-compose stack

**Files:**
- Create: `docker-compose.yml`

**Interfaces:**
- Consumes: the image built from `Dockerfile` (Task 4). Env vars are **passed through** from Dokploy's project environment (pass-through form — names only, no values, so nothing secret is committed).
- Produces: three services (`web`, `worker`, `scheduler`) + the `app_storage` volume. Dokploy attaches the `dev.cihrms.org` domain to `web:80`.

- [ ] **Step 1: Create `docker-compose.yml`**

```yaml
# CIHRMS dev stack for Dokploy. Postgres is a SEPARATE Dokploy-managed
# database (not defined here) reached over the shared Docker network.
# All env values are injected by Dokploy at deploy time (pass-through).

x-app: &app
  build:
    context: .
    dockerfile: Dockerfile
  restart: unless-stopped
  environment: &app-env
    - APP_NAME
    - APP_ENV
    - APP_KEY
    - APP_DEBUG
    - APP_URL
    - APP_TRUSTED_PROXIES
    - SESSION_DRIVER
    - SESSION_SECURE_COOKIE
    - SESSION_ENCRYPT
    - QUEUE_CONNECTION
    - CACHE_STORE
    - DB_CONNECTION
    - DB_HOST
    - DB_PORT
    - DB_DATABASE
    - DB_USERNAME
    - DB_PASSWORD
    - MAIL_MAILER
    - CIHRMS_ALLOW_DEMO_SEEDERS
  volumes:
    - app_storage:/var/www/html/storage/app

services:
  web:
    <<: *app
    environment:
      <<: *app-env
      # CONTAINER_ROLE defaults to "web" in the entrypoint; set explicitly for clarity.
    command: ["/usr/local/bin/serve-web"]
    expose:
      - "80"

  worker:
    <<: *app
    entrypoint: ["/usr/bin/tini", "--", "/usr/local/bin/entrypoint"]
    command: ["php", "artisan", "queue:work", "--sleep=3", "--tries=3", "--max-time=3600"]
    environment:
      - APP_NAME
      - APP_ENV
      - APP_KEY
      - APP_DEBUG
      - APP_URL
      - CONTAINER_ROLE=worker
      - SESSION_DRIVER
      - QUEUE_CONNECTION
      - CACHE_STORE
      - DB_CONNECTION
      - DB_HOST
      - DB_PORT
      - DB_DATABASE
      - DB_USERNAME
      - DB_PASSWORD
      - MAIL_MAILER

  scheduler:
    <<: *app
    command: ["sh", "-c", "while true; do php artisan schedule:run --no-interaction; sleep 60; done"]
    environment:
      - APP_NAME
      - APP_ENV
      - APP_KEY
      - APP_DEBUG
      - APP_URL
      - CONTAINER_ROLE=scheduler
      - SESSION_DRIVER
      - QUEUE_CONNECTION
      - CACHE_STORE
      - DB_CONNECTION
      - DB_HOST
      - DB_PORT
      - DB_DATABASE
      - DB_USERNAME
      - DB_PASSWORD
      - MAIL_MAILER

volumes:
  app_storage:
```

> **Why `worker`/`scheduler` set `CONTAINER_ROLE` but not `web`:** the entrypoint defaults the role to `web`, so only the non-web services need the override — and that override is what keeps them from running `migrate`.

- [ ] **Step 2: Validate YAML parses**

Run: `python -c "import yaml,sys; yaml.safe_load(open('docker-compose.yml')); print('yaml OK')" 2>/dev/null || node -e "const y=require('fs').readFileSync('docker-compose.yml','utf8'); require('js-yaml'); console.log('yaml OK')" 2>/dev/null || echo "no yaml parser available — validate at deploy with 'docker compose config'"`
Expected: `yaml OK` (or the fallback note if neither parser is installed; then rely on Task 6).

- [ ] **Step 3: Verify the three services and the volume exist**

Run: `grep -E '^  (web|worker|scheduler):|^volumes:|app_storage:' docker-compose.yml`
Expected: lines for `web:`, `worker:`, `scheduler:`, `volumes:`, and `app_storage:`.

- [ ] **Step 4: Verify only web runs migrations (role guard)**

Run: `grep -c 'CONTAINER_ROLE=worker\|CONTAINER_ROLE=scheduler' docker-compose.yml`
Expected: `2` (both non-web services carry the role override).

- [ ] **Step 5: Commit**

```bash
git add docker-compose.yml
git commit -m "build: docker-compose stack (web + worker + scheduler + storage volume)"
```

---

### Task 6: production env template

**Files:**
- Create: `.env.production.example`

**Interfaces:**
- Consumes: nothing.
- Produces: the annotated list of variables the operator pastes into Dokploy's environment editor. Documentation only — never loaded by the app.

- [ ] **Step 1: Create `.env.production.example`**

```dotenv
# CIHRMS — Dokploy environment for dev.cihrms.org
# Paste these into the Dokploy project "Environment" editor (NOT committed with values).
# Generate APP_KEY once with:  php artisan key:generate --show

APP_NAME="CIHRMS"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://dev.cihrms.org

# Behind Traefik (Dokploy's reverse proxy)
APP_TRUSTED_PROXIES=*
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true

# Drivers stay on the database (no Redis yet)
SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database

# Database — copy from the Dokploy managed Postgres service (internal host)
DB_CONNECTION=pgsql
DB_HOST=
DB_PORT=5432
DB_DATABASE=cihrms
DB_USERNAME=cihrms
DB_PASSWORD=

# Mail — log is acceptable on dev; switch to a real provider before prod
MAIL_MAILER=log

# Dev-box only: seed demo login accounts (admin@cihrms.local / password,
# all forced to change password on first login). MUST be removed/false for prod.
CIHRMS_ALLOW_DEMO_SEEDERS=true
```

- [ ] **Step 2: Verify template has no committed secrets**

Run: `grep -E '^(APP_KEY|DB_PASSWORD|DB_HOST)=' .env.production.example`
Expected: all three print with **empty** right-hand sides (no values leaked).

- [ ] **Step 3: Commit**

```bash
git add .env.production.example
git commit -m "docs: production env template for Dokploy"
```

---

### Task 7: operator runbook

**Files:**
- Create: `docs/ops/deploy-dokploy.md`

**Interfaces:**
- Consumes: all prior artifacts (referenced by path).
- Produces: the click-by-click operator guide covering Phases 1–6 of the spec. This is the human-facing deliverable; the operator performs the Hetzner/Namecheap/Dokploy actions.

- [ ] **Step 1: Create `docs/ops/deploy-dokploy.md`**

````markdown
# Deploying CIHRMS to Hetzner + Dokploy (dev.cihrms.org)

Companion to the design spec at
`docs/superpowers/specs/2026-07-03-hetzner-dokploy-deployment-design.md`.
Claude produced the repo artifacts; **you** perform the console steps below.

## Phase 1 — Provision the server
1. Hetzner Cloud → create server: **CPX21** (3 vCPU / 4 GB), image **Ubuntu 22.04**,
   location **Falkenstein (fsn1)**. Add your SSH key. Create.
2. SSH in as root. Create a sudo user and harden:
   ```bash
   adduser deploy && usermod -aG sudo deploy
   ufw allow OpenSSH && ufw allow 80 && ufw allow 443 && ufw --force enable
   apt update && apt -y install unattended-upgrades && dpkg-reconfigure -plow unattended-upgrades
   ```
3. Install Dokploy:
   ```bash
   curl -sSL https://dokploy.com/install.sh | sh
   ```
   It installs Docker, Traefik, and a panel Postgres. Panel comes up on `http://<IP>:3000`.

## Phase 2 — DNS (Namecheap)
4. Namecheap → Domain List → `cihrms.org` → **Advanced DNS**. Add:
   - **A record** — Host `dev`   → Value `<server IP>` → TTL Automatic.
   - **A record** — Host `panel` → Value `<server IP>` → TTL Automatic.
5. Leave `@` (root) and `www` untouched — production is a later project.
6. Wait for propagation (`nslookup dev.cihrms.org` returns the server IP).

## Phase 3 — Dokploy first login
7. Open `http://<IP>:3000`, create the admin account.
8. Settings → Server/Domain: set the panel domain to `panel.cihrms.org`, enable HTTPS.
   Re-open the panel at `https://panel.cihrms.org`.

## Phase 4 — Wire up the app
9. Dokploy → Git → connect GitHub (GitHub App) to `josephnadi/CIHRMS-MVP`.
10. Create a **Database → PostgreSQL** service:
    - Name `cihrms-db`, database `cihrms`, user `cihrms`, generate a strong password.
    - Note the **internal host** shown (used as `DB_HOST`).
11. Create a **Compose** application:
    - Source: GitHub `josephnadi/CIHRMS-MVP`, branch **`develop`**.
    - Compose path: `docker-compose.yml` (repo root is `cihrms-mvp/` — set the
      build context/base directory to `cihrms-mvp` if the repo is nested).
12. **Environment** tab: paste `.env.production.example`, then fill in:
    - `APP_KEY` — generate locally: `php artisan key:generate --show` and paste.
    - `DB_HOST` / `DB_PASSWORD` — from step 10.
13. **Domains** tab: add `dev.cihrms.org` → service `web`, container port `80`,
    enable **Let's Encrypt (HTTPS)** and **HTTP→HTTPS redirect**.
14. **Volumes/Mounts**: confirm the `app_storage` volume maps to
    `/var/www/html/storage/app` (declared in `docker-compose.yml`).

## Phase 5 — Deploy & seed
15. Click **Deploy**. Watch build logs — the image builds (node → composer →
    php-fpm) and the `web` entrypoint runs `migrate --force` automatically.
16. First run only, seed reference + demo data. In the `web` service **Terminal**:
    ```bash
    php artisan db:seed --force
    ```
17. Smoke test:
    - Visit `https://dev.cihrms.org/up` → expect HTTP 200.
    - Visit `https://dev.cihrms.org`, log in as `admin@cihrms.local` / `password`,
      **change the password when prompted**.
    - Click through a few modules; confirm no 500s.
    - In Dokploy, confirm `worker` and `scheduler` containers are **running**.

## Phase 6 — Operational baseline
18. Database service → **Backups**: enable a scheduled backup (daily) to a volume
    or S3-compatible bucket.
19. Compose app → enable **Auto Deploy** (webhook) so pushes to `develop` redeploy.
20. Deferred to production go-live (not now): real mail provider, real
    SMS/identity/Paystack/GhIPSS credentials, `cihrms.org` root domain, a separate
    production environment tracking `main`, and removing `CIHRMS_ALLOW_DEMO_SEEDERS`.

## Rollback
- Dokploy keeps previous deployments — use **Redeploy** on a prior successful build.
- Database: restore the latest backup from the DB service Backups tab.

## Troubleshooting
- **502 from Traefik:** the `web` container isn't healthy — check its logs for a
  php-fpm/nginx start error or a failed `config:cache` (usually a bad env value).
- **Migrations didn't run:** confirm the failing container is `web` (only it migrates);
  check `DB_HOST`/`DB_PASSWORD` and that the DB service is running.
- **Uploads vanish after redeploy:** the `app_storage` volume isn't mounted — re-check step 14.
- **Assets 404 / unstyled page:** the node build stage failed; check build logs for `npm run build`.
````

- [ ] **Step 2: Verify the runbook covers all six phases**

Run: `grep -c '^## Phase' docs/ops/deploy-dokploy.md`
Expected: `6`.

- [ ] **Step 3: Commit**

```bash
git add docs/ops/deploy-dokploy.md
git commit -m "docs: Dokploy deployment operator runbook"
```

---

### Task 8: publish `develop` branch for Dokploy

**Files:**
- No file changes — git branch/merge operations only.

**Interfaces:**
- Consumes: all artifacts from Tasks 1–7 committed on the working branch.
- Produces: an up-to-date `main` and a `develop` branch (off `main`) on GitHub that Dokploy tracks.

> **Operator gate:** merging into `main` changes the default branch. Confirm with the user before running this task, and ensure the working tree is clean and tests pass first.

- [ ] **Step 1: Confirm tests pass on the current branch**

Run: `php artisan test`
Expected: full suite green (matches the last recorded count, ~1408).

- [ ] **Step 2: Merge the current work + artifacts into `main`**

```bash
git checkout main
git pull origin main
git merge --no-ff fix/audit-remediation -m "chore: merge audit remediation + deployment artifacts"
```

- [ ] **Step 3: Create and push `develop` off `main`**

```bash
git checkout -b develop
git push -u origin develop
git checkout main && git push origin main
```

- [ ] **Step 4: Verify `develop` exists on the remote**

Run: `git ls-remote --heads origin develop`
Expected: one line showing the `develop` ref hash.

---

## Self-Review

**Spec coverage:**
- Repo artifacts (§3 of spec): Dockerfile → Task 4; compose → Task 5; nginx → Task 2; entrypoint → Task 3; .dockerignore → Task 1; `.env.production.example` → Task 6; runbook → Task 7. ✔
- Persistent `storage/app` volume (spec §2 critical note): declared in Task 5, verified in runbook step 14. ✔
- Web-only migrations (spec §7 risk): entrypoint role guard (Task 3) + role overrides (Task 5). ✔
- PHP extensions (spec §3): pdo_pgsql/bcmath/gd/zip/intl/exif/pcntl/opcache in Task 4. ✔
- Env hardening (spec §4): Task 6 template (secure cookies, trusted proxies, APP_DEBUG=false). ✔
- Merge-to-main-then-branch-develop (spec §1 decision 5): Task 8. ✔
- Rollout Phases 1–6 (spec §5): Task 7 runbook. ✔

**Placeholder scan:** No TBD/TODO. Empty RHS values in `.env.production.example` are intentional (secrets filled in Dokploy), asserted empty in Task 6 Step 2.

**Type/name consistency:** `CONTAINER_ROLE` values (`web`/`worker`/`scheduler`), `serve-web`, `app_storage`, `/var/www/html/storage/app`, and `/up` are used identically across Tasks 3, 4, 5, and 7.

**Known environmental limitation:** Local Docker is unavailable, so `docker build`/`docker compose up` are not run in Tasks 1–7; the authoritative build+run verification is the first Dokploy deploy (Task 7, Phase 5). This is called out at the top and in Task 4 Step 3.
