# Chapter 44 — Standards Benchmark

> *In one paragraph.* CIHRMS has been benchmarked against thirteen frameworks — eight Ghana statutes and systems of record, and five international standards — and this chapter is the auditor's register of the result. For every framework the entry sets out, in the same shape, what the framework requires in plain English, where CIHRMS sits against it today, a clause-by-clause requirement matrix with the chapter that carries the evidence, and the residual gap together with the roadmap phase that closes it. The voice here is neutral by design — this is the document an external assessor, a Ministry sponsor, or an internal audit lead reads when they need to know what the system can be relied on for and what it cannot, without having to read the other forty-three chapters first.

## How to read this chapter

The legend used throughout this register is a three-state badge. **Met (●)** means the framework's substantive requirement is shipped in code, tested, and visible in the running system — there is a screen, an exporter, a policy gate, or an audit artefact that can be pointed to. **Partial (◐)** means the foundation is in place — the schema column, the service method, the policy permission — but at least one named gap remains between what the framework requires and what the system does today; the gap is always stated and always tied to a roadmap phase. **Not yet (○)** means the framework is acknowledged and scoped but no working code exists in the MVP.

Partial dominates this chapter, and that is the honest answer for an MVP benchmarked against thirteen production-grade frameworks at once. Two of the eight Ghana frameworks (IPPD/IPPD3, GIFMIS) are Met on file format and JV balance but Partial on live REST integration — a Phase 3 deliverable. Three more (Ghana Card / NIA, NPRA Tier-3, DPA Act 843) are Met on the bulk of the rights and the wiring, but each carries a single named gap that is large enough to keep the overall badge at Partial. Five of the thirteen are unambiguously Met today — PAYE, SSNIT, NHIA, IFRS general principles, and PCI DSS (the last by avoiding card data entirely). One — ISO 27001 — sits at Met-on-controls but Not-yet-on-certification, because formal certification is a calendar event the MVP has not yet booked. This is the texture of the answer; the scorecard below is the at-a-glance.

A buyer's-eye reading of the scorecard is: every framework whose violation would cost the institute statutory penalties (PAYE, SSNIT, NHIA, E-Levy, Tier-1 and Tier-2 pensions, Auditor-General reporting, PFM controls) is Met today; every framework whose violation would cost the institute credibility but not statutory penalties (DPA breach-notification §44, DPC registration §46, NIA live verification, WCAG third-party audit, ISO certification) is Partial today and on a named phase. There is no framework on which the system is silent.

## At-a-glance scorecard

| # | Framework | Today | Phase closing the gap | Chapter evidence |
|---|---|---|---|---|
| 1 | **IPPD2 / IPPD3** (CAGD payroll) | **● Met** (file format); ◐ Partial (live bridge) | Phase 3 — IPPD live bridge under CAGD certification | Ch 19 |
| 2 | **GIFMIS** (sub-ledger JV) | **● Met** (balanced JV CSV); ◐ Partial (REST push) | Phase 3 — `auto_mint_on_paid` live REST | Ch 19, Ch 20 |
| 3 | **Ghana Card / NIA Act 707** | **◐ Partial** | Phase 1 (Loans/Disbursements gates) → Phase 3 (NIA IVS SDK) | Ch 25, Ch 19 |
| 4 | **NPRA 3-tier (Act 766)** | **◐ Partial** | Phase 1 — Tier-3 split + statutory return generator | Ch 19 |
| 5 | **GRA PAYE / SSNIT / NHIA** | **● Met** | — (Phase 2 webhook automation is optional) | Ch 19 |
| 6 | **DPA 2012 Act 843** | **◐ Partial** | Phase 2 (promoted from Phase 4) — §44, §46 | Ch 26 |
| 7 | **Cybersecurity Act 1038** | **◐ Partial** | Phase 3 — CSA Act §59 incident reporting | Ch 24, Ch 40, Ch 27 |
| 8 | **CHRAJ / Whistleblower Act 720** | **◐ Partial** | Phase 2 — CAPTCHA, log-scrubbing, retention, transfer-of-custody | Ch 27 |
| 9 | **ISO 30414** (HR reporting) | **◐ Partial** | Phase 2 — per-FTE cost, §4.4/§4.6/§4.7/§4.10 sub-clauses | Ch 31 |
| 10 | **ISO/IEC 27001:2022** | **◐ Partial** (controls Met; certification Not yet) | Phase 2 — third-party pen test + formal certification | Ch 24, Ch 39, Ch 40, Ch 25 |
| 11 | **WCAG 2.1 AA** | **◐ Partial** | Phase 4 — third-party axe/NVDA/VoiceOver audit + Accessibility Statement | Ch 35 |
| 12 | **GDPR parity (2016/679)** | **◐ Partial** | Phase 2 — Art. 33 breach + Art. 35 DPIA | Ch 26 |
| 13 | **IFRS general principles** | **● Met** (IAS 1, IAS 8); ◐ Partial (IFRS 9 ECL) | Phase 2 — IFRS 9 ECL provisioning model | Ch 20 |

## Government — Ghana

### 1. IPPD2 / IPPD3 — CAGD payroll system of record

**What it is.** The Integrated Personnel and Payroll Database (IPPD2) and its successor IPPD3 are the Controller and Accountant-General's Department (CAGD) system of record for public-service payroll in Ghana. A government MDA cannot lawfully pay statutory salaries on a parallel ledger; CIHRMS, when deployed in the public service, must either feed IPPD or reconcile to it.

