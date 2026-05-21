# CIHRMS — Product Requirements Document (PRD)

> **Product:** CIHRM Ghana Human Resource Management System (CIHRMS)
> **Version:** 2.0 — Stage-aware, end-state oriented
> **Document owner:** Product / Engineering
> **Last revised:** 2026-05-20
> **Supersedes:** `docs/Cihrm Hrms Product Requirements Document Prd.pdf` (v1, initial scope)
> **Status:** Active — tracks built scope, in-flight scope, and the government-grade end state

---

## 1. Executive Summary

CIHRMS is a **government-grade, modular HRMS** built for the **Chartered Institute of Human Resource Management Ghana (CIHRM Ghana)** and positioned for adoption by **Ghana public-sector MDAs** and **mid-to-large private employers** in the West African market.

It started as a Laravel + Vue MVP covering the eight commercial HRMS basics (employees, leave, tickets, payroll, recruitment, complaints, performance, analytics) and has been deliberately driven up the maturity curve toward the controls expected by **IPPD2/IPPD3 + HRMIS + GIFMIS**, the **Ghana Card (NIA) biometric identity baseline**, **NPRA-licensed three-tier pensions**, **CHRAJ whistleblower oversight**, and **Auditor-General reporting**.

The final end-state product is a single platform that any institution — from a 50-person professional body to a 50,000-person ministry — can deploy to run the full HR lifecycle, statutory compliance, and workforce intelligence on Ghanaian regulatory rails, with multilingual + low-bandwidth + offline access for the field workforce.

### 1.1 Vision

> *"One workforce platform — registered with the Data Protection Commission, signed off by the Auditor-General, trusted on a kiosk in a district office, and usable on a Nokia by USSD."*

### 1.2 Strategic outcomes

| Outcome | Measure |
|---|---|
| Statutory payroll auto-compliance | PAYE, SSNIT Tier-1, NPRA Tier-2/3, NHIA computed and remitted on a single approved run |
| Ghost-worker elimination | 100% of active staff identity-verified against Ghana Card + biometric |
| Audit defensibility | Full tamper-evident audit trail, exportable into the Auditor-General report pack |
| Workforce visibility | Live establishment & headcount dashboards from positions/grades/steps |
| Employee self-service | ≥80% of leave / payslip / claim / clearance interactions self-served |
| Accessibility & reach | WCAG 2.1 AA web, USSD/SMS fallback, locale switch (en / tw / ee / ga / dag) |
| Data-subject rights | DPA 2012 access/erasure/portability requests fulfilled within statutory window |

---

## 2. Stages — Where we are vs. where we are going

CIHRMS has been delivered in **six discrete phases**. Each phase is shippable on its own, and each unlocks a meaningful business outcome.

```
P0  Foundation  ─►  P1 Statutory  ─►  P2 Performance & Time
                                      │
P3 Loans, Off-board, Whistle ◄────────┘
                │
P4 Learning, Assets, Benefits, A11y ──►  P5 Integrations, API, PWA, SSO  ──►  P6 Hardening & Public-sector Readiness
```

### 2.1 Stage map

| Stage | Status (2026-05-20) | Theme | Outcome |
|---|---|---|---|
| **P0 — Foundation MVP** | ✅ Shipped | Employees, Leave, Tickets, Payroll basics, Recruitment, Complaints, Audit log, Notifications | Commercial mid-market parity |
| **P1 — Statutory & Identity** | ✅ Shipped | PAYE/SSNIT/Tier-2/3/NHIA engine, Positions/Grades/Steps, Ghana Card adapter, Tamper-evident audit, 2FA, Establishment ceilings | Minimum bar for a government pitch |
| **P2 — Performance & Time** | ✅ Shipped | Performance contracts, calibration, PIPs, biometric attendance, shifts, corrections, Auditor-General report pack, Whistleblower channel | PSC-style performance + Act 651-compliant time |
| **P3 — Loans, Off-board, Disburse** | ✅ Shipped | Loans & advances, off-boarding clearance + final settlement, MoMo/GhIPSS disbursement, SMS/USSD messaging, API tokens + outbound webhooks | Lifecycle close-out + payout rails |
| **P4 — Learning, Assets, Benefits, A11y** | ✅ Shipped | LMS (catalogue, enrolment, certifications), Asset register + maintenance + retirement, Benefits enrolment + claims + e-card, WCAG AA pass, locale switching | Full HR lifecycle coverage |
| **P5 — Integrations, API v1, PWA, SSO** | ✅ Shipped | Versioned REST API v1 + OpenAPI, scoped API tokens, outbound webhook subscriptions, SAML/OIDC SSO, PWA + offline shell, Stoplight docs | Interoperability + enterprise SSO |
| **P6 — Hardening & Public-sector Readiness** | 🟡 In progress | Sentry, backups, Supervisor units, Postgres, NITA hosting playbook, CSA registration, pen-test, accessibility audit command | Production readiness |
| **P7 — Government end-state** | 🔵 Planned | Full GIFMIS export, IPPD3 sync, USSD coverage of all employee actions, AI assistant grounded on the policy library, BI workspace | Government-grade differentiation |

