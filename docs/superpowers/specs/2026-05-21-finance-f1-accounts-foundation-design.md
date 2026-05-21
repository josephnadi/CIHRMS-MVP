# Finance F1 — Accounts Foundation Design

**Status:** Approved (design phase)
**Date:** 2026-05-21
**Owner:** finance_officer role / Finance department
**Related memory:** [[cihrms-project-overview]], [[cihrms-architecture-patterns]], [[cihrms-rbac-system]]

## 1. Context

CIHRMS already ships substantial finance functionality: payroll engine (PAYE/SSNIT/Tier‑2), loans (products, accounts, repayments, amortization), employee-direction payments, batch disbursements (MTN MoMo / AirtelTigo / VodafoneCash / GhIPSS ACH), and statutory return generation. The `finance_officer` role has permissions for all of these.

However, the system has **no concept of the institute's own accounts**: no chart of accounts, no organizational bank accounts, no general-ledger ledger. The existing finance dashboard ([resources/js/Pages/Dashboard/DeptFinance.vue](../../../resources/js/Pages/Dashboard/DeptFinance.vue)) renders hard-coded mock data ("GHS 1.67M Staff Payroll", "Vendor Invoice GHS 28,000"), and there is no `/finance` route — only a dashboard widget.

The user's request — "finance should manage loans, payrolls, all financially related activities, including invoicing, payment processing, organization accounts, banking details, and online card payments" — spans multiple independent finance subsystems totaling roughly 8–12 weeks of work.

## 2. Decomposition (the full request)

The work is decomposed into five sequential phases. **This document specifies Phase F1 only.** Subsequent phases will be specified separately when scheduled.

| Phase | Scope | Depends on |
|-------|-------|------------|
| **F1** | Chart of Accounts, Organizational Bank Accounts, Finance permission expansion, real `/finance` hub page | — |
| **F2** | Accounts Payable: Vendors, Vendor Invoices, approval workflow, payment via existing disbursement engine, journal-posting engine | F1 |
| **F3** | Accounts Receivable: Customers, AR Invoices, receipts, statements | F1 + journal engine from F2 |
| **F4** | Online card/payment gateway (Paystack) — inbound payment links, webhooks auto-receipting AR invoices | F3 |
| **F5** | Bank reconciliation — statement import, transaction matching | F1 + F2 |

Rationale for ordering: F1 is the schema foundation. F2 introduces journal posting because vendor invoices need it; F3 reuses that engine. F4 piggybacks on F3's AR invoice model. F5 requires both inbound (F4) and outbound (F2) postings to be matchable.

## 3. F1 Scope

### 3.1 In scope

- New data model: `gl_accounts`, `org_bank_accounts`, `gl_account_balances` table; `GlAccountType` and `OrgBankAccountPurpose` enums.
- New seeders for a Ghana NPO starter chart of accounts (~30 accounts), idempotent.
- New permission slugs (`accounts.view/manage`, `bank_accounts.view/manage`, `finance.hub`) and assignment to `finance_officer` (and `auditor` read-only where noted, plus implicit `super_admin` via wildcard).
- Backend services, FormRequests, Resources, Controllers following the established Enum → Migration → Model → FormRequest → Service → Resource pattern.
- New Inertia pages: `Finance/Hub`, `Finance/Accounts/Index`, `Finance/Accounts/Edit`, `Finance/BankAccounts/Index`, `Finance/BankAccounts/Edit`.
- Sidebar entry "Finance" in [AuthenticatedLayout.vue](../../../resources/js/Layouts/AuthenticatedLayout.vue), gated by `finance.hub` permission.
- Pest Feature tests covering CRUD, RBAC, seeder idempotency, and hub aggregation.

### 3.2 Out of scope (deferred)