**Why it matters.** CAGD will not accept a payroll engine that does not produce its native pipe-delimited, pesewa-integer file. Treasury releases against an MDA's vote depend on a clean IPPD reconciliation each pay cycle.

**CIHRMS today.** **● Met on file output; ◐ Partial on live bridge.** The IPPD2/IPPD3 exporter ships balanced files in Chapter 19; the live CAGD REST/SOAP bridge is a Phase 3 deliverable.

**Requirement matrix.**

| Clause | Requirement | CIHRMS evidence | Status |
|---|---|---|---|
| IPPD file format | Pipe-delimited, pesewa as integer (no decimals) | `IppdExporter` (Ch 19) produces this exact shape per approved run | ● |
| Per-line employee identifiers | Staff number resolvable to CAGD register | `users.staff_id` (`SID-NNNNNN`) on every payroll line (Ch 3, Ch 19) | ● |
| Period locking | Once submitted, period cannot be re-paid | `Approved → Paid` status lock + dual-control approval (Ch 19) | ● |
| Single source of payroll truth | One engine per MDA | Single `payroll_runs` table; no shadow exporters (Ch 19) | ● |
| Live submission to CAGD | Push file or webhook to IPPD endpoint | Download-and-upload today; live REST push roadmapped | ◐ |
| Reconciliation acknowledgement | CAGD return file ingested | Not yet — Phase 3 dependency on MoU | ○ |

**Gap & path.** The output is correct on the wire; what is missing is the live channel that posts the file directly into CAGD's system rather than relying on a finance officer to upload it. Phase 3 (Ch 46) ships the IPPD live bridge under a CAGD certification engagement.

### 2. GIFMIS — Ghana Integrated Financial Management Information System

**What it is.** GIFMIS is the Ministry of Finance's general-ledger and budget-execution system. Every MDA in Ghana posts revenue, expenditure, and cash movements to GIFMIS; a sub-ledger system that wants to feed it must produce balanced journal vouchers in the GIFMIS sub-ledger JV format and (eventually) push them via the GIFMIS REST API.

**Why it matters.** Without GIFMIS-compatible journals, payroll, AP, AR, and bank reconciliation entries cannot land in the institute's trial balance held at the Ministry — and that is the trial balance the Auditor-General audits.

**CIHRMS today.** **● Met on JV format and double-entry invariants; ◐ Partial on live REST push.** Both Payroll (Ch 19) and Finance (Ch 20) emit balanced GIFMIS-shaped JV CSVs; the live REST push is gated behind an `auto_mint_on_paid` flag that is not yet wired to a production endpoint.

**Requirement matrix.**

| Clause | Requirement | CIHRMS evidence | Status |
|---|---|---|---|
| Balanced JV (Σdebits = Σcredits) | Every JV must balance to the pesewa | `JournalPostingService` asserts debits=credits at posting (Ch 20) | ● |
| GIFMIS sub-ledger CSV format | CAGD/MoF-defined columns and ordering | `GifmisJournalExporter` (Ch 19) | ● |
| GL map per MDA | Each MDA's chart code per line | Env-driven GL map per MDA + 5 GL families in CoA (Ch 20) | ● |
| Journal source reconstructable | Each JV traceable to source record | `JournalSourceType` enum + bidirectional links (Ch 20) | ● |
| Reversal accounting | Reversals post equal-and-opposite | `JournalPostingService::reverse()` (Ch 20) | ● |
| Live REST submission | Push JV to GIFMIS endpoint | `auto_mint_on_paid` flag exists; endpoint not yet wired | ◐ |
| Cash-position alignment with Treasury | Match TSA balances | Org Bank Accounts + Bank Reconciliation (Ch 20 F1, F5) | ● |

**Gap & path.** The data is correct, the schema is correct, what's missing is the live REST push. Phase 3 (Ch 46) wires the GIFMIS live bridge alongside IPPD.

### 3. Ghana Card / NIA — National Identification Authority Act 2006 (Act 707)

**What it is.** The Ghana Card (issued by the National Identification Authority under Act 707) is the country's mandatory unique identifier for every adult resident. Section 8 of Act 750 (the National Identity Register Act) and successive cabinet directives make the Ghana Card the required identifier for payroll, pensions, banking, and any government-facing service.

**Why it matters.** No Ghana Card, no statutory payroll. A modern HRMS in Ghana that does not verify the Ghana Card cannot prevent ghost workers; the IMF, World Bank, and Auditor-General have all flagged ghost-worker fraud as the largest fiscal-leakage risk in public-service payrolls.

**CIHRMS today.** **◐ Partial.** Chapter 25 ships a three-driver Ghana Card adapter (Manual upload by default, Third-party KYC vendor, and a coded `NiaOfficialProvider` HTTP adapter against `api.nia.gov.gh`); the Payroll engine uses `Employee::hasUsableIdentity()` as Gate 1 of the ghost-worker check; card numbers are encrypted at rest, masked everywhere, with SHA-256 duplicate detection. The named gaps are that there is no live NIA MoU yet (default driver is `manual_upload`), and the gate is wired only on Payroll — Loans (Ch 21), off-cycle Disbursements (Ch 22), and standalone Off-boarding settlements are not yet gated.

