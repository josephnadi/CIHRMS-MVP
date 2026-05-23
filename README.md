# CIHRMS — CIHRM Ghana Human Resource Management System

A modular HRMS MVP covering employees, leave, tickets, payroll, recruitment, governance, performance, and integrations.

> **Stack:** Laravel 13.8 (PHP 8.3, CI on 8.4) · Vue 3 · Inertia.js v2 · Tailwind CSS v3 · PostgreSQL (production) / SQLite (dev) · Pest 4
> **Auth model:** Staff ID + Full Name (not email) · DB-backed RBAC layered over a legacy `User.role` enum
> **Last reviewed:** 2026-05-23 — post Documents v2, Finance F1–F5 + Paystack, Chat redesign, gamified sound pack

---

## Quick start

```bash
# 1. Dependencies
composer install
npm install

# 2. Env + key + database
cp .env.example .env
php artisan key:generate
php artisan migrate --seed   # seeds RBAC roles, permissions, fixed accounts, and demo data

# 3. Run everything (server + queue + logs + Vite)
composer dev
```

`composer dev` runs `php artisan serve`, `php artisan queue:listen`, `php artisan pail`, and `npm run dev` concurrently via the `dev` composer script.

For a production-style build instead:

```bash
npm run build
php artisan serve
```

## Seeded login credentials

All seeded passwords are `password`. Full list — including Finance, IT, and the auto-generated factory users — is in [docs/credentials.md](docs/credentials.md).

| Role | Name | Staff ID |
|---|---|---|
| Super Admin | Super Admin | `ADMIN-001` |
| HR Admin | HR Manager | `HR-001` |
| Employee | Akua Mensah | `GH-HR-821` |
| Finance | Kofi Asante | `FIN-001` |
| IT Support | Yaw Boateng | `IT-001` |

## Architecture

The codebase follows a strict layering convention for every module:

```
Enum → FormRequest → Service → Event → Listener → Resource → Inertia Page
```

| Layer | Location | Purpose |
|---|---|---|
| Enums | [app/Enums/](app/Enums/) | Backed PHP 8.1 enums for status/type fields |
| FormRequests | [app/Http/Requests/](app/Http/Requests/) | Validation + permission-based `authorize()` |
| Services | [app/Services/](app/Services/) | All business logic; controllers stay thin |
| Events | [app/Events/](app/Events/) | Domain events dispatched from services |
| Listeners | [app/Listeners/](app/Listeners/) | Queued side effects (analytics, notifications, integrations) |
| Jobs | [app/Jobs/](app/Jobs/) | `WriteAuditLog` (audit queue) |
| Policies | [app/Policies/](app/Policies/) | Per-model authorization |
| Middleware | [app/Http/Middleware/](app/Http/Middleware/) | `EnsurePermission`, `EnsureRole`, `AuditTrail`, webhook signature verification |
| Resources | [app/Http/Resources/](app/Http/Resources/) | Inertia/JSON transformers |
| Inertia pages | [resources/js/Pages/](resources/js/Pages/) | Per-module Vue 3 pages |

## Modules

Backend-complete (controllers, services, routes, resources):

- **Employees** — directory, profile fields, documents, skills, departments
- **Leave** — requests, balances, manager-notification listener
- **Tickets / Service Desk** — queue, assignment, resolution
- **Payments / Payroll** — payment records, payslip generation, cloud upload
- **Recruitment** — public careers portal, applicants, offer envelope (e-sign), Zoho contact sync
- **Complaints / Governance** — complaint log + status workflow
- **Performance** — review cycles, goals, goal check-ins, reviews
- **Documents v2** — upload + in-portal composer + sequential routing (sign/review/approve), **manipulable annotations** (drag/resize/rotate signatures and stamps), **stamp asset library** (personal/department/organization PNG uploads), **letterhead templates** (replaces hardcoded letterhead), **watermark templates** (per-document `watermark_id` + `none|on_burn|always` mode). Edit/Delete/Share (user/department/org audiences) with confidentiality guard.
- **Finance** — Chart of Accounts (F1), Accounts Payable + Journal Engine (F2), Accounts Receivable + customer statements (F3), **Paystack hosted-checkout gateway** (F4: payment links, webhook signature verification, refund flow), Bank Reconciliation (F5: CSV/OFX/MT940 import + 3-tier matching + bank-adjustment journal entries).
- **Internal Chat** — 1:1 messaging with optimistic send, 4-second polling, day separators, post-send dedupe, and a single-column scrollable directory.
- **Notifications** — multi-channel delivery with consent tracking; pluggable **sound packs** (musical / cinematic / gamified) with file-override architecture for production-grade UI audio.
- **Audit Logs** — queued write, RBAC-gated viewing
- **Integrations** — OAuth tokens + signature-verified webhooks for WhatsApp, Zoho, e-sign, MS Graph, Google, Slack, Paystack