- Journal posting engine (lands with F2; F1 ships the read-side `gl_account_balances` table seeded to zero).
- Vendors and vendor invoices (F2).
- Customers and AR invoices (F3).
- Online card gateway / Paystack integration (F4).
- Bank statement import / reconciliation (F5).
- Multi-currency conversion logic; F1 stores `currency` per account but assumes GHS throughout the UI.
- Replacing DeptFinance.vue dashboard widget — it stays as a decorative dashboard tile; the new `/finance` Hub page is a separate dedicated module page. The widget will get real data when F2/F3 supply aggregate sources.

## 4. Data Model

### 4.1 Enums

- `App\Enums\GlAccountType` — backed string enum, cases: `Asset`, `Liability`, `Equity`, `Income`, `Expense`.
- `App\Enums\OrgBankAccountPurpose` — backed string enum, cases: `Operating`, `Payroll`, `StatutoryEscrow`, `Receipts`, `Reserve`.

### 4.2 Migrations

**`gl_accounts`**
```
id (PK)
code              VARCHAR(20)   UNIQUE NOT NULL    -- e.g. "1000", "1100-01"
name              VARCHAR(150)  NOT NULL
type              VARCHAR(20)   NOT NULL           -- GlAccountType enum value
parent_id         FK gl_accounts.id NULLABLE       -- self-referential hierarchy
is_active         BOOLEAN       DEFAULT true
currency          CHAR(3)       DEFAULT 'GHS'
description       TEXT          NULLABLE
created_at, updated_at, deleted_at (SoftDeletes)
INDEX (type), INDEX (parent_id), INDEX (is_active)
```

**`org_bank_accounts`**
```
id (PK)
gl_account_id     FK gl_accounts.id NOT NULL       -- must reference a type=asset cash/bank account
bank_name         VARCHAR(150)  NOT NULL
branch            VARCHAR(150)  NULLABLE
account_name      VARCHAR(200)  NOT NULL
account_number    VARCHAR(64)   NOT NULL
sort_code         VARCHAR(20)   NULLABLE
swift             VARCHAR(20)   NULLABLE
currency          CHAR(3)       DEFAULT 'GHS'
purpose           VARCHAR(30)   NOT NULL           -- OrgBankAccountPurpose enum
opening_balance   DECIMAL(18,2) DEFAULT 0
is_active         BOOLEAN       DEFAULT true
notes             TEXT          NULLABLE
created_at, updated_at, deleted_at (SoftDeletes)
UNIQUE (bank_name, account_number)
INDEX (purpose), INDEX (is_active)
```

**`gl_account_balances`**
```
gl_account_id     FK gl_accounts.id PRIMARY KEY
balance           DECIMAL(18,2) DEFAULT 0
last_posted_at    TIMESTAMP     NULLABLE
updated_at        TIMESTAMP
```
Note: no `id`, no soft deletes — pure cache row keyed on `gl_account_id`. Seeded to one row per GL account with balance = 0. Future journal engine in F2 updates this synchronously inside posting transactions.

### 4.3 Models

- `App\Models\GlAccount` — SoftDeletes, casts `type` to `GlAccountType`, `is_active` to bool. Relations: `parent()`, `children()`, `balance()` (hasOne GlAccountBalance), `bankAccount()` (hasOne OrgBankAccount). Scopes: `scopeActive`, `scopeOfType($type)`, `scopeRoots()` (parent_id null).
- `App\Models\OrgBankAccount` — SoftDeletes, casts `purpose` to `OrgBankAccountPurpose`, `opening_balance` to `decimal:2`, `is_active` to bool. Relations: `glAccount()` (belongsTo GlAccount). Scopes: `scopeActive`, `scopeForPurpose($purpose)`.
- `App\Models\GlAccountBalance` — non-SoftDeletes, `$primaryKey = 'gl_account_id'`, `$incrementing = false`. Cast `balance` to `decimal:2`.

## 5. Seeders

**`Database\Seeders\ChartOfAccountsSeeder`** — idempotent, uses `updateOrCreate` keyed on `code`. Seeds a starter Ghana NPO chart with at least the following accounts (rough sketch — final list refined during implementation):

