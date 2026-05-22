# Finance F3 — Accounts Receivable Design

**Status:** Approved (design phase)
**Date:** 2026-05-22
**Owner:** finance_officer role / Finance department
**Depends on:** F1 (chart of accounts + bank accounts), F2 (journal posting engine + AP)
**Related memory:** [[cihrms-finance-f2-complete]], [[cihrms-finance-f1-complete]], [[cihrms-architecture-patterns]], [[cihrms-rbac-system]]

## 1. Context

[F1](2026-05-21-finance-f1-accounts-foundation-design.md) built the GL + bank-account schema. [F2](2026-05-22-finance-f2-accounts-payable-design.md) introduced the double-entry journal posting engine and wired it through Accounts Payable.

F3 mirrors F2 on the receivables side: customers, AR invoices, receipts, customer statements. Same architectural patterns, same code shape, opposite balance direction. CIHRM is a chartered training institute — receivables (membership dues, course fees, certification fees, training contracts) are core revenue.

`JournalPostingService` (the load-bearing component) is **unmodified** in F3. F3 only adds new `JournalSourceType` enum cases (`ArInvoice`, `ArReceipt`) and routes new business events through the existing engine.

## 2. Scope

### 2.1 In scope

- 5 new tables: `customers`, `ar_invoices`, `ar_invoice_lines`, `ar_receipts`, `ar_receipt_invoice_allocations`.
- 3 new enums (`CustomerStatus`, `ArInvoiceStatus`, `ArReceiptStatus`); 2 new cases on existing `JournalSourceType` (`ArInvoice`, `ArReceipt`).
- 4 services: `CustomerService`, `ArInvoiceService`, `ArReceiptService`, `CustomerStatementService`.
- AR invoice lifecycle: Draft → PendingApproval → Approved → PartiallyPaid → Paid, plus Cancelled and **WrittenOff** (new, bad-debt path).
- Receipt allocation: one receipt settles one or more invoices; receipt JE posts atomically with allocation.
- Write-off: posts a bad-debt JE (Dr Bad Debt Expense, Cr AR); irreversible without manual JE intervention.
- Customer Statement page: date-range view with running balance; HTML-only (print via browser; PDF deferred to F4+).
- 8 new permission slugs.
- 5 new Inertia pages.
- 2 new Hub KPIs: `arOutstanding` and `agingBuckets` (current / 30 / 60 / 90+).
- Pest Feature tests for all of the above.
- F2 lessons forward-applied: `customer_invoice_no` uniqueness at FormRequest level; `lockForUpdate` on invoice rows in `ArReceiptService::void()`; `2fa:fresh` middleware on receipt + write-off endpoints.

### 2.2 Out of scope (deferred)

- **F4:** Paystack online payment gateway — inbound payment links, webhooks → auto-receipt AR invoices.
- **F5:** Bank reconciliation — statement import, transaction matching.
- **Beyond F5:** Recurring invoices (membership annual dues automation), dunning letters / payment reminders, credit limits, multi-currency AR with FX gain/loss, fine-grained aging (120/180/365 buckets), PDF statement generation, undo-write-off action.

## 3. Data Model

### 3.1 Enums (`App\Enums\`)

- `CustomerStatus` — `active`, `inactive`, `suspended`
- `ArInvoiceStatus` — `draft`, `pending_approval`, `approved`, `partially_paid`, `paid`, `cancelled`, `written_off`
- `ArReceiptStatus` — `pending`, `processed`, `voided`
- **Extend** existing `App\Enums\JournalSourceType` with two new cases: `ArInvoice = 'ar_invoice'`, `ArReceipt = 'ar_receipt'`.

All three new enums follow the established pattern: backed string enum, `label()` method, `declare(strict_types=1)`, namespace `App\Enums`.

### 3.2 Migrations (5 tables)

Migrations are dated `2026_05_22_100001` through `2026_05_22_100005` so they run AFTER the F2 migrations (which use `2026_05_22_000001`-`000007`). Same date, larger sub-number — Laravel runs them in filename order.

