# CIHRMS Documentation

Index of every doc under `docs/`.

> **Last reviewed:** 2026-05-14

## Where to start

| If you want to… | Read |
|---|---|
| Set the project up and run it | [../README.md](../README.md) |
| Understand what's built today | [PROJECT_STATE.md](PROJECT_STATE.md) |
| Explain the application to non-technical stakeholders | [NON_TECHNICAL_APPLICATION_GUIDE.md](NON_TECHNICAL_APPLICATION_GUIDE.md) |
| Know what's planned next | [implementation_plan.md](implementation_plan.md) |
| Log in as a seeded user | [credentials.md](credentials.md) |
| Read the product spec | [Cihrm Hrms Product Requirements Document Prd.pdf](Cihrm%20Hrms%20Product%20Requirements%20Document%20Prd.pdf) |

## Doc descriptions

### [PROJECT_STATE.md](PROJECT_STATE.md)
A point-in-time snapshot of what exists in the codebase: per-layer counts (migrations, enums, models, controllers, routes, pages), per-module backend/frontend status, and the current gaps. Re-date this file whenever a layer materially moves.

### [NON_TECHNICAL_APPLICATION_GUIDE.md](NON_TECHNICAL_APPLICATION_GUIDE.md)
A plain-language walkthrough for executives, HR, finance, managers, auditors, trainers, and other non-technical stakeholders. It explains each major feature, who uses it, why it matters, and the business workflows it supports.

### [implementation_plan.md](implementation_plan.md)
The forward-looking punch list, organised into seven phases (version control → dashboard real data → module depth → tests → integration smoke tests → notifications/flash → production hardening), with effort estimates and parallelisation notes.

### [credentials.md](credentials.md)
Every seeded user account: name, staff ID, role, department, and the email used for password recovery. Sourced from [`database/seeders/DatabaseSeeder.php`](../database/seeders/DatabaseSeeder.php). All seeded passwords are `password`.

### [Cihrm Hrms Product Requirements Document Prd.pdf](Cihrm%20Hrms%20Product%20Requirements%20Document%20Prd.pdf)
The product requirements document. Treat as the canonical source of *what the product is supposed to do*; treat `PROJECT_STATE.md` as the canonical source of *what it does right now*.

### [stitch_cihrm_enterprise_hrms_dashboard/](stitch_cihrm_enterprise_hrms_dashboard/)
Source design exports (PNGs used to derive the public landing-page showcase under `public/images/showcase/`).

## Maintenance rules

1. **One source of truth per topic.** `IMPLEMENTATION_PLAN.md` at the project root is a pointer to [implementation_plan.md](implementation_plan.md). Do not re-fork it.
2. **Re-baseline `PROJECT_STATE.md` per PR** that materially changes a layer (new module, new migration set, schema rewrite). One-line entries are fine — the goal is freshness, not exhaustiveness.
3. **Cross-link, don't duplicate.** When two docs need the same fact, link the canonical one.
4. **Update credentials when seeders change.** [credentials.md](credentials.md) must match [`DatabaseSeeder.php`](../database/seeders/DatabaseSeeder.php).
