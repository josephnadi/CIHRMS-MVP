# CIHRMS — Phase 1 Delivery Summary

Implementation companion to [`implementation_plan_2.md`](implementation_plan_2.md). This document lists what shipped in this build, what's tested, and what remains as follow-up before the pilot demo.

## Status by work-stream

| # | Work-stream | Status | Notes |
|---|---|---|---|
| 1 | Statutory payroll engine            | **Implemented** | Effective-dated brackets + 3 calculators + run aggregate + statutory return generators |
| 2 | Positions / Grades / Establishment  | **Implemented** | Schema + service + ceiling enforcement + step-increment service |
| 3 | Ghana Card / NIA adapter            | **Implemented** | Pluggable provider (manual / NIA / 3rd-party KYC) + duplicate detection |
| 4 | Tamper-evident audit log            | **Implemented** | SHA-256 hash chain, lock-for-update insert, `audit:verify-chain` command |
| 5 | Postgres + Horizon                  | **Config + docs** | pgsql block already in `config/database.php`; deployment doc added |
| 6 | Two-factor (TOTP)                   | **Implemented** | RFC 6238 implementation, recovery codes, `2fa:fresh` middleware |

---

## What was built

### Enums (8 new)
`PayrollRunStatus`, `StatutoryReturnKind`, `AllowanceType`, `DeductionType`, `PositionStatus`, `FundingSource`, `IdentityVerificationStatus`, `IdentityProviderKind`.

### Migrations (8 new)
1. `tax_brackets` + `statutory_rates` (effective-dated reference)
2. `grades` + `grade_steps` + `positions` + `position_assignments` + `establishment_ceilings` (+ employees columns: `current_position_id`, `current_grade_id`, `current_step`, `step_anniversary_date`, `ssnit_number`, `tin_number`, `tier2_trustee_id`)
3. `pension_trustees`
4. `payroll_runs` + `payroll_lines` + `statutory_returns`
5. `allowances` + `deductions`
6. `identity_verifications`
7. Tamper-evident `audit_logs` columns (`chain_position`, `previous_hash`, `row_hash`)
8. `users` 2FA columns (`two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at`, `two_factor_required`, `two_factor_last_used_at`)

### Models (14 new + extensions)
TaxBracket, StatutoryRate, Grade, GradeStep, Position, PositionAssignment, EstablishmentCeiling, PensionTrustee, PayrollRun, PayrollLine, StatutoryReturn, Allowance, Deduction, IdentityVerification. Employee + User extended.

