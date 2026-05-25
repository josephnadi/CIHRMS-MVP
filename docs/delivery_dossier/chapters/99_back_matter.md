# Glossary — Ghana-specific terms {.unnumbered}

Acronyms, agencies and statutory references used throughout this dossier.

| Term | Expansion / Meaning |
|---|---|
| Act 456 | Commission on Human Rights and Administrative Justice Act, 1993 (CHRAJ constitutive law) |
| Act 584 | Audit Service Act, 2000 (Auditor-General's mandate, §13 record-keeping) |
| Act 651 | Labour Act, 2003 (hours, overtime, leave, termination, grievance, deduction limits) |
| Act 707 | National Identity Register Act, 2008 (NIA Ghana Card mandate) |
| Act 715 | Persons with Disability Act, 2006 (accessibility duty) |
| Act 720 | Whistleblower Act, 2006 (protected disclosures, §10 confidentiality, §11 anti-retaliation) |
| Act 723 | Foreign Exchange Act, 2006 (BoG FX regime) |
| Act 750 | National Identity Register Act, 2008 (register operation) |
| Act 766 | National Pensions Act, 2008 (NPRA three-tier scheme, §92 7-year retention) |
| Act 772 | Electronic Transactions Act, 2008 (§10 electronic signatures admissibility) |
| Act 773 | Borrowers and Lenders Act, 2008 (§11 rate disclosure) |
| Act 843 | Data Protection Act, 2012 (DPA — §§17-22 subject rights, §27 security, §39 sensitive data, §40 retention, §44 breach, §46 registration) |
| Act 852 | National Health Insurance Act, 2012 (NHIA §29 contribution) |
| Act 883 | Pensions (Amendment) Act, 2014 (Tier-3 portability) |
| Act 884 | Bank of Ghana Act, 2016 |
| Act 896 | Income Tax Act, 2015 (§116 PAYE, Sixth Schedule brackets) |
| Act 921 | Public Financial Management Act, 2016 (§11 internal controls / separation of duties, §52 record-keeping) |
| Act 1038 | Cybersecurity Act, 2020 (§41 record-keeping, §59 CII protection and incident reporting) |
| Act 1075 | Electronic Transfer Levy Act, 2022 (E-Levy at 1.5% on MoMo channels) |
| AG | Auditor-General of Ghana (Constitutional office under Article 187) |
| CAGD | Controller and Accountant-General's Department (custodian of IPPD payroll format) |
| CHRAJ | Commission on Human Rights and Administrative Justice |
| CSA | Cyber Security Authority (regulator under Act 1038) |
| DPA | Data Protection Act, 2012 (Act 843) |
| DPC | Data Protection Commission (regulator under Act 843) |
| E-Levy | Electronic Transfer Levy at 1.5% on mobile money under Act 1075 |
| GhIPSS | Ghana Interbank Payment and Settlement Systems (ACH operator) |
| GIFMIS | Ghana Integrated Financial Management Information System (Treasury ledger) |
| GRA | Ghana Revenue Authority (PAYE / VAT / Tier-3 voluntary returns) |
| IPPD2 / IPPD3 | Integrated Personnel and Payroll Database, 2nd / 3rd generation (CAGD payroll bridge) |
| MDA | Ministry, Department, Agency (Government of Ghana hierarchy) |
| MoMo | Mobile Money (MTN, Vodafone Cash, AirtelTigo Money) |
| NCA | National Communications Authority (sender-ID and short-code regulator) |
| NHIA | National Health Insurance Authority (2.5% allocation from SSNIT contribution) |
| NIA | National Identification Authority (issuer of the Ghana Card) |
| NITA | National Information Technology Agency |
| NPRA | National Pensions Regulatory Authority |
| NRCD 323 | Evidence Decree, 1975 (§3 electronic-document admissibility) |
| PFM | Public Financial Management Act 921 |
| PNDCL 247 | SSNIT-establishing law (§64 remittance) |
| PSC | Public Services Commission (HR governance for civil service) |
| Single Spine | Single Spine Salary Structure, 2010 (uniform pay scale GS-08 to GS-20) |
| SSNIT | Social Security and National Insurance Trust (Tier-1 administrator) |
| Tier-1 / Tier-2 / Tier-3 | NPRA pension tiers — basic (SSNIT 13.5%), mandatory occupational (5% to trustees), voluntary (capped 16.5% combined) |

\newpage

# Glossary — Technical terms {.unnumbered}

Engineering vocabulary used throughout the Delivery Dossier.

| Term | Definition |
|---|---|
| 2fa:fresh | Laravel middleware requiring a TOTP re-prompt within the last 15 minutes; gates every cash-moving, identity-mutating, and statutory-export endpoint |
| AriaLiveAnnouncer | Vue 3 component that exposes a polite `aria-live` region for SPA navigation announcements |
| AuditTrail middleware | HTTP middleware that captures every mutating request and queues a `WriteAuditLog` job on the `audit` queue |
| Canonical pattern | The Enum → FormRequest → Service → Event → Listener → Resource pipeline every CIHRMS module follows (Chapter 37) |
| Effective-dated row | A table row carrying `effective_from` / `effective_to` columns so historical reads can reconstruct the rate-of-record on any prior date (used for tax brackets, grade-step salaries, statutory rates) |
| Enum | PHP 8.1 backed enum used in CIHRMS for status machines, role kinds, channel kinds, and journal-source types |
| Event / Listener | Domain event dispatched by a Service; one or more queued Listeners react asynchronously (analytics, notifications, webhooks, audit) |
| FakeLlmProvider | In-test substitute for the Anthropic provider; returns deterministic responses without an API key |
| FormRequest | Laravel request class encapsulating authorisation + validation; ALL input validation lives here, NEVER inline `$request->validate()` |
| Hash chain | Tamper-evident audit-log structure: each row stores `previous_hash` + `row_hash`; nightly verifier walks the chain detecting any retroactive mutation |
| Horizon | Laravel queue dashboard (Phase 1 dependency after Redis migration) |
| Inertia.js v2 | SPA bridge — Laravel controllers return Vue page components with shared props; deferred props enable lazy-loaded panels |
| JournalPostingService | Sole mutator of `gl_account_balances` (Ch 20); enforces debit=credit invariant at every posting |
| lockForUpdate | Pessimistic row lock via `SELECT ... FOR UPDATE`; used on leave balances, ceiling counters, sequence allocators, allocation rows |
| Materialized annotation | Document annotation (signature, stamp, text, initial) stored as a moveable record on the document and only burned into the PDF when the routing slip closes |
| Pest | PHP testing framework layered on PHPUnit; CIHRMS uses 182 Feature + 10 Unit tests |
| PerUser permissions JSON | `users.permissions` JSON column carrying additional grants beyond the role pivot; merged at `User::hasPermission()` |
| Policy | Laravel authorization class; mediates module access (`view`, `manage`, `view_all`, etc.); 25 policies in CIHRMS |
| PreventLazyLoading | Eloquent guard that throws if a relation is touched without an explicit `with()`; used in non-production to surface N+1 |
| Resource | API-shape transformer that hides server columns and gates fields by viewer permission (e.g. salary omitted without `employees.view_salary`) |
| RBAC | Three-layer role-based access control: legacy `users.role` enum + DB-backed roles/permissions pivot + per-user JSON overlay |
| Sanctum | Laravel package providing both cookie sessions (web) and personal access tokens (API) |
| SequenceService | Single allocator of monotonic reference numbers; `SequenceService::next($key, $padding, $year)` replaces `count()+1` in race-prone code paths |
| Service class | Per-module orchestrator that holds business logic and is the ONLY caller of cross-table writes; controllers and listeners delegate to it |
| Soft delete | `deleted_at` timestamp instead of row removal; preserves history while hiding from default scopes |
| TCPDF | PHP PDF library used for in-portal letterheaded composer; dompdf used for payslips/certificates |
| TOTP | Time-based one-time password (RFC 6238); the sole 2FA method in CIHRMS |
| WriteAuditLog | Queued job (audit queue) that hashes payload + previous row, allocates `chain_position`, persists tamper-evident row |

\newpage

# Module index {.unnumbered}

Every chapter, in printed order, with the one-line description of its subject.

## Part I — Modules and Features

| # | Module | One-line description |
|---|---|---|
| 1 | What CIHRMS is and who it's for | Product statement, intended operator profile, scope of the dossier |
| 2 | The Sovereign Precision design language | Visual system, typography, animation grammar, accessibility posture |
| 3 | Employees | Master directory, SID-NNNNNN staff IDs, masked Ghana Card display, salary visibility gating |
| 4 | Leave | Seven Labour-Act-aligned types, balance locking, manager approval, calendar with Ghana holidays |
| 5 | Attendance | Unified record path for biometric/kiosk/web/manual; Labour Act §35 overtime; signed device webhooks |
| 6 | Performance management | Cycles, goals, 9-Box, PSC contracts, calibration, PIPs, weighted achievement |
| 7 | Learning & development | Course catalogue, skill matrix, external certifications, completion-triggered skill grant |
| 8 | Establishment — positions, grades, steps | Single-Spine grade structure, ceilings, step-increment automation, freeze/vacate |
| 9 | Recruitment + Public Careers | Public apply, kanban pipeline, e-sign offer letters, CV pipeline |
| 10 | Offboarding & clearance | Settlement with §31 severance, dual-control, loan netting, 2FA-gated approval |
| 11 | Tickets (Service Desk) | Kanban + list, four-state lifecycle, SLA export plumbing |
| 12 | Complaints | Public track-by-reference, anonymous-permitted, separate from Whistleblower |
| 13 | Documents | Manipulable annotations, three-scope asset libraries, confidentiality classes, immutable event log |
| 14 | Chat | 1:1 threads, post-redesign single-column directory, polling realtime |
| 15 | Messaging (administrative) | Pluggable SMS dispatcher, USSD self-service, WhatsApp Cloud webhook, signed inbound |
| 16 | Notifications | Bell, archive, per-channel preferences, three sound packs, WCAG-respecting motion |
| 17 | Announcements | Live ticker, audience scoping, composite layering, ARIA-live, reduced-motion fallback |
| 18 | Payments (one-off ledger) | Quick payment, per-payslip Ghana statutory breakdown, SSNIT/PAYE reminders |
| 19 | Payroll engine | Period-locked dual-approval runs; PAYE/SSNIT/NHIA/Tier-2/Tier-3 calculators; IPPD + GIFMIS exporters |
| 20 | Finance F1-F5 | Chart of Accounts, AP, AR, Paystack hosted checkout, Bank Reconciliation with three-tier matcher |
| 21 | Loans & advances | Six products, amortisation engine, dual-control approval, two-phase payroll integration |
| 22 | Disbursements | GhIPSS + MoMo + Vodafone + AirtelTigo rails, E-Levy applied, idempotent materialisation |
| 23 | Benefits | Plan catalogue, enrolment with dependants, four-state claims, e-card PDFs |
| 24 | Audit logs | SHA-256 hash chain, nightly verifier, Auditor-General Report Pack |
| 25 | Identity verification & Ghana Card | Three providers, masked card display, payroll gate, 12-month re-verification |
| 26 | Data Protection (DPA) & Privacy | Public portal, six Act 843 rights, 30-day SLA, tamper-evident export ZIP |
| 27 | Whistleblower (CHRAJ-aligned) | Anonymous-default, encrypted-at-rest, one-way closure, tracking-code |
| 28 | Governance | Policy versioning, acknowledgement ledger, certifications register, incident reports |
| 29 | Kiosk | Shared-device clock with three-stage flow, on-screen keyboard, first-name-only wall |
| 30 | Departments | Card-grid register, head assignment, eight portal landing pages, scoped visibility |
| 31 | Reports & analytics | Five XLSX exports, role-targeted dashboards, append-only analytics events |
| 32 | Profile / Self-service portal | Seven-tab employee portal, three separate write endpoints, channel preferences cross-link |
| 33 | Settings | Asset libraries, API token admin, webhook subscriptions, OpenAPI explorer |
| 34 | Role-by-role tours | Nine roles, two nav-shell sizes, per-role dashboards, three documented cross-role handoffs |
| 35 | Cross-cutting features | Three-layer RBAC, animation grammar, AI Assistant, polling realtime, public surfaces |

## Part II — Engineering Annex

| # | Chapter | Subject |
|---|---|---|
| 36 | Architecture & stack | Laravel 13.7 + Vue 3 + Inertia v2 + Tailwind; 424 routes, 116 migrations, 124 models, 121 services |
| 37 | Canonical pattern | Enum → FormRequest → Service → Event → Listener → Resource recipe applied across every module |
| 38 | Data model & migrations | Schema topology, soft-delete conventions, effective-dated tables, FK strategy |
| 39 | RBAC, policies, per-user permissions | Three-layer authorisation model and the 25 policies that enforce it |
| 40 | Security | 2FA + 2fa:fresh + audit chain + Sanctum + sanitised payloads + signed webhooks |
| 41 | Performance, caching, queues, jobs | 60s caches, five named queues, deferred Inertia props, polling cadence |
| 42 | Testing strategy | Pest Feature/Unit split, per-user JSON permissions in tests, FakeLlmProvider, AssertWithoutErrors |
| 43 | Deployment & operations | SQLite-to-Postgres path, scheduler entries, queue worker topology, backups |

## Part III — Standards & Market Readiness

| # | Chapter | Subject |
|---|---|---|
| 44 | Standards benchmark | Per-framework status table against actual shipped code (rewritten from audit JSON) |
| 45 | Why we are ready for market | The market-readiness argument grounded in the audited scope |
| 46 | What's left and how to get there | Four-phase roadmap, each Phase keyed to specific shipped/partial/gap items |
| 47 | Funding and sequencing | Effort estimates, cost ranges, recommended sequencing |

## Back Matter

| Section | Contents |
|---|---|
| Glossary — Ghana terms | Statutory references, agency acronyms, NIA/SSNIT/NPRA/GRA/GhIPSS/MoMo |
| Glossary — Technical terms | Laravel + Vue + Inertia + Pest + audit-chain + RBAC vocabulary |
| Module index | This table |
| Standards cross-reference | Per-framework status with chapter and closing-phase pointers |
| Change log | Pointer to `docs/delivery_dossier/CHANGELOG.md` in source repo |
| About this document | Title, version, source repo, rebuild command |

\newpage

# Standards cross-reference {.unnumbered}

Every regulatory and engineering framework the CIHRMS dossier touches, with the status the audit recorded on 2026-05-25 and the phase that closes any remaining gap. Cells marked **met** are fully implemented; **partial** is shipped-but-incomplete; **stub** is design-only.

## Ghana statutory & regulatory frameworks

| Framework | Status today | Phase that closes the gap | Chapter |
|---|---|---|---|
| IPPD2 / IPPD3 CAGD payroll | met | — (shipped) | 19 |
| GIFMIS sub-ledger JV format | met | Phase 3 (live REST push) | 19, 20 |
| Ghana Card / NIA Act 707 | partial | Phase 1 (extend gate to Loans/Disbursements/Offboarding); Phase 3 (certified NIA IVS SDK) | 25 |
| NPRA three-tier pensions (Act 766) | partial | Phase 1 (Tier-3 statutory return generator) | 19 |
| GRA PAYE (Act 896 §116) | met | — (shipped) | 19 |
| SSNIT Tier-1 (PNDCL 247 §64) | met | — (shipped) | 19 |
| NHIA (Act 852 §29) | met | — (shipped) | 19 |
| E-Levy (Act 1075) | partial | Phase 1 (disclose `e_levy` on payslip) | 22 |
| Labour Act 651 §35 overtime | met | — (shipped) | 5 |
| Labour Act 651 §17/§18/§31 termination | partial | Phase 1 (pay-frequency-aware notice validation) | 10 |
| Labour Act 651 §57 maternity | met | — (shipped) | 4 |
| Labour Act 651 §63 PIP before termination | met | — (shipped) | 6 |
| Labour Act 651 §64-§67 grievance | partial | Phase 2 (SLA timer + statutory clock enforcement) | 12 |
| Labour Act 651 §70 wage-deduction limits | met | — (shipped) | 19 |
| DPA Act 843 (data subject rights) | partial | Phase 4 (breach-notification workflow + DPC registration metadata + retention table) | 26 |
| Cybersecurity Act 1038 | partial | Phase 3 (CSA §59 incident-reporting integration) | 40, 24, 27 |
| Whistleblower Act 720 | partial | Phase 2 (log-scrubbing + CAPTCHA + retention schedule + CHRAJ transfer-of-custody hook) | 27 |
| CHRAJ Act 456 | partial | Phase 2 (automated transfer-of-custody) | 27 |
| Borrowers and Lenders Act 773 §11 | met | — (shipped) | 21 |
| Bank of Ghana lending guidelines (DTI) | partial | Phase 2 (LoanEligibilityService DTI enforcement) | 21 |
| NCA sender-ID + USSD code provisioning | partial | Phase 1 (operational registration) | 15 |
| Public Services Commission framework | partial | Phase 1 (mandatory-training engine, 14-day clock on grievance) | 6, 7, 8, 12 |
| PFM Act 921 §11 internal controls | met | — (shipped) | 19, 20, 10 |
| Single Spine Salary Structure 2010 | partial | Phase 1 (production CSV loader + AG reconciliation report) | 8 |
| Auditor-General Act 584 §13 | met | — (shipped) | 24 |
| Persons with Disability Act 715 | partial | Phase 2 (third-party WCAG audit + public Accessibility Statement) | 35 |
| Audit Service Act 584 / Article 187 | met | — (shipped) | 24, 31 |

## International standards & engineering frameworks

| Framework | Status today | Phase that closes the gap | Chapter |
|---|---|---|---|
| ISO 30414 (HR reporting) | partial | Phase 1 (saved-definition canvas + per-FTE training cost) | 31 |
| ISO/IEC 27001:2022 | partial | Phase 2 (formal certification + third-party pen test) | 40, 24, 39 |
| ISO/IEC 27001 A.5.15 access control | met | — (shipped) | 35, 39 |
| ISO/IEC 27001 A.12.4 logging | met | — (shipped) | 24 |
| ISO/IEC 27001 A.13 communications | partial | Phase 2 (chat at-rest encryption) | 14, 15 |
| ISO/IEC 27001 A.18.1 legal compliance | met | — (shipped) | 26 |
| ISO/IEC 20000-1 §8.6 incident management | partial | Phase 1 (major-incident pathway, SLA matrix) | 11 |
| ISO/IEC 29115 authentication assurance | met | — (shipped) | 25 |
| ISO 10002 complaints handling | partial | Phase 2 (events + notification fan-out + SLA timer) | 12 |
| ISO 31000 risk management | partial | Phase 1 (risk register table + `/governance/risks`) | 28 |
| ISO 37001 anti-bribery | partial | Phase 2 (recurring declaration acknowledgement) | 28 |
| ISO 37002 whistleblowing management | met | — (shipped) | 27 |
| ITIL 4 incident & request | partial | Phase 1 (SLA breach escalation) | 11 |
| WCAG 2.1 AA | partial | Phase 2 (third-party axe + NVDA + VoiceOver audit, Accessibility Statement) | 35 |
| GDPR parity (EU 2016/679) | partial | Phase 4 (Art 33 72-hour breach + Art 35 DPIA workflow) | 26 |
| IFRS general principles (IAS 1/IAS 8) | met | — (shipped) | 20 |
| IFRS 9 expected credit loss | partial | Phase 2 (explicit ECL provisioning model) | 20 |
| Balanced Scorecard (Kaplan & Norton) | met | — (shipped) | 6 |
| PCI DSS | met | — (no card data processed by design) | 22 |
| King IV Principle 5 / 13 | partial | Phase 1 (risk register builds out K-IV 5) | 28 |
| NIST SP 800-92 log integrity | met | — (shipped) | 24 |
| OpenAPI 3.1 | met | — (shipped) | 33 |

\newpage

# Change log {.unnumbered}

See `docs/delivery_dossier/CHANGELOG.md` in the source repository (`d:\CIHRMS\cihrms-mvp`, branch `dossier/v1.0`) for the full version history of this document, including every chapter-by-chapter revision, audit refresh, and standards-table update.

\newpage

# About this document {.unnumbered}

| Field | Value |
|---|---|
| Title | CIHRMS Delivery Dossier — Features, Standards, and Market Readiness |
| Version | 1.0 |
| Date | 2026-05-25 |
| Authored by | CIHRMS Engineering Team |
| Build pipeline | Markdown → Pandoc → Word `.docx` → manual PDF export |
| Source repository | `d:\CIHRMS\cihrms-mvp` (branch `dossier/v1.0`) |
| Source content | `docs/delivery_dossier/chapters/*.md` |
| Style template | `docs/delivery_dossier/reference.docx` |
| Re-build command | `powershell -File docs/delivery_dossier/build.ps1 -Version v1.0` |
| Audit data source | `docs/delivery_dossier/build/module_audit.json` (as-of 2026-05-25) |
| Chapter count | 47 (Part I §§1-35, Part II §§36-43, Part III §§44-47) plus front matter and back matter |
| Page count target | 500-700 pp A4 |
