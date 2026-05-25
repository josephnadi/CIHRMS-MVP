# Chapter 1 — What CIHRMS is and who it's for

> *In one paragraph.* CIHRMS is a Ghana-focused, web-based Human Resource Management System built for institutions that need both the everyday HR feature set (employees, leave, payroll, attendance, performance) and the harder controls a public-sector employer can be audited against (Ghana Card identity verification, NPRA three-tier pensions, IPPD-compatible payroll, GIFMIS-ready exports, Auditor-General report packs, tamper-evident audit trails). It runs as a single tenant per organisation on a Laravel + Vue stack, ships with thirty-two modules already wired end-to-end, is hardened by 973 passing automated tests, and is designed equally for Ministries, Departments and Agencies, mid-to-large private employers, and international donor programmes that fund any of the above. This chapter is the orientation. By the time you finish it you'll know what the product is, who it's aimed at, what makes it different from the off-the-shelf HRMS market, and how to navigate the rest of this dossier.

## What CIHRMS is

CIHRMS — the **Chartered Institute of Human Resource Management Ghana System** — is a single-tenant, web-based HR platform. You install it once per organisation, point it at a Postgres database, and from that moment one URL handles every HR interaction in the institution: every leave application, every payslip, every disciplinary case, every onboarding form, every Ghana Card check, every monthly pay run, every donor-funded training enrolment.

The stack is deliberately boring on the surface and considered underneath:

- **Backend** — Laravel 13.8 on PHP 8.3+, with a strict architecture pattern (Enum → FormRequest → Service → Event → Listener → Resource → Inertia Page) enforced across every module. You'll see this pattern walked module by module in Part II, Chapter 37.
- **Frontend** — Vue 3 with Inertia.js v2 and Tailwind CSS v3, served from the same Laravel app. There is no separate SPA build, no API/UI version drift, no CORS surface to defend. What the user sees and what the database stores are one deployment.
- **Database** — PostgreSQL in production (SQLite in development), with sixty-six migrations describing every table the platform owns. The schema is documented in Chapter 38.
- **Auth** — Staff ID + password (not email + password), with TOTP-based 2FA available on every account and *required* on every destructive operation (payroll approval, off-boarding final settlement, journal posting, whistleblower triage, identity verification override).
- **Tenancy** — One organisation per installation. There is no multi-tenant database mode and no sub-tenant trick. If the Ministry of Health and the Ministry of Education both adopt CIHRMS, they run two separate installations on two separate databases. The reasoning is statutory: Ghana's Data Protection Act 843 treats every employer as a distinct data controller, and a shared database would entangle controllers in ways the law does not contemplate.

The first version was scoped as a Minimum Viable Product covering the eight commercial HRMS basics. Over six delivery phases (P0 → P6), the scope was deliberately pushed up the maturity curve toward the controls that a Ghanaian public-sector buyer is required to demonstrate: identity verification against the Ghana Card register, payroll computation against NPRA's three-tier pension regime, disbursement through GhIPSS and mobile-money rails, a whistleblower channel compliant with Act 720, and a tamper-evident audit chain that the Auditor-General's office can verify. As of this snapshot, the first six phases are shipped; the seventh ("government end-state") is the active roadmap, and you'll see it referenced as future work in every chapter that touches it.

## Who CIHRMS is for

Three audiences sit at the centre of the design. They are not equally weighted, but each shapes decisions you'll see surfaced across Part I.

### Primary — Ghana public-sector MDAs

The primary buyer is a Ministry, Department or Agency of the Government of Ghana. That decision drives most of what is unusual about the product. A public-sector HR officer needs to verify a new hire's Ghana Card before they go on payroll (because the Ghost Worker problem is real and Auditor-General reports name names). They need a payroll that produces a SSNIT Tier-1 return, an NPRA Tier-2 return, a GRA-PAYE return, and an NHIA contribution split on a single approved run. They need every disbursement to be traceable to a GIFMIS chart-of-accounts code. They need the whistleblower channel mandated by Act 720, the data-subject access requests mandated by Act 843, and the audit pack the Auditor-General will ask for at the end of the financial year. CIHRMS treats each of those as a first-class module — not a configurable add-on, not a custom build for the lead customer, but a permanent part of the product.

### Adjacent — commercial buyers