**`customers`**
```
id (PK)
code              VARCHAR(30)   UNIQUE NOT NULL    -- e.g. "CUS-0001"
name              VARCHAR(200)  NOT NULL
tax_id            VARCHAR(50)   NULLABLE
status            VARCHAR(20)   NOT NULL DEFAULT 'active'
email             VARCHAR(255)  NULLABLE
phone             VARCHAR(50)   NULLABLE
address           TEXT          NULLABLE
default_income_gl_account_id   FK gl_accounts.id NULLABLE  -- defaults invoice lines (type=income)
default_ar_gl_account_id       FK gl_accounts.id NULLABLE  -- defaults accrual JE debit (type=asset); falls back to code '1200' if null
default_bank_account_id        FK org_bank_accounts.id NULLABLE  -- preferred receiving bank
notes             TEXT          NULLABLE
created_at, updated_at, deleted_at (SoftDeletes)
INDEX (status), INDEX (name)
```

**`ar_invoices`**
```
id (PK)
reference         VARCHAR(30)   UNIQUE NOT NULL    -- auto-gen "ARI-2026-0001"
customer_id       FK customers.id NOT NULL ON DELETE RESTRICT
customer_invoice_no  VARCHAR(100)  NULLABLE        -- caller's external reference, e.g. PO number
status            VARCHAR(30)   NOT NULL DEFAULT 'draft'
invoice_date      DATE          NOT NULL
due_date          DATE          NULLABLE
subtotal          DECIMAL(18,2) NOT NULL DEFAULT 0
tax_amount        DECIMAL(18,2) NOT NULL DEFAULT 0
total             DECIMAL(18,2) NOT NULL DEFAULT 0
amount_received   DECIMAL(18,2) NOT NULL DEFAULT 0
currency          CHAR(3)       NOT NULL DEFAULT 'GHS'
ar_gl_account_id  FK gl_accounts.id NOT NULL       -- snapshotted at creation
notes             TEXT          NULLABLE
accrual_journal_entry_id  FK journal_entries.id NULLABLE
write_off_journal_entry_id FK journal_entries.id NULLABLE  -- set when written off
created_by        FK users.id   NOT NULL
approved_by       FK users.id   NULLABLE
approved_at       TIMESTAMP     NULLABLE
cancelled_by      FK users.id   NULLABLE
cancelled_at      TIMESTAMP     NULLABLE
written_off_by    FK users.id   NULLABLE
written_off_at    TIMESTAMP     NULLABLE
written_off_reason VARCHAR(500) NULLABLE
created_at, updated_at, deleted_at (SoftDeletes)
UNIQUE (customer_id, customer_invoice_no) — but only when customer_invoice_no IS NOT NULL (partial unique)
INDEX (status), INDEX (invoice_date), INDEX (due_date)
```

