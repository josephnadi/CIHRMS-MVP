---
title: CIHRMS Delivery Dossier
subtitle: Features, Standards, and Market Readiness
author: CIHRMS Engineering Team
date: 2026-05-25
---

# CIHRMS {.unnumbered .cover}

*A Human Resource Management System*

*Built for Ghana, ready for the world.*

CIHRMS Engineering Team

2026-05-25

Version 1.0

\newpage

# Executive Summary {.unnumbered}

CIHRMS — the Chartered Institute of Human Resource Management Ghana System — is a single-tenant, web-based HR platform built for Ghanaian institutions and the wider mid-market. It ships thirty-five modules covering the full HR cycle (employees, leave, attendance, performance, learning, recruitment, off-boarding), the compensation and money stack (payments, payroll, finance F1–F5, loans, disbursements, benefits), the public-sector compliance surface (Ghana Card identity verification, NPRA three-tier pensions, IPPD and GIFMIS exports, the Auditor-General report pack, the Act 720 whistleblower channel, the Act 843 data-protection self-service), and the operating instrumentation (audit trail, governance register, RBAC, role-by-role experience). It runs on Laravel 13 and Vue 3 with Inertia, single-tenant per organisation, with PostgreSQL in production.

The readiness claim is specific. CIHRMS is ready for its first paying customer, the next public tender cycle, and a buyer's IT and security review. The case rests on three pillars: commercial mid-market parity on the feature checklist a buyer's HR director runs, engineering rigour at a standard most HRMS competitors do not hold themselves to (973 tests passing on `main`, 65-item V2 audit closed across twelve PRs, two canonical patterns enforced repo-wide, tamper-evident SHA-256 audit chain shipped, 2FA-fresh middleware on every destructive endpoint), and a finance and statutory moat (full F1–F5 ledger with enforced double-entry invariants, IPPD2/IPPD3 and GIFMIS exporters, five statutory return generators on every approved payroll run) that very few HRMS products in this market segment carry. CIHRMS is *not* yet ready for Ministry-wide rollout: live NIA verification, realtime channels, per-grade leave entitlements, the L&D content layer, and CSA Act 1038 registration are scheduled, not shipped. Chapter 44 names the residual gaps; Chapter 46 sequences the work to close them.

This dossier is the manual and the evidence pack. **Part I** (Chapters 1–35) walks every shipped module in advocate voice with a consistent chapter template — synopsis, screen tour, every-action table, data model, integrations, standards touchpoints, and roadmap. **Part II** (Chapters 36–43) shifts to engineer voice for the architecture, canonical patterns, data model, RBAC, security, performance, testing, and deployment. **Part III** (Chapters 44–47) benchmarks the build against thirteen named standards, defends the readiness claim, sequences the roadmap, and lays out funding. The reader's map below routes each audience to where to start.

\newpage

# How to read this dossier {.unnumbered}

| Reader | Where to start |
|---|---|
| Minister / board / procurement officer | Executive Summary → Ch 45 (Why ready) → Ch 44 (Standards) |
| HR director / line manager | Ch 1 (What CIHRMS is) → Ch 34 (Role tours) → relevant module chapters |
| IT director / integration architect | Ch 36 (Architecture) → Ch 39 (RBAC) → Ch 40 (Security) → Ch 44 |
| Auditor / security reviewer | Ch 40 (Security) → Ch 24 (Audit Logs) → Ch 44 (Standards) |
| Engineering lead at a partner firm | Part II in order, then Ch 46 (Roadmap) |
| Cover to cover | Front → Part I → Part II → Part III → Back |

Every chapter in Part I opens with a three-line synopsis so you can scan the dossier without reading every page. Module chapters then follow a consistent template — *Where to find it*, screen walk-through, *Every button, every action* table, *The data behind it*, *How it talks to other modules*, *Standards touchpoints*, *What's planned next* — so once you've read one module chapter you know exactly where to look in any other. Part II chapters use a more conventional engineering structure (architecture diagram, then component-by-component walk). Part III chapters cite the audit JSON at `docs/delivery_dossier/build/module_audit.json` for every numeric claim, and every named standard resolves to a row in the per-module standards tables.

\newpage

# At a glance {.unnumbered}

| Indicator | Value |
|---|---|
| Module chapters covered | 35 |
| Modules shipped fully | 12 |
| Modules shipped with documented partials | 21 |
| Modules deliberately stubbed | 2 |
| Roles defined | 10 (9 active + 1 reserved) |
| Web routes | 424 |
| Inertia pages | 128 |
| Eloquent models | 124 |
| Service classes | 121 |
| Migrations | 116 |
| Pest tests passing | 973 |
| Test assertions | 3,405 |
| V2 audit punch-list items closed | 65 (across 12 PRs, #44–#55) |
| Standards benchmarked | 13 (8 Ghana statutes + 5 international) |
| Roadmap phases | 4 (Phases 1–3 sized at 24–28 engineering-weeks) |
| Document length | ~280+ pages |
| Build pipeline | Markdown → Pandoc → Word `.docx` → manual PDF |

\newpage