### 2.2 End-state definition

The product is "complete" when:

1. A new MDA can be **on-boarded in ≤ 30 days** using only the wizard, the seed catalogues, and import templates.
2. A monthly payroll run can be **calculated, approved (2FA), disbursed (GhIPSS/MoMo), and remitted (SSNIT/GRA/NPRA/NHIA)** from inside the product with no spreadsheet round-trips.
3. Any employee in the institution can complete **every self-service action** (apply for leave, view payslip, submit a claim, acknowledge a policy, clock in, run an off-boarding checklist) either via the **web portal, the PWA, USSD, or SMS**.
4. The platform passes a **DPA 2012 + Cybersecurity Act 2020 audit** and ships with a public-facing **whistleblower channel** that meets Act 720 anonymity and traceability requirements.
5. The **Auditor-General report pack** can be generated for any cycle on demand and is byte-identical to the official template.

---

## 3. Users & Personas

| Persona | Description | Critical jobs-to-be-done |
|---|---|---|
| **Super Admin** | Tenant owner / platform admin | Configure orgs, departments, RBAC, integrations, SSO, retention |
| **HR Admin** | Senior HR — recruitment, employees, policies, learning, performance | Manage establishment, performance cycles, statutory calendars |
| **HR Officer** | Day-to-day HR | Process leave, attendance corrections, onboarding, off-boarding |
| **Manager / Department Head** | Line / cost-centre head | Approve leave, sign performance contracts, calibrate ratings |
| **Finance Officer** | Treasury / payroll exec | Run payroll, approve, disburse, reconcile, export GIFMIS |
| **IT Support** | Service desk | Triage tickets, manage assets, handle off-boarding clearance for IT |
| **Auditor** | Internal / external auditor | Read-only access; pull Auditor-General reports + tamper-evident log |
| **Investigator** | Whistleblower / governance officer | Triage anonymous reports, log actions, communicate with reporter |
| **DPO** | Data Protection Officer | Fulfil access / erasure / portability requests |
| **Employee** | Standard end-user | Self-service: leave, payslip, profile, learning, claims, attendance, USSD |
| **Applicant** | External job seeker | Browse careers, apply, e-sign offers |
| **Anonymous Reporter** | Public, unauthenticated | File and track a whistleblower report |

---

## 4. Functional Scope

### 4.1 Module catalogue (current — all shipped)

#### People & Organisation
- **Employees** — Directory, profile fields (personal/emergency/bank/skills), documents, departments, manager hierarchy, soft delete, RBAC scoping by department
- **Positions / Grades / Steps** — Establishment register, position assignment, vacate, freeze, establishment ceilings
- **Identity Verification** — Ghana Card (NIA) adapter; biometric verification record; ghost-worker detection

#### Time & Attendance
- **Shifts** — Shift master, shift assignments
- **Attendance Records** — Clock-in / clock-out via biometric device webhook, web kiosk, GPS self-clock, manual entry
- **Public Holidays** — Calendar with auto-application to OT
- **Corrections** — Employee-submitted correction workflow with approval
- **Overtime** — Act 651-compliant overtime computation

#### Compensation
- **Payroll Runs** — Statutory engine (PAYE 7 brackets, SSNIT Tier-1 18.5% with NHIA split, Tier-2 5+5%, Tier-3 voluntary), draft → calculate → approve (2FA) → mark paid → reverse
- **Payslips** — Preview, generate (DomPDF), upload to cloud storage
- **Allowances & Deductions** — Per-employee recurring / one-off
- **Loans & Advances** — Loan products catalogue, application, decision (2FA), disbursement (2FA), amortisation, repayment via payroll
- **Disbursements** — Run dispatch to GhIPSS / MoMo, reconcile responses
- **Statutory Returns** — SSNIT, GRA-PAYE, NPRA Tier-2, NHIA exports

