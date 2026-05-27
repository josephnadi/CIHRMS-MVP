# Chapter 46 — What's left and how to get there

> *In one paragraph.* Seventy-three outstanding items sit across four phases. This is the honest gap list — distilled from every chapter's "What's planned next" and "Honest gaps" sections, cross-checked against the thirty-six chain-broken findings surfaced by the module audit, and ordered by what an external tender evaluator will look for. Phase 1 is the smallest credible pre-tender bar — eight to ten engineering-weeks of work that closes the orphan events, schedules the unscheduled commands, applies the Ghana Card gate everywhere a salary moves, discloses E-Levy on the payslip itself, and stands up the production runtime (PostgreSQL, Redis, Horizon). Phase 2 is government-grade depth — leave entitlements per grade, loans affordability, complaints SLA timers, chat encryption at rest, breach-notification workflow, retention policies, ISO 27001 certification. Phase 3 is reach — live REST against GIFMIS, live REST against IPPD, certified NIA IVS SDK, MoMo provider toggle-on, multi-currency, kiosk face recognition. Phase 4 is sustained operations — Sentry, off-site backup install, pen-testing cadence, regulatory drift management. Everything below is sized in engineering-weeks; nothing is a guess that wasn't first written down by the chapter it came from.

## How to read the roadmap