The same platform sells, unchanged, to mid-to-large private employers, NGOs operating in Ghana, and professional bodies (the founding customer, the Chartered Institute of Human Resource Management Ghana, is one). A bank, a brewery, a 300-staff law firm, or a 1,200-staff agribusiness can switch on exactly the modules that apply to them and ignore the rest. The public-sector compliance modules (Ghana Card, NPRA, GIFMIS) are not in the way — they are present, but a commercial buyer can leave them dormant or configure them out of the sidebar via the role system in Chapter 39. What a commercial buyer *gets for free* is a stack that has been hardened against a much harder set of controls than the typical commercial HRMS faces: 973 automated tests, a tamper-evident audit trail, signed-URL downloads, queued audit writes, 2FA gates on every destructive action. The commercial deployment inherits the rigour of the public-sector deployment without paying for the rigour separately.

### Adjacent — international donor programme officers

A third audience reads this dossier without ever being its user: programme officers at the World Bank, GIZ, USAID, FCDO, the EU delegation, and the African Development Bank. Donor-funded HR strengthening programmes typically arrive at a Ministry with two questions: *will the candidate platform meet our fiduciary controls?* and *will it generate the reports our own M&E framework requires?* CIHRMS is built so that the answer to both questions is yes, and the evidence is in the dossier you are reading. You'll find ISO 30414 disclosures in every relevant chapter, DPA-2012 and Act-720 anchors named in their chapters, and a dedicated Auditor-General report pack (Chapter 24) that produces the exact document a donor's grant agreement typically requires.

## The thirty-two modules at a glance

Part I of this dossier walks every shipped module, one chapter each. They are grouped here by what they do, with a single line each. The chapter number in brackets tells you where to find the deep walk-through.

**People & Organisation**
- **Employees** (Ch 3) — the single master record of every person in the institution, with personal, emergency, bank, identity, and skills fields.
- **Departments** (Ch 30) — the org-chart tree that scopes RBAC, payroll cost centres, and reporting hierarchies.
- **Establishment** (Ch 8) — positions, grades, steps and headcount ceilings; the discipline that turns "we have 1,200 staff" into "we have 1,200 of an authorised 1,250 across these 14 grades".
- **Profile Portal** (Ch 32) — the employee-self-service face onto the same Employee row HR edits.

**Time & Lifecycle**
- **Leave** (Ch 4) — request, balance, accrual, approval, calendar, audit.
- **Attendance** (Ch 5) — shifts, clock-in/out (kiosk, biometric, GPS, manual), corrections, overtime under Act 651.
- **Recruitment** (Ch 9) — public careers page, applicant kanban, offer envelope with e-sign, Zoho contact sync.
- **Off-boarding** (Ch 10) — multi-area clearance, final settlement, last-working-day countdown, 2FA-gated completion.

**Service & Engagement**
- **Tickets** (Ch 11) — internal service desk with priority and SLA.
- **Complaints** (Ch 12) — auto-referenced complaint log with public tracking lookup.
- **Chat** (Ch 14) — 1:1 messaging with day separators and polling-based delivery.
- **Announcements** (Ch 17) — org-wide notice ticker.
- **Notifications** (Ch 16) — in-app inbox with per-user channel consent (email, SMS, WhatsApp, push).
- **Messaging Administration** (Ch 15) — outbound SMS via Hubtel, inbound SMS callbacks, USSD session handling.

**Performance & Development**
- **Performance** (Ch 6) — cycles, goals with check-ins, reviews with dual signature, 9-box calibration, PIPs.
- **Learning** (Ch 7) — course catalogue, enrolment, certification register with expiry reminders, skills matrix.

**Compensation & Money**
- **Payments** (Ch 18) — individual employee payments, payslip preview and generation.
- **Payroll Engine** (Ch 19) — statutory engine for PAYE, SSNIT Tier-1, NPRA Tier-2/3, NHIA, with draft → calculate → approve (2FA) → mark paid → reverse states.
- **Loans & Advances** (Ch 21) — loan products, applications, decisions (2FA), amortisation, payroll-deducted repayment.
- **Disbursements** (Ch 22) — GhIPSS ACH and mobile-money rails, with reconciliation of provider responses.
- **Benefits** (Ch 23) — plan master, enrolment, dependants, claims, downloadable e-cards.

**Finance**
- **Finance** (Ch 20) — full F1–F5 stack: Chart of Accounts and Org Bank Accounts, Accounts Payable with journal engine, Accounts Receivable with customer statements, Paystack hosted-checkout gateway with signature-verified webhooks, and Bank Reconciliation with three-tier matching against CSV/OFX/MT940 imports.

