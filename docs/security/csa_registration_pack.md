# CSA Registration Pack — CIHRMS

**Status:** Ready for legal submission
**Reg. authority:** Ghana Cyber Security Authority (CSA)
**Legal basis:** Cybersecurity Act 2020 (Act 1038), §49(1)(b) — Critical Information Infrastructure (CII) registration is mandatory for any platform that processes employee, payroll, or statutory data for a chartered public-sector institution.

This document is the org-side submission pack. It maps every section of the CSA's registration form to where the answer comes from in the CIHRMS codebase, so legal counsel can complete the form in one sitting without going back-and-forth with engineering.

---

## 1. Entity declaration

| CSA field | CIHRMS answer |
| --- | --- |
| Entity name | Chartered Institute of Human Resource Management — Ghana (CIHRM-GH) |
| Sector | Public-sector HR / statutory payroll |
| Registration class | **Class B — Sectoral CII** (HR & payroll for a chartered body) |
| Information custodian | DPO / Auditor lane (see §7 below) |
| Primary contact | `integrations@cihrm.gov.gh` |

## 2. System purpose

CIHRMS is the system of record for:
- Employee directory, employment lifecycle, and Ghana Card identity verification
- Statutory payroll calculation (PAYE, SSNIT Tier-1, Tier-2, NHIA) and disbursement
- Time & attendance, including biometric ingestion
- Performance management, learning, loans, off-boarding
- Whistleblower (Act 720) and data-subject (Act 843) workflows
- Public API for partner integration (GIFMIS, IPPD, GhIPSS, MDA dashboards)

## 3. Data categories processed

CSA requires us to enumerate categories of data, not specific fields. Map:

| Category | Source in code | Encrypted-at-rest? |
| --- | --- | --- |
| Direct identifiers (name, email, staff_id, phone) | `users`, `employees` | partial (email/phone in clear; staff_id in clear) |
| Statutory identifiers (Ghana Card, SSNIT no.) | `identity_verifications`, `employees` | **yes** — `EncryptedString` cast |
| Financial (bank account, MoMo number, salary) | `employees.bank_account`, `payroll_lines` | **yes** — bank fields encrypted; salary by RBAC |
| Biometric (attendance fingerprint / face vectors) | `attendance_records.biometric_payload` | **yes** — provider-side hash, never raw biometric |
| Health (benefit claims, sick leave) | `benefit_claims`, `leave_requests` | partial — claim attachments encrypted |
| Investigative (whistleblower content) | `whistleblower_reports` | **yes** — content + identity encrypted; tracking code is the only join key |
| Audit / forensic | `audit_logs` | **append-only**, SHA-256 hash chain (tamper-evident) |

Code reference: `app/Casts/EncryptedString.php` defines the cast; every model with sensitive fields uses it.

## 4. Architecture diagram (text form)

```
              ┌─────────────────────────────────────────────────────┐
              │  Public                                              │
   ┌──────────┤  - Careers (job postings, public apply)              │
   │ Internet │  - Whistleblower (anonymous, tracking-code retrieval)│
   └──────────┤  - Complaints (public-track-by-reference)            │
              └─────────────────────────────────────────────────────┘
                                   │ TLS 1.2+, HSTS, CSP
                                   ▼
              ┌─────────────────────────────────────────────────────┐
              │  Edge — Laravel HTTP kernel                          │
              │  - Throttle (60 rpm anon / 60 rpm authed)            │
              │  - HandleCors, TrustProxies, ValidatePathEncoding    │
              │  - Sanctum (cookie-session for web, bearer for API)  │
              │  - SetUserLocale, ForcePasswordChange                │
              └─────────────────────────────────────────────────────┘
                                   │
                  ┌────────────────┴────────────────┐
                  ▼                                  ▼
            Web (Inertia + Vue)                Public API v1 (REST)
            session-cookie auth                bearer-token auth (Sanctum)
            FormRequest validation             per-route scope check
            Inertia middleware shares user     ApiTokenMetadata (revoke/expiry/IP)
                  │                                  │
                  └────────────────┬─────────────────┘
                                   ▼
              ┌─────────────────────────────────────────────────────┐
              │  Domain services (app/Services/*)                    │
              │  Enum → FormRequest → Service → Event → Resource     │
              │                                                       │
              │  PayrollService, AttendanceService, LeaveService,    │
              │  LoanService, OffboardingService, BenefitsService,   │
              │  WhistleblowerSubmissionService, DataSubjectReqSvc,  │
              │  IdentityVerificationService (pluggable provider)    │
              └─────────────────────────────────────────────────────┘
                                   │
              ┌────────────────────┼────────────────────────────────┐
              ▼                    ▼                                ▼
         MySQL/MariaDB        Queue (Redis)                 Object storage
         (employee/payroll/   - WriteAuditLog (audit chan)   - Encrypted exports
          identity, with     - SendNotifications              (data-subject Access)
          encrypted casts    - GenerateStatutoryReturns       - Auditor-General zip
          + soft deletes)    - WebhookDispatcher              - Whistleblower attaches
                             - DispatchSms / Ussd
                                   │
                                   ▼
              ┌─────────────────────────────────────────────────────┐
              │  External integrations (pluggable adapters)          │
              │  Identity:   NIA, ThirdPartyKyc, ManualUpload        │
              │  Disburse:   MtnMomo, VodafoneCash, AirtelTigo       │
              │  SMS:        Hubtel, Twilio, Log (dev)               │
              │  SSO:        OIDC (NITA-ready), SAML (stub)          │
              │  Webhook:    Partner endpoints (HMAC-signed)         │
              └─────────────────────────────────────────────────────┘
```

