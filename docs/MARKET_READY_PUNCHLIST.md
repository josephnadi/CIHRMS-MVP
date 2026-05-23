# CIHRMS Market-Readiness Punch List

**Audit date:** 2026-05-23
**Scope:** Whole app — 127 Inertia pages, 381 web routes, ~50 service modules
**Method:** Read-only static analysis + memory-file cross-reference

---

## CRITICAL — would block / risk a production launch

### C1. AP payment endpoints lack `2fa:fresh` gate
**Risk:** Control asymmetry. AR receipts/void/write-off are gated with `2fa:fresh`. AP payment store/void/disburse are NOT. Both mutate GL balances through `JournalPostingService`. A stolen session can drain organisational cash through AP payments without a second factor.

**Evidence:**
- [routes/web.php:928-930](routes/web.php#L928-L930) — `ap-payments.*` group has only `permission:ap_invoices.pay`; no `2fa:fresh`
- [routes/web.php:977-980](routes/web.php#L977-L980) — `ar-receipts.*` correctly chains `['permission:ar_invoices.receive', '2fa:fresh']`

**Fix scope:** ~15 minutes. Add `2fa:fresh` to the AP payments middleware group; mirror an existing AR test (`pi2faFresh()` helper pattern from F4).

---

### C2. Manual journal posting (`journal.store`) lacks `2fa:fresh`
**Risk:** Direct GL mutation with no second factor. Per F2 design, this route is emergency-only (operator posts a one-off correcting JE) but currently any holder of `journal.post` permission can do it from a stolen session.

**Evidence:**
- [routes/web.php:938-940](routes/web.php#L938-L940) — `journal.store` has `permission:journal.post` but no `2fa:fresh`
- Compare: `payroll.reverse`, `loans.disburse`, `privacy.fulfill` all gate with `2fa:fresh`

**Fix scope:** ~5 minutes. Add `2fa:fresh` to the journal-post route. Existing F2 manual-JE test will continue to pass once given a fresh-2FA user via `pi2faFresh()`-style helper.

---

### C3. Reference-generation race (`count() + 1` pattern)
**Risk:** Concurrent invoice/payment submissions can collide on `reference` UNIQUE constraints, causing one of the two to 500 instead of getting a clean retry. F2/F3/F4/F5 memory files all explicitly call this out as "acceptable MVP." Acceptable while only one finance officer transacts at a time; problematic with two operators on the same minute.

**Evidence:** 11 services use the pattern:
- `ApPaymentService::nextReference()` ([app/Services/Finance/ApPaymentService.php:169](app/Services/Finance/ApPaymentService.php#L169))
- `ArReceiptService::nextReference()` ([app/Services/Finance/ArReceiptService.php:179](app/Services/Finance/ArReceiptService.php#L179))
- `PaymentIntentService::nextReference()` ([app/Services/Finance/PaymentIntentService.php:91](app/Services/Finance/PaymentIntentService.php#L91))
- `VendorInvoiceService::nextReference()` ([app/Services/Finance/VendorInvoiceService.php:198](app/Services/Finance/VendorInvoiceService.php#L198))
- … and 6 more

**Fix scope:** ~4-6h. Either add a `sequences` table with `SELECT FOR UPDATE` increment, or fall back to `Str::ulid()` for refs that don't need human-readable sequencing.

---

### C4. Kiosk face-scan is stubbed
**Risk:** Identity verification at the public attendance kiosk falls back to operator-supplied `employee_no` + name match. Functional but trust-weak — anyone with knowledge of an `employee_no` could clock in for a coworker.

**Evidence:**
- [resources/js/Pages/Kiosk/Index.vue:194](resources/js/Pages/Kiosk/Index.vue#L194) — `faceStatus.value = 'Face recognition coming soon...'`

**Fix scope:** Vendor-dependent (Face++, AWS Rekognition, ZKTeco SDK). Days, not hours. **Recommendation: ship without face-scan; document the limitation in the kiosk setup guide; revisit post-launch.** Not a true blocker — biometric devices already gate the higher-trust clock-in flow via `BiometricWebhookController`.

---

## IMPORTANT — daily-UX wart but won't immediately scare a customer off

### I1. `vendor_invoice_no` uniqueness only at DB level
**Why this matters:** Duplicate submission throws a generic 500. F3 already established the right pattern (closure-based `Rule::unique()` in FormRequest). F2 was deferred to "fix in F3"; F3 didn't end up touching F2.

**Evidence:**
- F2 memory file `project_finance_f2.md` lists this as deferral #1
- [app/Http/Requests/Finance/StoreVendorInvoiceRequest.php](app/Http/Requests/Finance/StoreVendorInvoiceRequest.php) — no per-vendor uniqueness rule
- F3's [app/Http/Requests/Finance/StoreArInvoiceRequest.php](app/Http/Requests/Finance/StoreArInvoiceRequest.php) — the model to copy

**Fix scope:** ~20 minutes. Copy F3's pattern into the F2 request.

---

### I2. `PaymentIntentService::expireStale()` not on a schedule
**Why this matters:** Stale Paystack payment links accumulate forever. F4 memory flagged this. Not customer-visible right now, but a year from now there will be tens of thousands of `pending` rows that never converted.

**Evidence:**
- F4 memory file deferral #3
- [app/Services/Finance/PaymentIntentService.php](app/Services/Finance/PaymentIntentService.php) — has `expireStale()` method
- [app/Console/Kernel.php] / `routes/console.php` — no scheduled invocation of it

**Fix scope:** ~30 minutes. Schedule `expireStale()` nightly; add a feature test confirming the schedule entry.

---

### I3. Learning SkillsMatrix `submitSkill()` is incomplete
**Why this matters:** UI hits a route the developer wasn't sure existed yet. Functional gap.

**Evidence:**
- [resources/js/Pages/Learning/SkillsMatrix.vue:90-94](resources/js/Pages/Learning/SkillsMatrix.vue#L90-L94) — function body has placeholder graceful-PATCH-may-not-exist comment

**Fix scope:** ~1-2h. Implement the missing `skills.update` route + controller action + form-request, or remove the affordance from the UI if the feature is genuinely deferred.

---

### I4. `ApPaymentService::void()` missing `lockForUpdate()`
**Why this matters:** F2 memory flagged this. F3's `ArReceiptService::void()` correctly uses `lockForUpdate()` on the invoice rows. F2's AP equivalent doesn't, allowing a theoretical double-void race.

**Evidence:**
- F2 memory file deferral #3
- [app/Services/Finance/ApPaymentService.php](app/Services/Finance/ApPaymentService.php) — `void()` method missing `lockForUpdate()` on the AP invoice rows
- Compare: [app/Services/Finance/ArReceiptService.php](app/Services/Finance/ArReceiptService.php) — `void()` does `lockForUpdate()`

**Fix scope:** ~30 minutes. Mirror the F3 pattern.

---

### I5. MT940 parser tested with one synthetic fixture
**Why this matters:** Real Ghanaian bank MT940 exports may have `:61:` subfield quirks the parser doesn't tolerate. First real-world upload will probably surface bugs.

**Evidence:**
- F5 memory file: "MT940 parser is permissive on `:61:` subfields. Tested with one synthetic fixture; real-world bank exports vary."

**Fix scope:** Depends on bank cooperation. Get a real MT940 sample from at least one of GCB/Stanbic/GTB/Ecobank before launch; add it as a fixture; harden the parser to handle whatever variations appear.

---

### I6. F3 deferred-2FA comment is misleading
**Why this matters:** F3 memory said `2fa:fresh` was deferred on AR receipts; the F3 follow-up commit (`5080269`) actually added it but the memory wasn't updated. Future maintainers reading the memory may think the gate is missing.

**Evidence:**
- [routes/web.php:977-980](routes/web.php#L977-L980) — AR receipts ARE gated with `2fa:fresh` ✓
- `project_finance_f3.md` deferral note — out of date

**Fix scope:** ~5 minutes. Update the F3 memory file to mark the deferral as resolved.

---

## POLISH — quality-of-life, not blocking launch

### P1. One skipped test  ✅ resolved 2026-05-23 (no-op — design is correct)
- [tests/Feature/Support/DbExprTest.php:23](tests/Feature/Support/DbExprTest.php#L23) — SQLite-only literal pin tests are intentionally skipped on Postgres (the PR #18 fix). Working as intended.
- The test file's own docstring (lines 7-18) already documents the intent: literal-pin tests assert the exact SQL fragment for the active driver; under the Postgres CI matrix the `emitted fragments execute against the live connection` test guards correctness instead. No change needed; future audits should not re-flag.

### P2. Bank-rec printable reports
- F5 memory deferral. The audit log in `bank_transaction_matches` covers the data; presentation is operator-visible in the Reconciliation/Show page but no PDF/printable export. Treasurers may ask. Defer until they do.

### P3. Bulk-refund / bulk-reconcile actions
- F4-R + F5 both deferred bulk operator actions. Edge case; small operator population (one or two finance officers); not a launch blocker.

---

## Module-by-module deferrals (from memory files)

### Finance
| Phase | Deferred | Status |
|---|---|---|
| F1 | None | — |
| F2 | `vendor_invoice_no` Rule::unique() at request level | I1 above |
| F2 | `ApPaymentService::void()` lockForUpdate | I4 above |
| F2 | `nextXxxReference()` concurrency safety | C3 above |
| F2 | Manual `journal.post` / `journal.reverse` routes | not implemented (acceptable — service-layer access) |
| F2 | 2FA gate on AP payment endpoints | **C1 above (still unresolved)** |
| F3 | 2FA gate on AR endpoints | Actually resolved; I6 (memory out of date) |
| F4 | Refund UI | **Resolved by F4-R (PR #19)** |
| F4 | `expireStale()` on a schedule | I2 above |
| F4-R | `refund.failed` webhook handler | Future work |
| F4-R | Stale-refund watcher | Future work |
| F5 | GhIPSS callback for AP `external_ref` | Future work (separate spec) |
| F5 | Multi-currency reconciliation | Out of scope |
| F5 | Printable bank-rec reports | P2 above |
| F5 | Bulk operator actions | P3 above |
| F5 | MT940 real-world fixture validation | I5 above |

### Other modules
- **Kiosk:** face-scan stub (C4)
- **Learning:** `submitSkill()` incomplete (I3)
- **Everything else:** no documented deferrals at audit time

---

## Recommended action plan

### Single focused PR — "production-hardening — Finance controls + small fixes" (~2h total)

Target: ship before the next demo / pilot.

1. **C1**: Add `2fa:fresh` to AP payment routes + add a feature test mirroring F3's `ar2faFresh` pattern
2. **C2**: Add `2fa:fresh` to manual journal-post route + feature test
3. **I1**: Copy F3's `customer_invoice_no` uniqueness rule into `StoreVendorInvoiceRequest` for `vendor_invoice_no`
4. **I4**: Add `lockForUpdate()` to `ApPaymentService::void()` mirroring `ArReceiptService::void()`
5. **I6**: Update F3 memory file deferral note (out of band — memory cleanup, no PR)
6. **I2**: Schedule `PaymentIntentService::expireStale()` nightly in `routes/console.php`

**Out of this PR (defer to separate work):**
- **C3** (reference-generation sequences) — 4-6h on its own; touches 11 services; needs careful migration. Separate PR.
- **C4** (kiosk face-scan) — vendor integration; weeks; ship documented limitation instead.
- **I3** (SkillsMatrix submit) — needs a routes+controller+request decision; separate small PR.
- **I5** (MT940 hardening) — needs real bank fixtures; can't fix without input data.

### After the focused PR

- Spec the reference-generation sequences table as a separate brainstorm.
- Document the kiosk face-scan limitation in `docs/DEPLOYMENT.md`.
- Open `bug/learning-skills-matrix-submit-skill` as a tracking issue.
- Solicit a real MT940 export from one Ghanaian bank during pilot onboarding.

---

## Overall assessment

The codebase is in **far better shape than the user message implied**. There are NO broken pages, NO failing tests, NO dead routes, NO missing migrations, NO permission slugs referenced but undefined. The 127-page Inertia frontend is fundamentally sound — the audit's grep for stubs only surfaced 5 tiny auth/DPA confirmation pages (all legitimate) and one Learning sub-feature (I3).

The real risks are **financial controls** (C1/C2 — security gates missing on two endpoint families) and **concurrency safety** (C3 — race-prone reference generation that hasn't bitten yet because traffic is low).

**My recommendation:** ship the C1+C2+I1+I2+I4 fixes as a single targeted PR today (~2h work, high-confidence changes mirroring existing patterns). Treat C3 and C4 as separate, larger initiatives. The current state is launch-acceptable for a controlled pilot; the C1/C2 gaps would not be acceptable for an open launch.

The original user request — "fix all UIs, add all missing buttons" — is **largely unfounded**. The UI is consistent and complete. The work that needs doing is in the controls layer and one or two specific modules (Learning + Kiosk), not a sweeping UI rebuild.
