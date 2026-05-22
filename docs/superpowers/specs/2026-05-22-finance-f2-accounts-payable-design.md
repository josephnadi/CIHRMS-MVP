# Finance F2 — Accounts Payable + Journal Engine Design

**Status:** Approved (design phase)
**Date:** 2026-05-22
**Owner:** finance_officer role / Finance department
**Depends on:** F1 (merged to main via PR #7)
**Related memory:** [[cihrms-finance-f1-complete]], [[cihrms-architecture-patterns]], [[cihrms-rbac-system]]

## 1. Context

[F1](2026-05-21-finance-f1-accounts-foundation-design.md) shipped the schema, permissions, and UI shell for the Finance module: Chart of Accounts, Organisational Bank Accounts, Finance Hub landing page. The `gl_account_balances` cache table exists but every row is at zero — no transactions have been posted to it. `FinanceHubService::cashPosition()` falls back to summing `org_bank_accounts.opening_balance` as a static proxy, with an inline `// NOTE:` comment marking the F2 swap point.

F2 introduces double-entry bookkeeping. Every finance event (vendor invoice, vendor payment) produces a journal entry with balanced debits and credits, which atomically updates `gl_account_balances`. This is the load-bearing piece F3 (AR), F4 (online gateway), and F5 (reconciliation) all build on.

The user's original request — "manage loans, payrolls, invoicing, payment processing, organisation accounts, banking details, and online card payments" — drives this. F2 covers the **invoicing management** and **payment processing** halves for the vendor (outbound) direction. F3 will mirror this on the customer (inbound) side.

## 2. Scope

### 2.1 In scope

- Journal posting engine (`JournalPostingService`) — the single mutator of `gl_account_balances`.
- Manual journal entries — backend support + a minimal admin-only UI for emergency corrections.
- Vendor master data — code, name, tax ID, address, default expense GL, default AP liability GL, status.
- Vendor invoices — header + lines (qty, unit price, expense GL per line), with auto-posting accrual JE on creation.
- Invoice approval workflow — Draft → PendingApproval → Approved (with `ap_invoices.approve` permission, dual-control enforced: approver must differ from creator).
- AP payments — record payment from a specific `org_bank_account`; allocate to one or more invoices; auto-post payment JE.
- Manual disbursement trigger — "Send via GhIPSS" / "Send via MoMo" buttons reuse the existing `BatchDisbursementService` and provider implementations under `app/Services/Disbursement/` (pre-existing CIHRMS infrastructure, not introduced by F2).
- 8 new permission slugs; granted to `finance_officer` (7) and `auditor` (3 view-only) and `super_admin` (all + `journal.post_manual`).
- 4 new Inertia pages (Vendors, AP Invoices, AP Payments, Journal Explorer).
- Sidebar additions under the existing Finance section.
- Pest Feature tests + a contract test asserting JE balance invariant.

### 2.2 Out of scope (deferred)

- **F3:** AR invoices, customers, receipts.
- **F4:** Paystack / Stripe-equivalent gateway.
- **F5:** Bank statement import and reconciliation.
- **Beyond F2:** Recurring invoices, multi-step approval routing (e.g., manager → CFO chain), three-way matching with purchase orders, expense categorisation taxonomies beyond `gl_account_id`, multi-currency FX gain/loss accounting.
- **Auto-disbursement on approval** — payments stay manual ("Send via …" button) to prevent accidental sends. Auto-disbursement deferred until operator confidence is established.
- **Partial-payment UI affordances** — schema supports partial via `ap_payment_invoice_allocations.allocated_amount < vendor_invoices.total`, but the F2 UI only renders full-invoice payments. Operators handle partials by recording multiple payments.

## 3. Data Model

### 3.1 Enums (`App\Enums\`)

- `VendorStatus` — `active`, `inactive`, `suspended`
- `VendorInvoiceStatus` — `draft`, `pending_approval`, `approved`, `partially_paid`, `paid`, `cancelled`
- `ApPaymentStatus` — `pending`, `processed`, `voided`
- `JournalEntryStatus` — `draft`, `posted`, `reversed`
- `JournalSourceType` — `manual`, `vendor_invoice`, `ap_payment` (extended in F3 to include `ar_invoice`, `ar_receipt`)

All five follow the existing pattern: backed string enum, `label()` method, `declare(strict_types=1)`, namespace `App\Enums`.

### 3.2 Migrations (7 tables)

**`vendors`**
```
id (PK)
code              VARCHAR(30)   UNIQUE NOT NULL    -- e.g. "VEN-0001"
name              VARCHAR(200)  NOT NULL
tax_id            VARCHAR(50)   NULLABLE           -- TIN
status            VARCHAR(20)   NOT NULL DEFAULT 'active'
email             VARCHAR(255)  NULLABLE
phone             VARCHAR(50)   NULLABLE
address           TEXT          NULLABLE
default_expense_gl_account_id  FK gl_accounts.id NULLABLE  -- defaults invoice lines
default_ap_gl_account_id       FK gl_accounts.id NULLABLE  -- defaults accrual JE credit; falls back to code '2100' if null
default_bank_account_id        FK org_bank_accounts.id NULLABLE  -- preferred outgoing bank
notes             TEXT          NULLABLE
created_at, updated_at, deleted_at (SoftDeletes)
INDEX (status), INDEX (name)
```

**`vendor_invoices`**
```
id (PK)
reference         VARCHAR(30)   UNIQUE NOT NULL    -- auto-gen "API-2026-0001"
vendor_id         FK vendors.id NOT NULL
vendor_invoice_no VARCHAR(100)  NULLABLE           -- the vendor's own invoice number
status            VARCHAR(30)   NOT NULL DEFAULT 'draft'
invoice_date      DATE          NOT NULL
due_date          DATE          NULLABLE
subtotal          DECIMAL(18,2) NOT NULL DEFAULT 0
tax_amount        DECIMAL(18,2) NOT NULL DEFAULT 0
total             DECIMAL(18,2) NOT NULL DEFAULT 0
amount_paid       DECIMAL(18,2) NOT NULL DEFAULT 0  -- running total, maintained by payment allocations
currency          CHAR(3)       NOT NULL DEFAULT 'GHS'
ap_gl_account_id  FK gl_accounts.id NOT NULL       -- snapshotted at creation from vendor default or 2100
notes             TEXT          NULLABLE
accrual_journal_entry_id  FK journal_entries.id NULLABLE  -- the auto-posted accrual JE
created_by        FK users.id   NOT NULL
approved_by       FK users.id   NULLABLE
approved_at       TIMESTAMP     NULLABLE
cancelled_by      FK users.id   NULLABLE
cancelled_at      TIMESTAMP     NULLABLE
created_at, updated_at, deleted_at (SoftDeletes)
UNIQUE (vendor_id, vendor_invoice_no)              -- one vendor can't bill the same number twice
INDEX (status), INDEX (invoice_date)
```

**`vendor_invoice_lines`**
```
id (PK)
vendor_invoice_id FK vendor_invoices.id NOT NULL ON DELETE CASCADE
line_no           SMALLINT      NOT NULL
description       VARCHAR(500)  NOT NULL
quantity          DECIMAL(12,3) NOT NULL DEFAULT 1
unit_price        DECIMAL(18,4) NOT NULL DEFAULT 0
line_total        DECIMAL(18,2) NOT NULL DEFAULT 0     -- quantity * unit_price (snapshot)
tax_rate          DECIMAL(7,4)  NOT NULL DEFAULT 0     -- e.g. 0.125 for 12.5% VAT
tax_amount        DECIMAL(18,2) NOT NULL DEFAULT 0
gl_account_id     FK gl_accounts.id NOT NULL           -- expense GL for this line
UNIQUE (vendor_invoice_id, line_no)
```

**`journal_entries`**
```
id (PK)
reference         VARCHAR(30)   UNIQUE NOT NULL        -- "JE-2026-000001"
entry_date        DATE          NOT NULL
narration         VARCHAR(500)  NULLABLE
status            VARCHAR(20)   NOT NULL DEFAULT 'draft'
source_type       VARCHAR(50)   NOT NULL DEFAULT 'manual'  -- JournalSourceType enum
source_id         UNSIGNED BIG  NULLABLE                -- e.g. vendor_invoices.id when source_type='vendor_invoice'
posted_at         TIMESTAMP     NULLABLE
posted_by         FK users.id   NULLABLE
reversed_at       TIMESTAMP     NULLABLE
reversed_by       FK users.id   NULLABLE
reversal_of_id    FK journal_entries.id NULLABLE       -- if this JE reverses another
created_by        FK users.id   NOT NULL
created_at, updated_at, deleted_at (SoftDeletes)
INDEX (status), INDEX (entry_date), INDEX (source_type, source_id)
```

**`journal_lines`**
```
id (PK)
journal_entry_id  FK journal_entries.id NOT NULL ON DELETE CASCADE
line_no           SMALLINT      NOT NULL
gl_account_id     FK gl_accounts.id NOT NULL RESTRICT ON DELETE
debit_amount      DECIMAL(18,2) NOT NULL DEFAULT 0
credit_amount    DECIMAL(18,2) NOT NULL DEFAULT 0
narration         VARCHAR(500)  NULLABLE
UNIQUE (journal_entry_id, line_no)
INDEX (gl_account_id)
CHECK: at least one of (debit_amount, credit_amount) > 0; not both -- enforced in model + service
```

**`ap_payments`**
```
id (PK)
reference         VARCHAR(30)   UNIQUE NOT NULL    -- "APP-2026-0001"
vendor_id         FK vendors.id NOT NULL
status            VARCHAR(20)   NOT NULL DEFAULT 'pending'
payment_date      DATE          NOT NULL
amount            DECIMAL(18,2) NOT NULL
currency          CHAR(3)       NOT NULL DEFAULT 'GHS'
org_bank_account_id  FK org_bank_accounts.id NOT NULL
narration         VARCHAR(500)  NULLABLE
journal_entry_id  FK journal_entries.id NULLABLE
disbursement_id   FK disbursements.id NULLABLE        -- linked when "Send via GhIPSS" fires
created_by        FK users.id   NOT NULL
processed_by      FK users.id   NULLABLE
processed_at      TIMESTAMP     NULLABLE
voided_by         FK users.id   NULLABLE
voided_at         TIMESTAMP     NULLABLE
created_at, updated_at, deleted_at (SoftDeletes)
INDEX (status), INDEX (payment_date), INDEX (vendor_id)
```

**`ap_payment_invoice_allocations`**
```
id (PK)
ap_payment_id     FK ap_payments.id NOT NULL ON DELETE CASCADE
vendor_invoice_id FK vendor_invoices.id NOT NULL ON DELETE RESTRICT
allocated_amount  DECIMAL(18,2) NOT NULL
notes             VARCHAR(255)  NULLABLE
created_at, updated_at
UNIQUE (ap_payment_id, vendor_invoice_id)
```

### 3.3 Models

Each follows the F1 pattern: `use HasFactory, SoftDeletes;` (where applicable), enum casts, decimal casts, named scopes, relations. `JournalLine` and `ApPaymentInvoiceAllocation` have no `SoftDeletes` (they're derived data, cascade with their parent).

Key model methods:
- `JournalEntry::isBalanced()` — returns true iff `SUM(debit_amount) == SUM(credit_amount)`.
- `VendorInvoice::outstandingAmount()` — `total - amount_paid`.
- `VendorInvoice::status()` — accessor that derives status from `amount_paid` vs `total` after payment.

## 4. Journal Posting Engine

### 4.1 `App\Services\Finance\JournalPostingService`

Single mutator of `gl_account_balances`. Two public methods.

```php
public function post(JournalEntry $entry): JournalEntry
```

Behaviour:
1. Asserts `$entry->status === JournalEntryStatus::Draft`.
2. Asserts `$entry->isBalanced()` — throws `DomainException` with the imbalance amount if not.
3. Inside `DB::transaction`:
   a. For each line: lock the matching `gl_account_balances` row (`lockForUpdate()`) and apply the **natural delta** for that account's type. The natural delta is computed by a private helper:
      - Asset / Expense accounts: `delta = debit_amount - credit_amount`
      - Liability / Equity / Income accounts: `delta = credit_amount - debit_amount`

      `gl_account_balances.balance` always stores the **natural balance** — positive when the account holds its expected sign (cash on hand: positive; A/P owed: positive). UI consumers can compare balances directly without sign-flipping. The helper lives on `JournalPostingService` as `naturalDelta(GlAccount $account, JournalLine $line): float` and is unit-tested.
   b. Mark entry: `posted_at = now()`, `posted_by = auth()->id()`, `status = Posted`.
   c. Update `gl_account_balances.last_posted_at` for each affected account.
   d. Dispatch `JournalEntryPosted` event (queued listener writes to audit log via the existing `WriteAuditLog` job pattern).

```php
public function reverse(JournalEntry $entry, User $by, string $reason): JournalEntry
```

Behaviour:
1. Asserts `$entry->status === JournalEntryStatus::Posted`.
2. Inside `DB::transaction`:
   a. Create a new `JournalEntry` with `status = Posted`, `reversal_of_id = $entry->id`, `source_type = Manual`, narration includes the reason.
   b. Copy each line with `debit_amount` and `credit_amount` swapped.
   c. Apply the reversed deltas to `gl_account_balances`.
   d. Mark original: `status = Reversed`, `reversed_at = now()`, `reversed_by = $by->id`.

### 4.2 Invariants enforced by the engine

- Every posted JE has at least 2 lines.
- Sum of debits equals sum of credits (down to the cent).
- A line cannot have both debit_amount > 0 AND credit_amount > 0 (enforced in model boot).
- `gl_account_balances.balance` exactly equals the **natural-balance** sum of all posted journal lines for that account: `SUM(debit) - SUM(credit)` for asset/expense accounts, `SUM(credit) - SUM(debit)` for liability/equity/income accounts.
- A contract test asserts this invariant by snapshotting balances, running a randomised sequence of posts/reverses, and re-computing natural-balance sums from `journal_lines`.

## 5. Vendor Invoice Flow

### 5.1 States

```
Draft --(approve)--> PendingApproval --(approve)--> Approved --(pay)--> PartiallyPaid / Paid
                                                       |
                                                  (cancel)
                                                       v
                                                   Cancelled
```

Draft → PendingApproval is the act of submitting for approval (any user with `ap_invoices.create`). PendingApproval → Approved requires `ap_invoices.approve` AND `approver_id !== creator_id` (dual-control). Cancellation reverses the accrual JE.

### 5.2 Service flow

`VendorInvoiceService::create(array $data, User $creator): VendorInvoice`

1. Validate (FormRequest already did basic; service revalidates total = sum(line_total + tax_amount)).
2. Inside `DB::transaction`:
   a. Create `VendorInvoice` with reference auto-gen, status = `Draft`.
   b. Create `VendorInvoiceLine` rows.
   c. Build the accrual `JournalEntry` (status = `Draft`, source_type = `VendorInvoice`, source_id = invoice.id, narration = "Accrual for {vendor.code} invoice {vendor_invoice_no}").
   d. Add lines: `Dr` expense GLs (from invoice lines) for each line's total amount (including tax); `Cr` AP GL (`vendor.default_ap_gl_account_id` or fallback to `gl_accounts.where('code', '2100')`) for the invoice total.
   e. Call `JournalPostingService::post($entry)`.
   f. Set `invoice.accrual_journal_entry_id = $entry->id` and save.
3. Dispatch `VendorInvoiceCreated` event.

`approve(VendorInvoice $invoice, User $approver)` — moves status to `Approved`, asserts approver !== creator.

`cancel(VendorInvoice $invoice, User $by, string $reason)` — calls `JournalPostingService::reverse(...)` on the accrual JE; sets status = `Cancelled`. Refuses if the invoice has any allocated payments.

## 6. AP Payment Flow

`ApPaymentService::record(array $data, array $allocations, User $creator): ApPayment`

1. Validate: sum of allocations == payment amount; every allocation's invoice is in `Approved` or `PartiallyPaid` state; sum of allocations per invoice ≤ invoice outstandingAmount.
2. Inside `DB::transaction`:
   a. Create `ApPayment` row (status = `Pending`).
   b. Create allocation rows.
   c. For each allocated invoice: `invoice.amount_paid += allocated_amount`; if `amount_paid == total` → status `Paid`, else `PartiallyPaid`.
   d. Build the payment `JournalEntry` (source_type = `ApPayment`): `Dr` AP GL accounts (one debit line per allocated invoice's `ap_gl_account_id` for its allocated amount); `Cr` the bank's `gl_account_id` for the payment total.
   e. Call `JournalPostingService::post($entry)`.
   f. Set `payment.journal_entry_id = $entry->id`, `status = Processed`, `processed_at = now()`, `processed_by = $creator->id`.
3. Dispatch `ApPaymentProcessed` event.

`void(ApPayment $payment, User $by, string $reason)` — reverses the payment JE, rolls back invoice `amount_paid` deltas, restores prior invoice statuses, sets payment status = `Voided`.

`triggerDisbursement(ApPayment $payment, string $channel)` — calls existing `BatchDisbursementService::dispatch($payment, $channel)`; sets `payment.disbursement_id`. Channels: `ghipss_ach`, `mtn_momo`, `airtel_tigo`, `vodafone_cash`. Out-of-scope for F2 if the existing service doesn't support single-payment dispatch; in that case ship a stub that records the intent and an operator confirms externally.

## 7. Permissions

Add to `RolePermissionSeeder` and `User::ROLE_PERMISSIONS`:

| Slug | Group | Granted to |
|---|---|---|
| `vendors.view` | Finance | finance_officer, auditor |
| `vendors.manage` | Finance | finance_officer |
| `ap_invoices.view` | Finance | finance_officer, auditor |
| `ap_invoices.create` | Finance | finance_officer |
| `ap_invoices.approve` | Finance | finance_officer |
| `ap_invoices.pay` | Finance | finance_officer |
| `journal.view` | Finance | finance_officer, auditor |
| `journal.post_manual` | Finance | super_admin only (via wildcard) |

`auditor` gets `vendors.view + ap_invoices.view + journal.view` (3 view-only). `finance_officer` gets 7 (all except `journal.post_manual`). `super_admin` gets all via the legacy wildcard `*`.

Dual-control note: `ap_invoices.approve` is granted to `finance_officer`, but the service enforces approver !== creator via code, not permission. This is consistent with the loan-approval pattern.

## 8. Frontend (Inertia pages)

All four under `resources/js/Pages/Finance/`:

- **`Vendors/Index.vue`** — vendor table with status filter chips, search, SlidePanel for create/edit. Form: code (auto-gen suggested), name, tax ID, status, contact (email/phone/address), default expense GL dropdown, default AP GL dropdown (filtered to type=liability), default bank dropdown.
- **`ApInvoices/Index.vue`** — invoice list with status filter chips, search, "New Invoice" SlidePanel. Form: vendor (combobox), invoice number, dates, dynamic line editor (add/remove rows; each row: description, qty, unit_price, tax_rate, expense GL dropdown). Totals computed client-side. Actions per row: View, Approve, Cancel, Pay.
- **`ApInvoices/Show.vue`** — invoice detail with line breakdown, accrual JE link, payments list, action buttons (Approve / Cancel / Record Payment).
- **`ApPayments/Index.vue`** — payment list with status filter, "Record Payment" SlidePanel allowing multi-invoice allocation (vendor → filter approved/partially-paid invoices → allocate amounts that sum to payment total → pick source bank account).
- **`Journal/Index.vue`** — read-only journal entry list with filters (date range, source type, status). Click → modal showing balanced debit/credit table.

Sidebar additions under the existing Finance section (after Bank Accounts):
- "Vendors" — `vendors.view`
- "AP Invoices" — `ap_invoices.view`
- "AP Payments" — `ap_invoices.view` (visibility tied to invoice perm; payment perm gates the action button)
- "Journal" — `journal.view`

## 9. Routes (`routes/web.php`)

Inside the existing `Route::middleware(['auth', 'audit'])->group(...)` block, extend the `prefix('finance')->name('finance.')` group with:

```php
// Vendors
Route::middleware('permission:vendors.view')->group(function () {
    Route::get('vendors', [VendorController::class, 'index'])->name('vendors.index');
});
Route::middleware('permission:vendors.manage')->group(function () {
    Route::post('vendors',                [VendorController::class, 'store'])->name('vendors.store');
    Route::patch('vendors/{vendor}',      [VendorController::class, 'update'])->name('vendors.update');
    Route::delete('vendors/{vendor}',     [VendorController::class, 'destroy'])->name('vendors.destroy');
});

// AP Invoices
Route::middleware('permission:ap_invoices.view')->group(function () {
    Route::get('ap-invoices',                       [ApInvoiceController::class, 'index'])->name('ap-invoices.index');
    Route::get('ap-invoices/{apInvoice}',           [ApInvoiceController::class, 'show'])->name('ap-invoices.show');
});
Route::middleware('permission:ap_invoices.create')->group(function () {
    Route::post('ap-invoices',                      [ApInvoiceController::class, 'store'])->name('ap-invoices.store');
    Route::post('ap-invoices/{apInvoice}/submit',   [ApInvoiceController::class, 'submit'])->name('ap-invoices.submit');
});
Route::middleware('permission:ap_invoices.approve')->group(function () {
    Route::post('ap-invoices/{apInvoice}/approve',  [ApInvoiceController::class, 'approve'])->name('ap-invoices.approve');
    Route::post('ap-invoices/{apInvoice}/cancel',   [ApInvoiceController::class, 'cancel'])->name('ap-invoices.cancel');
});

// AP Payments
Route::middleware('permission:ap_invoices.view')->group(function () {
    Route::get('ap-payments', [ApPaymentController::class, 'index'])->name('ap-payments.index');
});
Route::middleware('permission:ap_invoices.pay')->group(function () {
    Route::post('ap-payments',                            [ApPaymentController::class, 'store'])->name('ap-payments.store');
    Route::post('ap-payments/{apPayment}/disburse',       [ApPaymentController::class, 'disburse'])->name('ap-payments.disburse');
    Route::post('ap-payments/{apPayment}/void',           [ApPaymentController::class, 'void'])->name('ap-payments.void');
});

// Journal explorer (read-only for finance/auditor)
Route::middleware('permission:journal.view')->group(function () {
    Route::get('journal',                  [JournalController::class, 'index'])->name('journal.index');
    Route::get('journal/{journalEntry}',   [JournalController::class, 'show'])->name('journal.show');
});

// Manual JE posting (super-admin only)
Route::middleware('permission:journal.post_manual')->group(function () {
    Route::post('journal',                          [JournalController::class, 'store'])->name('journal.store');
    Route::post('journal/{journalEntry}/post',      [JournalController::class, 'post'])->name('journal.post');
    Route::post('journal/{journalEntry}/reverse',   [JournalController::class, 'reverse'])->name('journal.reverse');
});
```

## 10. Seeders

- `VendorSeeder` — idempotent, seeds 5 example Ghana vendors (GCB Bank for office supplies, Vodafone for telecoms, Ghana Water Co., ECG for electricity, a generic stationer). Each links to a sensible default expense GL (5200 Operations or 5300 IT & Technology) and AP code 2100. `updateOrCreate` keyed on `code`.
- Wire into `DatabaseSeeder` after `OrgBankAccountSeeder`.

No invoice / payment / JE seeding — those are operator-created.

## 11. Finance Hub update

After F2 lands, update `FinanceHubService::cashPosition()`:

```php
private function cashPosition(): float
{
    // F2 onward: sum live gl_account_balances for asset accounts linked to active bank accounts.
    return (float) GlAccountBalance::query()
        ->join('gl_accounts', 'gl_accounts.id', '=', 'gl_account_balances.gl_account_id')
        ->join('org_bank_accounts', 'org_bank_accounts.gl_account_id', '=', 'gl_accounts.id')
        ->where('org_bank_accounts.is_active', true)
        ->where('gl_accounts.type', 'asset')
        ->sum('gl_account_balances.balance');
}
```

Remove the F1 `// NOTE:` comment about the static proxy. Update the existing `FinanceHubTest` cash-position assertion to expect the journal-derived value.

Add three new Hub KPIs:
- `apOutstanding` — sum of `vendor_invoices.outstandingAmount()` where status in (Approved, PartiallyPaid).
- `pendingApprovals.invoices` — count of `vendor_invoices` where status = PendingApproval.
- `pendingApprovals.payments` — count of `ap_payments` where status = Pending.

## 12. Testing

Pest Feature tests under `tests/Feature/Finance/`:

**Journal engine (`JournalPostingTest.php`)**
- Posts a balanced 2-line JE; balances reflect the deltas.
- Rejects unbalanced JE with `DomainException`.
- Rejects JE with debit AND credit > 0 on the same line.
- Concurrent post safety: two transactions trying to post to the same GL account both succeed with correct final balance (`lockForUpdate`).
- Reversal posts the inverse JE; balances return to original; original status = Reversed.
- `gl_account_balances` invariant: balance equals the natural-balance sum of all posted journal lines for that account (signed per account type), after a sequence of posts + reverses.

**Vendor CRUD (`VendorTest.php`)**
- finance_officer can CRUD; auditor 200 on list, 403 on writes; employee 403 everywhere.
- vendor code unique enforcement.
- default_expense_gl_account_id must reference type=expense; default_ap_gl_account_id must reference type=liability.

**AP Invoices (`ApInvoiceTest.php`)**
- Creating an invoice auto-posts an accrual JE: Dr Expense (line GLs), Cr AP.
- Invoice totals = sum of line_total + tax_amount; mismatch is rejected.
- approve(): requires `ap_invoices.approve`; approver !== creator.
- cancel(): reverses the accrual JE; balances return to pre-creation state.
- cancel() refused if the invoice has allocated payments.

**AP Payments (`ApPaymentTest.php`)**
- record(): allocates to one invoice; payment JE posts (Dr AP, Cr Bank GL).
- record(): allocates to two invoices; allocation amounts sum to payment amount.
- record(): refuses allocation > outstandingAmount.
- After payment, invoice status flips to PartiallyPaid or Paid correctly.
- void(): reverses the payment JE; invoice amount_paid rolled back; status restored.

**Journal Explorer (`JournalExplorerTest.php`)**
- finance_officer and auditor can list and view; employee 403.
- Manual JE creation only for super_admin.

**Hub update (`FinanceHubTest.php` modifications)**
- cashPosition reflects journal-derived balance after one accrual + one payment cycle.
- apOutstanding KPI reflects approved-but-unpaid invoices.

**Pattern:** every test seeds RolePermissionSeeder + ChartOfAccountsSeeder + OrgBankAccountSeeder + GlAccountBalanceSeeder + VendorSeeder in `beforeEach`.

## 13. Risks and Trade-offs

- **No DB-level balance constraint.** SQLite can't enforce `SUM(debit) = SUM(credit)` as a deferred check. We enforce in `JournalPostingService::post()` with a Pest contract test. Postgres migration (post-F5) can add the constraint.
- **Race condition on `gl_account_balances`.** Two concurrent transactions posting to the same GL account could lose updates without locking. We use `lockForUpdate()` inside the transaction. A dedicated concurrency test validates this with parallel-process simulation.
- **Soft-delete of vendor with invoices.** Vendor archive must be blocked if the vendor has any non-cancelled invoices (analogous to the F1 archive guards on GL accounts).
- **Single AP liability account.** All vendors default to GL 2100. F2 allows per-vendor override but the seed data uses the common account. F3 will mirror this pattern with a single 1200 Accounts Receivable.
- **AP payment ↔ disbursement integration is "fire and forget".** The `disburse` endpoint hands off to existing `BatchDisbursementService` and trusts it. If the disbursement fails downstream, the payment stays Processed but with `disbursement.status = Failed`. The operator must reconcile manually. A future improvement: link payment status to disbursement status more tightly.
- **Cancel after partial payment is not allowed.** F2 refuses to cancel an invoice that has allocations. Operators must void the payments first. This avoids unsolvable balance puzzles.
- **Manual journal entries are an emergency tool.** The `journal.post_manual` permission is granted only to super_admin in F2. Misuse can break the GL invariant. The Pest contract test catches imbalances at post time but cannot prevent intentional fraud.

## 14. Acceptance criteria (F2 done means)

1. A `finance_officer` can create a vendor with tax ID, expense GL default, AP GL default, and preferred bank.
2. A `finance_officer` can create a multi-line vendor invoice; on submit, an accrual JE auto-posts; `gl_account_balances` shows the expense and AP increases.
3. A second `finance_officer` (or same user — but the system enforces approver ≠ creator) can approve the invoice; status flips to Approved.
4. A `finance_officer` can record an AP payment from an org bank account against one or more invoices; the payment JE auto-posts; balances reflect AP↓ and Bank GL↓; invoice status flips to PartiallyPaid or Paid.
5. The Finance Hub `cashPosition` KPI now equals the live `gl_account_balances` sum for active bank-linked accounts (not opening_balance).
6. The Finance Hub shows new KPIs: `apOutstanding`, `pendingApprovals.invoices`, `pendingApprovals.payments`.
7. The Journal Explorer page shows every posted JE with balanced debits/credits.
8. An employee gets 403 on every `/finance/{vendors,ap-invoices,ap-payments,journal}/*` route.
9. An auditor can read all four pages but cannot create, approve, pay, or post journals.
10. The journal engine's balance invariant holds after a randomised sequence of posts and reverses (contract test passes).
11. All Pest Feature tests under `tests/Feature/Finance/` pass (existing 52 + new ~30 ≈ 82+ green).
12. `php artisan migrate:fresh --seed` produces seeded vendors and remains idempotent.

## 15. Out of scope (deferred to later phases or beyond)

- AR invoices, customers, receipts (F3)
- Paystack / online card gateway (F4)
- Bank reconciliation (F5)
- Recurring invoices
- Multi-step approval routing
- Purchase orders + three-way matching
- Multi-currency FX gain/loss
- Auto-disbursement on payment approval
- Partial-payment UI affordances (schema supports; UI deferred)
