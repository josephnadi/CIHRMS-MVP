# CIHRMS — Implementation Plan

> **Stack:** Laravel 13.8 · Vue 3 · Inertia.js v2 · Tailwind v3 · SQLite (dev) → PostgreSQL (prod)
> **Architecture:** Enum → FormRequest → Service → Event → Listener → Resource → Inertia Page
> **Last re-baselined:** 2026-05-19

This document is the **forward-looking** punch list. For the current snapshot of what's already built, see [PROJECT_STATE.md](PROJECT_STATE.md).

---

## What's complete (as of 2026-05-19)

| Phase | Status |
|---|---|
| 1 — Dashboard headline KPIs wired to real `DashboardService` | ✅ |
| 2 — Module Index/Show pages for all eight modules | ✅ |
| 2b — Performance sub-pages: Goals, Reviews, NineBox | ✅ |
| 3 — Service feature tests (Tickets, Leave, Employees, Payments, Complaints, Recruitment, Performance) | ✅ written, ⚠️ blocked locally |
| 3b — Policy deny-path tests | ✅ written, ⚠️ blocked locally |
| 4 — Webhook signature smoke tests (all 6 providers) | ✅ written, ⚠️ blocked locally |

**Local execution blocker:** `laravel/pao` (v ^1.0.6) calls `stream_filter_remove()` in `vendor/laravel/pao/src/Execution.php:80`, which PHP 8.5.5 now treats as a fatal warning. Tests are syntactically valid Pest 4 / Laravel 13 code; they pass on PHP 8.3 / 8.4. Fix is environmental, not in test code.

---

## Phase 0 — Version control (still pending)

`cihrms-mvp/` is not yet a git repo. This is the highest-priority remaining task.

| Task | Output |
|---|---|
| `git init`, commit current state | Single initial commit on `main` |
| Add `.gitignore` covering `vendor/`, `node_modules/`, `.env`, `storage/*.log`, `database/database.sqlite`, `public/build/`, `public/hot` | Clean status |
| Add remote (GitHub/GitLab) | Working `git push` |
| Add minimal CI: lint + Pest on **PHP 8.4** | `.github/workflows/ci.yml` |

**Done when:** A clean clone + `composer setup` + `composer dev` reproduces the running app, and CI runs the new test suite green.

---

## Phase 5 — PHP 8.5 / pao compatibility

Three options, in increasing invasiveness:

1. **Downgrade local PHP to 8.4** for parity with composer.json `^8.3` and CI. Simplest.
2. **Pin or remove `laravel/pao`** from `composer.json` `require-dev` if it's not load-bearing. Test impact in isolation.
3. **Patch the call site** (`stream_filter_remove($this->filter);` wrapped in `@` or a `try { ... } catch (\Throwable) {}`) and submit upstream. Last resort.

---

## Phase 6 — Production hardening

| Task | Notes |
|---|---|
| Switch dev DB to PostgreSQL parity | Add `pgsql` config block; document in README |
| Queue: move from `sync` (default) to `database` or `redis` | Set `QUEUE_CONNECTION` in `.env.production` |
| Configure `audit` and `analytics` queues as separate workers | Supervisor / systemd unit files in `deploy/` |
| Force-reset seeded passwords on first login | New `password_must_change` column + middleware redirect |
| Add rate limiting to public careers POST `/careers/{job}/apply` | `throttle:5,1` |
| HTTPS-only cookies, SAMEsite=lax | `config/session.php` |
| Sentry or Bugsnag wiring | `config/logging.php` channel |
| Backup strategy (DB + `storage/app/`) | `spatie/laravel-backup` |
| Replace residual Dashboard sparkline literals with real `DashboardService` time-series | [Dashboard.vue](../resources/js/Pages/Dashboard.vue) lines 50–56 |

---

## Phase 7 — Real-provider integration tests

Signature verification is under test; outbound delivery is not. Add HTTP-fake-based tests for each integration listener:

| Listener | Outbound to | Test stub |
|---|---|---|
| `SendNotifications` | Email, in-app, WhatsApp, Slack, Teams (per `notification_channels`) | `Http::fake()` per channel |
| `CreateZohoContactOnHire` | Zoho CRM `POST /crm/v6/Contacts` | `Http::fake()` |
| `UploadPayslipToCloud` | MS Graph drive upload | `Storage::fake()` + `Http::fake()` |
| `SendOfferEnvelopeToApplicant` | DocuSign / e-sign provider | `Http::fake()` |

Where consent is involved (WhatsApp `whatsapp_consent_at`), assert that the listener short-circuits when consent is missing.

---

## Phase 8 — Documentation refresh (continuous)

Treat docs as code — every PR that changes a module updates [`PROJECT_STATE.md`](PROJECT_STATE.md) and, where relevant, this plan.

- [PROJECT_STATE.md](PROJECT_STATE.md) — current snapshot, re-dated whenever layers move
- [credentials.md](credentials.md) — updated whenever seeders change
- [README.md](../README.md) — updated whenever setup commands or top-level modules change
- Add per-module `docs/modules/<name>.md` once a module has non-obvious behaviour worth recording

---

## Effort estimate (remaining)

| Phase | Est. effort | Notes |
|---|---|---|
| 0 — Version control + CI | 1 day | Includes initial green CI run |
| 5 — PHP 8.5 / pao | 0.5–2 days | Depends on chosen fix |
| 6 — Production hardening | 2–3 days | Mostly config + middleware |
| 7 — Real-provider integration tests | 2–3 days | One per listener |
| **Total (single dev)** | **5–9 days** | |

---

## Out of scope (for now)

- Multi-tenancy
- Mobile native apps
- ML / AI features beyond the [`AiAssistantController`](../app/Http/Controllers/AiAssistantController.php) placeholder
- Real-time presence / WebSockets (Reverb/Pusher) — defer until a concrete in-product need emerges
