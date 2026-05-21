# Government-Grade Integrations Roadmap

> **For agentic workers:** This is a *master* roadmap, not an executable plan. It indexes 7 focused sub-plans, each of which follows the standard P3-P6 plan template. Implement the sub-plans in priority order using superpowers:subagent-driven-development.

**Goal:** Close the 14-domain gap between today's CIHRMS and Ghana public-sector requirements (IPPD2/IPPD3 + HRMIS + GIFMIS, Ghana Card biometric ID via NIA, NPRA-licensed 3-tier pensions, CHRAJ whistleblower oversight) so the platform is pitchable to MDAs and large institutions.

**Reference:** Memory file `project_government_gap_analysis.md` (researched 2026-05-15) sets the bar at Oracle IPPD2/IPPD3 (CAGD/PSC), Ghana Card (NIA), NPRA Tier-2 trustees, GhIPSS / MoMo / E-Levy, DPA 2012 (Act 843), Cybersecurity Act 2020.

---

## Status snapshot (as of 2026-05-20)

| Capability | Status | Notes |
|---|---|---|
| Statutory payroll engine (PAYE/SSNIT/Tier-2/Tier-3/NHIA) | ✅ Built | `app/Services/Payroll/` — `PayeCalculator`, `SsnitCalculator`, `Tier2Calculator`, `StatutoryReturnGenerator` all present |
| Positions / Grades / Steps | ✅ Built | `Position`, `PositionAssignment`, `Grade`, `GradeStep` models live; `current_position_id`/`current_grade_id`/`current_step` on `Employee` |
| 2FA | ✅ Built | `TwoFactorController` shipped |
| Tamper-evident audit | ✅ Built | SHA-256 chain in `WriteAuditLog`, `audit:verify-chain` + `audit:backfill-chain` commands, daily cron at 03:00 with `AuditChainBroken` super_admin notification |
| MoMo disbursement | ✅ Built | MTN, AirtelTigo, Vodafone providers shipped under `Disbursement/Providers/` |
| E-Levy on MoMo (1.5%) | 🟡 Unknown | Verify is applied inside disbursement service |
| Ghana Card / NIA identity adapter | ✅ Built | `NiaOfficialProvider` (live HTTP), `ThirdPartyKycProvider`, `ManualUploadProvider` swappable via `config/identity.php`; SHA-256 dup-detection in `IdentityVerificationService`; camera-based biometric capture; 12-month re-verification reminder via `identity:expiring` cron |
| GhIPSS bank transfer | ✅ Built | `GhIpssAchProvider` stages rows for batch; `GhIpssBatchFileBuilder` emits the GhIP CSV layout; `disbursement:ghipss-export` artisan command; `bank_sort_code` added to employees; 11 tests in `tests/Feature/Disbursement/GhIpssAchProviderTest.php` |
| IPPD payroll export | ✅ Built | `IppdExporter` emits CAGD-format H/D/T pipe-delimited file; pesewa-integer amounts; ASCII transliteration; HTTP download endpoint at `payroll-runs.ippd-export`; `payroll:ippd-export` artisan command; 14 tests including golden-layout assertions |
| GIFMIS journal export | ✅ Built | `GifmisJournalExporter` mints a balanced double-entry JV (sum debits ≡ sum credits, throws on residual); GL-code map config-driven per MDA; HTTP `payroll-runs.gifmis-export`; `payroll:gifmis-export` artisan command; auto-mint via `MintGifmisJournal` listener on `PayrollRunPaid` (gated on `payroll.gifmis.auto_mint_on_paid`); 14 tests |
| SAML SSO (NITA / Entra ID) | 🟡 Partial | See [2026-05-20-saml-sso-hardening.md](./2026-05-20-saml-sso-hardening.md) — signature verification stubbed |
| Postgres production migration | ✅ Built | CI matrix runs full Pest on SQLite **and** Postgres 16 on every PR; `db:reset-sequences` + `db:dump-for-postgres` operator commands; `docs/ops/postgres-migration.md` runbook; `DbExpr` helper emits driver-specific date functions; 7 driver-locking tests in `tests/Feature/Support/` |
| WCAG 2.1 AA accessibility | ✅ Built | `a11y:audit` static auditor in CI; 9 WCAG 3.3.2 violations fixed; regression test pins live-tree at 0 errors; `docs/accessibility/conformance.md` covers the 12 audited criteria + testing methodology + known limitations |
| USSD / SMS gateway | ✅ Built | Payslip, leave balance, clock in/out, whistleblower-case track, **bank-change two-factor confirmation** via `BankChangeRequestService` + USSD option 5 (SMS code → masked-account approval); 13 fraud-prevention tests including 5-failure lockout + supersede-old-request safety |
| NITA hosting / CSA registration / pen-test | ❌ Missing | Operational, not code |
| Auditor-General report pack | 🟡 Partial | `AuditorGeneralReportController` shipped; report templates unknown |

