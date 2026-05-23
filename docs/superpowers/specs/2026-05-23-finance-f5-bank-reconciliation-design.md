# Finance F5 — Bank Reconciliation Spec

**Date:** 2026-05-23
**Status:** Design approved; spec written for review
**Branch base:** `feat/finance-f4-paystack` head `928acef` (rebase onto `origin/main` once PR #14 merges)
**Phase position:** Final phase of the F1–F5 finance build-out

---

## 1. Purpose

F5 closes the books loop. F1–F4 record what CIHRMS *intends* to pay or be paid; F5 confirms what *actually moved* through the bank. After F5:

- An operator can upload a bank statement (CSV, OFX 2.x, or MT940 SWIFT) for any active org bank account.
- F5 auto-matches statement lines to the existing CIHRMS records they represent — Paystack-credited AR receipts via F4's `external_ref`, AP payments via amount+date+reference, and any user-marked external refs.
- Unmatched lines surface in a split-pane UI where the operator manually pairs them.
- Bank fees and interest credits that have no CIHRMS counterpart become single-line journal entries posted through F2's `JournalPostingService` — never written to `gl_account_balances` directly.

F5 produces no new accounting logic. It is a *matching + adjustment* layer over the existing engines.

## 2. Scope and non-scope

### In scope
- Statement upload for the three formats listed.
- Three-tier auto-matching against `ap_payments` and `ar_receipts`.
- Manual pairing UI.
- Bank-adjustment journal entries (bank fees, interest income).
- One new column on `ap_payments` (`external_ref`) so AP can be matched on the bank's transaction ID, mirroring F4's AR pattern.
- Permissions, sidebar entry, hub tile.

### Explicitly out of scope (deferred)
- **Automated GhIPSS callback** that would populate `ap_payments.external_ref` without operator input. F5 lets the operator set it manually; the callback wiring is a separate spec.
- **Multi-currency reconciliation.** All F1–F5 work assumes GHS. Foreign-currency statements rejected at upload.
- **Reconciliation reports** (e.g., printable monthly bank-rec sheets). The audit log in `bank_transaction_matches` covers the data; presentation comes later.
- **Refund flow**: F4 deferred operator refund UI; F5 does not unblock it.

## 3. Architecture

```
                          ┌──────────────────────┐
file upload (csv/ofx/m940)│ StatementImportService│  detect format → dispatch
                          └──────────┬───────────┘
                                     │
                  ┌──────────────────┼──────────────────┐
                  │                  │                  │
        ┌─────────▼──────┐ ┌─────────▼──────┐ ┌────────▼───────────┐
        │ CsvStatement   │ │ OfxStatement   │ │ Mt940Statement     │
        │ Parser         │ │ Parser         │ │ Parser             │
        └─────────┬──────┘ └─────────┬──────┘ └────────┬───────────┘
                  └──────────────────┼──────────────────┘
                                     │ normalized line records
                          ┌──────────▼───────────┐
                          │  bank_statements +    │
                          │  bank_statement_lines │
                          └──────────┬───────────┘
                                     │
                          ┌──────────▼───────────┐
                          │ ReconciliationMatcher │
                          │   (3-tier)           │
                          └──────────┬───────────┘
                                     │ link / suggest
                          ┌──────────▼───────────┐
                          │ ReconciliationService │  sole writer of
                          │  link() / unlink()    │  bank_transaction_matches
                          └──────────┬───────────┘
                                     │
                          ┌──────────▼───────────┐
                          │ BankAdjustmentService │  bank fee / interest
                          │   → JournalPosting    │  → JournalPostingService
                          └──────────────────────┘
```

Each box is a separately-testable unit with one responsibility. The matcher does not touch the database directly — it asks `ReconciliationService::link()` to record matches.

## 4. Schema

### 4.1 New tables

#### `bank_statements`
One row per uploaded file.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `org_bank_account_id` | foreignId → `org_bank_accounts`, `restrictOnDelete` | Statement belongs to one bank account; cross-account matching forbidden. |
| `statement_date` | date | The statement period's end date as printed on the file. |
| `period_start` | date, nullable | If the file declares a start date (OFX `DTSTART`, MT940 :60F:). |
| `opening_balance` | decimal(18,2) | From the file header. |
| `closing_balance` | decimal(18,2) | From the file header. |
| `currency` | char(3), default `'GHS'` | Reject upload if currency ≠ active bank account's currency. |
| `file_hash` | string(64), UNIQUE | SHA-256 of the raw uploaded bytes. Idempotency: re-uploading the same file collides on INSERT and the controller returns the existing statement. |
| `file_name` | string(255) | Original upload name; audit only. |
| `format` | string(10) | `csv` / `ofx` / `mt940` |
| `imported_by` | foreignId → `users`, `restrictOnDelete` | |
| `created_at`, `updated_at` | timestamps | |
| `softDeletes()` | | A statement can be retracted; lines cascade-soft-delete. |

Indexes: `org_bank_account_id`, `statement_date`.

#### `bank_statement_lines`
One row per line in the statement file.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `bank_statement_id` | foreignId → `bank_statements`, `cascadeOnDelete` | |
| `line_no` | smallint | 1-based position within the statement; for stable display order. |
| `transaction_date` | date | Posting date on the statement. |
| `value_date` | date, nullable | Settlement date (OFX `DTPOSTED`, MT940 :61: value-date subfield). |
| `description` | string(500) | Bank's free-text narration. |
| `reference` | string(100), nullable | Bank's transaction reference (OFX `FITID`, MT940 :61: ref-to-account-owner). |
| `amount` | decimal(18,2) | **Signed.** Positive = credit (money in), negative = debit (money out). |
| `running_balance` | decimal(18,2), nullable | If the file provides per-line balances; for display only, never reconciled against. |
| `line_hash` | string(64) | SHA-256 of `(transaction_date, amount, description, reference)`. UNIQUE within `bank_statement_id`. Guards against duplicate parsing if the file has identical lines on the same day (CIHRM bank exports occasionally repeat). |
| `matched_type` | string(50), nullable | `App\Models\ApPayment` / `App\Models\ArReceipt` / `App\Models\JournalEntry` (for adjustments). |
| `matched_id` | unsignedBigInteger, nullable | FK by name only; no DB-level constraint because target tables differ. |
| `confidence` | string(10), nullable | `high` / `medium` / `low` / `manual`. |
| `reconciled_at` | timestamp, nullable | Set when `matched_type` + `matched_id` are populated; cleared on unlink. |
| `created_at`, `updated_at` | timestamps | |

Indexes: `(bank_statement_id, line_no)` unique, `reconciled_at`, `(matched_type, matched_id)`.

#### `bank_transaction_matches`
Append-only audit log.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `bank_statement_line_id` | foreignId → `bank_statement_lines`, `restrictOnDelete` | |
| `matched_type` | string(50) | Same value space as `bank_statement_lines.matched_type`. |
| `matched_id` | unsignedBigInteger | |
| `confidence` | string(10) | At time of match. |
| `matched_by` | foreignId → `users`, `restrictOnDelete` | Auto-matches: the `imported_by` of the parent statement. |
| `matched_at` | timestamp | |
| `unmatched_at` | timestamp, nullable | Set when this match is reversed. Append-only — the row is never deleted. |
| `unmatched_by` | foreignId → `users`, nullable, `nullOnDelete` | |
| `unmatched_reason` | string(500), nullable | |

No `$timestamps` Eloquent column — the schema names them explicitly. Immutable audit trail (no `updated_at`).

### 4.2 Modified columns

- **`ap_payments`** — add `external_ref` (nullable string 100, indexed). Mirrors `ar_receipts.external_ref`. F5 populates it on link when the operator pairs a debit statement line with an AP payment, so the next statement-import auto-match upgrades from Tier 2/3 to Tier 1. Migration: `2026_05_27_000001_add_external_ref_to_ap_payments.php`.

### 4.3 Enum extension

- **`JournalSourceType`** — add `BankAdjustment` case (value `bank_adjustment`). Sole journaling source for bank-fee / interest entries.

## 5. Services

### 5.1 Statement parsing

#### Interface
```php
namespace App\Services\Finance\Statements;

interface StatementParser
{
    /**
     * @return array{
     *   period_start: ?string,
     *   statement_date: string,
     *   opening_balance: float,
     *   closing_balance: float,
     *   currency: string,
     *   lines: list<array{
     *     transaction_date: string,
     *     value_date: ?string,
     *     description: string,
     *     reference: ?string,
     *     amount: float,
     *     running_balance: ?float,
     *   }>,
     * }
     */
    public function parse(string $rawContent): array;
}
```

#### `CsvStatementParser`
- Reads CSV with a per-bank column map driven by `config/banks.php`. The seeded map covers GCB, Stanbic, GTB, Ecobank; new banks added by editing config, no code change.
- Detects the bank either from a parser parameter (operator picks at upload) or from a header signature (e.g., GCB's CSV always opens with `"GCB Bank Limited"` on row 1).
- Lines with both debit and credit columns get unified into a signed `amount`.

#### `OfxStatementParser`
- OFX 2.x XML. Reads via PHP's built-in `SimpleXMLElement`; no library dependency.
- Walks `<BANKMSGSRSV1>/<STMTTRNRS>/<STMTRS>/<BANKTRANLIST>/<STMTTRN>`.
- `<TRNAMT>` is already signed in OFX.
- `<FITID>` → `reference` (canonical for Tier-1 matching).

#### `Mt940StatementParser`
- SWIFT MT940 plain-text. Lines split on CRLF; blocks delimited by `:tag:` markers.
- `:20:` reference, `:25:` account, `:60F:` opening balance, `:62F:` closing balance.
- Each transaction is `:61:` (machine line) followed by `:86:` (narration). The parser pairs them and emits one normalized line record per pair.
- Sign comes from the `D`/`C` flag in the `:61:` tag.

### 5.2 `StatementImportService`
- Single entry point: `import(UploadedFile $file, OrgBankAccount $bank, User $importer): BankStatement`.
- Detects format by extension (`.csv` / `.ofx` / `.sta`|`.mt940`) and a magic-bytes sanity check.
- Computes `file_hash`. If a statement with that hash already exists on the same bank account, returns the existing row (200 OK to the upload UI).
- Persists `bank_statements` + `bank_statement_lines` inside one `DB::transaction`. Either the whole file lands or none of it does.
- Currency mismatch → `DomainException` before persisting.

### 5.3 `ReconciliationMatcher`
- One method: `matchUnreconciled(BankStatement $statement): array` returning per-tier counts.
- Iterates `bank_statement_lines` where `reconciled_at IS NULL`.
- For each line:
  1. **Tier 1 (high):** If `line.reference` (or `line.description` substring) matches an unreconciled `ar_receipts.external_ref` (credit lines) or `ap_payments.external_ref` (debit lines) for the same bank account, auto-link.
  2. **Tier 2 (medium):** Exact `amount` + `transaction_date ± 2 days` + CIHRMS record's `reference` appears as substring in `line.description`. If exactly one candidate, auto-link; if multiple, fall through to Tier 3.
  3. **Tier 3 (low / needs-review):** Exact `amount` + `transaction_date ± 2 days`. If exactly one candidate, link with `confidence=low`. If multiple, leave `matched_type` null and set `confidence=low` as a hint that *something* could match — UI will surface the candidate list.
- Debit lines (`amount < 0`) only consider AP payments. Credit lines (`amount > 0`) only consider AR receipts. The matcher never crosses these.
- Idempotent: re-running on the same statement skips lines already reconciled.

### 5.4 `ReconciliationService`
- Sole writer of `bank_transaction_matches`.
- `link(BankStatementLine $line, Model $target, User $user, string $confidence): BankTransactionMatch` — wraps in transaction:
  1. Refuses if line is already reconciled.
  2. Updates `bank_statement_lines` (`matched_type`, `matched_id`, `confidence`, `reconciled_at`).
  3. If `target` is an `ApPayment` and `target.external_ref` is null, back-populates it from `line.reference` (improves future matching).
  4. Appends a `bank_transaction_matches` row.
- `unlink(BankStatementLine $line, User $user, string $reason): void` — wraps in transaction:
  1. Clears the line's `matched_type`, `matched_id`, `confidence`, `reconciled_at`.
  2. Stamps the existing `bank_transaction_matches` row with `unmatched_at`, `unmatched_by`, `unmatched_reason`. Never deletes.
  3. Does **not** clear back-populated `ap_payments.external_ref` (that information remains valid — the link is being removed, not the bank's record of which transaction this was).
- `acceptSuggestion(BankStatementLine $line, User $user): BankTransactionMatch` — convenience wrapper that promotes a `confidence=low` line's suggested candidate to a confirmed match.

### 5.5 `BankAdjustmentService`
- One entry point: `postAdjustment(BankStatementLine $line, GlAccount $offsetGl, User $user, string $narration): JournalEntry`.
- Posts a two-line JE via `JournalPostingService::post(...)`:
  - For a bank fee (debit line, `offsetGl.type=expense`): `Dr offsetGl, Cr bank_gl`
  - For interest credit (credit line, `offsetGl.type=income`): `Dr bank_gl, Cr offsetGl`
- The JE's `source_type` is the new `BankAdjustment` case; `source_id` is the statement line's id.
- After successful post, calls `ReconciliationService::link($line, $journalEntry, $user, 'manual')` so the line shows reconciled against the JE.
- `2fa:fresh` gated at the controller (this writes to `gl_account_balances`).

## 6. Permissions

| Slug | Description | Granted to |
|---|---|---|
| `reconciliation.view` | List statements and lines; view audit log | `finance_officer`, `auditor` |
| `reconciliation.import` | Upload a statement file | `finance_officer` |
| `reconciliation.match` | Link, unlink, accept suggested match | `finance_officer` |
| `reconciliation.adjust` | Post bank-fee / interest adjustment JE | `finance_officer` (with `2fa:fresh`) |

Mirrors F4's RBAC pattern: DB-backed `permissions` + `role_permissions` seeded by `RolePermissionSeeder`, lock-stepped to the legacy `User::ROLE_PERMISSIONS` constant. `super_admin` covers everything via the wildcard.

## 7. Routes

All under `Route::prefix('finance')->name('finance.')` (the existing F1–F4 grouping):

```
GET    /finance/reconciliation                    reconciliation.view       index
GET    /finance/reconciliation/{statement}        reconciliation.view       show
POST   /finance/reconciliation                    reconciliation.import     store           (file upload)
POST   /finance/reconciliation/lines/{line}/link     reconciliation.match      link
POST   /finance/reconciliation/lines/{line}/unlink   reconciliation.match      unlink
POST   /finance/reconciliation/lines/{line}/accept   reconciliation.match      acceptSuggestion
POST   /finance/reconciliation/lines/{line}/adjust   reconciliation.adjust   adjust          (2fa:fresh)
```

## 8. Inertia UI

- **`Finance/Reconciliation/Index.vue`** — table of statements per bank account with reconciliation progress (matched lines / total lines · matched amount / total volume). Upload button (drop zone + bank-account picker). Sidebar entry `reconciliation` under Finance.
- **`Finance/Reconciliation/Show.vue`** — split pane:
  - Left column: unmatched statement lines, sorted by `transaction_date`. Confidence badge (`high`/`medium`/`low`/`unmatched`). Click expands to show suggested CIHRMS records (Tier-1/2/3 candidates from the matcher).
  - Right column: unreconciled CIHRMS records for this bank account (`ap_payments` for the debit panel, `ar_receipts` for the credit panel). Toggle between debit and credit view.
  - Pair action: select one from each side → "Confirm match" → POST to `link`.
  - Accept-suggested action: one-click on a suggested match → POST to `accept`.
  - Adjust action: "Post bank fee" / "Post interest credit" button on an unmatched line → opens a modal with GL account picker → POST to `adjust`.
- **Hub tile** — under the existing F4 `gatewayHealth` block:
  - `reconciliationStats`: `{ unreconciled_count, oldest_unreconciled_date, matched_pct_last_30d }` per active bank account.

## 9. Testing

Per-component tests with one fixture per parser:

| Test file | Subjects |
|---|---|
| `tests/Unit/Finance/EnumsF5Test.php` | `JournalSourceType::BankAdjustment` case + label |
| `tests/Feature/Finance/BankReconciliationMigrationsTest.php` | All three new tables + `ap_payments.external_ref` column |
| `tests/Feature/Finance/F5ModelsTest.php` | `BankStatement`, `BankStatementLine`, `BankTransactionMatch` casts + relations + scopes (`unreconciled`, `forBankAccount`) |
| `tests/Feature/Finance/F5PermissionsSeedTest.php` | 4 new perms; finance_officer + auditor grants |
| `tests/Feature/Finance/CsvStatementParserTest.php` | One fixture per supported bank; signed-amount unification; mixed debit/credit columns |
| `tests/Feature/Finance/OfxStatementParserTest.php` | One typical Ghana-bank export; `<TRNAMT>` sign preserved; `<FITID>` mapped to `reference` |
| `tests/Feature/Finance/Mt940StatementParserTest.php` | Multi-block `:61:`/`:86:` pairing; D/C flag sign mapping; `:60F:`/`:62F:` balance extraction |
| `tests/Feature/Finance/StatementImportServiceTest.php` | Format detection by extension; `file_hash` idempotency (re-upload returns existing row); currency mismatch rejection |
| `tests/Feature/Finance/ReconciliationMatcherTest.php` | Tier-1 via `external_ref`; Tier-2 via amount+date+ref-in-description; Tier-3 single-candidate; Tier-3 multi-candidate (line stays unmatched but confidence set to `low`); debit lines never match AR; idempotency on re-run |
| `tests/Feature/Finance/ReconciliationServiceTest.php` | `link()` updates line + appends match row + back-populates `ap_payments.external_ref`; `unlink()` clears line and stamps unmatched fields without deleting; `link()` refuses already-reconciled line |
| `tests/Feature/Finance/BankAdjustmentServiceTest.php` | Bank fee posts via `JournalPostingService`; interest credit reverses sign; `source_type=bank_adjustment`; auto-links the line to the resulting JE |
| `tests/Feature/Finance/ReconciliationEndpointsTest.php` | Permission gates; 2fa:fresh on adjust; file-upload validation (size, MIME); audit log written |
| `tests/Feature/Finance/FinanceHubF5Test.php` | `reconciliationStats` key present; per-bank counts |

Expected count: ~35 new tests on top of F4's 209.

## 10. Risks and mitigations

| Risk | Mitigation |
|---|---|
| MT940 fragmentation — different banks produce slightly different `:61:` / `:86:` structures | Use a permissive `:61:` parser (numeric value-date + entry-date + D/C flag + amount + transaction-type code) and treat `:86:` purely as free-text narration. Accept any extra subfields silently. Ship with one fixture; rely on real-world fixtures to refine post-pilot. |
| CSV column-mapping drift — a bank changes its export format | `config/banks.php` per-bank maps make this a config change, not a code release. Add a synthetic-header sanity check that fails the upload with an actionable message if the expected columns are missing. |
| Amount-precision mismatch (statement shows GHS 1,234.56 vs CIHRMS computed GHS 1234.555 rounded to 1234.56) | Match using `abs(diff) <= 0.005`, never strict `==`. Consistent with F4's pesewas-conversion rounding. |
| Tier-3 false positives — same amount and date but unrelated transactions | Tier 3 only suggests; never auto-links. Operator must accept. Confidence badge in UI makes the uncertainty visible. |
| Statement re-upload race — two operators upload the same file simultaneously | `file_hash` UNIQUE collision at INSERT time. The second importer sees a controller-level "already imported" message and is redirected to the existing statement. |
| Bank-statement file leaks sensitive data via `description` (e.g., third-party account numbers) | Upload is permission-gated (`reconciliation.import`). Files stored under `storage/app/private/bank-statements/`; only filename + hash recorded in DB. Future phase can add a redaction step. |
| Soft-deleted `bank_statements` leave orphan `bank_transaction_matches` rows pointing at gone lines | `bank_transaction_matches.bank_statement_line_id` is `restrictOnDelete`. Soft-delete on the parent statement doesn't cascade; reactivation is supported. If a statement is hard-deleted, all related matches and adjustments come along — but the design treats this as outside the F5 surface (no UI for hard delete). |

## 11. Forward-fixes applied from F4

1. **2FA test helper:** `rec2faFresh($user)` — same shape as F4's `pi2faFresh`; calls `App\Services\Auth\TwoFactorService::markFresh()`. Lives at the top of `tests/Feature/Finance/ReconciliationEndpointsTest.php`.
2. **Test pattern for raw uploads:** Use `$this->call('POST', ...)` with `$server` array for `CONTENT_TYPE: multipart/form-data` headers. F4 discovered `withHeaders()` doesn't propagate to `call()`.
3. **Service error-message case:** All service-level exception messages use lowercase first word in their `processing_error`-style fields, so test `->toContain('amount')` style assertions match without case juggling.
4. **Branch hygiene:** F5 branches off F4's task-12 head `928acef` directly, not via the contaminated tip. Rebase onto `origin/main` once F4 PR #14 merges.

## 12. Acceptance criteria

F5 ships when:

1. All ~35 new tests pass alongside the full Finance suite (F1+F2+F3+F4+F5 ≈ 245 tests, all green).
2. Manual smoke: upload a CSV containing a Paystack-paid AR receipt's `external_ref` → matcher links Tier 1 → UI shows the receipt against the line.
3. Manual smoke: upload a CSV containing a bank fee → operator clicks "Post adjustment" → JE appears via `journal.show` with `source_type=bank_adjustment` → bank GL balance reflects the fee.
4. Manual smoke: upload the same CSV file twice → second upload returns the existing statement (no duplicate rows).
5. Manual smoke: OFX file with the same data set → matcher produces the same set of links as the CSV equivalent.
6. Manual smoke: spoof an invalid MT940 file (random text) → parser raises `DomainException` and no `bank_statements` row is created.
7. Permission smoke: `auditor` can list statements but not import; `employee` gets 403 on `index`.
8. 2FA smoke: `finance_officer` without fresh 2FA challenge gets blocked on adjust.
9. `JournalPostingService` is unmodified (binary `git diff` between F4 head and F5 head shows no change to the file).
10. `ArReceiptService::record()` is unmodified.
11. Hub tile shows reconciliation stats for at least one bank account.

## 13. Non-goals (explicitly out)

- Automatic GhIPSS callback for AP `external_ref` — separate spec.
- Multi-currency reconciliation.
- Printable bank-rec reports.
- F4 refund flow.
- Bulk operator actions ("link all suggested" — could be added later but not in F5 scope).

## 14. Related

- F1: `project_finance_f1.md` — chart of accounts + org bank accounts
- F2: `project_finance_f2.md` — `JournalPostingService` (sole `gl_account_balances` mutator)
- F3: `project_finance_f3.md` — `ArReceiptService::record()` (sole AR receipt mutator)
- F4: `project_finance_f4.md` — Paystack gateway; populates `ar_receipts.external_ref` that F5 Tier-1 matching reads