#### Lifecycle
- **Recruitment** — Public careers portal, job postings, applicants kanban, offer envelope (e-sign), Zoho contact sync
- **Off-boarding** — Cases, multi-area clearance items, final settlement calculation, approve (2FA), complete (2FA), cancel
- **Performance** — Cycles, goals, goal check-ins (AtRisk auto-flip on red checkin), reviews (draft → submit → acknowledge), 9-box calibration, performance contracts (dual signature), calibration sessions, PIPs

#### Service & Engagement
- **Tickets / Service Desk** — Queue, assign, resolve, priority, SLA
- **Complaints / Governance** — Auto-generated `CMP-` references, public tracking lookup, status workflow
- **Whistleblower** — Anonymous public submission, tracking via reference, investigator dashboard, triage (2FA), actions, messages, assignment (2FA)
- **Incident Reports** — Categorised log, assignment, attachments, messaging, close/reopen
- **Announcements** — Org-wide notice ticker

#### Compliance & Governance
- **Policies** — Library + versions + publish workflow + per-version acknowledgement tracking
- **Certifications** — Per-employee external certs with expiry reminders
- **Audit Logs** — Queued write, tamper-evident chain (hash + previous hash), RBAC-gated viewing
- **Auditor-General Reports** — Report pack generation (2FA), download
- **Privacy (DPA 2012)** — Subject self-service for access/erasure/portability, DPO admin queue (acknowledge → fulfil (2FA) / reject)

#### Learning & Development
- **Courses** — Catalogue, create/update/publish/destroy
- **Enrolments** — Self-enrol, progress tracking
- **Certifications** — Employee-tracked external/internal certs with expiry reminders
- **Skills Matrix** — Role-vs-staff heatmap

#### Assets
- **Asset Register** — Inventory with depreciation snapshots, maintenance, retirement, lost flagging
- **Assignments** — Issue / return per employee

#### Benefits
- **Plans** — Health / pension / wellness plan master
- **Enrolments** — Self enrol + dependants
- **Claims** — Submit → decide
- **E-cards** — Downloadable benefit cards

#### Documents
- **DMS** — Documents with versions, routing (sequential approval), annotations, signed-URL download, in-portal composer (HTML → PDF on institutional letterhead), format conversion

#### Communications
- **Notifications** — In-app + per-channel consent (email / SMS / WhatsApp / push)
- **Messaging** — Outbound SMS via Hubtel, inbound SMS callbacks, staff phone PINs
- **USSD** — Provider-callback driven menu sessions for low-bandwidth self-service
- **Webhooks** — Inbound (signed: WhatsApp, Zoho, e-sign, MS Graph, Google, Slack, biometric, Hubtel SMS/USSD); outbound (subscription registry per customer)

#### Integrations & Identity
- **OAuth Integrations** — WhatsApp Business, Zoho, e-sign provider, Microsoft Graph, Google, Slack
- **SSO** — SAML 2.0 + OIDC identity providers, login attempt log, identity links to existing users
- **2FA** — TOTP enrol/challenge/disable, `2fa:fresh` middleware gate on destructive operations

#### Platform
- **API v1** — Versioned REST surface, OpenAPI 3.x at `/api/v1/openapi.yaml`, Stoplight Elements docs at `/api/docs`, scoped API tokens (Sanctum)
- **PWA** — Service worker + manifest + `/offline` Blade fallback
- **Accessibility** — WCAG 2.1 AA pass, skip link, ARIA live announcer, audit command
- **I18n** — Per-user locale preference, lang files for en + Ghanaian languages (planned ee/ga/dag/tw)
- **Dashboard** — KPIs + sparklines + activity feed, cached per user

### 4.2 Cross-cutting capabilities

| Capability | What it does |
|---|---|
| **RBAC** | DB-backed roles/permissions + legacy `User.role` enum + per-user JSON overrides + department scoping |
| **Audit trail** | Every authenticated mutating request logged via queued `WriteAuditLog` job + tamper-evident hash chain |
| **Soft delete** | Every core entity supports recovery |
| **Caching** | Dashboard stats (60 s/user), permission resolution (60 s/user) |
| **Domain events** | Services emit events → queued listeners (analytics, notifications, integrations) |
| **Signed URLs** | All downloads (payslip, document, settlement) use temporary signed routes |
| **Rate limits** | Public endpoints (`careers.apply 5/min`, `kiosk 60/min`, `clock_self 10/min`, `whistleblower 6/min`, `sso 30/min`) |