Note on the conditional unique: SQLite supports partial indexes via raw SQL but Laravel's blueprint doesn't expose it. Implementation uses a regular composite unique on `(customer_id, customer_invoice_no)` and tolerates NULL repetitions (NULLs don't collide in SQLite/Postgres). The validation rule in `StoreArInvoiceRequest` enforces uniqueness application-side only when the value is non-null.

**`ar_invoice_lines`**
```
id (PK)
ar_invoice_id     FK ar_invoices.id NOT NULL ON DELETE CASCADE
line_no           SMALLINT      NOT NULL
description       VARCHAR(500)  NOT NULL
quantity          DECIMAL(12,3) NOT NULL DEFAULT 1
unit_price        DECIMAL(18,4) NOT NULL DEFAULT 0
line_total        DECIMAL(18,2) NOT NULL DEFAULT 0
tax_rate          DECIMAL(7,4)  NOT NULL DEFAULT 0
tax_amount        DECIMAL(18,2) NOT NULL DEFAULT 0
gl_account_id     FK gl_accounts.id NOT NULL ON DELETE RESTRICT  -- income GL for this line
UNIQUE (ar_invoice_id, line_no)
```

**`ar_receipts`**
```
id (PK)
reference         VARCHAR(30)   UNIQUE NOT NULL    -- auto-gen "ARC-2026-0001"
customer_id       FK customers.id NOT NULL ON DELETE RESTRICT
status            VARCHAR(20)   NOT NULL DEFAULT 'pending'
receipt_date      DATE          NOT NULL
amount            DECIMAL(18,2) NOT NULL
currency          CHAR(3)       NOT NULL DEFAULT 'GHS'
org_bank_account_id  FK org_bank_accounts.id NOT NULL  -- receiving bank
external_ref      VARCHAR(100)  NULLABLE             -- bank/MoMo transaction ID
narration         VARCHAR(500)  NULLABLE
journal_entry_id  FK journal_entries.id NULLABLE
created_by        FK users.id   NOT NULL
processed_by      FK users.id   NULLABLE
processed_at      TIMESTAMP     NULLABLE
voided_by         FK users.id   NULLABLE
voided_at         TIMESTAMP     NULLABLE
created_at, updated_at, deleted_at (SoftDeletes)
INDEX (status), INDEX (receipt_date), INDEX (customer_id), INDEX (external_ref)
```

Note: F4 (Paystack) will populate `external_ref` from webhook payloads. F3 just exposes the column.

**`ar_receipt_invoice_allocations`**
```
id (PK)
ar_receipt_id     FK ar_receipts.id NOT NULL ON DELETE CASCADE
ar_invoice_id     FK ar_invoices.id NOT NULL ON DELETE RESTRICT
allocated_amount  DECIMAL(18,2) NOT NULL
notes             VARCHAR(255)  NULLABLE
created_at, updated_at
UNIQUE (ar_receipt_id, ar_invoice_id)
```

### 3.3 Models

Each follows the F2 model pattern. Key methods:
- `Customer::scopeActive()`, `archive()` guard via service.
- `ArInvoice::outstandingAmount()` = `total - amount_received`.
- `ArInvoice::scopeOpen()` = where status in (Approved, PartiallyPaid).
- `ArInvoice::scopeWriteable()` = where status in (Approved, PartiallyPaid) — write-off eligibility.

### 3.4 ChartOfAccountsSeeder addition

Add one new GL account to the existing seeder (the seeder is idempotent — `updateOrCreate` keyed on `code`):

```
['5600', 'Bad Debt Expense', 'expense', '5000']
```

Total seeded accounts: 32 (F1) → 33 (F3).

## 4. Journal Flow

All three flows route through F2's `JournalPostingService::post()`. F3 does not modify the engine.

### 4.1 Invoice creation → accrual JE

`ArInvoiceService::create()`, inside `DB::transaction`:

```
Dr AR GL (customer.default_ar_gl_account_id, fallback to GL code 1200)   = invoice.total
Cr Income GL (per invoice line)                                         = line_total + tax_amount  (per line)
```

JE.source_type = `ar_invoice`, JE.source_id = invoice.id. Invoice.accrual_journal_entry_id ← JE.id.

### 4.2 Receipt processing → receipt JE

`ArReceiptService::record()`, inside `DB::transaction`:

```
Dr Bank GL (the receiving org_bank_account's linked gl_account_id)       = receipt.amount (total)
Cr AR GL (per allocated invoice's ar_gl_account_id)                      = allocated_amount per allocation
```

JE.source_type = `ar_receipt`, JE.source_id = receipt.id. Receipt.journal_entry_id ← JE.id.

After posting, each allocated invoice's `amount_received += allocated_amount`. Status flips:
- If `amount_received == total` (cent tolerance): `Paid`
- Otherwise: `PartiallyPaid`

### 4.3 Write-off → bad-debt JE

`ArInvoiceService::writeOff()`, inside `DB::transaction`:

Eligibility: invoice status is Approved or PartiallyPaid (not Draft, PendingApproval, Paid, Cancelled, or already WrittenOff). The amount written off is the **outstanding** amount (`total - amount_received`), so partially-paid invoices can still be partially written off.

```
Dr Bad Debt Expense GL (code 5600)                                      = invoice.outstandingAmount()
Cr AR GL (invoice.ar_gl_account_id)                                     = invoice.outstandingAmount()
```

JE.source_type = `ar_invoice`, JE.source_id = invoice.id, narration = "Write-off: <reason>".
Invoice.write_off_journal_entry_id ← JE.id. Invoice status → `WrittenOff`.
`amount_received` is not modified; `outstandingAmount()` becomes 0 only via status check (write-off is treated as a separate concept from payment).

Note: an invoice cannot be both PartiallyPaid AND WrittenOff in storage. Once written off, the status is `WrittenOff` — UI shows the original `amount_received` and the written-off amount separately on the Show page. F3 acceptance: a partially-paid invoice CAN be written off (the remaining outstanding is the write-off amount); the receipts already recorded stay valid.

### 4.4 Cancellation, void, reversal

- **Cancel an AR invoice** (only allowed when no receipts allocated): `ArInvoiceService::cancel()` → reverses accrual JE. Status → `Cancelled`.
- **Void an AR receipt**: `ArReceiptService::void()` → reverses receipt JE, rolls back each invoice's `amount_received`, restores prior invoice status (Approved or PartiallyPaid based on residual). Uses `lockForUpdate` on each invoice row (fixing F2's gap). Status → `Voided`.