- **1000-series Assets:** 1000 Cash on Hand, 1100 Bank — GCB Operating, 1110 Bank — Stanbic Payroll, 1120 Bank — Statutory Escrow, 1200 Accounts Receivable, 1300 Loans Receivable from Staff
- **2000-series Liabilities:** 2000 Accounts Payable, 2100 SSNIT Payable, 2110 PAYE Payable, 2120 Tier‑2 Payable, 2130 Tier‑3 Payable, 2140 NHIA Payable, 2200 Salaries Payable
- **3000-series Equity:** 3000 General Fund
- **4000-series Income:** 4000 Membership Dues, 4100 Course Fees, 4200 Certification Fees, 4300 Donations & Grants
- **5000-series Expenses:** 5000 Salaries Expense, 5100 Allowances Expense, 5200 Statutory Employer Contributions, 5300 Operations Expense, 5400 IT & Technology, 5500 Marketing

Total ≥ 30 accounts. Seeder is added to `DatabaseSeeder` after `RolePermissionSeeder` and is safe to re-run.

**`Database\Seeders\OrgBankAccountSeeder`** — idempotent, creates 3 starter bank accounts (GCB Operating, Stanbic Payroll, ADB Statutory Escrow) each linked to its matching `gl_accounts.code` row (1100, 1110, 1120 respectively). Skipped if accounts already exist.

**`Database\Seeders\GlAccountBalanceSeeder`** — for every GL account, ensure one balance row exists with balance = 0. Idempotent.

## 6. Permissions

Add to `Database\Seeders\RolePermissionSeeder`:

| Slug | Group | Description | Granted to |
|---|---|---|---|
| `accounts.view` | Finance | View chart of accounts | finance_officer, auditor |
| `accounts.manage` | Finance | Create/edit/delete GL accounts | finance_officer |
| `bank_accounts.view` | Finance | View organizational bank accounts | finance_officer, auditor |
| `bank_accounts.manage` | Finance | Manage organizational bank accounts | finance_officer |
| `finance.hub` | Finance | Access the Finance hub landing page | finance_officer |

`super_admin` gets these automatically via the legacy `*` wildcard in `User::ROLE_PERMISSIONS`.

Update `User::ROLE_PERMISSIONS['finance_officer']` array to include all five new slugs. Update `User::ROLE_PERMISSIONS['auditor']` to include the two `.view` slugs.

## 7. Backend

### 7.1 Services (`app/Services/Finance/`)

- `ChartOfAccountsService` — `list(array $filters)`, `tree()`, `create(array $data)`, `update(GlAccount, array)`, `archive(GlAccount)`. Tree builder returns nested structure for hierarchical UI.
- `OrgBankAccountService` — `list(array $filters)`, `create(array)`, `update(OrgBankAccount, array)`, `archive(OrgBankAccount)`. `create` validates that `gl_account_id` references a GL row with `type=asset` (defense-in-depth; FormRequest also validates).
- `FinanceHubService` — aggregates KPIs for the hub landing. Reads from existing models, no writes:
  - **Cash position:** sum of `opening_balance` across active `org_bank_accounts` (will become true balances when F2 lands).
  - **Next payroll run:** earliest `PayrollRun` with status `Draft|Pending`, plus participant count and projected total from existing `PayrollLine` aggregates.
  - **Pending approvals:** count of `PayrollRun` in `Pending` status + count of `LoanAccount` in `Pending` status.
  - **Statutory compliance:** latest `StatutoryReturn` per `StatutoryReturnKind`, status mapped to badge color.
  - **Outstanding loans:** sum of `LoanAccount.balance` where status is `Active`.
  Cached 60s per user via `Cache::remember()` (matches `DashboardService` pattern).

### 7.2 FormRequests (`app/Http/Requests/Finance/`)

- `StoreGlAccountRequest`, `UpdateGlAccountRequest` — `authorize()` returns `$this->user()->hasPermission('accounts.manage')`. Rules: `code` unique (ignore self on update), `name` required, `type` in enum values, `parent_id` exists and not self.
- `StoreOrgBankAccountRequest`, `UpdateOrgBankAccountRequest` — `authorize()` returns `$this->user()->hasPermission('bank_accounts.manage')`. Rules: `gl_account_id` exists and is `type=asset`, `bank_name` + `account_number` unique-together (ignore self on update), `purpose` in enum.