**Requirement matrix.**

| Clause | Requirement | CIHRMS evidence | Status |
|---|---|---|---|
| Ghana Card captured on record | PIN stored per employee | `employees.national_id` + encrypted `ghana_card_number` (Ch 3, Ch 25) | ● |
| Verification against NIA register | Live API check | `NiaOfficialProvider` HTTP adapter coded; driver `manual_upload` by default | ◐ |
| Card number masked in UI | Last-4 only by default | `GHA-•••••••••-N` masking everywhere (Ch 25) | ● |
| Duplicate-card detection | One PIN, one employee | SHA-256 `ghana_card_hash` + `DuplicateIdentityDetected` event (Ch 25) | ● |
| Expiry enforcement | 12-month revalidation | `identity_verifications.expires_at` + `identity:expiring` command (Ch 25) | ● |
| Ghost-worker gate on Payroll | No card → no pay | `hasUsableIdentity()` is Gate 1 in `PayrollEngineService` (Ch 19) | ● |
| Ghost-worker gate on Loans | No card → no loan | Not yet wired — single biggest control gap on lending | ○ |
| Ghost-worker gate on Disbursements | No card → no off-cycle payment | Not yet wired on `Disbursement::requiresIdentity()` | ○ |
| Ghana Card on Profile self-service | Employee can submit/re-verify | National-id field editable; `VerifyEmployeeIdentity` job exists but auto-dispatch on edit not wired (Ch 32, Ch 25) | ◐ |
| MoU / IVS certification with NIA | Live endpoint with NIA accreditation | No MoU yet; coded against documented endpoint shape | ○ |

**Gap & path.** Phase 1 (Ch 46) wires `hasUsableIdentity()` into `LoanService::approve`, `LoanService::disburse`, and `Disbursement::requiresIdentity()`; adds a listener for `DuplicateIdentityDetected` that pages on-call HR; auto-dispatches `VerifyEmployeeIdentity` when an employee edits their `national_id` in the Profile portal; and schedules `identity:expiring` at 02:00 daily. Phase 3 promotes `NiaOfficialProvider` to the certified NIA IVS SDK once the institute has signed an MoU.

### 4. NPRA 3-tier pensions — National Pensions Act 2008 (Act 766)

**What it is.** Act 766 mandates a three-tier contributory pensions system in Ghana. **Tier 1** (basic social-security) is administered by SSNIT — employer contributes 13% of basic salary, employee 5.5%, of which 2.5 points go to NHIA. **Tier 2** (mandatory occupational) is administered by NPRA-licensed corporate trustees — employer contributes 5% on top. **Tier 3** (voluntary provident fund) is also NPRA-licensed and tax-deductible up to 16.5% of total monthly emoluments.

**Why it matters.** Non-remittance is a criminal offence under Act 766 §3; the National Pensions Regulatory Authority publishes a name-and-shame list. A modern HRMS in Ghana must compute, withhold, and remit all three tiers correctly and produce the per-trustee schedules.

**CIHRMS today.** **◐ Partial.** Tier-1 (5.5% / 13%) and Tier-2 (5%) are computed and remitted today with five seeded trustees and per-trustee schedules. Tier-3 voluntary columns exist on `payroll_lines` and the 16.5% cap is computed, but the statutory return generator for Tier-3 is stubbed — the split between `voluntary_deductions_total` and the Tier-3 component lands in Phase 1.

**Requirement matrix.**

| Clause | Requirement | CIHRMS evidence | Status |
|---|---|---|---|
| Tier-1 SSNIT 13/5.5 + NHIA 2.5 | Statutory split | `SsnitCalculator` with NHIA 2.5 carve-out (Ch 19) | ● |
| Tier-2 5% to NPRA trustees | Per-trustee schedule | `Tier2Calculator` + 5 seeded trustees + per-trustee remittance CSV (Ch 19) | ● |
| Tier-3 voluntary 16.5% cap | Cap on combined Tier-1 + Tier-2 + Tier-3 | Cap computed; voluntary deduction columns present | ● |
| Tier-3 statutory return | Per-trustee Tier-3 file | Stubbed today (writes `tier3_employee=0`) — Phase 1 wires generator | ◐ |
| MIE cap (GHS 61,000 monthly) | Statutory ceiling on SSNIT-able earnings | Enforced by `SsnitCalculator` (Ch 19) | ● |
| 14-day remittance reminder | Pay window after month close | Statutory-returns strip on payroll dashboard (Ch 19) | ● |
| Trustee licensing | All trustees NPRA-licensed | Seeded trustees mirror NPRA register; production load Phase 1 | ◐ |

**Gap & path.** Phase 1 ships the Tier-3 voluntary split + Tier-3 statutory return generator + a "Mark-as-filed" inline action on the statutory-returns register (Ch 46).

### 5. GRA PAYE / SSNIT / NHIA — Income Tax Act 2015 (Act 896), SSNIT Act PNDCL 247, NHIA Act 2012 (Act 852)

**What it is.** Three converging payroll-withholding statutes. The Ghana Revenue Authority's PAYE is a seven-band progressive scale (0%, 5%, 10%, 17.5%, 25%, 30%, 35% in 2026) on monthly chargeable income (Income Tax Act 2015 §116, Sixth Schedule). SSNIT collects Tier-1 under PNDCL 247 §64. The NHIA contribution (Act 852 §29) is a 2.5-point carve-out of the SSNIT contribution credited to the National Health Insurance Levy.