## 5. Services

### 5.1 `CustomerService`

CRUD + archive guard analogous to `VendorService`. Refuses archive if customer has any non-cancelled / non-WrittenOff AR invoices. Same `list/create/update/archive` signatures.

### 5.2 `ArInvoiceService`

Mirrors `VendorInvoiceService` plus `writeOff()`. Methods:
- `create(array $data, User $creator): ArInvoice` — validates line GLs are type=income, computes totals, creates invoice + lines, builds + posts accrual JE.
- `submit(ArInvoice $invoice): ArInvoice` — Draft → PendingApproval.
- `approve(ArInvoice $invoice, User $approver): ArInvoice` — PendingApproval → Approved; enforces approver ≠ creator.
- `cancel(ArInvoice $invoice, User $by, string $reason): ArInvoice` — refuses if allocations exist; reverses accrual JE; status → Cancelled.
- `writeOff(ArInvoice $invoice, User $by, string $reason): ArInvoice` — requires `ar_invoices.write_off` permission (gated at controller); posts bad-debt JE; status → WrittenOff. Refuses if status ∉ {Approved, PartiallyPaid}.

### 5.3 `ArReceiptService`

Mirrors `ApPaymentService`. Methods:
- `record(array $data, User $creator): ArReceipt` — validates sum(allocations) == amount; locks each allocated invoice with `lockForUpdate`; verifies status ∈ {Approved, PartiallyPaid} and allocated ≤ outstanding; creates receipt + allocations; updates `amount_received` on each invoice; posts receipt JE.
- `void(ArReceipt $receipt, User $by, string $reason): ArReceipt` — reverses JE; **uses `lockForUpdate` when rolling back `amount_received`** (fixing F2's `ApPaymentService::void()` gap); restores invoice statuses.

### 5.4 `CustomerStatementService`

New for F3. Pure read service.

```php
public function generate(Customer $customer, CarbonImmutable $from, CarbonImmutable $to): array
```

Returns an array shape:

```php
[
    'customer' => CustomerResource data,
    'period'   => ['from' => '...', 'to' => '...'],
    'opening_balance' => float,   // sum of outstanding from invoices dated < $from
    'lines' => [
        ['date' => '...', 'reference' => 'ARI-2026-0001', 'type' => 'invoice', 'debit' => 1000.00, 'credit' => 0, 'running_balance' => 1000.00, 'description' => '...'],
        ['date' => '...', 'reference' => 'ARC-2026-0001', 'type' => 'receipt', 'debit' => 0, 'credit' => 500.00, 'running_balance' => 500.00, 'description' => '...'],
        ...
    ],
    'closing_balance' => float,
    'aging' => ['current' => float, '30' => float, '60' => float, '90_plus' => float],
]
```

Cached 60s per `(customer_id, from, to)` triple via `Cache::remember()`.

## 6. Permissions

Add to `RolePermissionSeeder` and `User::ROLE_PERMISSIONS`:

| Slug | Group | Granted to |
|---|---|---|
| `customers.view` | Finance | finance_officer, auditor |
| `customers.manage` | Finance | finance_officer |
| `ar_invoices.view` | Finance | finance_officer, auditor |
| `ar_invoices.create` | Finance | finance_officer |
| `ar_invoices.approve` | Finance | finance_officer |
| `ar_invoices.receive` | Finance | finance_officer |
| `ar_invoices.write_off` | Finance | finance_officer |
| `statements.view` | Finance | finance_officer, auditor |

`auditor` gets 3 view-only slugs (`customers.view`, `ar_invoices.view`, `statements.view`). `finance_officer` gets all 8. `super_admin` gets all via legacy wildcard.

## 7. Frontend (Inertia pages)

5 new pages under `resources/js/Pages/Finance/`:

- **`Customers/Index.vue`** — Mirrors `Vendors/Index.vue`. Status filter chips, search, SlidePanel CRUD form with default income / AR GL dropdowns + bank picker.
- **`ArInvoices/Index.vue`** — Mirrors `ApInvoices/Index.vue`. Status filter (includes WrittenOff chip), customer filter, "New Invoice" SlidePanel with dynamic line editor + client-side total computation. Actions per row: View, Submit, Approve, Cancel, **Write Off**.
- **`ArInvoices/Show.vue`** — Mirrors `ApInvoices/Show.vue`. Adds a "Write Off" button gated by `ar_invoices.write_off`. Shows outstanding vs original total vs written-off amount when applicable.
- **`ArReceipts/Index.vue`** — Mirrors `ApPayments/Index.vue`. Multi-invoice allocation UI, source-bank picker, external_ref field for manual entry.
- **`Statements/Index.vue`** — NEW. Customer picker + date-range pickers + "Generate" button. Table renders opening balance, line-by-line transactions with running balance, closing balance, aging summary. Browser print button. No PDF export in F3.

Sidebar additions under existing Finance section:
- "Customers" — `customers.view`
- "AR Invoices" — `ar_invoices.view`
- "AR Receipts" — `ar_invoices.view`
- "Statements" — `statements.view`

## 8. Routes (`routes/web.php`)

Inside the existing `Route::middleware(['auth', 'audit'])->group(...)` block, in the `finance.` prefix group, extend with:

```php
// F3 — Customers
Route::middleware('permission:customers.view')->group(function () {
    Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
});
Route::middleware('permission:customers.manage')->group(function () {
    Route::post('customers',               [CustomerController::class, 'store'])->name('customers.store');
    Route::patch('customers/{customer}',   [CustomerController::class, 'update'])->name('customers.update');
    Route::delete('customers/{customer}',  [CustomerController::class, 'destroy'])->name('customers.destroy');
});

// F3 — AR Invoices
Route::middleware('permission:ar_invoices.view')->group(function () {
    Route::get('ar-invoices',                       [ArInvoiceController::class, 'index'])->name('ar-invoices.index');
    Route::get('ar-invoices/{arInvoice}',           [ArInvoiceController::class, 'show'])->name('ar-invoices.show');
});
Route::middleware('permission:ar_invoices.create')->group(function () {
    Route::post('ar-invoices',                      [ArInvoiceController::class, 'store'])->name('ar-invoices.store');
    Route::post('ar-invoices/{arInvoice}/submit',   [ArInvoiceController::class, 'submit'])->name('ar-invoices.submit');
});
Route::middleware('permission:ar_invoices.approve')->group(function () {
    Route::post('ar-invoices/{arInvoice}/approve',  [ArInvoiceController::class, 'approve'])->name('ar-invoices.approve');
    Route::post('ar-invoices/{arInvoice}/cancel',   [ArInvoiceController::class, 'cancel'])->name('ar-invoices.cancel');
});
Route::middleware(['permission:ar_invoices.write_off', '2fa:fresh'])->group(function () {
    Route::post('ar-invoices/{arInvoice}/write-off',[ArInvoiceController::class, 'writeOff'])->name('ar-invoices.write-off');
});

// F3 — AR Receipts
Route::middleware('permission:ar_invoices.view')->group(function () {
    Route::get('ar-receipts', [ArReceiptController::class, 'index'])->name('ar-receipts.index');
});
Route::middleware(['permission:ar_invoices.receive', '2fa:fresh'])->group(function () {
    Route::post('ar-receipts',                        [ArReceiptController::class, 'store'])->name('ar-receipts.store');
    Route::post('ar-receipts/{arReceipt}/void',       [ArReceiptController::class, 'void'])->name('ar-receipts.void');
});

// F3 — Statements
Route::middleware('permission:statements.view')->group(function () {
    Route::get('statements', [StatementController::class, 'index'])->name('statements.index');
    Route::get('statements/{customer}', [StatementController::class, 'show'])->name('statements.show');
});
```

The `2fa:fresh` middleware on receipt + write-off endpoints is forward-applied from F2's review (AP payments lacked this; F3 fixes the pattern).

## 9. Seeders

- `CustomerSeeder` — idempotent, seeds 5 example customers reflecting CIHRM's revenue mix:
  - `CUS-001` "Acme Industries Ltd" (membership)
  - `CUS-002` "Government of Ghana — Min of Finance" (training contract)
  - `CUS-003` "Ghana National Bank — HR Dept" (training contract)
  - `CUS-004` "Individual Member — A. K. Asante" (membership dues)
  - `CUS-005` "MTN Ghana — Training Programme" (training contract)
  Each links default income GL (4100/4200/4300 mix) and default AR GL (1200).
- `ChartOfAccountsSeeder` modified to add code `5600 Bad Debt Expense` under parent code `5000 Expenses` (parent already exists). Idempotent.

Wire `CustomerSeeder` into `DatabaseSeeder` after `VendorSeeder`.

## 10. Finance Hub upgrade

After F3 lands, `FinanceHubService::build()` returns:
- All F2 keys unchanged
- New: `arOutstanding` — sum of `ar_invoices.outstandingAmount()` for status ∈ {Approved, PartiallyPaid}
- New: `agingBuckets` — `{ current, 30, 60, 90_plus }` over those same outstanding invoices, computed against `due_date`:
  - `current` — due_date ≥ today
  - `30` — due_date 1–30 days past
  - `60` — due_date 31–60 days past
  - `90_plus` — due_date 61+ days past

`Hub.vue` extends to 6-tile KPI grid on `lg` breakpoint:

| Cash Position | AP Outstanding | **AR Outstanding** | Outstanding Loans | Pending Approvals | Next Payroll Run |
|---|---|---|---|---|---|

Aging tile is shown as a second row card below the KPI strip (4 sub-tiles current/30/60/90+).

## 11. Testing

Pest Feature + Unit tests under `tests/Feature/Finance/`:

**`CustomerTest.php`** — CRUD endpoints + RBAC (mirrors VendorTest).

**`ArInvoiceServiceTest.php`** — service-level:
- create + accrual JE auto-post; balances reflect AR↑ Income↑
- fallback to GL code 1200 when customer has no default_ar_gl
- rejects line GL that isn't type=income
- submit + approve + dual-control
- cancel reverses accrual JE; refuses if allocations exist
- **writeOff posts bad-debt JE**; refuses if status ∉ {Approved, PartiallyPaid}; partially-paid invoice can be written off for outstanding amount

**`ArReceiptServiceTest.php`** — mirrors ApPaymentServiceTest plus:
- void uses `lockForUpdate` on invoice rows (concurrency-safe)
- aging buckets computed correctly across mixed due dates

**`CustomerStatementServiceTest.php`** — new:
- generate() returns expected shape with running balance
- opening balance = outstanding from invoices dated < from
- closing balance = opening + (sum of debits) - (sum of credits) within range
- aging buckets computed for outstanding invoices
- cached 60s per (customer, from, to) key

**`ArInvoiceTest.php`** — HTTP endpoints + RBAC (employee 403; auditor view-only; finance_officer can write).

**`ArReceiptTest.php`** — HTTP endpoints + 2fa middleware blocks when fresh session not asserted.

**`StatementTest.php`** — list + show endpoints; permission gating; cache busts correctly.

**`FinanceHubTest.php` modifications** — add assertions for `arOutstanding` and `agingBuckets` shape.

**`F3PermissionsSeedTest.php`** — verifies all 8 new slugs exist, granted correctly.

Approximate new tests: ~50. Total Finance suite target: ~180+.

## 12. Risks and Trade-offs

- **`written_off` is irreversible in F3.** Once an invoice is written off, the only path to un-write-off is `super_admin` manually posting a reversal JE (which un-credits Bad Debt Expense and re-debits AR). Adding an automated un-write-off action requires careful audit trail thought and is deferred.
- **Statement generation could be slow** for customers with many invoices. F3 caches 60s per (customer, from, to) tuple. Pagination within a statement is not implemented — operators should pick reasonable date ranges. Acceptable for membership scale (most customers have <100 invoices).
- **Aging buckets are computed on every Hub render** for ALL outstanding invoices. With <1000 outstanding invoices this is sub-100ms; if AR volume grows, move to a cached materialized view in F5.
- **Cancel-after-receipt is blocked**. The same constraint as F2's invoice cancellation: if any receipts allocate to the invoice, cancellation is refused. Operators must void the receipts first.
- **Receipt allocation is single-currency.** All amounts assumed GHS. Multi-currency AR (e.g. invoicing a Nigerian client in USD) is out of F3 scope; the schema reserves the column but no FX logic exists yet.
- **6-tile Hub KPI strip** may feel cluttered on smaller laptops. Tailwind responsive: `grid-cols-2` on mobile, `grid-cols-3` on md, `grid-cols-6` on lg. Aging sub-tiles in a separate row reduce visual density.

## 13. Acceptance criteria (F3 done means)

1. A `finance_officer` can create a customer with tax ID, default income GL, default AR GL, and preferred receiving bank.
2. A `finance_officer` can create a multi-line AR invoice; on submit, the accrual JE auto-posts; `gl_account_balances` shows AR↑ and the relevant Income↑.
3. A second `finance_officer` can approve (dual-control enforced); status flips to Approved.
4. A `finance_officer` can record an AR receipt against one or more invoices from a specific org bank account. The receipt JE auto-posts: Bank↑ AR↓. Invoice statuses flip to PartiallyPaid or Paid as appropriate.
5. A `finance_officer` can void an AR receipt. Receipt JE is reversed. Invoice `amount_received` rolls back; invoice status is restored.
6. A `finance_officer` can write off an approved or partially-paid invoice as bad debt. Bad-debt JE posts: BadDebtExpense↑ AR↓. Invoice status flips to WrittenOff.
7. The Customer Statement page renders a date-range statement with running balance + aging summary.
8. The Finance Hub shows `arOutstanding` KPI and `agingBuckets` (current / 30 / 60 / 90+) tile.
9. An `employee` gets 403 on every `/finance/{customers,ar-invoices,ar-receipts,statements}/*` route.
10. An `auditor` can view all 4 read pages (customers, ar-invoices, ar-receipts, statements) but cannot write, approve, receive, or write off.
11. `migrate:fresh --seed` adds GL code `5600 Bad Debt Expense` and seeds 5 customers; idempotent on re-run.
12. All Pest Feature tests under `tests/Feature/Finance/` pass (~180+ total: F1 49 + F2 80 + F3 ~50).
13. `2fa:fresh` middleware blocks AR receipt creation, void, and invoice write-off when the user's session 2FA assertion is older than the configured TTL.

## 14. F2 lessons forward-applied

This section is intentional documentation of what F3 does differently because of F2's review:

1. **`customer_invoice_no` uniqueness at FormRequest level** — `StoreArInvoiceRequest` includes `Rule::unique('ar_invoices')->where('customer_id', ...)` conditional on non-null value, surfacing a clean field error instead of a DB constraint violation.
2. **`lockForUpdate` in `ArReceiptService::void()`** — explicit row lock on each allocated invoice before mutating `amount_received`, fixing F2's `ApPaymentService::void()` gap.
3. **`2fa:fresh` middleware on receipt + write-off endpoints** — added at the route level for all sensitive money-moving actions, consistent with `payroll.disburse` / `loans.disburse`.
4. **Reference generation unchanged** — same `nextXxxReference()` count-based approach as F2. Concurrency-safe upgrade deferred to F5+ when transaction volume warrants it. The `reference` column has `UNIQUE` so a race produces a DB error, not data corruption.
5. **`status->value` in service comparisons** — F2 had a string-comparison nit; F3 uses enum-type comparison directly (`$invoice->status === ArInvoiceStatus::Paid`).

## 15. Out of scope (deferred to later phases or beyond)

- F4: Paystack online payment gateway, webhook auto-receipts
- F5: Bank reconciliation, statement import/matching
- Recurring AR invoices (membership dues automation)
- Dunning letters / overdue payment reminders
- Customer credit limits and credit-block enforcement
- Multi-currency AR with FX gain/loss accounting
- PDF statement generation (HTML print only in F3)
- Fine-grained aging buckets (120/180/365 day splits)
- Un-write-off automation
- AR invoice templates / saved line items
- Bulk-import / API for AR invoices

## 16. References

- F2 spec: `docs/superpowers/specs/2026-05-22-finance-f2-accounts-payable-design.md`
- F1 spec: `docs/superpowers/specs/2026-05-21-finance-f1-accounts-foundation-design.md`
- F2 plan: `docs/superpowers/plans/2026-05-22-finance-f2-accounts-payable.md`
- F1 plan: `docs/superpowers/plans/2026-05-21-finance-f1-accounts-foundation.md`