### 7.3 Resources (`app/Http/Resources/Finance/`)

- `GlAccountResource` — id, code, name, type (enum value + label), parent_id, parent (whenLoaded), children (whenLoaded), is_active, currency, balance (whenLoaded from GlAccountBalance).
- `OrgBankAccountResource` — id, gl_account (nested GlAccountResource), bank_name, branch, account_name, account_number (full value if user has `bank_accounts.manage`; otherwise masked to last 4 digits only), purpose (enum value + label), currency, opening_balance, is_active. Auditors holding only `bank_accounts.view` see the masked form.

### 7.4 Controllers (`app/Http/Controllers/Finance/`)

- `FinanceHubController@index` — renders `Inertia::render('Finance/Hub', [...])` with the FinanceHubService aggregate payload.
- `ChartOfAccountsController` — resource controller (index, store, update, destroy). `index` accepts `?type=` and `?search=` filters; returns Inertia page `Finance/Accounts/Index` with paginated GlAccountResource collection.
- `OrgBankAccountController` — resource controller. Same shape as above; renders `Finance/BankAccounts/Index`.

Controllers are thin: inject service, delegate, return `back()->with('success', $message)` or Inertia render. No business logic in controllers.

### 7.5 Routes (`routes/web.php`)

```php
Route::middleware(['auth', 'permission:finance.hub'])
    ->prefix('finance')->name('finance.')
    ->group(function () {
        Route::get('/', [FinanceHubController::class, 'index'])->name('hub');

        Route::middleware('permission:accounts.view')->group(function () {
            Route::get('accounts', [ChartOfAccountsController::class, 'index'])->name('accounts.index');
        });
        Route::middleware('permission:accounts.manage')->group(function () {
            Route::post('accounts',          [ChartOfAccountsController::class, 'store'])->name('accounts.store');
            Route::patch('accounts/{account}', [ChartOfAccountsController::class, 'update'])->name('accounts.update');
            Route::delete('accounts/{account}', [ChartOfAccountsController::class, 'destroy'])->name('accounts.destroy');
        });

        Route::middleware('permission:bank_accounts.view')->group(function () {
            Route::get('bank-accounts', [OrgBankAccountController::class, 'index'])->name('bank-accounts.index');
        });
        Route::middleware('permission:bank_accounts.manage')->group(function () {
            Route::post('bank-accounts',          [OrgBankAccountController::class, 'store'])->name('bank-accounts.store');
            Route::patch('bank-accounts/{bankAccount}', [OrgBankAccountController::class, 'update'])->name('bank-accounts.update');
            Route::delete('bank-accounts/{bankAccount}', [OrgBankAccountController::class, 'destroy'])->name('bank-accounts.destroy');
        });
    });
```

## 8. Frontend

All pages follow the existing CIHRMS design system (Sovereign Precision palette, Plus Jakarta Sans, Material Symbols, SlidePanel/EmptyState/StatusBadge/Pagination components from `@/Components/`).

### 8.1 Pages

- **`resources/js/Pages/Finance/Hub.vue`** — real-data treasury landing. Sections:
  - Header: "Finance & Treasury" with cash position headline.
  - KPI strip: Cash Position, Next Payroll, Outstanding Loans, Pending Approvals.
  - Two-column body: left = Org Bank Accounts list (clickable to bank-accounts page) + Statutory Compliance status. Right = Next Payroll Run summary + Outstanding Loans summary.
- **`resources/js/Pages/Finance/Accounts/Index.vue`** — chart of accounts as a tree (expandable nodes grouped by type), with filter chips for asset/liability/equity/income/expense, search box, and "New Account" button opening a SlidePanel form.
- **`resources/js/Pages/Finance/BankAccounts/Index.vue`** — bank accounts grid: one card per account showing bank, masked number, purpose chip, opening balance, edit/archive actions. "New Bank Account" button opens SlidePanel with bank fields + GL-account dropdown (filtered to `type=asset`).