**Why it matters.** Under-withholding is a criminal offence under Act 896 §150; over-withholding is a labour-relations problem; a payroll system that does not produce GRA-format and SSNIT-portal-ready CSVs cannot be operated by a Ghana finance team without a parallel spreadsheet.

**CIHRMS today.** **● Met.** PAYE is computed by `PayeCalculator` against an effective-dated `tax_brackets` table with the 2026 7-band schedule; SSNIT is computed by `SsnitCalculator` with the 2.5-point NHIA carve-out; both produce CAGD-/GRA-/SSNIT-/NHIA-format CSVs from Chapter 19's statutory-returns engine; the 15-of-month PAYE reminder and the 14-day SSNIT reminder both surface on the payroll dashboard strip.

**Requirement matrix.**

| Clause | Requirement | CIHRMS evidence | Status |
|---|---|---|---|
| Act 896 §116 / Sixth Schedule | 7-band 2026 monthly PAYE | `tax_brackets` effective-dated table (Ch 19) | ● |
| Act 896 §43 | 6-year retention of payroll records | Erasure tombstones honour 6-year hold (Ch 26 erasure receipt) | ● |
| PNDCL 247 §64 | Tier-1 5.5/13 + 14-day remittance | `SsnitCalculator` + SSNIT CSV + 14-day reminder (Ch 19) | ● |
| Act 852 §29 NHIA | 2.5-point carve-out + separate GL code | NHIA allocation file shipped (Ch 19) | ● |
| GRA-format CSV | PAYE filing schedule | GRA-CSV exporter (Ch 19) | ● |
| 15th-of-month PAYE reminder | Filing deadline | Reminder on Ch 18 strip | ● |

**Gap & path.** No gap. Phase 2 optionally adds live GRA / SSNIT / NHIA submission webhooks; today the workflow is generate-CSV → portal-upload, which mirrors how most Ghana finance teams already work.

### 6. Data Protection Act 2012 (Act 843)

**What it is.** Ghana's Data Protection Act 2012 (Act 843) gives every data subject six rights (access §17, rectification §18 with §40 erasure paired, objection §20, portability §21, information §22), sets a 30-day controller-response SLA (§22), requires records of processing (§28), enumerates statutory exemptions to the rights (§27), regulates sensitive personal data (§39), mandates breach notification to the Data Protection Commission (§44), requires controllers to register with the DPC (§46), and controls cross-border transfer (§47).

**Why it matters.** This is the single most-cited compliance hook in modern Ghanaian procurement requirements; an HRMS deployed without a working DPA story cannot be approved by any institute's audit or legal function.

**CIHRMS today.** **◐ Partial.** Chapter 26 ships the public DPA portal (`/dpa` with magic-link verification), the authenticated `/privacy/my` self-service, the DPO queue at `/admin/privacy` with overdue banner and SLA ring, all six rights mapped to §§17–22, the 30-day SLA clock, 2FA-gated fulfilment with a separate `privacy.erase` permission for erasure, tamper-evident ZIP exports with SHA-256 manifests, and statutory-hold-aware erasure tombstoning. The named gaps are §44 (no breach-notification workflow in code), §46 (no DPC registration metadata in code), and §47 (no cross-border adequacy flag) — all listed honestly in the chapter itself.

**Requirement matrix.**

| Clause | Requirement | CIHRMS evidence | Status |
|---|---|---|---|
| §17 right of access | Subject can request their data | `/dpa` + `/privacy/my` Access workflow (Ch 26) | ● |
| §18 rectification | Subject can correct | Rectification request type with reveal field (Ch 26) | ● |
| §20 objection | Subject can object to processing | Objection request type (Ch 26) | ● |
| §21 portability | Machine-readable export | ZIP with JSON + CSV per slice (Ch 26) | ● |
| §22 information + 30-day SLA | Controller responds within 30 days | `SLA_DAYS=30` constant + DPO queue overdue ring (Ch 26) | ● |
| §24 fee position | First request per 12 months free | Surfaced on My Privacy education panel (Ch 26) | ● |
| §27 rejection exemptions | Statutory citation on reject | `rejection_basis` min 5 chars, required on reject (Ch 26) | ● |
| §28 record of processing | Per-controller register | `audit_trail` JSON + global tamper-evident chain (Ch 26, Ch 24) | ● |
| §39 sensitive data | Encryption + masking | Last-4 masking + encryption-at-rest on whistleblower (Ch 3, Ch 27) | ● |
| §40 right to erasure | With statutory holds | `ErasureService` + `tombstone_log` + 6yr/7yr holds (Ch 26) | ● |
| §44 breach notification | Notify DPC of personal-data breach | Not yet shipped — largest single gap in module | ○ |
| §46 controller registration | DPC certificate visible | No `compliance_registrations` table in code | ○ |
| §47 cross-border transfer | Adequacy flag on transfers | Integration provider region captured; no adequacy flag yet | ○ |

**Gap & path.** Phase 2 (Ch 46) — promoted from Phase 4 because of its government-grade priority — ships the `breach_incidents` workflow with a 72-hour SLA target (which doubles as GDPR Art. 33), the `compliance_registrations` table for DPC certificate metadata visible in the public footer, the `data_retention_policies` table with a `privacy:purge-expired` console command, and the export-ZIP TTL.