**Compliance & Governance**
- **Audit Logs** (Ch 24) — queued write, tamper-evident hash chain, RBAC-gated viewing.
- **Identity Verification & Ghana Card** (Ch 25) — NIA adapter, twelve-month expiry, encrypted-PIN storage, used as the first gate of every payroll run.
- **Data Protection (Act 843) & Privacy** (Ch 26) — subject self-service for access/erasure/portability requests, DPO admin queue, 2FA-gated fulfilment.
- **Whistleblower** (Ch 27) — Act-720-compliant confidential channel, anonymous submission, reference-tracked investigator dashboard.
- **Governance** (Ch 28) — policy register with versioned acknowledgements, certifications, incident reports.

**Documents & Workspace**
- **Documents** (Ch 13) — DMS with versions, routed approval, manipulable annotations (signatures and stamps), stamp/letterhead/watermark asset libraries, signed-URL downloads, in-portal composer.
- **Kiosk** (Ch 29) — shared-device attendance terminal with face-scan posture.

**Insight & Configuration**
- **Reports & Analytics** (Ch 31) — KPI dashboards, sparkline previews, XLSX export.
- **Settings** (Ch 33) — org-level configuration: branding, sound packs, asset libraries, integrations.
- **Role-by-Role Tours** (Ch 34) — what the platform looks like through each of the twelve role lenses.
- **Cross-Cutting Features** (Ch 35) — RBAC in everyday use, sound packs, animation grammar, accessibility, search, AI Assistant, public pre-login pages, the realtime story.