### 8.2 Sidebar

Add nav item to [AuthenticatedLayout.vue](../../../resources/js/Layouts/AuthenticatedLayout.vue):

```js
{ label: 'Finance', icon: 'account_balance', route: 'finance.hub', perm: 'finance.hub' }
```

Positioned between existing finance-adjacent items (Payroll, Loans) — exact ordering decided during implementation.

## 9. Testing

Pest Feature tests under `tests/Feature/Finance/`:

- `ChartOfAccountsTest`
  - finance_officer can list, create, update, archive a GL account
  - employee gets 403 on all CRUD endpoints
  - auditor can list (200) but cannot create (403)
  - cannot create account whose `parent_id` references itself (after creation, attempting to set parent_id = own id)
  - `code` uniqueness enforced
- `OrgBankAccountTest`
  - finance_officer can CRUD bank accounts
  - `gl_account_id` must reference an account with `type=asset` — using a liability GL account returns 422
  - non-managers see masked account number (last 4 only)
- `FinanceHubTest`
  - finance_officer hits `/finance` and gets 200 with expected aggregate keys (`cashPosition`, `nextPayroll`, `outstandingLoans`, `pendingApprovals`, `statutoryCompliance`)
  - employee gets 403 on `/finance`
  - hub aggregates reflect real seeded data (assert against known seeded fixtures, not mocks)
- `ChartOfAccountsSeederTest`
  - Running the seeder twice produces the same row count (idempotent)
  - All seeded accounts have a corresponding `gl_account_balances` row
  - At least 30 accounts seeded, with at least one row per `GlAccountType`

Pest patterns follow the project's established test conventions: per-user JSON `permissions` column for granular grants in tests, the existing Feature base class, and route-binding via the resource controller's implicit `{account}` / `{bankAccount}` slugs.

## 10. Risks and Trade-offs

- **No journal posting in F1.** `gl_account_balances` ships with all-zero balances. This is intentional: F1 establishes the schema; F2 (vendor invoices) introduces the posting engine that mutates these balances atomically. Alternative considered: bundle posting into F1. Rejected because there are no transactions to post in F1 (the existing payroll/loan disbursements would all need retrofitting at the same time, doubling phase size).
- **Single-currency assumption in UI.** Schema supports currency per account, but all UI assumes GHS. Multi-currency conversion is a future concern.
- **DeptFinance dashboard widget stays mocked.** The existing dashboard tile is decorative; replacing its mock data needs aggregate sources that don't exist until F2/F3 land. The new `/finance` Hub page is a separate, real-data module page.
- **NPO chart of accounts is opinionated.** Seeded codes (1000/2000/etc.) and labels are NPO-flavored Ghana defaults. Operators will customize freely via the UI; design imposes no constraints on code format or hierarchy depth.
- **Coupling between `org_bank_accounts.gl_account_id` and `gl_accounts.type`.** Enforced in FormRequest + Service, not at the DB level (would require a trigger). Acceptable: all writes go through the controllers and seeders. Direct DB writes outside the app are not a supported workflow.

## 11. Acceptance criteria (F1 done means)

1. A user with `finance_officer` role logs in and sees a "Finance" entry in the sidebar.
2. Clicking it loads `/finance` with real KPI tiles backed by actual seeded data (no mock strings).
3. From the hub, the user can navigate to Chart of Accounts and Bank Accounts pages.
4. The user can create, edit, and archive GL accounts; cannot reference a non-existent parent or duplicate a `code`.
5. The user can create and edit org bank accounts; the GL-account dropdown only shows asset-type accounts.
6. An employee user gets 403 on every `/finance/*` route.
7. An auditor user can view both lists (read-only) but receives 403 on writes.
8. All Pest tests under `tests/Feature/Finance/` pass.
9. `php artisan migrate:fresh --seed` produces a populated chart of accounts with ≥30 rows, 3 bank accounts, and matching balance rows — and re-running the seeder makes no changes.
