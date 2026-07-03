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