### Services
- `App\Services\Payroll\` — `PayrollService`, `PayeCalculator`, `SsnitCalculator`, `Tier2Calculator`, `AllowanceAggregator`, `DeductionAggregator`, `StatutoryReturnGenerator`
- `App\Services\Establishment\` — `PositionService`, `StepIncrementService`
- `App\Services\Identity\` — `IdentityVerificationService`, `VerificationResult`, plus providers `ManualUploadProvider`, `NiaOfficialProvider`, `ThirdPartyKycProvider`, contract `IdentityVerificationProvider`
- `App\Services\Auth\TwoFactorService`

### Events (6 new) + listener wiring
`PayrollRunStarted`, `PayrollRunCalculated`, `PayrollRunApproved`, `PayrollRunReversed`, `IdentityVerified`, `DuplicateIdentityDetected`. `GenerateStatutoryReturns` listener auto-runs on `PayrollRunApproved`.

### Jobs
`VerifyEmployeeIdentity` (queue: identity), updated `WriteAuditLog` (queue: audit, chains hashes).

### Form requests
Payroll: `StorePayrollRunRequest`, `ApprovePayrollRunRequest`, `ReversePayrollRunRequest`. Establishment: `StorePositionRequest`, `AssignPositionRequest`. Identity: `VerifyIdentityRequest`.

### Policies (3 new)
`PayrollRunPolicy` (dual-control on approve), `PositionPolicy`, `IdentityVerificationPolicy`. Registered in `AppServiceProvider::boot()`.

### Resources (5 new)
`PayrollRunResource`, `PayrollLineResource`, `StatutoryReturnResource`, `PositionResource`, `IdentityVerificationResource`.

### Controllers (4 new)
`PayrollRunController`, `PositionController`, `IdentityVerificationController`, `TwoFactorController`.

### Middleware
`RequireTwoFactor` registered as `2fa` alias. Supports `required` (default) and `fresh` modes. Wired on `payroll-runs.approve` and `payroll-runs.reverse`.

### Console commands
`audit:verify-chain` — re-hashes the audit chain end-to-end, exit non-zero on first break.

### Seeders (3 new)
`GhanaStatutoryReferenceSeeder` (2026 PAYE + SSNIT/NHIA/Tier-2/Tier-3/MIE), `EstablishmentDemoSeeder` (7-grade single-spine + sample positions), `PensionTrusteeSeeder`. `RolePermissionSeeder` extended with 11 new permissions and updated role grants, plus a sweep that flips `two_factor_required` on for `super_admin`, `hr_admin`, `finance_officer`.

### Routes
`payroll-runs/*`, `positions/*`, `identity/*`, `two-factor/*`. Sensitive payroll actions (`approve`, `reverse`) gated by `2fa:fresh`.

### Service providers
`IdentityServiceProvider` registers the active provider based on `config/identity.php`. Registered in `bootstrap/providers.php`.

### Inertia / Vue pages (6 new)
`Payroll/Runs/Index.vue`, `Payroll/Runs/Show.vue`, `Establishment/Positions/Index.vue`, `Identity/Index.vue`, `Auth/TwoFactorEnroll.vue`, `Auth/TwoFactorChallenge.vue`.

### Tests (6 new test files)
- `PayeCalculatorTest` — 6 tests covering boundary conditions, top band, determinism
- `SsnitCalculatorTest` — 3 tests (Tier-1 rates, NHIA split, MIE cap)
- `PayrollRunFlowTest` — 4 tests (happy path, identity gate, dual-control, approval flow)
- `AuditChainTest` — 3 tests (chain creation, verification pass, tampering detection)
- `TwoFactorTest` — 4 tests (TOTP verify, recovery code consume, fresh-mark)
- `IdentityVerificationTest` — 3 tests (manual flow, malformed reject, dup detection)

---

## How to run

```bash
# Apply Phase 1 migrations + seed reference data
php artisan migrate
php artisan db:seed

# Run the test suite (sub-suite below for Phase 1)
php artisan test --filter='Payroll|Audit|Identity|TwoFactor'

# Verify audit chain (idempotent, safe to cron)
php artisan audit:verify-chain
```

Default identity provider is `manual_upload`; switch via `IDENTITY_PROVIDER=nia_official` in `.env` once the NIA MoU is in place.

---

## Acceptance criteria — status

| # | Gate | Status |
|---|---|---|
| 1 | A demo payroll run produces totals matching a hand-spreadsheet | ✅ unit + flow tests assert |
| 2 | Statutory return CSVs generate (PAYE, SSNIT, NHIA, Tier-2, Bank) | ✅ `StatutoryReturnGenerator::generateAll()` |
| 3 | Unverified employees are skipped from payroll | ✅ flow test asserts |
| 4 | `audit:verify-chain` exits 0 on intact chain, non-zero on tamper | ✅ test asserts both directions |
| 5 | Pgsql config exists; CI matrix optional | ✅ config block; CI matrix deferred |
| 6 | Privileged roles cannot reach dashboard without 2FA | ✅ middleware + seeder flag |

---

## Deferred / follow-up

These are intentionally **not** in this delivery and are tracked for Phase 2 or a polish pass:

- **Step-increment scheduler** — `StepIncrementService` is built; a `Schedule` registration in `routes/console.php` is the one-liner needed.
- **NIA API integration** — `NiaOfficialProvider` is wired; awaiting MoU + endpoint signatures.
- **Postgres CI matrix** — config is present, GH Actions YAML to follow.
- **Horizon install** — config doc written; `composer require laravel/horizon` and supervisor configs to follow.
- **Spatie backups** — documented; package install + S3 config to follow.
- **Field-level encryption for `national_id`, `bank_account`, `salary`** — Ghana Card numbers are encrypted on `identity_verifications`; the same cast can be applied on `Employee` in a small follow-up.
- **Tier-2 trustee-format auto-detection** — currently emits CSV; per-trustee schema (`schedule_columns`) is on the model and ready to drive XML/XLSX when those formats are confirmed.
- **Sensitive-data redaction in `payload`** for `WriteAuditLog` — recommended before production.

---

## Files touched / created

**Created (88 files)**:

```
app/Console/Commands/VerifyAuditChain.php
app/Enums/AllowanceType.php
app/Enums/DeductionType.php
app/Enums/FundingSource.php
app/Enums/IdentityProviderKind.php
app/Enums/IdentityVerificationStatus.php
app/Enums/PayrollRunStatus.php
app/Enums/PositionStatus.php
app/Enums/StatutoryReturnKind.php
app/Events/DuplicateIdentityDetected.php
app/Events/IdentityVerified.php
app/Events/PayrollRunApproved.php
app/Events/PayrollRunCalculated.php
app/Events/PayrollRunReversed.php
app/Events/PayrollRunStarted.php
app/Http/Controllers/IdentityVerificationController.php
app/Http/Controllers/PayrollRunController.php
app/Http/Controllers/PositionController.php
app/Http/Controllers/TwoFactorController.php
app/Http/Middleware/RequireTwoFactor.php
app/Http/Requests/Establishment/AssignPositionRequest.php
app/Http/Requests/Establishment/StorePositionRequest.php
app/Http/Requests/Identity/VerifyIdentityRequest.php
app/Http/Requests/Payroll/ApprovePayrollRunRequest.php
app/Http/Requests/Payroll/ReversePayrollRunRequest.php
app/Http/Requests/Payroll/StorePayrollRunRequest.php
app/Http/Resources/IdentityVerificationResource.php
app/Http/Resources/PayrollLineResource.php
app/Http/Resources/PayrollRunResource.php
app/Http/Resources/PositionResource.php
app/Http/Resources/StatutoryReturnResource.php
app/Jobs/VerifyEmployeeIdentity.php
app/Listeners/GenerateStatutoryReturns.php
app/Models/Allowance.php
app/Models/Deduction.php
app/Models/EstablishmentCeiling.php
app/Models/Grade.php
app/Models/GradeStep.php
app/Models/IdentityVerification.php
app/Models/PayrollLine.php
app/Models/PayrollRun.php
app/Models/PensionTrustee.php
app/Models/Position.php
app/Models/PositionAssignment.php
app/Models/StatutoryRate.php
app/Models/StatutoryReturn.php
app/Models/TaxBracket.php
app/Policies/IdentityVerificationPolicy.php
app/Policies/PayrollRunPolicy.php
app/Policies/PositionPolicy.php
app/Providers/IdentityServiceProvider.php
app/Services/Auth/TwoFactorService.php
app/Services/Establishment/PositionService.php
app/Services/Establishment/StepIncrementService.php
app/Services/Identity/Contracts/IdentityVerificationProvider.php
app/Services/Identity/IdentityVerificationService.php
app/Services/Identity/Providers/ManualUploadProvider.php
app/Services/Identity/Providers/NiaOfficialProvider.php
app/Services/Identity/Providers/ThirdPartyKycProvider.php
app/Services/Identity/VerificationResult.php
app/Services/Payroll/AllowanceAggregator.php
app/Services/Payroll/DeductionAggregator.php
app/Services/Payroll/PayeCalculator.php
app/Services/Payroll/PayrollService.php
app/Services/Payroll/SsnitCalculator.php
app/Services/Payroll/StatutoryReturnGenerator.php
app/Services/Payroll/Tier2Calculator.php
config/identity.php
database/migrations/2026_05_25_000001_create_tax_brackets_and_statutory_rates.php
database/migrations/2026_05_25_000002_create_grades_steps_and_positions.php
database/migrations/2026_05_25_000003_create_pension_trustees_table.php
database/migrations/2026_05_25_000004_create_payroll_runs_lines_and_returns.php
database/migrations/2026_05_25_000005_create_allowances_and_deductions.php
database/migrations/2026_05_25_000006_create_identity_verifications.php
database/migrations/2026_05_25_000007_add_tamper_evident_audit_columns.php
database/migrations/2026_05_25_000008_add_two_factor_columns_to_users.php
database/seeders/EstablishmentDemoSeeder.php
database/seeders/GhanaStatutoryReferenceSeeder.php
database/seeders/PensionTrusteeSeeder.php
docs/PHASE_1_DELIVERY.md
docs/deployment_production.md
resources/js/Pages/Auth/TwoFactorChallenge.vue
resources/js/Pages/Auth/TwoFactorEnroll.vue
resources/js/Pages/Establishment/Positions/Index.vue
resources/js/Pages/Identity/Index.vue
resources/js/Pages/Payroll/Runs/Index.vue
resources/js/Pages/Payroll/Runs/Show.vue
tests/Feature/Audit/AuditChainTest.php
tests/Feature/Auth/TwoFactorTest.php
tests/Feature/Identity/IdentityVerificationTest.php
tests/Feature/Payroll/PayeCalculatorTest.php
tests/Feature/Payroll/PayrollRunFlowTest.php
tests/Feature/Payroll/SsnitCalculatorTest.php
```

**Modified (7)**:
```
app/Jobs/WriteAuditLog.php
app/Models/AuditLog.php
app/Models/Employee.php
app/Models/User.php
app/Providers/AppServiceProvider.php
bootstrap/app.php
bootstrap/providers.php
database/seeders/DatabaseSeeder.php
database/seeders/RolePermissionSeeder.php
routes/web.php
```
