# Finance Universal Posting — Plan 3: Refactor Existing Services onto the Engine

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. This is a REFACTOR plan: the discipline is "existing tests green → refactor → same tests still green" (characterization), not new failing tests.

**Goal:** Route the five existing finance services that hand-build journal entries through the unified `PostingService` engine — so every posting in the system goes through one pathway — without changing any posted JE (same accounts, amounts, references, dates, source keys).

**Architecture:** For each service, inject `PostingService` (alongside the existing `JournalPostingService`, which is retained ONLY for the unchanged reversal/void paths). Replace the manual `JournalEntry::create()` + `JournalLine::create()` + `$this->journal->post(...)` block with a `PostingDocument` of `PostingLine`s (literal `accountId`s — these services use per-record GL accounts, not slugs) and `$this->posting->post($doc, $actor)`. Remove the now-dead private `nextJournalReference()`.

**Tech Stack:** Laravel 13, PHP 8.3, Pest. Builds on Plan 1's `PostingService`/`PostingDocument` and Plan 2A's `?User $actor`.

**This is Plan 3 (consolidation).** Plans 1, 2A–2D are merged + pushed. Member fees (via `ArInvoiceService`) and Paystack settlement (via `ArReceiptService`) are covered transitively by refactoring those two services. After Plan 3, the engine is the single posting pathway.

**Spec:** `docs/superpowers/specs/2026-06-16-finance-universal-posting-design.md`

## Invariants every task MUST preserve (characterization)