---

## Sub-plan index

Each row links to (or names) a focused plan. Execute roughly in this order — Phase 1 items unblock the MDA pitch; later phases harden the platform.

### Phase 1 (8–10 weeks) — Government pitch minimum

1. **[2026-05-20-saml-sso-hardening.md](./2026-05-20-saml-sso-hardening.md)** — 2 days. Replace the SAML stub with `onelogin/php-saml`. Unblocks NITA-tenant and Entra-ID SSO.
2. **Ghana Card / NIA adapter** ✅ shipped 2026-05-21. `NiaOfficialProvider` is a live HTTP client driven by `config/identity.php`; `ThirdPartyKycProvider` + `ManualUploadProvider` cover the alternative deployment modes. `IdentityVerificationService` hashes the card number for dup-detection and fires `DuplicateIdentityDetected`. Front-end has a `BiometricCapture.vue` component that drives the device camera for face+card composition. 13 new tests (7 HTTP-wire, 6 expiry-reminder) in `tests/Feature/Identity/`. Daily `identity:expiring --window=30` cron nudges employees 30 days ahead of their 12-month re-verification window.
3. **Tamper-evident audit chain** ✅ shipped 2026-05-20. SHA-256 chain in `WriteAuditLog` (lockForUpdate + transaction for total order); `audit:verify-chain --notify` walks the chain and pages super_admins on mismatch; `audit:backfill-chain` is idempotent and extends an existing chain rather than re-genesis. Scheduled daily 03:00 in `routes/console.php`. 11 tests in `tests/Feature/Audit/`.
4. **Postgres production migration** ✅ shipped 2026-05-21. CI matrix in [`.github/workflows/ci.yml`](../../.github/workflows/ci.yml) runs the entire Pest suite on SQLite **and** Postgres 16 (service container) on every PR; `.env.example` carries a documented Postgres section + the rationale for why production must use it; [`db:reset-sequences`](../../app/Console/Commands/ResetSequences.php) bumps every serial sequence to MAX(id)+1 after a bulk import; [`db:dump-for-postgres`](../../app/Console/Commands/DumpForPostgres.php) emits a Postgres-formatted INSERT-only dump from any source driver; [docs/ops/postgres-migration.md](../../docs/ops/postgres-migration.md) is the 9-step operator runbook with rollback section + known-gotcha table. 7 new tests pin DbExpr's cross-driver behaviour in `tests/Feature/Support/`.

### Phase 2 (8–10 weeks) — Government-export interoperability