---

## 5. Non-Functional Requirements (NFRs)

| Category | Requirement |
|---|---|
| **Performance** | p95 page TTFB < 400 ms on warm cache; payroll run for 5,000 employees ≤ 90 s |
| **Availability** | 99.5% monthly during business hours; planned-maintenance windows weekly off-peak |
| **Scalability** | Single Postgres primary up to 25,000 employees per tenant; Redis queue with horizontal worker scaling |
| **Security** | TLS 1.2+, signed cookies, CSRF, signed URLs, 2FA on destructive ops, tamper-evident audit |
| **Compliance** | DPA 2012 (Act 843), Cybersecurity Act 2020, Whistleblower Act 720, Labour Act 651, NPRA Act 766 |
| **Accessibility** | WCAG 2.1 AA (target AAA on auth flows); USSD/SMS reach for non-smartphone staff |
| **Localisation** | English default; Twi / Ewe / Ga / Dagbani planned; date/currency rendered as `GHS`, `Africa/Accra` |
| **Backup / DR** | Daily DB + storage snapshot; RPO ≤ 24 h, RTO ≤ 4 h |
| **Observability** | Sentry for errors, queue/horizon dashboards, signed-request audit, performance histograms |
| **Browser support** | Last 2 versions of Chromium, Firefox, Safari; PWA install on Android Chrome |

---

## 6. Key User Journeys (acceptance level)

### 6.1 Monthly payroll close (Finance Officer)
1. Open *Payroll → Runs*, create run for the period.
2. Click **Calculate** — engine resolves base + steps + allowances + deductions + statutory.
3. Review variance vs. last cycle; download draft payslips.
4. **Approve** (2FA challenge).
5. **Dispatch** disbursement run → GhIPSS / MoMo.
6. **Mark Paid** when bank receipts return; **Reconcile** any failed lines.
7. Generate statutory returns (SSNIT / GRA / NPRA / NHIA) and **Download**.

### 6.2 Employee self-service via USSD
1. Employee dials provider code, picks "CIHRMS".
2. Menu: 1. Payslip · 2. Leave balance · 3. Apply for leave · 4. Last attendance · 5. Submit complaint
3. Provider POSTs each step to `/webhooks/ussd` → `UssdSession` resumes → response.
4. Sensitive responses (payslip total) are sent by SMS link, not in the USSD reply.

### 6.3 Off-boarding (HR Officer + Department Heads + Finance)
1. HR Officer opens *Off-boarding* and initiates a case with LWD.
2. System auto-creates clearance items per area (IT / Library / Finance / HR / Admin).
3. Each area lead clears their item via the dashboard.
4. Once all cleared, HR calculates the **final settlement**; Finance **approves** (2FA).
5. HR **completes** the case (2FA); employee account is locked; assets re-pooled.

### 6.4 Anonymous whistleblower (Public)
1. Reporter opens `/whistleblower`, submits a categorised report.
2. System assigns reference `WB-XXXX`; reporter optionally sets a track-PIN.
3. Investigator triages (2FA), logs actions, exchanges messages without learning identity.
4. Reporter polls `/whistleblower/track` with reference + PIN to read updates.

### 6.5 Government audit pull (Auditor)
1. Auditor logs in via SSO with the `auditor` role.
2. Opens *Reports → Auditor-General*, generates pack for fiscal year (2FA).
3. Downloads the file (signed URL, 5-min expiry).
4. All read operations are themselves audit-logged with hash chained to the previous entry.

---

## 7. Out-of-Scope (explicit non-goals for this version)

- **Native iOS / Android apps** — PWA covers mobile for v2; native deferred.
- **General accounting GL beyond payroll** — GIFMIS export is the contract surface; CIHRMS does not become an ERP.
- **Recruitment psychometric / video interview** — out of scope; integration point only.
- **Live chat between employees** — service desk + WhatsApp DMs cover the urgent channel.
- **In-product AI Assistant generation of policies / contracts** — placeholder controller exists; full generation deferred to P7.

---

## 8. Success Metrics