**"Engineering-week" means roughly one senior engineer for forty hours.** It folds in design, code, tests, documentation, code review, and the round-trip to merge — not just heads-down typing. Two engineering-weeks is two engineers working in parallel for one calendar week, or one engineer for two weeks; the numbers compose. Calendar time will always exceed engineering-week count when external dependencies (NIA MoU sign-off, CAGD certification, MTN/Vodafone/AirtelTigo MoMo onboarding, an ISO 27001 lead auditor's availability) are on the critical path.

**Phase 1 is the smallest pre-tender bar.** It is what we believe an evaluator scoring CIHRMS against a Ministry of Finance HRMS RFP will reject the bid for if missing. We have not padded it — every entry is justified by either a broken event chain that already exists in code or a statutory disclosure that the law expects on the artefact (payslip, statutory return, audit log) we already ship. If Phase 1 lands, the system is honestly tender-ready against IPPD3 / GIFMIS / Ghana Card baselines, with the public-sector depth items deferred to Phase 2.

**Dependencies between phases are real but narrow.** Phase 2 work assumes Phase 1's runtime stack (Postgres + Redis + Horizon), the SequenceService convention, and the hire-to-Employee chain are all closed. Phase 3 work assumes Phase 1's listener wiring (events that fire today land on real listeners) and the production runtime, but does **not** require Phase 2 — an institute deploying on the public-sector depth schedule can run Phase 2 and Phase 3 in parallel once Phase 1 is shipped. Phase 4 assumes Phase 1 and Phase 3 are both done; it is the continuous-operations layer.

**Cross-cutting items vs module items.** Cross-cutting items show up in the table with no chapter reference (or with multiple) — they are runtime, observability, or compliance changes that touch many modules. Module items are scoped to one chapter. The two categories are mixed in each phase table because work scheduling is by effort and dependency, not by ownership.

**On the seventy-three count.** The audit JSON enumerates seventy-five raw priority lines across the four phases (Phase 1 = thirty-five, Phase 2 = twenty-three, Phase 3 = eight, Phase 4 = nine). Two Phase 1 lines and one Phase 2 line are duplicates of cross-cutting work surfaced elsewhere — the consolidated table below collapses them to thirty-four / twenty-two / eight / nine = seventy-three distinct workstreams. The thirty-six chain-broken cross-module findings are not separate items; they are the **evidence** behind a subset of the Phase 1 and Phase 2 workstreams, and are cited inline next to the workstream that closes them.

## At-a-glance

| Phase | Theme | Effort | Items | Dependencies |
|---|---|---|---|---|
| 1 | Pre-tender minimum | 8–10 ew | 34 | none — work begins immediately |
| 2 | Public-sector depth | 8–10 ew | 22 | Phase 1 complete |
| 3 | Reach & integration | 8 ew | 8 | Phase 1 complete (Phase 2 independent) |
| 4 | Sustained operations | ongoing | 9 | Phase 1 + Phase 3 complete |

**Total Phase 1–3 effort:** twenty-four to twenty-eight engineering-weeks. At one senior engineer that is six to seven calendar months. At two engineers it is three to four calendar months. Phase 4 is treated as ongoing operations and is not added to the headline number.

## Phase 1 — Pre-tender minimum (8–10 engineering-weeks)

The smallest credible pre-tender bar groups along five themes: closing the orphan event chains, scheduling the unscheduled commands, pushing the Ghana Card gate everywhere a salary moves, disclosing E-Levy on the payslip itself, and standing up the production runtime. Every workstream below is justified by either a `cross_module_findings` chain-broken finding or a chapter-level "What's planned next" entry already written down by the module owner.

### Theme A — Close the orphan event chains

| Workstream | Effort | Depends on | Why |
|---|---|---|---|
| Wire ApplicantHired so EnvelopeStatusChanged → applicant.status=hired → Employee + User creation chain fires (Ch 9) | 1.0 ew | none | Single biggest deliverable on the hire pipeline. Today HR manually creates the Employee row after the candidate signs the offer; the ApplicantHired event class exists, the CreateZohoContactOnHire listener is wired, but **no code path dispatches it**. Closes audit findings on Ch 9 + Ch 3. |
| Dispatch PayslipGenerated at the tail of PaymentService::generatePayslip() (Ch 18) | 0.3 ew | none | UploadPayslipToCloud listener exists; `mirror_documents_to_cloud` feature flag exists; the dispatch call is missing. Closes the per-payment payslip cloud-mirror gap. |
| Extend PayslipGenerated to fire per `payroll_lines` row on approval — bulk payslip-PDF emission for batch payroll (Ch 19) | 0.5 ew | PayslipGenerated wired | Today the only path is the per-payment Ch 18 producer (which itself does not dispatch the event). Batch payroll runs do not produce payslip PDFs at all. |
| Add BenefitPremium DeductionType case + BenefitEnroled listener materialises Deduction row with floor protection (Ch 23) | 0.7 ew | none | BenefitsService::enrol does **not** create a payroll Deduction row; the DeductionType enum has no BenefitPremium case; `monthly_premium` never flows into payroll. Resolves a Ch 23-vs-Ch 19 cross-reference contradiction. |
| Listener for DuplicateIdentityDetected — write to audit log + page on-call HR (Ch 25) | 0.3 ew | none | Event fires from detectDuplicates() but has no listener. The single most important ghost-worker fraud indicator goes nowhere today. |
| Auto-dispatch VerifyEmployeeIdentity when employee edits national_id in Profile portal (Ch 25, Ch 32) | 0.3 ew | none | Job exists, works when queued, but is not dispatched automatically on the self-service edit. |
| OffboardingCompleted listener — disable User account + revoke Sanctum tokens + force SSO logout (Ch 10) | 0.5 ew | none | Today OffboardingService::complete only flips Employee.status to `terminated`; the User row stays active, tokens stay valid, SSO sessions persist. Tender evaluators will look for this and the gap will be visible the moment they click into a terminated user. |
| OffboardingService::complete walks open benefit_enrolments and ends them on last_working_day (Ch 10, Ch 23) | 0.3 ew | none | Terminated employees continue to appear in monthly premium totals until manual cleanup. |
| Wire PaymentCreated / PaymentPaid / PaymentCancelled events + PAY-YYYY-NNNN SequenceService references + Cancel-with-reason action (Ch 18) | 0.7 ew | SequenceService | PaymentMarkedPaid icon has been sitting in the Notifications palette since Ch 16. The hookpoint already exists in the UI; only the events are missing. |
| Listener wiring for LoanDisbursed / LoanRepaid / LoanSettled / LoanWrittenOff — finance journal entry on each (Ch 21 + Ch 20) | 0.7 ew | LoanService refactor | Today LoanService writes nothing to the audit log on status transitions and dispatches no finance JEs. The journal-posting service exists; the listeners do not. |
| TicketAssigned notification dispatched from TicketService::assign() (Ch 11 + Ch 16) | 0.2 ew | none | Notification class exists, is queue-aware, and is never dispatched. |
| EnvelopeStatusChanged → ApplicantHired chain + applicant_events audit table + public consent UI + position_id FK on job_postings + bulk multi-select + edit panel + required Reject reason + CVs into Documents pipeline (Ch 9) | 1.5 ew | ApplicantHired wired | Recruitment Phase 1 consolidation — the rest of the hire pipeline once the event chain is closed. |

### Theme B — Schedule the unscheduled commands

| Workstream | Effort | Depends on | Why |
|---|---|---|---|
| Schedule DataSubjectRequestService::markOverdueRequests nightly at 00:10 (Ch 26) | 0.1 ew | none | The function is implemented and tested; today a DSR row only flips to `overdue` when someone visits the DPO queue. A §22 SLA breach without a scheduled sweep is a §22 violation by omission. |
| Schedule `identity:expiring` at 02:00 daily (Ch 25) | 0.1 ew | none | Artisan command exists; no schedule entry. Twelve-month Ghana Card expiry warnings have no surface today. |
| Schedule PaymentIntentService::expireStale() nightly (Ch 20) | 0.1 ew | none | Method exists; no schedule entry. Stale payment intents accumulate indefinitely. |

### Theme C — Ghana Card gate everywhere a salary moves

| Workstream | Effort | Depends on | Why |
|---|---|---|---|
| Wire hasUsableIdentity() into LoanService::approve and LoanService::disburse (Ch 21 + Ch 25) | 0.3 ew | none | Today only Payroll Calculate calls the gate; loans disburse to identity-less employees. |
| Wire Disbursement::requiresIdentity() for off-cycle payments (Ch 22 + Ch 25) | 0.3 ew | none | Off-cycle disbursements include termination settlements and bonus payouts — both bypass the Ghana Card gate today. |
| Wire the gate into standalone Off-boarding settlement path (Ch 10 + Ch 25) | 0.2 ew | none | The third unguarded payment path. With these three wires, every cedi leaving CIHRMS is identity-gated. |

### Theme D — E-Levy disclosure on the payslip

| Workstream | Effort | Depends on | Why |
|---|---|---|---|
| Add `e_levy` field to PayrollLineResource + payslip Blade template + payslip PDF (Ch 22 + Ch 19) | 0.4 ew | none | E-Levy 1.5% is **applied** at disbursement materialisation today but is **not disclosed** on the payslip. The Disbursement Ledger shows the deduction; the artefact the employee actually receives does not. Single biggest disclosure gap on the salary rail and the easiest fix in this whole document. |

### Theme E — Production runtime (Postgres + Redis + Horizon)

| Workstream | Effort | Depends on | Why |
|---|---|---|---|
| PostgreSQL production migration replacing SQLite + dual-driver test suite green (Ch 36, Ch 41) | 1.5 ew | none | SQLite is fine for CI and local development; it is not a production database. PostgreSQL gives row-level locking parity with the `lockForUpdate()` patterns used in payroll balance increments, AP allocation, and bank reconciliation, plus the `pg_dump` artefact the AG audit pack expects. |
| Redis cache + Redis queue + Horizon dashboard (Ch 36, Ch 41) | 1.0 ew | Postgres migration | Default Laravel `database` queue handles MVP load but does not give job inspection, retry-with-failure-reason, or fan-out parallelism. Horizon is the operator's eyes on the messaging admin module, the Slack/Teams/WhatsApp dispatchers, and the FanOutWebhooks subscriber. |

### Theme F — Module-specific Phase 1 deliverables

| Workstream | Effort | Depends on | Why |
|---|---|---|---|
| WebhookSubscription schema/controller drift fix (Ch 33) | 0.3 ew | none | Migration uses `signing_secret` / `target_url` / `event_types` / `consecutive_failures`; controller uses `secret` / `callback_url` / `subscribed_events` / `failure_count`. Webhook admin currently fails silently for any institute that has migrated. |
| Auto-mint off-cycle PayrollLine on settlement approval + assets pre-flighted into clearance checklist + exit-letter/clearance-certificate PDFs (Ch 10) | 1.0 ew | OffboardingCompleted listener | Off-boarding consolidation — closes the loop between the workflow, the payroll line, and the paper artefact. |
| Tier-3 voluntary deduction split + Tier3 statutory return generator (Ch 19) | 0.5 ew | none | Columns + 16.5% cap exist on PayrollLine but the statutory return generator is stubbed (writes `tier3_employee=0`; voluntary_deductions_total split pending). |
| Department-scoped runs in Create panel UI + Mark-as-filed inline action on statutory returns (Ch 19) | 0.4 ew | none | Two UX completions on the Payroll Engine. |
| Realtime notifications via Laravel Reverb + Echo broadcast channels (Ch 16) + per-row read/delete/navigate actions | 0.8 ew | Redis | Inbox today polls on page load; Reverb gives the live tick. Per-row actions are the discoverability fix on existing rows. |
| SLA matrix + breach escalation cron + ticket_categories + ticket_comments + ticket_attachments + audit-log integration (Ch 11) | 1.2 ew | none | Tickets module ships the four-state lifecycle but no SLA, no comments, no attachments. The bare-minimum service-desk shape. |
| Mandatory-training engine + prerequisites column + catalogue edit panel + dompdf certificate generator + revocation lifecycle + dept_head scoping on Skills Matrix + Performance→Learning recommender (Ch 7) | 1.5 ew | none | Learning module Phase 1 consolidation. The PSC training policy register ships; the mandatory engine is the gap. |
| 360 campaign engine + KPI cascade UI + mid-year contract review form + PIP trigger button + per-manager dashboards + compensation linkage (Ch 6) | 1.5 ew | none | Performance module Phase 1 consolidation. The contract shape is right; the cycle machinery is missing. |
| Position/Grade/Step FK lookups on Employee (Ch 3, Ch 8) + NIA adapter sync+async (Ch 3, Ch 25) + Phase 1 audit chain wiring on Employee CRUD (Ch 3, Ch 24) | 1.0 ew | none | Employee chapter Phase 1 consolidation — three threads that all touch the same table. |
| Establishment sidebar + Add Position panel + Single Spine CSV loader + Auditor-General reconciliation report (Ch 8) | 0.8 ew | Position FK on Employee | Establishment Phase 1 consolidation. The grade structure ships; the institute can't load its own Single Spine table yet. |
| OrganizationSetting model + /settings/organization + /settings/branding + CI route↔docs diff check (Ch 33) | 0.7 ew | none | Settings hub Phase 1 — every other deployment of CIHRMS hardcodes the institute name today. |
| Templates + Campaigns + queue-backed dispatch + DPA opt-out enforcement + WhatsApp send UI + delivery analytics (Ch 15) | 1.0 ew | Redis | Messaging Admin Phase 1 — the bilingual + DPA fields exist on the templates table; the dispatch path doesn't. |
| 30/7/1 cadence certification reminders + notification listener to cert holder + risks table + /governance/risks + markdown rendering upgrade + board-pack assembler v0 (Ch 28) | 0.8 ew | none | Governance Phase 1 — policy lifecycle is shipped; the certification clock and the risk register are the gaps. |
| Department audience scoping + acknowledgement receipts + attachments + cross-channel fan-out (Ch 17) | 0.6 ew | none | Announcements Phase 1. |
| Audit-log wiring for 5 Benefits events + dual-control on claim payment + Finance journal on claim payment + SequenceService claim refs + /benefits/admin/overview + trustee-side balance call + QR e-cards (Ch 23) | 1.2 ew | LoanDisbursed JE pattern | Benefits Phase 1 — the events fire but go nowhere; this closes that and adds the dual-control on the payout. |
| Sidebar entry for /privacy/my from user-menu dropdown + 2FA status tile on Security tab + Ghana Card verified badge on hero + bank-change approval loop wired (Ch 32) | 0.5 ew | BankChangeRequestService scaffolding exists | Profile Portal Phase 1 — discoverability and a real approval loop on bank edits. |
| Reassign-head picker + UpdateDepartmentRequest consolidation + Portal KPI wiring to real stats (Ch 30) | 0.3 ew | none | Departments Phase 1. |
| Manual JE reversal route + AP external_ref via GhIPSS settlement bridge + concurrency-safe ApPaymentService::void + IFRS-grade trial-balance + IFRS 9 ECL bad-debt provision (Ch 20) | 1.5 ew | none | Finance Phase 1 — the gaps surfaced by the F2/F3 build-outs that did not block PR merge but do block a clean tender response. |
| Saved report definitions + scheduled email delivery + AG pack v1.1 (variance + establishment + chained-pack hash) + compliance posture sparkline (Ch 31) | 1.0 ew | Redis | Reports Phase 1. The AG pack v1.0 ships; v1.1 is the variance/establishment/chained-hash upgrade. |

**Phase 1 effort total:** 25.0 engineering-weeks of raw work, compressible to **8–10 engineering-weeks** with two-engineer parallelism on the orphan event chains (which dominate the dependency graph). The thirty-four workstreams collectively close twenty-three of the thirty-six chain-broken cross-module findings; the remaining thirteen sit in Phase 2.

## Phase 2 — Public-sector depth (8–10 engineering-weeks)

Phase 2 is what an institute needs to operate the system through a full government audit cycle — Internal Audit Agency, Public Accounts Committee, the Auditor-General, and the Data Protection Commission. The shape is the same as Phase 1 — workstream, effort, dependency, justification — but the weight shifts from chain-fixing to depth-adding. **Phase 1 must be complete before Phase 2 begins**, because the runtime stack (Postgres + Redis + Horizon) and the listener pattern (events that fire today land on real listeners) are pre-requisites for almost every Phase 2 item.

| Workstream | Effort | Depends on | Why |
|---|---|---|---|
| Per-grade leave entitlements + leave_request_history + lightweight calendar endpoint + public-holiday-aware working-days + Attendance reconciliation (Ch 4) | 1.0 ew | Establishment Phase 1 | Leave module today seeds every employee with the same 21-day annual balance. Per-grade entitlements are what the Single Spine pay-and-leave framework actually specifies. |
| Mobile app with offline buffer + per-shift overtime policies + roster shift planning + bulk approve corrections (Ch 5) | 1.5 ew | Phase 1 runtime | Attendance Phase 2 — the field-staff use cases the institute is forced to solve when adoption broadens beyond head-office. |
| Loans Phase 2: affordability/DTI gate + finance journal hooks + audit-log hook + manipulable schedule (early-repay/top-up/restructure) + default detection + guarantor workflow + employee self-service Loans tab (Ch 21) | 1.5 ew | LoanDisbursed listener (Phase 1) | `max_dti_ratio` is captured today but never enforced — the single largest gap on the lending side. BoG affordability guidelines expect the gate. |
| Complaints Phase 2: events + notification fan-out + in-app thread + resolution-summary + SLA timer + escalate-to-Whistleblower + throttle (Ch 12) | 1.0 ew | Notifications Phase 1 | ComplaintSubmitted / ComplaintStatusChanged events do **not exist** today; the submitter learns of status change only by polling the public tracker. Also closes PSC HR Handbook §5.4's fourteen-day clock gap. |
| Chat Phase 2: in-app notifications + Reverb realtime + audit log + file attachments + group chats + body encryption at rest (Ch 14) | 1.2 ew | Phase 1 Reverb | Chat message create/update/delete do not write to the tamper-evident audit chain today — soft-deletes leave a DB breadcrumb only. ISO 27001 A.13 expects encryption at rest on internal comms. |
| Whistleblower Phase 2: evidence download endpoint + anonymity hardening + CAPTCHA + related-case linking + read-receipts + retention schedule + CHRAJ transfer-of-custody hook (Ch 27) | 1.0 ew | none | Public form bypasses the global audit chain (correct per Act 720 §10) but the **standard Laravel request log still captures IP and user-agent** — the anonymity log-scrub is the largest remaining gap. |
| DPA Phase 4 priorities promoted into Phase 2: breach_incidents table + DPC registration metadata + retention policies + auto-purge + export ZIP TTL + subject sidebar nav (Ch 26) | 1.5 ew | none | Listed in Ch 26 as Phase 4 but is government-grade critical — Act 843 §44 breach notification and §46 controller registration are statutory floors. We promote these into Phase 2 if Phase 4 has not yet started. |
| Documents Phase 2: bulk routing + parallel routing + eSign integration extended + DOCX/XLSX burn-in (Ch 13) | 0.8 ew | none | Documents v2 landed annotations + asset libraries in PR #15; the workflow side is the gap. |
| Audit log: action vocabulary widening + per-row detail page + audit_log_id pointers on high-stakes tables (Ch 24) | 0.7 ew | none | AnalyticsEvent vocabulary today does not 1:1 with a route log under a generic name. Per-row drill-down from any table to the audit row is the auditor's most-asked feature. |
| Identity Phase 2: biometric template extraction + CSA registration + card OCR/MRZ + Identity Disputed workflow (Ch 25) | 1.0 ew | NiaOfficialProvider Phase 3 | The depth round for Ghana Card identity — what makes the verification a real biometric check and not just a number lookup. |
| Establishment ceiling enforcement + tamper-evident assign/vacate/freeze audit rows (Ch 8 + Ch 24) | 0.5 ew | Audit log Phase 2 vocabulary | The ghost-position prevention layer. |
| Templates Phase 2: bulk import audience uploads + auto-handling inbound + bilingual English+Twi (Ch 15) | 0.6 ew | Messaging Phase 1 | The bilingual + audience-import features the Ministry of Local Government will ask for. |
| Governance Phase 2: committee meeting tracker + retention schedule + ISO 37001 §7.3 recurring acknowledgement (Ch 28) | 0.7 ew | Governance Phase 1 | The recurring conflict-of-interest declaration is the ISO 37001 §7.3 expectation. |
| Performance Phase 2 (if not landed in Phase 1): 360 + KPI cascade + mid-year + PIP trigger + per-manager dashboards + compensation linkage (Ch 6) | 1.5 ew | none | Backstop entry — if Performance Phase 1 slipped, this picks it up in Phase 2. |
| Documents v2: per-type retention table + DPA §40 redaction job + court-grade evidence ZIP (Ch 13) | 0.7 ew | DPA retention policies | The same redaction engine that drives DSR erasure, extended to time-based document retention. |
| Reports: scheduled delivery + Echo realtime + compliance sparkline + AG pack v1.1 (Ch 31) | 0.6 ew | Phase 1 reports | Phase 2 polish on top of the Phase 1 saved-definitions feature. |
| Profile Portal Phase 2: 2FA status tile + Ghana Card verified badge + bank-change approval loop + employee-side document upload (Ch 32) | 0.5 ew | Phase 1 portal items | Some Phase 1 portal items get promoted to Phase 2 if the user-menu nav lands first. |
| Global Cmd+K search (Meilisearch RBAC-baked indexing) + third-party WCAG audit + Public Accessibility Statement + Multi-tenant AI key vault (Ch 35) | 1.5 ew | Phase 1 runtime | The cross-cutting accessibility and search bar. The WCAG 2.1 AA third-party audit is also the evidence pack for Persons with Disability Act 715 compliance. |
| Tickets Phase 2: customer-confirmation auto-close + public ticket portal + merge-duplicates (Ch 11) | 0.6 ew | Tickets Phase 1 SLA | The public-portal side of the service desk. |
| Postgres read replica when single instance feels load (Ch 36) | 0.5 ew | Postgres Phase 1 | Reactive — only if the institute's adoption profile demands it. |
| ISO 27001 formal certification + third-party pen test (cross-cutting) | 2.0 ew (calendar, mostly external) | Phase 1 + Phase 2 controls | Lead-auditor engagement is mostly calendar-time, not engineering time. The engineering cost is closing the findings the lead auditor surfaces — typically two to three engineering-weeks of remediation. |
| Recruitment Phase 2: hCaptcha + apply ack/shortlist/rejection email + post-hire performance link (Ch 9) | 0.6 ew | Recruitment Phase 1 hire chain | The applicant-comms layer once the hire chain is closed. |

**Phase 2 effort total:** 21.0 engineering-weeks of raw work, compressible to **8–10 engineering-weeks** with two- to three-engineer parallelism. The twenty-two workstreams collectively close the remaining thirteen chain-broken cross-module findings (Documents composer count()+1, ApPaymentService::void lock, Whistleblower log-scrub, AR bad-debt provision JE, AnalyticsEvent vocabulary, etc.) and add the depth that Phase 1 deliberately deferred.

## Phase 3 — Reach & integration (8 engineering-weeks)

Phase 3 is the wave where CIHRMS stops being a self-contained system and starts speaking the live REST protocols of the Ghanaian government stack and the commercial payment rails. **Phase 3 depends on Phase 1 but is independent of Phase 2** — an institute can run Phases 2 and 3 in parallel after Phase 1 ships.

| Workstream | Effort | Depends on | Why |
|---|---|---|---|
| Live GRA/SSNIT/NPRA/NHIA submission webhooks + GIFMIS live REST push under `auto_mint_on_paid` + IPPD bridge CAGD certification (Ch 19, Ch 20) | 2.0 ew (incl. MoU lead-time) | Phase 1 statutory exports clean | Today statutory returns are download-and-upload. The exporters are right; the live REST push is the gap. CAGD certification on IPPD is mostly calendar (institute applies, CAGD verifies pilot file, joint test cycle). |
| Disbursements: maker/checker dual-control above threshold + live MoMo provider onboarding (MTN/Vodafone/AirtelTigo sandboxes) + per-provider settlement webhook handlers + GhIPSS statement reconciliation + loan disbursement through Disbursement table + channel-mix/failure-rate reporting (Ch 22) | 2.0 ew | E-Levy disclosure (Phase 1), Loans Phase 2 | The single largest reach deliverable — toggles MoMo from "off by default" to a working production rail across all three operators. Each sandbox is an institute-onto-operator MoU. |
| NiaOfficialProvider promoted to certified NIA IVS SDK after MoU (Ch 25) | 1.0 ew (engineering) + calendar for MoU | Ghana Card gate Phase 1 | Today the default driver is `manual_upload`; the HTTP adapter exists; the certified SDK swap is what the NIA MoU unlocks. |
| Finance Phase 3: multi-currency posting + effective-dated FX rate tables + FX-gain/loss period close + Stripe + Flutterwave second-gateway pair (Ch 20) | 1.5 ew | Finance Phase 1 manual JE reversal | Multi-currency is the donor-funding posture. FX rate tables are effective-dated to match the period-close discipline already used for PAYE bracket tables. |
| Kiosk: face recognition + device registration + offline buffer + per-employee PIN fallback (Ch 29, Ch 25) | 1.5 ew | Identity Phase 2 biometric extraction | The depth round for kiosk attendance — what makes the kiosk a real biometric station, not a username-password panel. |
| Audit log: off-site sealed replication every 15min + CSA Act 1038 §59 incident-reporting integration + WORM mode at DB privilege level (Ch 24) | 1.0 ew | Phase 1 audit log wiring | The reach side of audit log — talking to CSA, replicating off-site, locking writes at the DB privilege layer. |
| Learning Phase 3: in-system content layer (video/PDF/SCORM 1.2/xAPI) + quiz engine with pass marks/proctoring + WCAG AA on content + SCORM Cloud SDK connector (Ch 7) | 1.5 ew | Learning Phase 1 mandatory engine | The LMS-grade content layer — what turns the Learning module from a register into a delivery platform. |
| AI features beyond employee summary (policy drafting, leave triage, chat summarisation, transcript helper) (Ch 35) | 1.0 ew | Multi-tenant AI key vault Phase 2 | The Anthropic-powered features that need the key vault foundation. |

**Phase 3 effort total:** 11.5 engineering-weeks of raw work; calendar is dominated by the external MoU and certification cycles (NIA, CAGD, MTN/Vodafone/AirtelTigo). Realistic compressed schedule is **8 engineering-weeks** with two engineers, plus six to nine calendar weeks of external lead-time that runs in parallel with the engineering.

## Phase 4 — Sustained operations (ongoing)

Phase 4 is the continuous-operations layer — what the institute does **after** the launch wave to keep the system government-grade as regulations drift and adoption broadens. **Phase 4 depends on Phase 1 and Phase 3 being complete.** Items here are not one-shot — they recur on a calendar cadence (quarterly, annual, every two years for re-certification) and are sized accordingly.

| Workstream | Effort | Depends on | Why |
|---|---|---|---|
| Marketing role permissions seeded + 2FA required on auditor role + manager-on-leave escalation timeout for leave (Ch 34) | 0.3 ew (one-shot) | none | Three small role-tour cleanups deferred from Phase 1 because none is blocking. |
| Anthropic responsible-use Phase 4: multi-tenant key vault completion + Anthropic SLA monitoring (Ch 35) | 0.5 ew + ongoing | Phase 2 key vault | The completion of the key vault work plus the monitoring that proves Anthropic is meeting its SLAs. |
| DPA Phase 4 (alternative if not promoted to Phase 2): breach_incidents workflow + DPC registration metadata + retention policies + auto-purge + export ZIP TTL + subject sidebar nav (Ch 26) | 1.5 ew | none | Backstop entry — same workstream as in Phase 2, parked here if the institute elected to defer it. |
| Continued government-grade pen testing + ISO 27001 re-certification cadence | 1.0 ew/year (ongoing) | Phase 2 ISO 27001 cert | Re-certification cycles are two-yearly under the ISO 27001:2022 transition rules; pen-testing should be annual minimum. |
| Annual regulatory drift management — PAYE bracket refresh, MIE cap updates, Tier-3 rate revisions, Single Spine pay table reloads (Ch 19, Ch 8) | 1.0 ew/year (ongoing) | Phase 1 statutory tables | The PAYE table is effective-dated; the refresh is mechanical when GRA publishes the new gazette. Same for SSNIT MIE cap, Tier-3 voluntary cap, and the biennial Single Spine review. |
| Performance Phase 2 backlog (if not landed in Phase 1 or Phase 2): KPI cascade + 360 + compensation linkage (Ch 6) | 1.5 ew | none | Second backstop entry — the Performance module fully landed at Phase 1 in the happy path, but if it slipped past Phase 2 this is where it lands. |
| Whistleblower SLA dashboards + cross-case pattern detection (Ch 27) | 0.7 ew | Whistleblower Phase 2 | Pattern detection is the operational-maturity outcome — visible only after several quarters of cases. |
| Operational maturity: Sentry installation + on-call rotation tooling + SLO instrumentation + backup install + Horizon dashboards in production (Ch 36, Ch 40, Ch 41) | 1.5 ew + ongoing | Phase 1 Horizon | The observability stack — what makes the institute's site reliability engineer's job possible. |
| Phase 4 backlog continuation as institute adoption matures | open-ended | n/a | The bucket that catches the long tail of "we'll know when we get there" work. |

**Phase 4 effort total:** treated as ongoing operational capacity rather than a closeable bucket. A reasonable steady-state headcount is one to two engineers continuously assigned to Phase 4 work plus the recurring annual blocks for regulatory drift and re-certification.

## What this roadmap does not cover

Several categories are deliberately excluded from the four phases above:

- **New product lines.** A separate-SaaS surface (multi-institute hosted CIHRMS), a public-employer-search portal, or a CIHRM Ghana professional-membership module are out of scope. They are not gaps in the v1.0 product; they are different products.
- **Multi-tenancy.** Tenant-per-database isolation is an architectural change that touches every model, every policy, every queue, and every export. It is out of scope for v1.0 and will be a separate work package if and when CIHRM Ghana decides to host more than one institute on one runtime.
- **Marketing automation.** Outbound campaigns to non-employees (recruitment marketing, alumni engagement, donor cultivation) sit in Ch 15's "could be expanded but is not" pile. The current messaging admin is for employee-and-applicant correspondence only.
- **Native iOS and Android.** The mobile-app line in Phase 2 (Ch 5 attendance) is a Capacitor wrapper around the existing Inertia views. A truly native pair of apps is out of scope until adoption justifies it; the wrapper is the v1.0 answer.
- **Real-time video / voice.** Chat is text-first. Audio and video calls are not in scope — institutes use Zoom, Teams, or Google Meet on their existing licences.
- **Public-facing employer branding sites.** Recruitment portal at `/careers` is in scope; a full marketing site with a CMS layer is out of scope. The institute can publish branding content on its main `.gov.gh` site and link to `/careers`.
- **Donor / grant management.** Project-and-grant accounting is a finance module of its own. Finance Phase 3 adds multi-currency, which is the donor-funding posture — but the dedicated grants ledger (sub-account hierarchy per donor, restricted-fund reporting, donor narrative reporting) is a follow-on product, not a Phase 4 item.
- **External board management.** Governance Phase 1 ships the policy lifecycle and the board pack assembler v0; Phase 2 adds the committee meeting tracker. A full board portal (papers, minute-keeping, e-voting, register of interests with public disclosure) is a separate product class and is not on this roadmap.

Every item above is **knowable**, not aspirational — each is justified by either a chapter that wrote it down or a chain-broken finding the audit surfaced. If a tender evaluator asks "what's left and how do you get there", this chapter is the answer in one document.