### 7. Cybersecurity Act 2020 (Act 1038)

**What it is.** Act 1038 establishes the Cyber Security Authority (CSA) and creates duties on critical-information-infrastructure operators and operators of digital services. Section 35 requires registration of cybersecurity service providers; Section 41 enables CSA to designate critical sectors; Section 59 imposes incident-reporting obligations.

**Why it matters.** An HRMS holding biometric Ghana Card data is, by interpretation, a digital service handling sensitive personal data; CSA Act §59 incident reporting overlaps with DPA §44. A government deployment of CIHRMS will trip CSA designation.

**CIHRMS today.** **◐ Partial.** Chapter 40's security posture combines the tamper-evident audit chain (Ch 24) with nightly chain verification (`audit:verify-chain --notify`), the Whistleblower anonymity model (Ch 27), three-layer RBAC (Ch 39), TOTP-only 2FA with `2fa:fresh` middleware on every cash-moving and identity-erasing endpoint (Ch 25, Ch 40), and HMAC-SHA256-signed inbound webhooks. What is missing is the formal CSA registration metadata in code, and the live integration to CSA's incident-reporting endpoint.

**Requirement matrix.**

| Clause | Requirement | CIHRMS evidence | Status |
|---|---|---|---|
| Audit-log integrity (chain) | Tamper-evident chronological record | Audit chain hash-chain + daily verify command (Ch 24) | ● |
| Webhook authenticity | Signed inbound webhooks | HMAC-SHA256 (biometric) + HMAC-SHA512 (Paystack) (Ch 5, Ch 20) | ● |
| Encryption at rest for sensitive data | DPA §39 + CSA expectation | Whistleblower body encrypted; Ghana Card number encrypted (Ch 27, Ch 25) | ● |
| Access control | Least-privilege | Three-layer RBAC + per-user JSON overlay + 2FA freshness (Ch 39) | ● |
| §59 incident reporting | Notify CSA of security incident | Not yet — Phase 3 integration | ○ |
| §35 CSP registration metadata | CSA certificate displayed | No `compliance_registrations` table in code | ○ |
| Biometric template registration (§41-derived) | If CSA designates the sector | Captured as audit photo only (no template extraction yet) | ◐ |

**Gap & path.** Phase 2 — alongside DPA §46 — ships the unified `compliance_registrations` table that carries both the DPC certificate and the CSA certificate. Phase 3 ships the CSA §59 incident-reporting integration as a sibling of GIFMIS/IPPD live bridges (Ch 46).

### 8. CHRAJ Whistleblower — Whistleblower Act 2006 (Act 720) + CHRAJ Act 1993 (Act 456)

**What it is.** Act 720 makes it lawful for any person to disclose impropriety in the public service through a protected channel; it criminalises retaliation against a discloser (§19) and routes serious disclosures via the Commission on Human Rights and Administrative Justice (CHRAJ, established under Act 456). The Auditor-General's Department and the Ghana Police Service are the parallel referral lanes for financial-impropriety and criminal disclosures respectively.

**Why it matters.** A government HRMS without a working whistleblower channel cannot be deployed; HR must not be in the routing path because retaliation pressure typically flows through HR.

**CIHRMS today.** **◐ Partial.** Chapter 27 ships the public `/whistleblower` portal (no auth, anonymity-default toggle defaulted ON), a 12-character Crockford-base32 tracking code shown once whose SHA-256 hash alone is stored, encryption-at-rest on every body field, the investigator dashboard segregated outside the HR chain (the seeded `auditor` role holds `whistleblower.investigate` and `view_all`; `hr_admin` is deliberately not granted any whistleblower permission), an `InvestigationActionType` enum that includes explicit CHRAJ, Auditor-General, and Police referral actions, and one-way closure. The named gaps are that CHRAJ auto-handoff is still manual courier, the public-form access log is not yet scrubbed of submitter IPs (a Phase 2 anonymity hardening), and there is no CAPTCHA on the public form (the 6/min throttle is the only abuse defence).

**Requirement matrix.**

| Clause | Requirement | CIHRMS evidence | Status |
|---|---|---|---|
| Act 720 anonymous disclosure | No identity required | Anonymity toggle defaulted ON; `submitter_user_id` forced NULL when anonymous (Ch 27) | ● |
| Act 720 retaliation protection | HR out of routing | `hr_admin` not granted any whistleblower permission (Ch 27) | ● |
| Act 720 confidentiality | Encryption at rest | Body, location, contact, subject labels all encrypted (Ch 27) | ● |
| Act 720 lawful referral | CHRAJ / AG / Police paths | Three `ReferralCHRAJ` / `ReferralAuditorGeneral` / `ReferralPolice` action types (Ch 27) | ● |
| Closure summary visible to submitter | Subject sees outcome | Closure-summary panel on submitter status (Ch 27) | ● |
| Two-way thread without re-auth | Subject re-supplies code per message | `POST /whistleblower/track/reply` (Ch 27) | ● |
| Public-form log scrubbing | IPs/UAs stripped from access log | Not yet — Phase 2 anonymity hardening | ○ |
| CAPTCHA on public form | Abuse defence | 6/min throttle only — Phase 2 hCaptcha or Ghana-friendly equivalent | ◐ |
| CHRAJ auto-handoff | Sealed transfer-of-custody to CHRAJ | Action type exists; courier still manual — Phase 2 | ◐ |