1. **`purpose`**: pass `''` for vendor-invoice / AP-payment / AR-accrual / AR-receipt / bank-adjustment documents; pass `'write_off'` for the AR write-off document ONLY. (These match the current `source_purpose` values and the idempotency unique index.)
2. **Actor**: pass the same explicit user the service already stamps as `created_by` — `$creator` (vendor/AP/AR-accrual/receipt), `$by` (AR write-off), `$user` (bank adjustment) — as the 2nd arg to `post()`, so `created_by` is identical.
3. **`date`**: pass the SAME date the service used (NOT today, except AR write-off): vendor `invoice->invoice_date`, AP `payment->payment_date`, AR-accrual `invoice->invoice_date`, AR-receipt `receipt->receipt_date`, bank-adj `line->transaction_date`, AR write-off `now()`. Pass as a `Y-m-d` string (`->format('Y-m-d')` on date casts).
4. **Lines**: same GL accounts (literal `accountId`), same debit/credit amounts, same line narrations, same order (PostingService numbers `line_no` from 1 in array order).
5. **The JE→record link** (e.g. `$invoice->accrual_journal_entry_id = $je->id`) and any post-post side effects (e.g. bank-adjustment's `reconciliation->link(...)`) MUST be preserved — `post()` returns the `JournalEntry`, so capture it.
6. **Reversal/void paths stay byte-identical**: keep injecting `JournalPostingService` and keep calling `$this->journal->reverse($loadedEntry, $by, $reason)`. Do NOT switch them to `reverseFor()`.
7. **Keep `SequenceService`** where the service still uses it for its OWN document reference (`API-`/`APP-`/`ARI-`/`ARC-`). Only `nextJournalReference()` is removed.

Each task's characterization gate is the named existing test file(s); they must pass unchanged.

---

### Task 1: VendorInvoiceService.create() → PostingDocument

**Files:**
- Modify: `app/Services/Finance/VendorInvoiceService.php`
- Characterization tests: `tests/Feature/Finance/VendorInvoiceServiceTest.php`, `tests/Feature/Finance/VendorInvoiceUniquenessTest.php`

- [ ] **Step 1: Baseline — confirm green before touching**

Run: `php artisan test tests/Feature/Finance/VendorInvoiceServiceTest.php tests/Feature/Finance/VendorInvoiceUniquenessTest.php`
Expected: PASS. (Establishes the characterization baseline.)

- [ ] **Step 2: Read the full `create()` method** in `app/Services/Finance/VendorInvoiceService.php` so you preserve all variables (`$apGl`, `$total`, `$data['lines']`, the `accrual_journal_entry_id` link) and surrounding transaction.

- [ ] **Step 3: Refactor**

(a) Add imports: `use App\Services\Finance\PostingService;`, `use App\Services\Finance\Posting\PostingDocument;`, `use App\Services\Finance\Posting\PostingLine;` (`JournalSourceType` is already imported).

(b) Add `PostingService $posting` to the constructor (keep `JournalPostingService $journal` and `SequenceService $sequences`):

```php
    public function __construct(
        private readonly JournalPostingService $journal,
        private readonly SequenceService $sequences,
        private readonly PostingService $posting,
    ) {
    }
```

(c) Replace the manual JE block (the `JournalEntry::create([...])` + per-line `JournalLine::create([...])` + final AP `JournalLine::create([...])` + `$this->journal->post(...)`) with:

```php
            $lines = [];
            foreach ($data['lines'] as $line) {
                $lines[] = PostingLine::debit(
                    amount: (float) $line['line_total'] + (float) $line['tax_amount'],
                    accountId: (int) $line['gl_account_id'],
                    narration: $line['description'] ?? null,
                );
            }
            $lines[] = PostingLine::credit(
                amount: (float) $total,
                accountId: (int) $apGl->id,
                narration: 'Accounts Payable',
            );

            $je = $this->posting->post(new PostingDocument(
                sourceType: JournalSourceType::VendorInvoice,
                sourceId: $invoice->id,
                purpose: '',
                date: $invoice->invoice_date->format('Y-m-d'),
                narration: "Accrual: {$vendor->code} invoice " . ($invoice->vendor_invoice_no ?? $invoice->reference),
                lines: $lines,
            ), $creator);
```

Keep whatever line linked the JE to the invoice afterward (e.g. `$invoice->accrual_journal_entry_id = $je->id; $invoice->save();`) exactly as it was — `$je` is the returned `JournalEntry`.

(d) Delete the now-unused private `nextJournalReference()` method.

(e) Leave `cancel()` and its `$this->journal->reverse($invoice->accrualJournalEntry, ...)` untouched.

- [ ] **Step 4: Characterization gate — confirm still green**

Run: `php artisan test tests/Feature/Finance/VendorInvoiceServiceTest.php tests/Feature/Finance/VendorInvoiceUniquenessTest.php tests/Feature/Finance/ApPaymentServiceTest.php`
Expected: PASS — identical JE output (the tests assert balances 900/900, 2 lines, cancel zero-out). `ApPaymentServiceTest` is included because it builds invoices via this service.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Finance/VendorInvoiceService.php
git commit -m "refactor(finance): route VendorInvoiceService accrual through PostingService"
```

---

### Task 2: ApPaymentService.record() → PostingDocument

**Files:**
- Modify: `app/Services/Finance/ApPaymentService.php`
- Characterization tests: `tests/Feature/Finance/ApPaymentServiceTest.php`, `ApPaymentVoidLockTest.php`, `ApPayment2faTest.php`, `ApPaymentEndpointTest.php`

- [ ] **Step 1: Baseline**

Run: `php artisan test tests/Feature/Finance/ApPaymentServiceTest.php tests/Feature/Finance/ApPaymentVoidLockTest.php tests/Feature/Finance/ApPaymentEndpointTest.php`
Expected: PASS.

- [ ] **Step 2: Read the full `record()` method** (preserve `$bank`, `$amount`, `$allocations`, `$invoices[...]`, the `$payment->journal_entry_id` link, the allocation loop, status transitions).

- [ ] **Step 3: Refactor**

(a) Add the three Posting imports (as in Task 1). `JournalSourceType` already imported.

(b) Add `PostingService $posting` to the constructor (keep `JournalPostingService $journal`, `SequenceService $sequences`).

(c) Replace the manual JE block (per-allocation Dr AP `JournalLine` + final Cr bank `JournalLine` + `$this->journal->post(...)`) with:

```php
            $lines = [];
            foreach ($allocations as $a) {
                $inv = $invoices[$a['vendor_invoice_id']];
                $lines[] = PostingLine::debit(
                    amount: (float) $a['allocated_amount'],
                    accountId: (int) $inv->ap_gl_account_id,
                    narration: "Clear AP for {$inv->reference}",
                );
            }
            $lines[] = PostingLine::credit(
                amount: (float) $amount,
                accountId: (int) $bank->gl_account_id,
                narration: "Cash out: {$bank->bank_name}",
            );

            $je = $this->posting->post(new PostingDocument(
                sourceType: JournalSourceType::ApPayment,
                sourceId: $payment->id,
                purpose: '',
                date: $payment->payment_date->format('Y-m-d'),
                narration: "AP Payment: {$payment->reference}",
                lines: $lines,
            ), $creator);
```

Keep the `$payment->journal_entry_id = $je->id` link (and the subsequent status/processed-by updates) exactly as before. NOTE: if `$payment->payment_date` is a plain string (not a date cast), pass it directly without `->format()`. Read the model cast and match the stored value.

(d) Delete the unused `nextJournalReference()`.

(e) Leave `void()` and its `$this->journal->reverse($payment->journalEntry, ...)` untouched.

- [ ] **Step 4: Characterization gate**

Run: `php artisan test tests/Feature/Finance/ApPaymentServiceTest.php tests/Feature/Finance/ApPaymentVoidLockTest.php tests/Feature/Finance/ApPayment2faTest.php tests/Feature/Finance/ApPaymentEndpointTest.php`
Expected: PASS (balances AP 0 / bank -500, allocation counts, void zero-out unchanged).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Finance/ApPaymentService.php
git commit -m "refactor(finance): route ApPaymentService through PostingService"
```

---

### Task 3: ArInvoiceService.create() (accrual) + writeOff() → PostingDocument

The trap: both JEs share `(ArInvoice, invoice->id)`; the accrual MUST use `purpose: ''` and the write-off `purpose: 'write_off'`.

**Files:**
- Modify: `app/Services/Finance/ArInvoiceService.php`
- Characterization tests: `tests/Feature/Finance/ArInvoiceServiceTest.php`, `ArInvoiceEndpointTest.php`

- [ ] **Step 1: Baseline**

Run: `php artisan test tests/Feature/Finance/ArInvoiceServiceTest.php tests/Feature/Finance/ArInvoiceEndpointTest.php`
Expected: PASS.

- [ ] **Step 2: Read both `create()` and `writeOff()` fully** (preserve `$arGl`, `$total`, `$data['lines']`, `$badDebtGl`, `$outstanding`, `$invoice->ar_gl_account_id`, the `accrual_journal_entry_id` / `write_off_journal_entry_id` links).

- [ ] **Step 3: Refactor the accrual in `create()`**

(a) Add the three Posting imports + `PostingService $posting` to the constructor (keep `JournalPostingService $journal`, `SequenceService $sequences`).

(b) Replace the accrual JE block (Dr AR `JournalLine` + per-line Cr income `JournalLine` + `$this->journal->post(...)`) with:

```php
            $lines = [];
            $lines[] = PostingLine::debit(
                amount: (float) $total,
                accountId: (int) $arGl->id,
                narration: 'Accounts Receivable',
            );
            foreach ($data['lines'] as $line) {
                $lines[] = PostingLine::credit(
                    amount: (float) $line['line_total'] + (float) $line['tax_amount'],
                    accountId: (int) $line['gl_account_id'],
                    narration: $line['description'] ?? null,
                );
            }

            $je = $this->posting->post(new PostingDocument(
                sourceType: JournalSourceType::ArInvoice,
                sourceId: $invoice->id,
                purpose: '',
                date: $invoice->invoice_date->format('Y-m-d'),
                narration: "Accrual: {$customer->code} invoice " . ($invoice->customer_invoice_no ?? $invoice->reference),
                lines: $lines,
            ), $creator);
```

Preserve the accrual link assignment exactly. (Match the existing accrual narration string precisely — read it and copy verbatim.)

- [ ] **Step 4: Refactor `writeOff()`**

Replace the write-off JE block (Dr bad-debt `JournalLine` + Cr AR `JournalLine` + `$this->journal->post(...)`) with:

```php
            $je = $this->posting->post(new PostingDocument(
                sourceType: JournalSourceType::ArInvoice,
                sourceId: $invoice->id,
                purpose: 'write_off',
                date: now()->format('Y-m-d'),
                narration: "Write-off: {$invoice->reference} — {$reason}",
                lines: [
                    PostingLine::debit(amount: (float) $outstanding, accountId: (int) $badDebtGl->id, narration: "Bad debt: {$invoice->reference}"),
                    PostingLine::credit(amount: (float) $outstanding, accountId: (int) $invoice->ar_gl_account_id, narration: "Clear AR for {$invoice->reference}"),
                ],
            ), $by);
```

Preserve the `write_off_journal_entry_id` link assignment. (Match the existing narrations verbatim.)

(c) Delete the unused `nextJournalReference()`. Leave `cancel()`'s `$this->journal->reverse(...)` untouched.

- [ ] **Step 5: Characterization gate**

Run: `php artisan test tests/Feature/Finance/ArInvoiceServiceTest.php tests/Feature/Finance/ArInvoiceEndpointTest.php`
Expected: PASS (accrual balances 5625/5625, 2 lines; write-off AR=0/badDebt=1000/income=1000; cancel zero-out).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Finance/ArInvoiceService.php
git commit -m "refactor(finance): route ArInvoiceService accrual + write-off through PostingService"
```

---

### Task 4: ArReceiptService.record() → PostingDocument (covers Paystack settlement)

**Files:**
- Modify: `app/Services/Finance/ArReceiptService.php`
- Characterization tests: `tests/Feature/Finance/ArReceiptServiceTest.php` + the Paystack settlement tests (find them: `grep -rl recordWithFifoAllocation tests` and any Paystack webhook test).

- [ ] **Step 1: Baseline + find Paystack tests**

Run: `php artisan test tests/Feature/Finance/ArReceiptServiceTest.php`
Then locate Paystack/receipt tests: search the test suite for `recordWithFifoAllocation` and `PaystackWebhook` and run those files too. Expected: PASS (record the list of Paystack test files for Step 4).

- [ ] **Step 2: Read the full `record()` method** (preserve `$bank`, `$amount`, `$allocations`, `$invoices`, the `$receipt->journal_entry_id` link). Note `recordWithFifoAllocation()` calls `record()` internally — refactoring `record()` covers both.

- [ ] **Step 3: Refactor `record()`**

(a) Add the three Posting imports + `PostingService $posting` to the constructor.

(b) Replace the manual JE block (Dr bank `JournalLine` + per-allocation Cr AR `JournalLine` + `$this->journal->post(...)`) with:

```php
            $lines = [];
            $lines[] = PostingLine::debit(
                amount: (float) $amount,
                accountId: (int) $bank->gl_account_id,
                narration: "Cash in: {$bank->bank_name}",
            );
            foreach ($allocations as $a) {
                $inv = $invoices[$a['ar_invoice_id']];
                $lines[] = PostingLine::credit(
                    amount: (float) $a['allocated_amount'],
                    accountId: (int) $inv->ar_gl_account_id,
                    narration: "Settle AR for {$inv->reference}",
                );
            }

            $je = $this->posting->post(new PostingDocument(
                sourceType: JournalSourceType::ArReceipt,
                sourceId: $receipt->id,
                purpose: '',
                date: $receipt->receipt_date->format('Y-m-d'),
                narration: "AR Receipt: {$receipt->reference}",
                lines: $lines,
            ), $creator);
```

IMPORTANT: match the exact allocation key the existing loop uses (the map key — `$a['ar_invoice_id']` vs another name) and the `$invoices[...]` indexing by reading the current code; copy verbatim. Preserve the `journal_entry_id` link.

(c) Delete the unused `nextJournalReference()`. Leave `void()`'s `$this->journal->reverse($receipt->journalEntry, ...)` untouched.

- [ ] **Step 4: Characterization gate (incl. Paystack)**

Run: `php artisan test tests/Feature/Finance/ArReceiptServiceTest.php` plus the Paystack/receipt test files found in Step 1.
Expected: PASS (receipt bank=2000/AR=0, void bank=0/AR=2000; Paystack settlement JE unchanged).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Finance/ArReceiptService.php
git commit -m "refactor(finance): route ArReceiptService (incl. Paystack settlement) through PostingService"
```

---

### Task 5: BankAdjustmentService.postAdjustment() → PostingDocument

**Files:**
- Modify: `app/Services/Finance/BankAdjustmentService.php`
- Characterization tests: `tests/Feature/Finance/BankAdjustmentServiceTest.php` (+ `ReconciliationServiceTest.php`, `ReconciliationEndpointsTest.php` if they exercise it)

- [ ] **Step 1: Baseline**

Run: `php artisan test tests/Feature/Finance/BankAdjustmentServiceTest.php`
Expected: PASS.

- [ ] **Step 2: Read the full `postAdjustment()` method** (preserve `$bankGl`, `$offsetGl`, `$abs`, the `isDebit()` sign branch, the post-post `$this->reconciliation->link($line, $je->fresh(), $user, 'manual')` side effect, the `JournalEntry` return type).

- [ ] **Step 3: Refactor**

(a) Add the three Posting imports + `PostingService $posting` to the constructor. This service injects `JournalPostingService $journal`, `ReconciliationService $reconciliation`, `SequenceService $sequences`. It has NO reversal path and `SequenceService` is used ONLY by `nextJournalReference()`, so after removing that method you may drop both `JournalPostingService` AND `SequenceService` from the constructor — but ONLY if nothing else in the file references them (verify with a grep first; if unsure, keep them).

(b) Replace the manual JE block (the `isDebit()` sign-dependent two `JournalLine`s + `$this->journal->post(...)`) with a sign-dependent line array, then post:

```php
            $abs = abs((float) $line->amount);

            $lines = $line->isDebit()
                ? [
                    PostingLine::debit(amount: $abs, accountId: (int) $offsetGl->id, narration: $narration),
                    PostingLine::credit(amount: $abs, accountId: (int) $bankGl->id, narration: $narration),
                ]
                : [
                    PostingLine::debit(amount: $abs, accountId: (int) $bankGl->id, narration: $narration),
                    PostingLine::credit(amount: $abs, accountId: (int) $offsetGl->id, narration: $narration),
                ];

            $je = $this->posting->post(new PostingDocument(
                sourceType: JournalSourceType::BankAdjustment,
                sourceId: $line->id,
                purpose: '',
                date: $line->transaction_date->format('Y-m-d'),
                narration: $narration,
                lines: $lines,
            ), $user);
```

Confirm `$bankGl` is the GL model (`$line->statement->orgBankAccount->glAccount`) and use `->id`. Keep the post-post `$this->reconciliation->link($line, $je->fresh(), $user, 'manual');` and `return $je;` exactly.

(c) Delete the unused `nextJournalReference()`.

- [ ] **Step 4: Characterization gate**

Run: `php artisan test tests/Feature/Finance/BankAdjustmentServiceTest.php`
Expected: PASS (source_type/source_id==line->id, reconciliation matched_id/confidence, 2 lines, sign correctness for debit vs credit adjustments).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Finance/BankAdjustmentService.php
git commit -m "refactor(finance): route BankAdjustmentService through PostingService"
```

---

### Task 6: Full regression gate

**Files:** none (verification only).

- [ ] **Step 1: Full Finance + dependent suites**

Run: `php artisan test tests/Feature/Finance tests/Unit/Finance tests/Feature/Disbursement tests/Feature/Payroll tests/Feature/Loans`
Expected: PASS — every characterization test green; nothing across the finance domains regressed.

- [ ] **Step 2: Full app suite**

Run: `php artisan test`
Expected: PASS (allowing the known time-of-day `KioskRecentTest` flake — if it's the ONLY failure and is unrelated to finance, that's pre-existing). Report any finance-touching failure.

- [ ] **Step 3: Mark the gate**

```bash
git commit --allow-empty -m "test(finance): Plan 3 refactor-onto-engine regression gate green"
```

---

## Self-Review notes (for the implementer)

- **This refactor must not change a single posted JE.** If a characterization test fails, the refactor diverged — most likely a wrong `purpose` (must be `''` except AR write-off `'write_off'`), a wrong actor (must be the same explicit user), a wrong `date` (must match the record's date, not today), or a changed line order/narration. Fix the divergence, don't change the test.
- **Why keep `JournalPostingService` injected:** the reversal/void paths call `$this->journal->reverse($loadedEntry, ...)` on an already-loaded relation, with specific exception semantics. Leaving them untouched guarantees zero behavioral change there. `PostingService` is added purely for the `post()` path.
- **The reference is preserved:** all services already used `JE-{year}-{seq}` from the `journal:{year}` SequenceService key — exactly what `PostingService` generates — so JE references are continuous across the refactor.
- **Idempotency is newly enforced** by `PostingService` + the unique index, but each flow posts a fresh `source_id` per call, so dedup never fires in normal use (no characterization test re-posts the same source).
- **After Plan 3, every JE in the system is posted through `PostingService`.** Member fees and Paystack settlement are covered via the AR services. Phases 2–4 (periods/close, statements, budgeting) can then assume a single posting choke point.