5. **IPPD payroll export** ✅ shipped 2026-05-21. [`IppdExporter`](../../app/Services/Payroll/Ippd/IppdExporter.php) emits the CAGD-format H/D/T pipe-delimited file: H header carries the MDA code + period + IPPD3 marker, one D row per PayrollLine with pesewa-integer amounts (not decimal GHS), T trailer reconciles to per-column totals. Names are ASCII-transliterated because CAGD's parser is ASCII-only; over-width fields truncate instead of overflowing into the next column. HTTP download at [`payroll-runs.ippd-export`](../../routes/web.php) (gated on `statutory.export`) and [`payroll:ippd-export`](../../app/Console/Commands/IppdExportCommand.php) artisan command. 14 tests including golden-layout assertions on every column index.
6. **GIFMIS journal export** ✅ shipped 2026-05-21. [`GifmisJournalExporter`](../../app/Services/Payroll/Gifmis/GifmisJournalExporter.php) mints a balanced double-entry JV: DR salary expense + employer SSNIT + employer Tier-2, CR net-pay-payable + PAYE + employee SSNIT + employer SSNIT + NHIA + Tier-2 + Tier-3 + voluntary. The exporter **throws** if the journal doesn't balance — an unbalanced JV is a calculator-residual bug, not a soft warning. NHIA split: the employer SSNIT credit is reduced by the NHIA portion which posts to its own GL, keeping the double-entry intact. Config-driven GL-code map per MDA in `config/payroll.php`. HTTP download at [`payroll-runs.gifmis-export`](../../routes/web.php), `payroll:gifmis-export` artisan command, auto-mint via [`MintGifmisJournal`](../../app/Listeners/MintGifmisJournal.php) listener on the new `PayrollRunPaid` event (gated on `auto_mint_on_paid`). 14 tests including the balance-or-throw guarantee.
7. **GhIPSS bank disbursement** ✅ shipped 2026-05-21. `GhIpssAchProvider` implements the existing `DisbursementProvider` contract; since GhIPSS is a *bulk file* rail rather than a per-row API, `send()` stages the row with a deterministic `GHIPSS-{run}-{disbursement}` reference and `refreshStatus()` is a no-op. The actual EFT bytes are produced by [`GhIpssBatchFileBuilder`](../../app/Services/Disbursement/GhIpssBatchFileBuilder.php) in a canonical GhIP CSV layout (CRLF-terminated, sanitised name + narration, 35-char ACH-compatible narration). [`disbursement:ghipss-export`](../../app/Console/Commands/GhIpssExportCommand.php) generates the file or streams to STDOUT. New `bank_sort_code` column on `employees` carries the GhIPSS branch identifier. 11 tests covering provider semantics, file layout, sanitisation, command execution.

### Phase 3 (8 weeks) — Compliance + accessibility

8. **WCAG 2.1 AA audit + fixes** ✅ shipped 2026-05-21. `php artisan a11y:audit` static auditor catches the 7 highest-leverage WCAG breakage patterns (alt text, icon-only-button labels, input/select/textarea labels, empty anchors, positive tabindex). Wired into `.github/workflows/ci.yml` so a fresh violation fails the build. 9 real WCAG 3.3.2 violations identified + fixed across Documents/StampPicker, Documents/Compose, Documents/Index, Documents/Show, Dpa/Submit, and Governance/Incidents pages. Pest regression test [`AccessibilityAuditorTest::it_the_live_project_tree_has_zero_error_severity_WCAG_findings`](../../tests/Feature/Accessibility/AccessibilityAuditorTest.php) locks the zero-error state. Conformance statement at [docs/accessibility/conformance.md](../../docs/accessibility/conformance.md) covers 12 WCAG criteria, the 4 testing layers (CI static, Pest lock, manual + axe-core planned), and known limitations. `@axe-core/playwright` browser audit deferred — listed under "planned" in the conformance doc.
9. **USSD/SMS payroll flow** ✅ shipped 2026-05-21. Payslip + leave-balance + clock-in/out + whistleblower-track were already in [`UssdSessionHandler`](../../app/Services/Messaging/Ussd/UssdSessionHandler.php). New: **bank-change two-factor confirmation** — main-menu option 5 walks the subject through entering the 6-digit SMS code, then shows a masked-account approval screen, then applies the change in a single DB transaction. [`BankChangeRequestService`](../../app/Services/BankChangeRequestService.php) handles the request lifecycle (snapshot old values, hash + SMS the code, supersede prior pending rows, 30-min expiry, 5-failure lockout). Pre-application is the key safety: payroll-redirection fraud needs both the admin's session AND the employee's phone — defence in depth. 13 new tests across the service + USSD flow.
10. **DPA 2012 data-subject portal** ✅ shipped 2026-05-21. Public `/dpa` portal (no auth, throttled 10/min) lets ex-employees + failed applicants file Access / Erasure / Rectification / Portability / Objection requests. Email-verified flow via [`DpaVerificationLink`](../../app/Notifications/DpaVerificationLink.php) — requests stay in `pending_verification` (invisible to DPO) until the subject clicks the emailed magic link, at which point status flips to `submitted` and the Act-843 30-day clock starts. [`PublicDpaController`](../../app/Http/Controllers/PublicDpaController.php) handles submit/verify/track/confirmation; `subject_email` + `reference` are required for tracking so a leaked reference alone tells you nothing. `verification_token` is `$hidden` on the model so it never leaks via API or admin UI. [`DataSubjectExportBuilder`](../../app/Services/Privacy/DataSubjectExportBuilder.php) now emits **CSV alongside JSON** for every payload (Act 843 §17(2) portability) with RFC-4180 escaping. 13 new tests (10 portal + 3 CSV) — total 22 across `tests/Feature/Privacy/`.