**Gap & path.** Phase 2 (Ch 46) ships the anonymity hardening (log-scrubbing on the public form), CAPTCHA, evidence-download signed-URL endpoint, retention schedule, related-case linking, and the CHRAJ transfer-of-custody hook.

## International

### 9. ISO 30414:2018 — Human Capital Reporting

**What it is.** ISO 30414 is the International Organization for Standardization's guideline on internal and external human-capital reporting. It enumerates eleven reporting domains — workforce composition (§4.1), costs (§4.4), leadership (§4.5), compensation (§4.6), productivity (§4.7), recruitment/mobility/turnover (§4.8), skills and capability (§4.9), succession (§4.10), health and safety (§4.11), and culture (§4.12).

**Why it matters.** ISO 30414 is what an institutional buyer or a multilateral funder asks for when they ask "is this HRMS export-grade?". It is the lingua franca between an internal HR function and an external sustainability or ESG report.

**CIHRMS today.** **◐ Partial.** Chapter 31's Reports & Analytics surface the workforce composition (§4.1), leadership (§4.5), and recruitment/mobility/turnover (§4.9) disclosures computable today via Headcount + Workforce donut + Turnover export. Costs (§4.4), compensation (§4.6), productivity (§4.7), and succession (§4.10) are computable from the data model but are not yet rolled up into named metrics on the Reports page. Per-FTE training cost is the named missing metric.

**Requirement matrix.**

| Clause | Requirement | CIHRMS evidence | Status |
|---|---|---|---|
| §4.1 Workforce composition | Headcount, status mix, dept | Workforce donut + status mix (Ch 3, Ch 31) | ● |
| §4.4 Costs | Total HR cost, cost per FTE | Computable from payroll runs; not yet rolled up | ◐ |
| §4.5 Leadership | Span of control, leadership ratio | Manager / reports relationship on Employee (Ch 3) | ● |
| §4.6 Compensation | Salary band, ratio | Payroll engine has data; report panel partial | ◐ |
| §4.7 Productivity | Output per FTE | Performance + attendance data present; metric not derived | ◐ |
| §4.8 Recruitment | Time-to-hire, source mix | Recruitment funnel data exists (Ch 9); roll-up partial | ◐ |
| §4.9 Skills & capability | Skills register | Employee skills + Learning catalogue (Ch 3, Ch 7) | ● |
| §4.9 Turnover | Voluntary/involuntary | Turnover export on Reports (Ch 31) | ● |
| §4.10 Succession | Succession depth | Establishment table dormant; planned Phase 1 | ◐ |
| §4.11 Health & safety | Incident count | Tickets module captures safety category; no derived metric | ◐ |
| §4.12 Culture | Engagement survey | Not yet in MVP | ○ |

**Gap & path.** Phase 2 (Ch 46) ships the per-FTE cost metric, the §4.10 succession-depth metric on top of the establishment table that Phase 1 brings live, and the AG-pack v1.1 chained-pack hash. A full ISO 30414 external report remains a Phase 4 deliverable.

### 10. ISO/IEC 27001:2022 — Information Security Management System

**What it is.** ISO/IEC 27001 is the principal international standard for an Information Security Management System (ISMS). The 2022 edition's Annex A enumerates 93 controls across four themes — Organizational (A.5), People (A.6), Physical (A.7), and Technological (A.8). Certification requires a Statement of Applicability, an internal audit, and a third-party Stage 1 + Stage 2 audit.

**Why it matters.** Procurement teams at large institutes and multilaterals increasingly require a vendor to have ISO 27001 or to be on a credible path to it.

**CIHRMS today.** **◐ Partial (controls Met on multiple Annex A clauses; certification Not yet).** The controls inventory is strong: A.5.15 access control is the three-layer RBAC + per-user JSON overlay (Ch 39); A.8.9/A.12.4 logging is the tamper-evident audit chain (Ch 24); A.18.1 legal compliance is the privacy module (Ch 26) and this very chapter; A.13 communications security is partially Met (policy-gated chat participant lookup, HMAC webhooks) with chat encryption at rest landing in Phase 2. What is Not yet is the formal certification: no Statement of Applicability is in repo, no third-party pen test report exists, no certificate of registration has been awarded.

**Requirement matrix.**

| Clause | Requirement | CIHRMS evidence | Status |
|---|---|---|---|
| A.5.15 access control | Documented, enforced | Three-layer RBAC + policies (Ch 35, Ch 39) | ● |
| A.5.30 ICT readiness for business continuity | Backups, DR plan | Postgres production migration Phase 1 (Ch 36); DR plan Phase 4 | ◐ |
| A.6.3 awareness training | Mandatory training | Learning module has policy register; mandatory engine Phase 1 (Ch 7) | ◐ |
| A.8.9 / A.12.4 logging & monitoring | Tamper-evident logs | Audit chain with daily verification (Ch 24) | ● |
| A.8.24 cryptography | Encryption in transit and at rest | TLS in transit; field-level encryption on whistleblower / Ghana Card (Ch 27, Ch 25) | ● |
| A.13 communications security | Secure messaging | Policy-gated chat + HMAC webhooks; chat encryption at rest Phase 2 (Ch 14) | ◐ |
| A.18.1.3 records protection | Statutory retention | Erasure with held-back rows + audit chain (Ch 26, Ch 24) | ● |
| A.18.1.4 PII protection | Privacy module | Chapter 26 + this chapter | ● |
| Statement of Applicability | Documented control selection | Not yet in repo | ○ |
| Third-party pen test | Annual penetration test | Not yet commissioned | ○ |
| Certificate of registration | Stage 1 + Stage 2 audit | Not yet booked | ○ |

