# CIHRMS Delivery Dossier — Plan Addendum (v1.1)

**Date:** 2026-05-24
**Reason:** During Wave 0, a codebase audit revealed that the project has shipped
16 modules that were not in the original plan and that the gap analysis memory
(8 days old) listed as "Phase 1 / 2 / 3 / missing". The dossier scope was
expanded by user decision to cover all ~30 modules at the same depth as Ch 3,
and to rewrite Ch 27 (Standards Benchmark) + Ch 29 (Roadmap) against ACTUAL
shipped state, not the stale memory.

This addendum supplements (does not replace) `2026-05-24-cihrms-delivery-dossier-plan.md`.

## Final chapter numbering (Part I)

| # | Title | Status |
|---|---|---|
| 1 | What CIHRMS is and who it's for | pending |
| 2 | The Sovereign Precision design language | pending |
| 3 | Employees | ✅ DONE (W0.10) |
| 4 | Leave | pending |
| 5 | Attendance | **NEW** |
| 6 | Performance management | **NEW** |
| 7 | Learning & development | **NEW** |
| 8 | Establishment — positions, grades, steps | **NEW** |
| 9 | Recruitment + Public Careers | pending |
| 10 | Offboarding & clearance | **NEW** |
| 11 | Tickets (Service Desk) | pending |
| 12 | Complaints | pending |
| 13 | Documents | pending |
| 14 | Chat | pending |
| 15 | Messaging (administrative) | **NEW** |
| 16 | Notifications | pending |
| 17 | Announcements | **NEW** |
| 18 | Payments | pending |
| 19 | Payroll engine | **NEW** |
| 20 | Finance F1-F5 (CoA, AP, AR, Paystack, Bank Rec) | pending |
| 21 | Loans & advances | **NEW** |
| 22 | Disbursements | **NEW** |
| 23 | Benefits | **NEW** |
| 24 | Audit Logs | pending |
| 25 | Identity verification & Ghana Card | **NEW** |
| 26 | Data Protection (DPA) & Privacy | **NEW** |
| 27 | Whistleblower (CHRAJ-aligned) | **NEW** |
| 28 | Governance | **NEW** |
| 29 | Kiosk | **NEW** |
| 30 | Departments | pending |
| 31 | Reports & Analytics | pending |
| 32 | Profile / Self-Service Portal | pending |
| 33 | Settings | **NEW** |
| 34 | Role-by-role tours | pending |
| 35 | Cross-cutting features | pending |

## Part II — Engineering Annex

| # | Title |
|---|---|
| 36 | Architecture & stack |
| 37 | Canonical pattern (Enum → FormRequest → Service → Event → Resource) |
| 38 | Data model & migrations |
| 39 | RBAC, policies, per-user permissions |
| 40 | Security |
| 41 | Performance, caching, queues, jobs |
| 42 | Testing strategy |
| 43 | Deployment & operations |

## Part III — Standards & Market Readiness

| # | Title | Special note |
|---|---|---|
| 44 | Standards benchmark | **MUST be rewritten** against actual shipped state, not stale memory. New audit task added below. |
| 45 | Why we are ready for market | Argument strengthens substantially given shipped scope. |
| 46 | What's left and how to get there | Roadmap shrinks; surviving items: tamper-evident audit, Postgres migration, 2FA, full WCAG, SSO, CSA pen-test. |
| 47 | Funding and sequencing | Effort estimates revise downward. |

## New Wave 0.5 task — Module audit for Standards & Roadmap

Before Wave 3, dispatch a subagent to audit each module's actual capabilities
against the standards in Ch 44 and the gap-analysis claims in Ch 46. The audit
output is a single JSON file at `docs/delivery_dossier/build/module_audit.json`
that Ch 44 + Ch 46 + Ch 47 chapters read from. Without this audit, the
standards/roadmap chapters will under-claim what's shipped.

## Cross-reference guidance for all chapters

Every Part I chapter MUST use the numbering above when cross-referencing other
chapters. To keep the markdown stable across renumbering events, prefer this
form where possible:

> See Chapter 30 (Departments).

Avoid inline shorthand like "(Ch 15)" without the title — when the number
changes the title clarifies intent.

## Implementation order (Wave 1 revised)

Chapters can be written in any order, but the following grouping is logical
because adjacent chapters share source material:

1. **Workforce cluster** (sources: Employees, Department, Position models): Ch 4, 5, 6, 7, 8, 9, 10
2. **Service cluster** (Tickets, Complaints, Documents): Ch 11, 12, 13
3. **Communication cluster** (Chat, Messaging, Notifications, Announcements): Ch 14, 15, 16, 17
4. **Finance cluster** (Payments, Payroll, Finance, Loans, Disbursements, Benefits): Ch 18, 19, 20, 21, 22, 23
5. **Compliance cluster** (Audit, Identity, DPA, Whistleblower, Governance, Kiosk): Ch 24, 25, 26, 27, 28, 29
6. **Cross-cutting cluster** (Departments, Reports, Profile, Settings): Ch 30, 31, 32, 33
7. **Synthesis** (Role tours, Cross-cutting): Ch 34, 35
8. **Intro chapters** (write last so they can reference everything): Ch 1, 2

## Effort revision

| Original estimate | Revised |
|---|---|
| 14 module chapters at full depth | 32 module chapters at full depth |
| Total: ~280-360 pp | Total: ~500-700 pp |
| Wave 1 tasks: ~22 | Wave 1 tasks: ~38 |

Each new module chapter uses the same subagent dispatch shape as W0.10
(see implementer prompt template at
`C:\Users\j.nadi\.claude\plugins\cache\claude-plugins-official\superpowers\5.1.0\skills\subagent-driven-development\implementer-prompt.md`).