### Phase 4 (ongoing) — Ops hardening

11. **2026-05-20-nita-hosting-and-csa-registration.md** *(operational, not code)* — Procurement + paperwork. Migrate prod from current host to a NITA-approved data centre, register the data controller with the DPC, register the operator with the CSA per the Cybersecurity Act 2020, schedule the annual external pen-test, and capture the cert in `docs/compliance/`.
12. **2026-05-20-ai-assistant-wiring.md** *(to be written)* — 2 weeks. Wire the existing `AiAssistantController` skeleton to a real LLM endpoint with per-tenant API keys, prompt-caching, and a guardrail layer that strips PII before egress.

---

## Sequencing & dependencies

```
    1. SAML hardening ───────┐
                             │
    2. Ghana Card adapter ───┤  Phase 1
    3. Tamper-evident audit ─┤  (8–10 weeks, parallelisable)
    4. Postgres migration ───┘

    5. IPPD export ──────────┐
    6. GIFMIS export ────────┤  Phase 2
    7. GhIPSS disbursement ──┘  (8–10 weeks, sequence after PG)

    8. WCAG audit ───────────┐
    9. USSD/SMS payroll ─────┤  Phase 3
   10. DPA portal ───────────┘  (8 weeks)

   11. NITA + CSA + pen-test ┐  Phase 4 (ongoing)
   12. AI assistant wiring ──┘
```

**Postgres migration (#4) blocks IPPD/GIFMIS exports** because both produce large CSV/journal exports that we'll want to stream from Postgres COPY rather than re-paginate via Eloquent on SQLite.

**SAML hardening (#1) blocks Phase 4 NITA-SSO acceptance** since most government IdPs only speak SAML, not OIDC.

**Ghana Card adapter (#2) and tamper-evident audit (#3) are independent** — they can run in parallel.

---

## Sizing summary

| Phase | Effort | Items |
|---|---|---|
| 1 | 8–10 weeks | SAML hardening, Ghana Card NIA, audit hash chain, Postgres migration |
| 2 | 8–10 weeks | IPPD export, GIFMIS journal, GhIPSS disbursement |
| 3 | 8 weeks | WCAG audit, USSD payroll, DPA portal |
| 4 | Ongoing | NITA/CSA paperwork, AI assistant wiring |
| **Total** | **~24 weeks core + ongoing** | 12 sub-plans |

**Critical-path callout:** the first 4 items unlock the government-grade pitch. Everything after is either differentiator (exports) or hygiene (WCAG, DPA, hosting).

---

## Out of scope (for now)

- **HRMIS-specific integrations** beyond IPPD/GIFMIS. If a buyer asks for direct HRMIS API integration we'll spec it then.
- **PSC e-Recruit gateway** — the existing recruitment module covers internal-only flows; PSC integration is a Phase 5 question.
- **CHRAJ direct case-filing** — the whistleblower module already covers anonymous reporting; CHRAJ-direct submission is procurement, not code.

---

## How to use this roadmap

1. Pick a sub-plan from the index above.
2. If it's a `*(to be written)*` placeholder, ask Claude to draft the full plan following the [P3-Assets](./2026-05-15-p3-assets.md) template structure: Goal · Architecture · File map · Implementation steps · Sizing · Out of scope.
3. Execute with `superpowers:subagent-driven-development`.
4. Update this index's Status column when each sub-plan ships.
