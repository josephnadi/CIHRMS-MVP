# Security Control Mapping — CIHRMS

Maps each CIHRMS technical control to the matching control number in the three frameworks that auditors check against in Ghana: **ISO/IEC 27001:2022 Annex A**, **NIST CSF 2.0**, and **Ghana DPA 2012 (Act 843)**.

Use this when answering RFP security questionnaires or preparing the annual ISO 27001 surveillance audit.

---

## A. Identity & access

| Control | ISO 27001:2022 | NIST CSF 2.0 | DPA 2012 | Code reference |
| --- | --- | --- | --- | --- |
| Unique user ID per person | A.5.16, A.5.17 | PR.AA-01 | §27 (accuracy) | `users.staff_id` unique; soft delete preserves the identifier |
| Password complexity + history | A.5.17 | PR.AA-01 | — | Laravel default + `users.password_must_change` |
| TOTP 2FA (RFC 6238) | A.5.17, A.8.5 | PR.AA-03 | — | `app/Services/Auth/TwoFactorService.php`; enforced via `users.two_factor_required` for privileged roles |
| Force first-login change | A.5.17 | PR.AA-01 | — | `app/Http/Middleware/ForcePasswordChange.php` |
| Role-based access | A.5.15, A.5.18 | PR.AA-05 | §29 (lawful processing) | `database/seeders/RolePermissionSeeder.php`; `app/Policies/*` |
| Privilege segregation | A.5.3, A.8.2 | PR.AA-05 | — | `whistleblower.investigate` separated from HR; `payroll.approve` separated from `payroll.run` |
| API token scopes + revoke | A.8.5 | PR.AA-03 | — | Sanctum + `app/Models/ApiTokenMetadata.php` |
| SSO with JIT provisioning | A.5.17 | PR.AA-05 | — | `app/Services/Sso/*` |

## B. Data protection

| Control | ISO 27001:2022 | NIST CSF 2.0 | DPA 2012 | Code reference |
| --- | --- | --- | --- | --- |
| Encryption at rest of identifiers | A.8.24 | PR.DS-01 | §30 (security of personal data) | `app/Casts/EncryptedString.php` on Ghana Card, SSO config, whistleblower content |
| Encryption in transit (TLS 1.2+) | A.8.24 | PR.DS-02 | §30 | infra-level (org-side cert); HSTS header |
| Right of access | — | — | §35 | `app/Services/Privacy/DataSubjectExportBuilder.php` produces ZIP with SHA-256 manifest |
| Right of rectification | — | — | §38 | self-service `Profile/Edit.vue`; audit-logged |
| Right of withdrawal | — | — | §41 | `DataSubjectRequestService::withdraw()` — subject-only check enforced |
| Right of erasure | — | — | §40 | `app/Services/Privacy/ErasureService.php`; tombstoning preserves audit chain |
| Data subject reference | — | — | §35(2) | `DSR-{YYYY}-{N}` reference; tracked per request |
| Retention & disposal | A.8.10 | PR.IP-06 | §28 | soft-delete trait; tombstones on erasure |

## C. Audit & monitoring

| Control | ISO 27001:2022 | NIST CSF 2.0 | DPA 2012 | Code reference |
| --- | --- | --- | --- | --- |
| Tamper-evident logging | A.8.15 | DE.AE-03 | §30 | `app/Models/AuditLog.php` SHA-256 hash chain; `app/Jobs/WriteAuditLog.php` |
| Chain verification | A.8.16 | DE.AE-03 | — | `php artisan audit:verify-chain` |
| Auditor-General pack | A.5.34 | RS.AN-04 | — | `app/Services/Reports/AuditorGeneralReportPack.php` — ZIP w/ MANIFEST.md |
| Login attempt log (SSO) | A.8.15 | DE.AE-03 | — | `app/Models/SsoLoginAttempt.php` |
| Failed-attempt rate limiting | A.8.5 | DE.CM-01 | — | `throttle:N,1` on login + public routes |

## D. Statutory & business-logic guards