## 5. Security controls

This list is the answer to CSA's "implemented controls" matrix. Each item links to the code or migration that proves it exists.

| Control | Code reference |
| --- | --- |
| Encrypted-at-rest PII | `app/Casts/EncryptedString.php`; applied on `IdentityVerification.ghana_card_number`, `WhistleblowerReport.content`, `SsoIdentityProvider.config`, bank fields |
| TOTP 2FA (RFC 6238) | `app/Services/Auth/TwoFactorService.php`; required for `super_admin`, `hr_admin`, `finance_officer` (`two_factor_required` flag) |
| Tamper-evident audit chain | `app/Models/AuditLog.php` + `app/Jobs/WriteAuditLog.php`; SHA-256 hash chain; verified by `php artisan audit:verify-chain` |
| Dual-control on payroll | `app/Services/Payroll/PayrollService.php::approve()` throws on creator-self-approval |
| RBAC + Policies | `database/seeders/RolePermissionSeeder.php` (canonical), `app/Policies/*` (object-level), `app/Models/User::hasPermission()` |
| Rate limiting (anti-brute) | `app/Providers/AppServiceProvider.php::boot()` registers `RateLimiter::for('api', ...)`; route-level `throttle:N,1` on public-facing endpoints |
| Force first-login password change | `users.password_must_change`, `app/Http/Middleware/ForcePasswordChange.php` |
| Whistleblower confidentiality | Submission-time encryption + tracking-code-only retrieval (`app/Services/Whistleblower/*`); RolePermissionSeeder segregates `whistleblower.investigate` from HR roles |
| Right-to-erasure tombstoning | `app/Services/Privacy/ErasureService.php`; preserves audit chain by tombstoning identifiers |
| Data export with hash | `app/Services/Privacy/DataSubjectExportBuilder.php` produces a ZIP with SHA-256 manifest |
| Webhook signature (HMAC) | `app/Services/Api/WebhookDispatcher.php`; partner-side verifies; secrets encrypted at rest |
| API token scopes + sidecar metadata | `app/Models/ApiTokenMetadata.php`; revoke, expiry, IP-allowlist gating beyond Sanctum default |
| WCAG 2.1 AA accessibility | `resources/js/Components/SkipLink.vue`, `AriaLiveAnnouncer.vue`; `php artisan a11y:audit` |

## 6. Statutory compliance map

| Statute | How CIHRMS satisfies it |
| --- | --- |
| Data Protection Act 2012 (Act 843) | DSR workflow (`Privacy/*`); 30-day SLA; DPO is the Auditor role (segregated from HR); right-to-access, rectification, withdrawal, erasure |
| Whistleblower Act 2006 (Act 720) | Anonymous channel; tracking-code retrieval; investigator role segregated from HR line management; encryption at rest |
| Labour Act 2003 (Act 651) | Leave entitlements; overtime per §35 (1.5×/2× tiers); off-boarding & final settlement |
| Income Tax Act 2015 (Act 896) | PAYE 7-bracket calculator (`app/Services/Payroll/PayeCalculator.php`); statutory return export (PAYE) |
| SSNIT Act 766 / Tier-2 NPRA | SSNIT 13.5/5.5 with 2.5% NHIA split + Tier-2 5%; max insurable GHS 61,000; reference seeded |
| NIA Act / Ghana Card | `IdentityVerificationService` requires verified card before payroll line; encrypted card number with hash for lookup |
| Persons with Disability Act 2006 (Act 715) | WCAG 2.1 AA throughout web UI; `docs/wcag_aa_checklist.md` |
| Cybersecurity Act 2020 (Act 1038) | **This pack** — CII registration; CSA security audit consent (§7) |

## 7. Designated officers (CSA requires names + emails)

| Role | Mapped to | Notes |
| --- | --- | --- |
| Data Protection Officer | Auditor role | Holds `privacy.fulfill`; segregated from HR by RBAC seeder |
| Information Security Officer | TBD — org-side appointment | Document the appointment in the registration form |
| Incident Response lead | TBD — org-side appointment | Use whistleblower channel for internal reports |

## 8. Submission checklist for legal

- [ ] Print this document and the org chart (page 1 of the CSA form).
- [ ] Attach last 90 days of `audit_logs` chain-verification output:
      `php artisan audit:verify-chain --limit=10000 > audit-verify-90d.log`
- [ ] Generate the Auditor-General report pack for last fiscal year:
      `php artisan reports:ag-pack --year=2025 --out=storage/ag-pack-2025.zip`
- [ ] Confirm CSA-listed sectoral regulator (NITA) has been notified separately.
- [ ] Designate the ISO and IR lead (open positions above).
- [ ] Sign the data-processor agreement template (templated form 3-B).

## 9. Annual re-attestation

CSA requires annual re-attestation. Calendar reminders:
- **Q1**: re-run audit chain verification across the full prior fiscal year.
- **Q2**: pen-test (see `docs/security/pentest_scope.md`).
- **Q3**: refresh DPO + ISO designations if staff changed.
- **Q4**: submit the re-attestation form.