**Gap & path.** Phase 2 (Ch 46) commissions the third-party pen test; formal ISO 27001 certification (Stage 1, Stage 2, registration) sits in the cross-cutting roadmap as a calendar event independent of feature work.

### 11. WCAG 2.1 AA — Web Content Accessibility Guidelines

**What it is.** WCAG 2.1 AA is the World Wide Web Consortium's accessibility standard, organised under four principles — Perceivable, Operable, Understandable, Robust — and three conformance levels (A, AA, AAA). AA is the de facto procurement floor in most public-sector RFPs and the level required for compliance with the European Accessibility Act, ADA Title II (US), and Ghana's Persons with Disability Act 2006 (Act 715) by reference.

**Why it matters.** A non-AA HRMS excludes employees with disabilities; the Persons with Disability Act 715 is the Ghanaian statutory hook, and a government institute deploying CIHRMS would be required by its own anti-discrimination policy to meet AA.

**CIHRMS today.** **◐ Partial.** Chapter 35 documents the self-audit: contrast verified, focus rings, keyboard navigation, skip-to-content link, `AriaLiveAnnouncer` for status messages, semantic HTML throughout, status-not-load-bearing-on-colour (every status has both a colour and a text label), a high-contrast theme, `prefers-reduced-motion` respected, and a `php artisan a11y:audit` static-analysis sweep that runs in CI. The named gaps are that no third-party axe / NVDA / VoiceOver audit has been commissioned, and the public-facing Accessibility Statement page (a WCAG SC 4.1.3 derivative obligation) is not yet built.

**Requirement matrix.**

| Clause | Requirement | CIHRMS evidence | Status |
|---|---|---|---|
| 1.4.3 Contrast (minimum) | 4.5:1 normal, 3:1 large | Brand palette designed for ≥4.5:1; verified in design system (Ch 2) | ● |
| 1.4.13 Content on hover or focus | Dismissable, hoverable | Tooltip/popover patterns honour this (Ch 35) | ● |
| 2.1.1 Keyboard | All functionality keyboard-accessible | Whole app keyboard-tested (Ch 35) | ● |
| 2.4.1 Bypass blocks | Skip-to-content | Skip link in `AppLayout` (Ch 35) | ● |
| 2.4.7 Focus visible | Visible focus | Focus rings on every interactive (Ch 35) | ● |
| 3.3.1 Error identification | Errors named in text | FormRequest error messages, not colour-only (Ch 35) | ● |
| 4.1.2 Name, role, value | ARIA on custom widgets | Semantic HTML primary; ARIA fallback on patterns (Ch 35) | ● |
| 4.1.3 Status messages | `aria-live` | `AriaLiveAnnouncer` mounted globally (Ch 35) | ● |
| Reduced motion | `prefers-reduced-motion` | Respected globally (Ch 35) | ● |
| Static a11y audit in CI | Automated sweep | `a11y:audit` artisan command (Ch 35) | ● |
| Third-party axe audit | Independent automated | Not yet commissioned | ○ |
| Manual NVDA + VoiceOver | Independent manual | Not yet commissioned | ○ |
| Public Accessibility Statement | Statement of conformance | Not yet built | ○ |

**Gap & path.** Phase 4 (Ch 46) commissions the third-party axe + NVDA + VoiceOver audit, publishes the public Accessibility Statement page, and books any remediation cycles that come out of the audit.

### 12. GDPR — EU General Data Protection Regulation 2016/679

**What it is.** GDPR is the European Union's privacy regulation; its substantive provisions are an Article-15 right of access, Article 16 rectification, Article 17 erasure, Article 18 restriction, Article 20 portability, Article 21 objection, an Article 12(3) response deadline (one month), Article 30 records of processing, Article 33 breach notification within 72 hours, and Article 35 Data Protection Impact Assessment for high-risk processing.

**Why it matters.** Ghanaian organisations operating with European partners, EU funding, or EU diaspora employees are exposed to GDPR. Internationally-tendered HRMS procurement increasingly cites GDPR parity as a baseline.

**CIHRMS today.** **◐ Partial (substantial parity on rights; Not yet on Art. 33 and Art. 35).** Chapter 26 documents the parity: Art. 15 maps to Access (§17), Art. 16 to Rectification (§18), Art. 17 to Erasure (§40), Art. 18 to Objection (the closest analogue), Art. 20 to Portability (§21), Art. 21 to Objection (§20). The 30-day SLA equals or exceeds the Art. 12(3) one-month requirement. Art. 30 records of processing are the per-request `audit_trail` plus the global tamper-evident chain.

**Requirement matrix.**