That is thirty-two module chapters, plus Chapter 2 (this part's introduction is Chapter 1, the executive summary is Chapter 2) at the front of Part I, and the engineering deep-dive chapters from 36 onwards in Part II.

## What makes CIHRMS different

The HRMS market is crowded. Three things distinguish CIHRMS from what an MDA or a Ghanaian commercial buyer would otherwise be looking at.

### It is built for Ghana, not localised to Ghana

Most of the HRMS products an MDA evaluates were built for a different market and then "localised" — usually by adding a currency dropdown and a tax-rate table. CIHRMS was designed from the first commit to fit the Ghanaian regulatory and operational shape:

- **Staff ID login, not email login.** Public-sector staff are issued a staff number on day one; many do not have an institutional email until weeks later, and many never have a personal email they will reliably check. The whole platform authenticates on `staff_id` + password, with email as an optional contact channel. You'll see this everywhere in Part I — every login flow, every password-reset path, every audit row.
- **Ghana Card baked into onboarding.** The Ghana Card PIN field is present on the Employee record from the start, the NIA adapter is a Phase-1 module (Chapter 25), and the Payroll Engine refuses to compute a pay line for an employee whose Ghana Card identity has not been verified in the last twelve months. Ghost-worker prevention is not a feature you turn on; it is the default refusal posture of the payroll engine.
- **NPRA three-tier pensions are first-class.** The payroll engine in Chapter 19 computes SSNIT Tier-1 (11% employer / 5.5% employee, with the NHIA split inside the employer share), NPRA Tier-2 (5% + 5% mandatory occupational), and Tier-3 (voluntary) on every run. The Tier-2 trustee reference is a field on the Employee record. There is no "pension module" to configure — there is a pension regime, and it runs every payroll.
- **GhIPSS ACH and mobile-money disbursement, not generic bank export.** Chapter 22 walks the disbursement engine: a run-dispatch surface that talks to GhIPSS for bank transfers and to the mobile-money providers (MTN, AirtelTigo, Vodafone) for the unbanked workforce. The disbursement record links to a payment intent, the payment intent links to a payroll run line, and the payroll run line links to the employee — one chain, fully reconciled.
- **E-Levy disclosure where it applies.** Payments and disbursements through mobile-money rails surface the E-Levy implication on the payslip and on the disbursement record, so the employee sees the deduction before it is taken and the employer documents the disclosure for the GRA.
- **IPPD-compatible payroll and GIFMIS-ready exports.** The payroll engine emits an export the IPPD2/IPPD3 reconciliation expects, and every monetary movement is journalled through the Finance engine in Chapter 20 against a GIFMIS-compatible chart of accounts. The exports are not glued on at the end of a pay run; they are the same data the journal engine has already booked.

### It is engineered to commercial-grade standards

The second differentiator is rigour. The dossier you are reading documents a platform that, at the time of this snapshot, runs:

- **973 automated tests, all passing.** Pest 4 feature tests cover every module — auth, employees, leave, tickets, complaints, recruitment, performance, payments, payroll, documents, finance F1–F5, signature verification for all six integration providers (Paystack, WhatsApp, Zoho, e-sign, MS Graph, Google, Slack), and every policy denial path. Chapter 42 walks the strategy.
- **121 services**, each one the single owner of its domain's business logic. No controller writes to the database directly; every mutation passes through a service that emits a domain event the rest of the system listens to. The pattern is forced — Chapter 37 explains how.
- **A tamper-evident audit chain.** Every authenticated mutating request is logged via a queued `WriteAuditLog` job, with a SHA-256 hash linking each row to its predecessor. An auditor who suspects a row has been altered can verify the chain in Chapter 24's report pack.
- **A V2 audit hardening pass shipped in May 2026.** Sixty-five market-readiness items, twelve pull requests, all merged. The audit replaced count-and-add reference generation with a `SequenceService` (closing a race-condition gap), enforced that admin-created users always get a paired Employee row, mirrored CEO permissions on super_admin so the two roles never drift, and made password a required field at create (closing a silent default-password gap). You'll see these patterns named as canonical in every module chapter.

### It ships the full finance stack — which is rare for an HRMS

Most HRMS products stop at "send the gross payroll number to a bookkeeping system". CIHRMS does not stop there. The Finance module (Chapter 20) is a five-phase build-out covering Chart of Accounts and Org Bank Accounts, Accounts Payable with a journal engine, Accounts Receivable with customer statements, Paystack hosted-checkout payment intents with signature-verified webhooks, and Bank Reconciliation with three-tier matching of CSV/OFX/MT940 imports against the journal. For an institution that does not already run a separate ERP, CIHRMS is the ERP for HR-driven money movements. For one that does, the journal is exportable to GIFMIS in the format the Treasury expects.

### Public-sector compliance modules are already shipped

The four modules that most often kill an HRMS deal with an MDA are already built, not on the roadmap:

- **Identity Verification & Ghana Card** (Ch 25) — NIA-aligned, encrypted PIN, twelve-month expiry, audit-grade.
- **Data Protection** (Ch 26) — DPA Act 843 subject self-service for access, erasure, and portability requests, with DPO admin queue and 2FA-gated fulfilment.
- **Whistleblower** (Ch 27) — Act 720 confidential channel with anonymous submission and reference-tracked investigator dashboard.
- **Auditor-General Report Pack** (Ch 24) — generated on demand, byte-identical to the official template, ready for the audit close.

## How to read the rest of this dossier

This dossier is organised as a two-part document with an addendum.

**Part I — Modules** (this part) walks every shipped feature in plain-English advocate voice. You're reading it now. Each chapter follows the same shape: a one-paragraph summary, a "Where to find it" anchor section, an illustrated screen walk-through, an exhaustive "Every button, every action" table, a "The data behind it" section, a "How it talks to other modules" section, a "Standards touchpoints" section that names every international standard or Ghanaian statute the module answers to, and a "What's planned next" section. The chapter on Employees (Chapter 3) is the canonical example — if you only have ten minutes, read that one first.

**Part II — Engineering** (Chapters 36–42) shifts voice. It is written for the peer engineer, the technical due-diligence reviewer, and the architect evaluating whether to adopt the platform. It covers the stack, the canonical pattern, the data model, RBAC, security, performance and testing — at the depth a senior engineer needs to make a build-or-buy call.

**The front matter and reader's map** (Chapter 0 and the addendum) tells the donor officer, the procurement reviewer, and the new HR officer which chapters to read first for *their* job. If you are a Ministry's Chief Director, the reader's map sends you to Chapters 1, 2, 31, 34 and the executive summary in the front matter. If you are a programme officer at a donor, it sends you to Chapters 1, 25, 26, 27 and 24. If you are an HR officer about to operate the system on Monday, it sends you to Chapters 3, 4, 18, 19 and 32, in that order.

Every chapter is self-contained — you can read Chapter 19 without having read Chapter 18 — but the "How it talks to other modules" section in each chapter names every related chapter, so you can follow a thread from one end of the system to the other.

The rest of this dossier is, in the most literal sense, the manual. By the time you finish it you should know not only what CIHRMS does but also why each thing is built the way it is, what statute or standard each thing answers to, and what is coming next.

Welcome to the walk-through.