| KPI | Target | Source |
|---|---|---|
| Time to onboard a new MDA | ≤ 30 days | Onboarding checklist + go-live signoff |
| Payroll cycle-time | ≤ 1 working day from cutoff to disbursement | `payroll_runs` timestamps |
| Audit-log integrity | 100% — every hash chain validates | `php artisan audit:verify` (planned) |
| Employee self-service adoption | ≥ 80% of leave + claim + payslip + clearance actions | analytics events |
| WCAG AA conformance | 0 critical issues on Axe report | `php artisan accessibility:audit` |
| Public whistleblower response time | ≤ 7 days first-action SLA | `whistleblower_actions` |
| DPA request fulfilment | ≤ 30 days statutory window | `data_subject_requests` |
| Mean ticket resolution | ≤ 24 h business hours | `tickets.resolved_at - created_at` |

---

## 9. Open Questions / Decisions to make

| # | Question | Owner | Due |
|---|---|---|---|
| Q1 | Final NITA hosting tier and connectivity SLA | Platform | Before P7 kickoff |
| Q2 | NPRA-licensed Tier-2 trustee selection per tenant | Finance | First MDA contract |
| Q3 | E-sign provider (DocuSign vs. PandaDoc vs. local) for offer envelope and performance contract | Product | P6 closeout |
| Q4 | AI Assistant LLM vendor + data-residency posture | Platform | Before P7 kickoff |
| Q5 | Statutory return submission API vs. portal upload (SSNIT/GRA) | Compliance | Per-MDA |

---

## 10. Dependencies & Risks

| ID | Description | Mitigation |
|---|---|---|
| D1 | NIA Ghana Card API availability + rate limits | Cache verification result; queue-retry; allow manual fallback |
| D2 | NPRA approval per tenant's chosen Tier-2 trustee | Configurable per tenant via `pension_trustees` |
| D3 | PHP 8.5 incompatibility in `laravel/pao` blocks local tests | Pin / drop the package; CI runs on 8.4 |
| D4 | SMS / USSD vendor (Hubtel) rate limits and pricing | Bulk billing window; throttle outbound; per-tenant budget |
| D5 | CSA registration timeline for cybersecurity authorisation | Begin during P6; production launch is contingent |
| R1 | Government procurement lead-time | Maintain commercial SaaS revenue from CIHRM Ghana itself |
| R2 | Currency volatility — disbursement FX | Disburse only in GHS; FX exposure is the tenant's |

---

## 11. Appendices

- **Appendix A — Ghana Statutory Constants (2026):**
  - **PAYE 2026:** 7 brackets, 0% on first GHS 490/month → 35% above GHS 50,000/month.
  - **SSNIT Tier 1:** employer 13%, employee 5.5% (= 18.5%); 2.5% routed to NHIA; 11% net Tier-1 pension.
  - **Tier 2:** 5% employer + 5% employee, mandatory since 2010, NPRA-licensed trustee.
  - **Tier 3:** voluntary, up to 16.5% combined, tax-relieved.
  - **Remittance deadline:** 14 days after month-end.
  - **E-Levy on MoMo disbursement:** 1.5%.

- **Appendix B — Companion documents:**
  - [TRD.md](TRD.md) — Technical Requirements Document
  - [SYSTEM_ARCHITECTURE.md](SYSTEM_ARCHITECTURE.md) — Architecture document
  - [SYSTEM_DESIGN_DIAGRAMS.md](SYSTEM_DESIGN_DIAGRAMS.md) — Mermaid diagrams
  - [PROJECT_STATE.md](PROJECT_STATE.md) — Live build status
  - [implementation_plan.md](implementation_plan.md) · [implementation_plan_2.md](implementation_plan_2.md) — Phase plans
  - [PHASE_1_DELIVERY.md](PHASE_1_DELIVERY.md) · [PHASE_2_TIME_ATTENDANCE_DELIVERY.md](PHASE_2_TIME_ATTENDANCE_DELIVERY.md) — Phase deliveries
  - [wcag_aa_checklist.md](wcag_aa_checklist.md) — Accessibility verification

- **Appendix C — Glossary:**
  - **CAGD** — Controller and Accountant-General's Department
  - **GIFMIS** — Ghana Integrated Financial Management Information System
  - **IPPD** — Integrated Personnel and Payroll Database (2/3)
  - **NIA** — National Identification Authority (issuer of the Ghana Card)
  - **NPRA** — National Pensions Regulatory Authority
  - **NITA** — National Information Technology Agency
  - **CSA** — Cyber Security Authority
  - **DPC** — Data Protection Commission (under DPA 2012, Act 843)
  - **CHRAJ** — Commission on Human Rights and Administrative Justice (whistleblower oversight)