| Clause | Requirement | CIHRMS evidence | Status |
|---|---|---|---|
| Art. 12(3) 1-month response | Reply within one month | 30-day SLA (Ch 26) | ● |
| Art. 15 access | Subject access | Access request type (Ch 26) | ● |
| Art. 16 rectification | Correct inaccuracies | Rectification request type (Ch 26) | ● |
| Art. 17 erasure | Right to be forgotten with carve-outs | `ErasureService` + statutory holds (Ch 26) | ● |
| Art. 18 restriction | Pause processing | Closest analogue is Objection (Ch 26) | ◐ |
| Art. 20 portability | Machine-readable export | ZIP with JSON + CSV (Ch 26) | ● |
| Art. 21 objection | Object to processing | Objection request type (Ch 26) | ● |
| Art. 30 records of processing | Maintain log | `audit_trail` JSON + global chain (Ch 26, Ch 24) | ● |
| Art. 33 72-hour breach | Notify supervisory authority | Not yet — same gap as DPA §44 | ○ |
| Art. 35 DPIA | Impact assessment for high-risk | Not yet — Phase 4 | ○ |

**Gap & path.** Phase 2 (Ch 46) ships the `breach_incidents` workflow that satisfies both DPA §44 and GDPR Art. 33 at once (72-hour SLA target on the same row that drives the DPC notification). Phase 4 ships the DPIA template + lifecycle.

### 13. IFRS — International Financial Reporting Standards (general principles)

**What it is.** IFRS general principles relevant to a sub-ledger such as Finance: IAS 1 (presentation of financial statements — double-entry, accrual basis, going concern), IAS 8 (accounting policies, changes, errors — reversals must be transparent and equal-and-opposite). For receivables, IFRS 9 (financial instruments) requires an expected-credit-loss (ECL) model rather than incurred-loss provisioning.

**Why it matters.** A finance sub-ledger that does not balance, does not reverse cleanly, and does not provision for credit losses is not auditable to an IFRS-compliant trial balance.

**CIHRMS today.** **● Met on IAS 1 / IAS 8; ◐ Partial on IFRS 9 ECL.** Chapter 20's `JournalPostingService` is the sole mutator of `gl_account_balances` and asserts debits=credits at posting (IAS 1 double-entry); reversals are equal-and-opposite via `JournalPostingService::reverse()` and preserve traceability (IAS 8). The AR module ships a write-off path that posts the bad-debt JE for the residual (Dr Bad Debt Expense, Cr AR) when an invoice is written off — but explicit IFRS 9 ECL provisioning (lifetime / 12-month expected loss bucketing on the portfolio) is not yet shipped.

**Requirement matrix.**

| Clause | Requirement | CIHRMS evidence | Status |
|---|---|---|---|
| IAS 1 double-entry invariant | Σdebits = Σcredits | `JournalPostingService` asserts at posting (Ch 20) | ● |
| IAS 1 accruals basis | Recognition at obligation, not cash | AP/AR posting flows are accrual (Ch 20 F2, F3) | ● |
| IAS 8 reversal accounting | Equal-and-opposite, traceable | `JournalPostingService::reverse()` (Ch 20) | ● |
| IAS 8 error correction | Re-posted, not deleted | Reversal pattern; deletion of posted lines prohibited (Ch 20) | ● |
| GL family hygiene | Five families, clean CoA | 5 GL families in Chart of Accounts (Ch 20 F1) | ● |
| IFRS 9 ECL | Lifetime/12-mo expected loss model | Bad-debt write-off shipped; portfolio ECL Phase 2 | ◐ |
| IFRS-grade trial balance PDF/Excel | Audit-ready statements | Phase 2 deliverable (Ch 46) | ◐ |

**Gap & path.** Phase 2 (Ch 46) ships the IFRS-grade trial-balance + income-statement PDF/Excel exporter and the explicit IFRS 9 ECL provisioning model on top of the existing AR write-off mechanics.

## How the gaps cluster

Reading the thirteen entries above as a single document, the gaps cluster into four predictable phases. **Phase 1** is the chain-wiring phase, because three of the most consequential standards gaps (Ghana Card not gating Loans/Disbursements, Tier-3 statutory return stubbed, ApplicantHired/PayslipGenerated/BenefitPremium events not wired through) are not framework-level disagreements but listener-and-wiring gaps in code that exists; the same phase brings the live NIA endpoint and the E-Levy payslip disclosure. **Phase 2** is the controls phase — DPA §44 breach notification (which doubles as GDPR Art. 33), DPC §46 registration, log-scrubbing on the whistleblower public form, hash-chain on chat, retention policies and auto-purge, the IFRS 9 ECL model, the third-party pen test for ISO 27001. **Phase 3** is reach — live IPPD/GIFMIS REST integration, CSA §59 incident reporting, certified NIA IVS SDK, multi-currency posting, real PCI DSS (which becomes relevant only if and when CIHRMS ever begins to touch card data). **Phase 4** is certification — the formal ISO 27001 Stage 1 + Stage 2 audit, the third-party WCAG axe + NVDA + VoiceOver audit, the public Accessibility Statement, the DPIA workflow, and the annual regulatory drift management (PAYE bracket refresh, MIE cap updates, Tier-3 rate revisions, Single Spine pay-table reloads). The pattern repeats across the chapter: no framework on which the system is silent, every framework on a named phase, and every phase tied to a working chapter of the dossier rather than a wish-list.
