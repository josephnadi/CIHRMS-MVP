# Security Medium + Low Fixes — Implementation Plan

> Follow-up to `2026-05-26-security-critical-high-fixes.md`. Covers 16 Mediums + 11 Lows from the 2026-05-26 audit. M11 (virus scan) skipped per user direction; L7 (JE integer-cents) deferred per user direction.

**Goal:** Close the M-tier and L-tier security findings on top of the already-shipped C+H fixes.

**Architecture:** Mostly surgical changes layered onto existing Enum → FormRequest → Service → Event → Resource pattern. One new middleware (SecurityHeaders), one new enum (Permission), one new migration (password history + reconciliation audit columns).

**Scope:** 27 fixes grouped into bundles. Each bundle = one focused commit.

---

## Bundles

### Bundle 1 — Config hardening (M1, M3, L5, L11)
- `.env.example`: `SESSION_ENCRYPT=true` recommendation comment
- `AppServiceProvider`: `URL::forceScheme('https')` when env is production
- `config/auth.php`: `password_timeout` default 900s (15 min) instead of 10800
- `docs/TRD.md` (or `docs/delivery_dossier/...`): align Argon2id mention with bcrypt-cost-12 reality

### Bundle 2 — Security headers middleware (M2)
- New `app/Http/Middleware/SecurityHeaders.php`: X-Frame-Options DENY, X-Content-Type-Options nosniff, Referrer-Policy strict-origin-when-cross-origin, Permissions-Policy minimal, Strict-Transport-Security max-age=31536000
- Register in `bootstrap/app.php` web group
- Test asserts headers present on a sample route

### Bundle 3 — Health endpoint trim (M4)
- `HealthApiController::__invoke` returns only `{service, version, status, time}` — no `env` leak
- Test confirms `env` key absent

### Bundle 4 — Login per-staff_id global throttle (M5)
- `LoginRequest::throttleKey` — add `RateLimiter::for('login_staff_id', ...)` keyed by staff_id only
- Block at 10/15-min sliding window per staff_id, in addition to existing staff_id+IP per-5 limit
- Test: 11 attempts on same staff_id from different IPs → 429 on the 11th

### Bundle 5 — Force-password-change interstitial tightening (M6)
- `ForcePasswordChange::handle` — keep allowed list minimal: `password.confirm`, `password.update`, `logout`, and only the GET for `profile.edit` (the page hosting the form)
- Test: an authenticated `password_must_change=true` user trying `/dashboard` → redirect to `profile.edit`

### Bundle 6 — Authz tightening (M7, M8, M9)
- M7: `EmployeeController::updateDepartment`/`destroyDepartment` add `$this->authorize('update'|'delete', $department)`
- M7: Create `DepartmentPolicy` with `update`/`delete` gated by `employees.manage`
- M8: `ApInvoiceController::show/approve/cancel`, `JournalController::show`, `ArInvoiceController::approve` add `$this->authorize(...)`
- M8: Create `ApInvoicePolicy`, `JournalEntryPolicy`, `ArInvoicePolicy` if missing
- M9: `StatementController::show(Customer $customer)` — add `$this->authorize('view', $customer)`
- M9: Create `CustomerPolicy::view` keyed off `finance.view` permission

### Bundle 7 — OIDC SSRF allowlist (M14)
- `OidcIdTokenVerifier`: add `assertSafeHttpUrl()` helper. Reject http://, localhost, 127.0.0.0/8, 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 169.254.0.0/16 before any Http::get
- Apply to JWKS fetch AND any other config-driven URL the verifier hits
- Same helper exposed/reused for `OidcSsoAdapter::resolveConfig` discovery fetch
- Tests cover internal-IP rejection + valid HTTPS allowed

### Bundle 8 — Input validation hardening (M13, M16, L8)
- `ReportsController::export`: validate `year` (integer, 2000..year+1) and `month` (Y-m format)
- All Finance amount FormRequests (`StorePaymentIntent`, `StoreManualJournalEntry`, `StoreApPayment`, `StoreArReceipt`): add `max:9999999.99`
- `StoreManualJournalEntryRequest`: custom rule that rejects lines where `debit_amount == 0 && credit_amount == 0`

### Bundle 9 — File upload + storage validation (M10, M12, L1, L2, L3, L4)
- M10/L3: Letterhead/Stamp/Watermark FormRequests use `exif_imagetype()` verification in `withValidator`
- M12: `StatementImportService` (or CSV parser): reject files with > 100k lines or > 50MB raw
- L1: `DocumentRenderService` temp files use `tempnam` + `chmod(0600)` + explicit `register_shutdown_function` unlink
- L2: `DocumentController::download` runs `original_name` through `Str::slug` + extension whitelist
- L4: Add `Employee::deleting` model observer that deletes the avatar + document files from the local disk

### Bundle 10 — CSV formula injection (M15)
- `PayrollExport::map`: pass every string cell through `Str::ltrim($v, "=+-@\t\r")` + prefix `'` for any leading `=+-@`
- Helper `escapeSpreadsheetCell()` in `app/Support/Spreadsheet.php`
- Test: row with description `=cmd|'/c calc'` exports as `'=cmd|'/c calc'`

### Bundle 11 — CI security audit (M17)
- `.github/workflows/ci.yml`: add `composer audit --locked` (non-blocking warn) + `npm audit --audit-level=high` (non-blocking warn)
- Run after dependency install

### Bundle 12 — Password reuse history (L6)
- Migration: `password_histories` table (`id`, `user_id`, `password_hash`, `created_at`)
- New service `PasswordHistoryService` — records on password change, checks new password not in last 5
- Wire into `NewPasswordController::store` and `PasswordController::update`
- Custom validation rule `NotRecentPassword` for the form requests
- Test: changing password to a recent one returns 422

### Bundle 13 — Permission enum (L10)
- New `app/Enums/Permission.php` cases for every permission string currently scattered across the codebase (`employees.manage`, `incidents.review`, `finance.*`, etc.)
- Update `User::hasPermission` to accept `Permission|string`
- Update FormRequests / middleware progressively (not all at once — only `hasPermission` call sites that get touched)

### Bundle 14 — Bank reconciliation audit columns (L9)
- Migration: `bank_statement_lines` add nullable `matched_by` (FK users.id) + `matched_at` (timestamp)
- `ReconciliationService::link()` writes these alongside the existing Match row
- Test asserts both columns populate

### Bundle 15 — v-html cleanup (L12)
- Switch hardcoded/safe `v-html` to `{{ }}` in: `Departments/Show.vue` (3 occurrences), `Pagination.vue` (label), `Chat/Index.vue` (label)
- Where label is real HTML from Laravel paginator, use `:aria-label` + plain text rather than v-html

---

## Pre-flight

- Confirm baseline 1018/1018 tests pass on `dossier/v1.0` at `1c5a408`
- No new deps for this batch (all native Laravel + Vue patterns)

## Final validation

- Run `php artisan test --parallel`
- Run `npm run build`
- Report total commits + new test count + skipped (M11, L7) + remaining follow-ups