Frontend depth varies — see [docs/PROJECT_STATE.md](docs/PROJECT_STATE.md) for the per-module breakdown.

## Authentication

Login uses **Staff ID + Full Name** (see [LoginRequest](app/Http/Requests/Auth/LoginRequest.php)) — not email. Email is stored but only used as a unique key in seeding and for password recovery.

Authorization is layered:

- Legacy `User.role` enum + per-user `permissions` array (still used by [EnsurePermission middleware](app/Http/Middleware/EnsurePermission.php) and by [User::ROLE_PERMISSIONS](app/Models/User.php))
- DB-backed `roles` / `permissions` / `role_user` tables (migration `2026_05_15_000010`)
- Per-model Laravel Policies in [app/Policies/](app/Policies/)

## Testing

```bash
composer test    # config:clear + artisan test
```

Pest 4. As of 2026-05-23: **895 tests / ~2,950 assertions passing** on both SQLite (dev) and PostgreSQL (CI on PHP 8.4). Coverage spans every module: auth, employees, leave, tickets, complaints, recruitment, performance, payments, payroll, documents (annotations, stamps, letterheads, watermarks, shares), finance (F1–F5 including Paystack webhook signature verification), policies, audit, and webhook signature verification for all integration providers. Filter examples: `php artisan test --filter=Documents`, `--filter=Finance`, `--filter=Stamp|Letterhead|Watermark`.

> **PHP 8.5 local note:** the `laravel/pao` stream-filter is excluded from auto-discovery to unblock 8.5 boot. CI runs on 8.4 where the issue does not apply.

## Documentation

- [docs/PROJECT_STATE.md](docs/PROJECT_STATE.md) — Current state of every layer and module
- [docs/MARKET_READY_PUNCHLIST.md](docs/MARKET_READY_PUNCHLIST.md) — Pre-launch risk audit and deferral log
- [docs/implementation_plan.md](docs/implementation_plan.md) — Forward-looking punch list and phases
- [docs/credentials.md](docs/credentials.md) — All seeded login accounts
- [docs/sound_pack_sources.md](docs/sound_pack_sources.md) — CC0 audio sources + drop-in contract for the cinematic / gamified sound packs
- [docs/Cihrm Hrms Product Requirements Document Prd.pdf](docs/Cihrm%20Hrms%20Product%20Requirements%20Document%20Prd.pdf) — Product requirements
- [docs/README.md](docs/README.md) — Index of every doc

## Design system

UI follows the "Sovereign Precision" direction:

- Obsidian sidebar, cobalt accents
- Plus Jakarta Sans (sans) + Instrument Serif (display)
- Material Symbols Outlined icons
- RGB-triplet stat-card pattern (`rgba(${rgb},0.12)` borders and glows)

Shared components live under [resources/js/Components/](resources/js/Components/) — `StatusBadge`, `EmptyState`, `Pagination`, `SlidePanel`, `KanbanBoard`. New module pages should compose these inside [AuthenticatedLayout.vue](resources/js/Layouts/AuthenticatedLayout.vue).

## License

MIT — see [Laravel framework license](https://opensource.org/licenses/MIT).