| Control | ISO 27001:2022 | NIST CSF 2.0 | DPA 2012 | Code reference |
| --- | --- | --- | --- | --- |
| Identity gate on payroll | A.5.30 | PR.AA-05 | — | `PayrollService::calculate()` gate 1: `Employee::hasUsableIdentity()` |
| Attendance gate on payroll (ghost-worker) | A.5.30 | PR.AA-05 | — | `PayrollService::calculate()` gate 2: `days_worked + days_on_leave == 0` skips |
| Dual-control on payroll approval | A.5.3 | PR.AA-05 | — | `PayrollService::approve()` throws on creator-self-approval |
| Dual-control on calibration | A.5.3 | PR.AA-05 | — | `CalibrationService` separates `calibrate` from `calibrate_apply` |
| Statutory rate references | A.5.31 | ID.GV-03 | — | `database/seeders/GhanaStatutoryReferenceSeeder.php` (PAYE, SSNIT, NHIA, Tier-2) |
| Ghana Card hash-without-cleartext lookup | A.8.11 | PR.DS-01 | §30 | `IdentityVerification::hashCardNumber()` — sha256, salt-derived |

## E. Network & infrastructure

| Control | ISO 27001:2022 | NIST CSF 2.0 | DPA 2012 | Notes |
| --- | --- | --- | --- | --- |
| WAF in front of HTTP edge | A.8.20, A.8.21 | PR.AC-05 | — | org-side; not in this repo |
| Rate-limiting per token & IP | A.8.5 | DE.CM-01 | — | `AppServiceProvider::boot()` registers `RateLimiter::for('api', …)`; route-level throttles |
| HMAC webhook signature | A.8.20 | PR.DS-02 | — | `app/Services/Api/WebhookDispatcher.php` |
| IP-allowlist for partner tokens | A.5.18 | PR.AA-05 | — | `ApiTokenMetadata.ip_allowlist` |
| Backups & restoration test | A.8.13 | PR.IP-04 | — | org-side; not in this repo |

## F. Accessibility / inclusion

| Control | ISO 27001:2022 | NIST CSF 2.0 | DPA 2012 | Code reference |
| --- | --- | --- | --- | --- |
| WCAG 2.1 AA compliance | — | — | (PWD Act 2006, Act 715) | `docs/wcag_aa_checklist.md`; `php artisan a11y:audit` |
| Multilingual UI (en/tw/ga/ee) | — | — | — | `lang/{en,tw,ga,ee}/*.php`; `LocaleResolver` |

## G. Continuity

| Control | ISO 27001:2022 | NIST CSF 2.0 | DPA 2012 | Notes |
| --- | --- | --- | --- | --- |
| Background-sync offline | A.5.30 | RC.CO-03 | — | `resources/js/composables/useOfflineQueue.js`; `public/sw.js` |
| Statutory return regeneration | A.5.30 | RC.IM-02 | — | `app/Services/Payroll/StatutoryReturnGenerator.php` is idempotent |

---

## How to use this map

- **For an RFP security questionnaire**: paste the ISO 27001 control numbers into the form; the code reference column proves the control exists.
- **For an ISO 27001 audit**: this is the Statement of Applicability (SOA) compressed to one table. Expand each row in your SOA document.
- **For CSA re-attestation**: the DPA 2012 column maps directly to the questions on form 3-B.
- **When adding a new module**: extend this map *before* merging. Don't ship a feature without claiming the controls it satisfies.

## What's NOT in this map (do not pretend it is)

- Disaster recovery RPO/RTO numbers — these depend on org-side infrastructure choices
- Physical security of the server room — N/A for cloud-hosted, document the cloud provider's attestations instead
- Vendor risk (third-party SaaS) — separate document; not in this repo

## Gaps acknowledged

We are tracking these as open items, NOT claiming them complete:

1. **Pen-test artefact**: see `docs/security/pentest_scope.md`; engagement not yet scheduled.
2. **SIEM integration**: audit_logs are tamper-evident locally but not shipped to a SIEM. Org has agreed this is OK for MVP — re-evaluate at 250+ employees.
3. **Backup integrity test**: backups exist (org-side); restoration is not yet tested end-to-end.
4. **Real CIHRM brand assets**: PWA icons are placeholders until design hands over.
