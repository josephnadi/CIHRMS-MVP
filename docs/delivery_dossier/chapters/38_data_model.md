# Chapter 38 — Data Model & Migrations

> CIHRMS persists 116 migrations into a single relational schema serving 124 Eloquent models. `User` owns identity and authorisation; `Employee` owns the HR record and hangs off `User` via a `hasOne`; `Department` is the unit of org-chart scoping. Everything else — leave, tickets, payments, payroll, finance, identity verification, audit — fans out from those three. Soft deletes are universal on the row tables that matter, enum casts are the default for state columns, foreign keys are declared at the migration layer (not assumed by code), and `php artisan migrate:fresh` runs the lot top-to-bottom without manual intervention. This chapter is the map of that schema. Chapter 20 has the full Finance treatment; Chapter 24 has the audit chain; Chapter 25 has the identity layer.

---

## 38.1  What this chapter is, and is not

Chapter 37 unfolded the package list. This chapter unfolds the schema those packages persist into. The audience is an engineer joining the team and needing to know which table to start from, an auditor walking the FK graph, or a reviewer comparing the data design to a competing HRIS.

What follows is the map, not the gazetteer. Every column of every table is in the migration files themselves under `database/migrations/`; chasing the individual `$table->...()` calls there is the source of truth. What this chapter does is group those 116 migrations by domain, name the FK relationships that bind them, and call out the conventions (soft deletes, enum casts, sequence-driven references, restricted-on-delete chains) that hold across the whole schema.

Two upstream facts are useful to keep in mind while reading:

- **Single-tenant by omission.** There is no `tenant_id` column on any row table. An institute that buys CIHRMS gets its own database. Multi-tenancy is not a Phase 1 concern; Chapter 36 §36.9 covers the trade-off.
- **SQLite in dev, PostgreSQL in production.** The schema is written to the common subset both drivers honour. JSON columns, decimal precision, soft-delete timestamps, and foreign-key cascade rules all behave the same on both — that's why none of the migrations use `DB::statement(...)` to drop into raw SQL. The Postgres migration itself (running `php artisan migrate:fresh --database=pgsql` against a clean Postgres instance) is a Phase 1 deliverable, not a code change.

---

## 38.2  Counts at the schema layer

The figures below were taken from the working tree at the time of writing.

| Artifact | Count | Source |
|---|---|---|
| Migration files | **116** | `database/migrations/*.php` |
| Eloquent models | **124** | `app/Models/*.php` |
| Models using `SoftDeletes` trait | **61** | grep `app/Models/*.php` for `use SoftDeletes` |
| Enums (PHP backed) | **88** | `app/Enums/*.php` |
| Models declaring at least one cast | **99** | grep `app/Models/*.php` for `casts()` |

The migration count includes the three Laravel-framework defaults (`users`, `cache`, `jobs`), 16 Sanctum/personal-access-token/messaging-consent additions to existing tables, and 97 domain tables and pivots. The model count exceeds the migration count because several migrations create multiple tables in one file (`create_assets_tables.php` creates four; `create_whistleblower_supporting_tables.php` creates three) and because some tables have no model (`role_permissions`, `conversation_user`, `password_reset_tokens`).

About the soft-delete number: 61 of 124 models use the trait. The omitted 63 fall into three buckets — immutable ledger rows (`JournalLine`, `LoanRepayment`, `Payment`, `IntegrationEvent`), pivot tables that follow their parent's lifecycle (`AssetAssignment`, `ConversationUser`), and append-only event/audit rows where retention is the audit chain itself (`AuditLog`, `AnalyticsEvent`, `DocumentEvent`, `SsoLoginAttempt`, `IncidentReportMessage`). Soft deletes on those would be misleading — the chain tolerates row-level deletion by leaving a gap that `audit:verify-chain` flags; it does not tolerate "soft" deletes that look intact to the chain walker but are scoped out at the query layer.

---

## 38.3  The core triangle: User, Employee, Department

These three are the spine. Everything user-facing references at least one of them.

```
                +------------------+
                |   departments    |
                |  id (pk)         |
                |  name (unique)   |
                |  code (unique)   |
                |  head_user_id ───────────────┐
                |  description     |           │
                |  deleted_at      |           │
                +---------┬--------+           │
                          │ 1                  │
                          │                    │
                          │ N                  │
                +---------┴--------+    1   1  │
                |    employees     ├───────────┤
                |  id (pk)         |           │
                |  department_id ──┘           │
                |  user_id ────────────────────┼─────────┐
                |  employee_no (unique)        │         │ 1
                |  position                    │         │
                |  hire_date                   │         │
                |  status (enum)               │         │
                |  manager_id (self-FK) ───┐   │         │
                |  ...18 profile fields    │   │         │
                |  deleted_at              │   │         │
                +---------┬----------------┘   │         │
                          │ N                  │         │
                          │ N                  │         │
                          v                    │         v
                  (leave_requests,             │     +-------+
                   tickets, complaints,        │     | users |
                   payments, employee_         │     |  id   |
                   documents, payroll_         │     |  ...  |
                   lines, allowances,          │     +-------+
                   deductions, dependants,     │
                   benefit_enrolments,         │
                   identity_verifications,     │
                   position_assignments,       │
                   employee_skills,            │
                   loan_accounts,              │
                   attendance_records,         │
                   asset_assignments,          │
                   leave_balances)             │
                                               │
                                          headed by
```

**`users`** (created by `0001_01_01_000000_create_users_table.php`, augmented by `2026_05_12_083600_add_permissions_to_users_table.php`, `..._110923_add_staff_id_to_users_table.php`, `2026_05_18_000001_add_messaging_consent_columns_to_users.php`, `2026_05_25_000008_add_two_factor_columns_to_users.php`, `2026_06_01_000001_add_password_must_change_to_users.php`, `2026_06_05_000001_add_locale_to_users.php`). This is the identity row, not the HR record. It carries `staff_id` (unique, the actual login identifier — see Chapter 36 §36.6), `role` (legacy enum cast to `App\Enums\UserRole`), a JSON `permissions` column for per-user overrides, TOTP secrets, WhatsApp consent timestamps, locale preference, and the `password_must_change` flag. `users.deleted_at` is the SoftDeletes column added in `2026_05_13_113905_add_soft_deletes_and_missing_columns_to_all_tables.php`.

**`employees`** (created by `2026_05_12_081018_create_employees_table.php`, expanded by `2026_05_15_000020_expand_employees_with_profile_fields.php` and a handful of follow-ups for establishment, pension trustee, and bank sort code). This is the HR record. `employees.user_id` is nullable because not every employee row has a login account (terminated employees retain the row but lose the user; some legacy imports created employee rows before the user account was provisioned). The reverse — `users.id` without a matching `employees.employee_id` — is also tolerated for admin-only accounts. The model exposes `Employee::scopeVisibleTo(?User)` which encodes the canonical access rule: super_admin/HR sees all, dept heads and managers see their dept plus direct reports plus self, everyone else sees only their own row. That scope is the single visibility predicate used by the employee listing, the payroll picker, the leave approval queue, the chat directory, and the assets-assignment dropdown. Changing it changes them all.

**`departments`** (created by `2026_05_12_081017_create_departments_table.php`, augmented with `head_user_id` in the roles-and-permissions migration). Two unique columns: `name` and `code`. `head_user_id` is the FK that backs the "department head" role: a user whose `id` appears in `departments.head_user_id` gets `User::managedDepartmentIds()` to include that department, which in turn drives `Employee::scopeVisibleTo()`, `LeaveService::approvableBy()`, the policy gates on `EmployeePolicy`, and the inertia-shared `auth.managedDepartmentIds` prop. Deleting a department `nullOnDelete()`s every employee in it — by design — but the department's history (audit logs, payroll runs scoped to it) is preserved.

Three observations about the triangle:

1. **`employees.user_id` is nullable, `employees.department_id` is nullable.** Both are `->nullOnDelete()`. A deleted user does not cascade-delete the employee; the employee remains as a historical HR record with `user_id = NULL`. The audit trail and the payment history are preserved.
2. **`employees.manager_id` is a self-FK.** The org chart is encoded by that single column. `Employee::reports()` walks down; `Employee::manager()` walks up. There is no separate `org_chart` table. The trade-off is that org-chart traversals are recursive queries (`WITH RECURSIVE` on Postgres, repeated `IN`-clauses on SQLite) — fine at the institute scale CIHRMS targets, would need a closure table at ten-thousand-row scale.
3. **`Employee` has 17 distinct `hasMany` / `belongsTo` relations defined on the model** (count from `app/Models/Employee.php`). That makes it the most cross-linked entity in the schema. It is also the one most commonly eager-loaded — `with('user', 'department', 'currentPosition', 'currentGrade')` is the default for the employee index, and every Service that writes a row touching an employee accepts the `Employee` instance, never the integer id, to keep eager-loading consistent up the call stack.

---

## 38.4  Soft deletes — universal on core models

The convention is: row tables get `SoftDeletes`; ledger and event tables do not.

The original `2026_05_13_113905_add_soft_deletes_and_missing_columns_to_all_tables.php` migration retroactively added `deleted_at` to the nine tables that pre-dated the convention: `users`, `departments`, `employees`, `leave_requests`, `tickets`, `complaints`, `job_postings`, `applicants`, `payments`. Everything created from that date onward declares `$table->softDeletes()` in the same migration that creates it — the audit table is an exception (append-only by design) and a few pivots are exceptions (deletion follows the parent).

Verified by grepping `app/Models/*.php` for `use SoftDeletes`:

| Has SoftDeletes | Doesn't |
|---|---|
| `User`, `Employee`, `Department` | `AuditLog`, `AnalyticsEvent`, `DocumentEvent` |
| `LeaveRequest`, `LeaveBalance` | `Payment` (legacy — `payments.deleted_at` exists, model doesn't use the trait — see §38.10) |
| `Ticket`, `Complaint`, `IncidentReport` | `JournalLine`, `LoanRepayment` |
| `JobPosting`, `Applicant` | `ConversationUser`, `AssetAssignment` |
| `PayrollRun`, `PayrollLine` (via parent cascade) | `IncidentReportMessage`, `SmsMessage` |
| `Asset`, `AssetMaintenance` | `IntegrationEvent`, `WebhookDelivery` |
| `JournalEntry`, `VendorInvoice`, `ArInvoice` | `SsoLoginAttempt`, `PaystackWebhookEvent` |
| `LoanAccount`, `LoanProduct`, `OffboardingCase` | `ChatMessage` (uses `deleted_for_everyone_at` instead) |
| `Document`, `DocumentVersion`, `BenefitPlan` | … |
| `IdentityVerification`, `BankStatement`, `OrgBankAccount` | … |
| `Policy`, `Course`, `Certification`, `Conversation` | … |

`SoftDeletes` is enabled on **61** of the 124 models. The 63 that omit it are append-only by design — covered above in §38.2.

A note on the `Payment` model specifically: the `payments.deleted_at` column exists (added by the May 13 retro migration) but `app/Models/Payment.php` does not include the `SoftDeletes` trait. This is a deliberate inconsistency carried from the V1 cleanup: payments are immutable receipts once issued, and the team chose to keep the column for forward-compatibility (so a future "void payment with audit trail" feature wouldn't need another schema migration) while not exposing soft-delete semantics through the ORM today. A `Payment::delete()` therefore hard-deletes the row, which is the intended behaviour — `payroll.disburse` writes Payments via `PaymentService`, and the only path that removes them is `PayrollRun::reverse`, which deletes the linked rows and audit-logs the reversal.

---

## 38.5  Enum casts — the standard

Every column that represents a state, a kind, a tier, or a channel is a string in the database and an enum in PHP. There are 88 enum classes under `app/Enums/`. They cast through Laravel's enum-cast machinery on the relevant model:

```php
// app/Models/Employee.php, casts()
'status' => EmployeeStatus::class,

// app/Models/User.php, casts()
'role' => UserRole::class,

// app/Models/LeaveRequest.php, casts()
'type'   => LeaveType::class,
'status' => LeaveStatus::class,
```

99 of the 124 models declare at least one cast. The pattern is consistent: **never store enum strings without a cast**, never let "status" be a free-text VARCHAR at the application boundary. The string-in-DB / enum-in-PHP split was chosen over native Postgres enums because (a) altering a native enum on Postgres requires a `ALTER TYPE ... ADD VALUE` migration, which can't run inside a transaction prior to PG 12 and is generally painful to roll back, and (b) the same migration must work against SQLite where native enums don't exist. PHP-side enums give us the type safety; DB-side `string` columns give us the migration ergonomics.

Three enums show up repeatedly:

- **`App\Enums\UserRole`** — the legacy 9-role list (`super_admin`, `ceo`, `hr_admin`, `manager`, `dept_head`, `employee`, `finance_officer`, `it_support`, `marketing`, `auditor`). Still the source of `User::$role`; still the input to the legacy permission lookup in `User::ROLE_PERMISSIONS`. Chapter 39 has the full RBAC story.
- **`App\Enums\EmployeeStatus`** — `active`, `on_leave`, `suspended`, `terminated`, `retired`. The scoping query `Employee::active()` filters on this.
- **State-machine enums** for payroll (`PayrollRunStatus`), journal entries (`JournalEntryStatus`), AP/AR invoices, loan accounts, and offboarding cases. Each is consumed by exactly one Service to gate transitions. `PayrollRunStatus::Draft` → `Calculated` → `Approved` → `Paid` → `Reversed` is enforced by `PayrollService` and only by `PayrollService`; nothing else mutates `payroll_runs.status`.

---

## 38.6  Migration discipline

`php artisan migrate:fresh --seed` runs all 116 migrations top-to-bottom against an empty database and seeds the demo data. The CI workflow (`.github/workflows/...`) runs this on every push. It must pass; PRs that break it are blocked.

A few points of discipline that hold across all 116 files:

**Filename timestamps are ordered.** Migrations after the framework defaults (`0001_01_01_*`) use date-stamped names (`2026_05_12_*` through `2026_06_11_*`). Laravel runs them in lexical order; the timestamp ordering means a column added to `employees` after the table was created (e.g. `bank_sort_code` in `2026_06_06_000001_add_bank_sort_code_to_employees.php`) is always applied after the table itself (`2026_05_12_081018_create_employees_table.php`). The few same-day collisions are disambiguated by the time component (`081017`, `081018`, `081019`).

**Foreign keys are declared in the migration.** Every FK in the schema is created via `->constrained()` or `->foreign()` with an explicit cascade rule:

- `->cascadeOnDelete()` for child rows that have no meaning without their parent (e.g. `payroll_lines.payroll_run_id`, `journal_lines.journal_entry_id`, `ar_invoice_lines.ar_invoice_id`)
- `->nullOnDelete()` for soft links where the row should survive (`employees.user_id`, `employees.department_id`, `tickets.assigned_to`, `audit_logs.user_id`)
- `->restrictOnDelete()` for ledger references the database must protect (`journal_lines.gl_account_id`, `vendor_invoices.vendor_id`, `bank_statements.org_bank_account_id`, `journal_entries.created_by`)

The choice of cascade rule is part of the data contract. `gl_accounts` deletion is restricted because deleting a GL account that has ever been posted-to would orphan the journal lines and silently break the trial balance; the application enforces "archive, don't delete" via the `is_active` flag and the migration enforces it via `restrictOnDelete()`.

**Indexes are declared where queries hit them.** Every FK gets the implicit index Laravel creates with `->constrained()`. Beyond that, indexes are added explicitly only where a query plan benefits — composite indexes on `(employee_id, status)` for identity verifications, `(payroll_run_id, status)` for payroll lines, `(conversation_id, created_at)` for chat messages, `(period_year, period_month, department_id)` as the unique constraint on payroll runs. The schema is not over-indexed; the institute-scale data volumes don't need it.

**No raw SQL in migrations.** Every migration uses `Schema::create`, `Schema::table`, or `DB::table(...)->insert(...)` for seed data. The one exception is `2026_06_11_000001_create_finance_sequences.php`, which back-fills the sequence table by reading existing references out of the AP/AR/JE tables — a data migration that does `DB::table($s['table'])->where(...)->pluck(...)` to compute the starting value for each `(key, year)` pair. Even that file uses the query builder, not literal SQL.

**`down()` is honoured.** Every migration ships a `down()` method that reverses what `up()` did. `php artisan migrate:rollback --step=N` works for any N. In practice, production deploys never roll back — we ship forward fixes — but the discipline of writing the `down()` flushes out cascade-order mistakes that would otherwise show up only on `migrate:fresh` against a non-empty database.

---

## 38.7  Domain groups — the 116 migrations by topic

The migrations sort cleanly into thirteen domain groups. The groupings below are mine, not the framework's; the migration filenames don't enforce any such structure.

| Group | Count | Representative migrations |
|---|---|---|
| Framework / Laravel defaults | 3 | `0001_01_01_*` (users, cache, jobs) |
| Identity, auth, sessions | 8 | `add_staff_id`, `add_permissions`, `personal_access_tokens`, `add_two_factor_columns`, `add_password_must_change`, `add_locale_to_users`, `add_messaging_consent`, `create_sso_tables` |
| RBAC | 1 | `create_roles_and_permissions_tables` |
| Org structure & employees | 5 | `create_departments`, `create_employees`, `create_employee_documents`, `expand_employees_with_profile_fields`, `add_bank_sort_code_to_employees` |
| Leave, attendance, shifts | 7 | `create_leave_requests`, `create_leave_balances`, `create_public_holidays`, `create_biometric_devices`, `create_attendance_records`, `create_shifts_and_assignments`, `create_attendance_corrections` |
| Tickets, complaints, incidents | 4 | `create_tickets`, `create_complaints`, `add_assigned_to_to_complaints`, `create_incident_reports_tables` |
| Recruitment & onboarding | 2 | `create_job_postings`, `create_applicants` |
| Performance & learning | 8 | `create_review_cycles`, `create_goals`, `create_goal_checkins`, `create_reviews`, `create_performance_contracts`, `create_calibration_sessions`, `create_performance_improvement_plans`, `create_courses` + `enrolments` + `certifications` |
| Payroll & establishment | 6 | `create_payroll_items`, `create_grades_steps_and_positions`, `create_tax_brackets_and_statutory_rates`, `create_pension_trustees`, `create_payroll_runs_lines_and_returns`, `create_allowances_and_deductions` |
| Finance (Chapter 20) | 19 | `create_gl_accounts` + `org_bank_accounts` + `gl_account_balances` + `vendors` + `journal_entries` + `journal_lines` + `vendor_invoices` + `vendor_invoice_lines` + `ap_payments` + `ap_payment_invoice_allocations` + `customers` + `ar_invoices` + `ar_invoice_lines` + `ar_receipts` + `ar_receipt_invoice_allocations` + `payment_intents` + `paystack_webhook_events` + `bank_statements` + `bank_statement_lines` + `bank_transaction_matches` + `finance_sequences` |
| Payments & disbursements | 4 | `create_payments`, `create_disbursements`, `add_external_ref_to_ap_payments`, `add_refund_columns_to_payment_intents` |
| Loans, offboarding, assets, benefits | 13 | `create_loan_products`, `create_loan_accounts`, `create_loan_repayments`, `create_offboarding_cases`, `create_clearance_items`, `create_final_settlements`, `create_assets_tables` (4 tables), `create_benefits_tables`, `create_policies_tables` |
| Audit, identity verification, integrations | 8 | `create_audit_logs`, `add_tamper_evident_audit_columns`, `create_identity_verifications`, `create_integrations`, `create_integration_tokens`, `create_integration_events`, `add_integration_tracking_columns`, `create_webhook_subscriptions` |
| Documents & assets (stamps/letterhead) | 9 | `create_documents`, `create_document_versions`, `create_document_routes`, `create_document_annotations`, `create_document_events`, `create_document_shares`, `create_stamp_assets`, `create_letterhead_templates`, `add_letterhead_id_to_documents`, `create_watermark_templates`, `add_watermark_to_documents` |
| Messaging, notifications, announcements | 6 | `create_notifications`, `create_announcements`, `create_chat_conversations_and_messages`, `create_document_shares`, `create_sms_ussd_tables`, `change_identity_providers_config_to_text` |
| Whistleblower, governance, DPA | 6 | `create_whistleblower_reports` + `_supporting_tables`, `create_policies_tables`, `create_data_subject_requests`, `add_public_submission_to_data_subject_requests`, `create_pending_bank_changes` |
| API v1, webhooks, skills, sequences | 5 | `create_api_v1_supporting_tables`, `create_webhook_subscriptions`, `create_skill_catalog`, `create_finance_sequences`, others |

Group totals don't exactly sum to 116 because some migrations modify a table that already exists (column additions are counted with their parent group) and a few tables are listed under more than one group (e.g. `document_shares` is messaging-adjacent but lives in the documents migration sequence).

---

## 38.8  Finance schema — overview only

Chapter 20 has the full treatment of the finance build-out, including the four "F" waves (F1 Chart of Accounts, F2 Accounts Payable, F3 Accounts Receivable, F4 Paystack Gateway, F5 Bank Reconciliation). For the schema map: the 19 finance migrations under §38.7 produce these tables, anchored on the double-entry pair `journal_entries` + `journal_lines`:

```
                  gl_accounts (chart of accounts, self-FK hierarchy)
                       │
                       │ restrictOnDelete
                       │
                       ▼
                 journal_lines  ◄────cascade────  journal_entries
                  (debit, credit,                  (header, status,
                   line_no unique                   reversal_of_id self-FK,
                   per entry)                       source_type/source_id
                                                    polymorphic origin)
                       ▲
                       │ posted by
                       │
                  JournalPostingService  (only writer to journal_*
                                          and gl_account_balances)
                       ▲
                       │
       ┌───────────────┼───────────────────────┬──────────────┐
       │               │                       │              │
 vendor_invoices  ap_payments              ar_invoices    bank_statements
       │               │                       │              │
 vendor_invoice_  ap_payment_invoice_      ar_invoice_     bank_statement_
 lines           allocations              lines (+        lines (+
                                          ar_receipts +   bank_transaction_
                                          ar_receipt_     matches)
                                          invoice_
                                          allocations)
       │               │                       │
 vendors          org_bank_accounts        customers
                  finance_sequences
                  (key, current_value
                   per sequence,
                   per year)
                  paystack_webhook_events
                  payment_intents
```

Five points worth pulling out at the schema level:

1. **`journal_entries.reversal_of_id` is a self-FK.** A reversal is a new posted JE whose `reversal_of_id` points to the original; the original's status flips to `reversed`. The original is not deleted. The `audit:verify-chain` walker treats both as live rows.
2. **`journal_lines` has no `timestamps()`.** Lines are immutable once posted; an `updated_at` would be a lie. The header has `posted_at` and `reversed_at`; lines inherit their timestamp from the entry.
3. **`journal_entries.source_type` + `source_id` is a polymorphic origin reference**, but not via Laravel's `morphTo` — there's no `*_type` column following the framework convention because the source table set is closed (vendor invoices, AP payments, AR invoices, AR receipts, payroll runs, manual). The Service that posts the entry sets the source explicitly. No model on the receiving end needs `morphMany`.
4. **`finance_sequences.key` is a string primary key**, e.g. `'ap_invoice:2026'`. `SequenceService::next($key)` is the only writer; references like `API-2026-000123` are issued by `current_value + 1`-then-`UPDATE` inside a row-locked transaction. PR #21 closed a race-condition gap where parallel writers were generating the same reference via `count() + 1`. The project memory file `project_finance_sequences.md` documents the convention.
5. **`paystack_webhook_events` is a dedupe ledger.** Inbound webhooks from Paystack carry an `event.id` that we record in this table; replays are dropped at the controller boundary before they hit `ProcessPaystackWebhook`. The migration adds a unique constraint on the Paystack event id.

---

## 38.9  Audit chain table

Chapter 24 has the full chain semantics; Chapter 40 has the engineering treatment. For the schema map:

```sql
audit_logs (
  id              BIGINT PRIMARY KEY,
  chain_position  BIGINT NULL,         -- monotonic per-table sequence
  previous_hash   CHAR(64) NULL,       -- sha256(previous row's row_hash)
  row_hash        CHAR(64) NULL,       -- sha256(this row's canonical JSON)
  user_id         BIGINT NULL,         -- FK users(id) nullOnDelete
  action          VARCHAR,             -- e.g. "leave.approve", "payroll.disburse"
  route_name      VARCHAR NULL,
  method          VARCHAR(10),
  path            VARCHAR,
  ip_address      VARCHAR(45) NULL,
  user_agent      TEXT NULL,
  payload         JSON NULL,           -- sanitised request payload
  created_at      TIMESTAMP,
  updated_at      TIMESTAMP
)
INDEX (chain_position)
```

The base table is `2026_05_12_083700_create_audit_logs_table.php`. The three chain columns (`chain_position`, `previous_hash`, `row_hash`) are added in `2026_05_25_000007_add_tamper_evident_audit_columns.php` — a separate migration so the audit table existed for two weeks of dev usage before the chain was layered on top of it, and the back-fill could be controlled.

`App\Models\AuditLog::canonicalJson()` is the input to `hash('sha256', ...)`. The exact serialisation matters: any field added to the canonical representation breaks every existing row's hash, so the field set is frozen and additions go in `payload` (which is part of the canonical input, but only at the value level).

The chain is built asynchronously. `AuditTrail` middleware dispatches `WriteAuditLog` onto the `audit` queue. The job acquires a row lock on the most recent `audit_logs` row, computes `chain_position = previous + 1`, computes `row_hash = sha256(canonical_json)` with `previous_hash` set to the previous row's `row_hash`, and inserts. Concurrent jobs serialise on that lock. `php artisan audit:verify-chain` walks the table in `chain_position` order and recomputes; the first mismatch points at the tampered (or missing) row.

---

## 38.10  Identity verification

Chapter 25 has the full Ghana Card / NIA story. Schema-wise:

```sql
identity_verifications (
  id                BIGINT PRIMARY KEY,
  employee_id       BIGINT NOT NULL   -- FK employees(id) cascadeOnDelete
  provider          VARCHAR(32),       -- nia_official | third_party_kyc | manual_upload
  ghana_card_number VARCHAR,           -- encrypted via model cast
  ghana_card_hash   CHAR(64) INDEXED,  -- sha256 fingerprint for dup-detection
  status            VARCHAR(16) DEFAULT 'pending',
  verified_at       TIMESTAMP NULL,
  verified_by       BIGINT NULL,       -- FK users(id) nullOnDelete
  expires_at        TIMESTAMP NULL,
  evidence_path     VARCHAR NULL,      -- storage path to scan/photo
  raw_response      JSON NULL,         -- raw NIA / third-party response
  failure_reason    TEXT NULL,
  created_at, updated_at, deleted_at   -- SoftDeletes
)
INDEX (employee_id, status)
INDEX (status, expires_at)
```

Created by `2026_05_25_000006_create_identity_verifications.php`. Three columns warrant individual notes:

- **`ghana_card_number`** is plaintext in the migration but encrypted at the model layer via `'ghana_card_number' => 'encrypted'` in `IdentityVerification::casts()`. This is application-level encryption using Laravel's `APP_KEY`-derived cipher. Field-level encryption with a per-institute KMS-managed key is a Phase 4 DPA work item; the current scheme protects against a stolen database dump but not against an attacker who has both the DB and the `APP_KEY`.
- **`ghana_card_hash`** is the deterministic SHA-256 of the canonical Ghana Card number (no encryption involved). It exists so duplicate-detection ("is this card already linked to another employee?") doesn't have to decrypt every row and string-compare. The hash is indexed; the encrypted ciphertext is not.
- **`expires_at`** drives the re-verification reminder. The `(status, expires_at)` index supports the daily job that emails employees and HR when a verified identity is approaching expiry.

The `IdentityVerification::scopeUsable()` scope (used by `Employee::latestVerifiedIdentity()`) filters to `status = 'verified'` AND (`expires_at IS NULL OR expires_at > NOW()`). Anything that demands a usable identity (loan disbursement, payroll first-run, off-boarding settlement) gates on that scope.

---

## 38.11  RBAC schema in passing

Chapter 39 has the full RBAC story (three-tier evaluation: enum role, DB-assigned roles, per-user JSON overrides). For the schema:

```sql
roles (id, slug UNIQUE, name, description, is_system, timestamps)

permissions (id, slug UNIQUE, name, group INDEXED, description, timestamps)

role_permissions (role_id FK, permission_id FK, PRIMARY KEY(role_id, permission_id))

user_roles (
  id PRIMARY KEY,               -- surrogate, NOT composite — see below
  user_id FK cascadeOnDelete,
  role_id FK cascadeOnDelete,
  department_id FK NULL nullOnDelete,  -- NULL = global-scope assignment
  timestamps
)
INDEX (user_id, role_id)
INDEX (user_id, department_id)
```

Created in `2026_05_15_000010_create_roles_and_permissions_tables.php`. Two design choices documented in the migration itself:

1. **`user_roles.id` is a surrogate key**, not a composite primary key on `(user_id, role_id, department_id)`. The reason is in the migration's own comment: "composite PK can't include a nullable column on Postgres/MySQL, and we want department_id to be nullable for global-scope roles (e.g. super_admin)". A global `super_admin` assignment has `department_id IS NULL`; a dept-scoped `dept_head` assignment has the FK set.
2. **There is no `user_permissions` table.** Per-user permission overrides live in the JSON `permissions` column on `users` (added by `2026_05_12_083600_add_permissions_to_users_table.php`). `User::allPermissions()` merges three sources: the legacy `ROLE_PERMISSIONS` array (hardcoded mirror of the seeder), the DB-resolved permissions from `user_roles → role_permissions → permissions`, and the per-user JSON. The merge is cached per-user for 60 seconds.

`departments.head_user_id` is the fourth piece. A user with their `id` in that column gets the department auto-added to their `managedDepartmentIds` collection — no `user_roles` row needed. That's how the "department head" privilege survives even when the DB role assignments are wiped.

---

## 38.12  A few representative non-finance domain tables

The following are picked as worked examples — not because they're more important than others, but because they show the conventions in action.

**`leave_requests`** (created `2026_05_12_081019`, `approved_by` added in the May 13 retro). Five columns plus FKs plus soft delete. `type` and `status` are both string columns with enum casts on `LeaveRequest`. No `processed_at`, no `rejected_at` — the audit log carries the transition history. `leave_balances` is a separate aggregate keyed on `(employee_id, type, year)` and maintained by `LeaveService::recompute()` after every approval.

**`tickets` and `complaints`** (created `2026_05_12_081020/081021`). Same shape: `employee_id` nullable FK, title, description, priority enum, status enum, optional `assigned_to` (added retro for tickets in `081020`, retro for complaints in `2026_05_17_055805_add_assigned_to_to_complaints.php`). Both soft-deleted.

**`payments`** (created `2026_05_12_081023`). The legacy payment ledger that pre-dates the payroll-run aggregate. `processed_by` added retro on May 13. As covered in §38.4, `payments.deleted_at` exists but the model doesn't expose the SoftDeletes trait.

**`payroll_runs` / `payroll_lines` / `statutory_returns`** (created in `2026_05_25_000004_create_payroll_runs_lines_and_returns.php`). The replacement for ad-hoc payments. Unique constraint on `(period_year, period_month, department_id)` prevents two parallel runs of the same period; `department_id` null means "whole org". `payroll_lines.breakdown` is a JSON column carrying the full calculator snapshot — PAYE bracket walk, SSNIT tier split, NHIA portion, tier-2 employer, tier-3 employee, voluntary deductions. Replaying a calculation against an archived `breakdown` is how `audit:verify-payroll` works (planned for a Phase 1 audit-tool extension).

**`assets` / `asset_assignments` / `asset_maintenance` / `asset_depreciation_snapshots`** (created in `2026_05_28_000001_create_assets_tables.php`). Four tables in one migration. `assets.current_assignment_id` is a forward FK that points into `asset_assignments` — the schema declares the FK after both tables exist, in a `Schema::table('assets', ...)` block in the same migration. Cascade rule on the deferred FK is `nullOnDelete()` so deleting an assignment leaves the asset addressable as "in stock". `asset_depreciation_snapshots` has a `UNIQUE(asset_id, as_of_date)` so the depreciation cron can't double-write a row for the same valuation date.

**`conversations` / `conversation_user` / `chat_messages`** (created in `2026_06_08_000001_create_chat_conversations_and_messages.php`). 1-on-1 only for now, but the schema is group-ready — `conversations.is_group` is a boolean, the `conversation_user` pivot can hold N>2 members, and `conversations.title` is nullable so 1:1 threads render names client-side. `chat_messages.deleted_for_everyone_at` is the "sender retracted" timestamp; it is not a SoftDeletes column — the model exposes its own "is retracted" semantics.

**`api_token_metadata`** (created in `2026_06_02_000001_create_api_v1_supporting_tables.php`). A 1:1 sidecar to Sanctum's `personal_access_tokens`. Sanctum holds the token hash and the `abilities` JSON; this table adds operational metadata that doesn't fit on a Sanctum token — purpose, rate limit per minute, IP CIDR allowlist, issuing/revoking user. The earlier scaffold of a redundant `webhook_subscriptions` schema in this migration was removed (note in the migration's docblock) to keep `migrate:fresh` idempotent.

---

## 38.13  Forward roadmap — what the schema doesn't yet do

Three items on the schema roadmap that intentionally are not in the current 116 migrations.

**1. Postgres migration.** The current dev database is SQLite (`database/database.sqlite`). Production targets Postgres 16. The migrations are written to the common subset, so the move is a `DB_CONNECTION=pgsql` config flip and a `php artisan migrate:fresh` against a clean Postgres instance — no schema rewrites. The two things that will need post-migration attention are (a) the `JSON` columns, which become `JSONB` on Postgres and gain GIN-index capabilities CIHRMS doesn't currently use, and (b) the `WITH RECURSIVE` queries on `employees.manager_id`, which Postgres optimises differently from SQLite's emulation. Both are non-breaking. Phase 1.

**2. Field-level encryption.** Today, the `ghana_card_number` column is encrypted via Laravel's `'encrypted'` cast (AES-256-CBC, key derived from `APP_KEY`). The Phase 4 DPA work is to bring per-institute KMS-managed encryption keys, with rotation, into the picture — most likely via the `App\Services\Crypto\FieldEncrypter` pattern that abstracts the cipher behind a configurable provider. The columns that need it are `employees.bank_account`, `employees.national_id`, `employees.ssnit_number`, `employees.tin_number`, `identity_verifications.ghana_card_number` (already partially covered), and `users.two_factor_secret`. None of these are encrypted at the column level today; `two_factor_secret` is encrypted via the model cast in `User`. The migration cost is zero — the columns stay as VARCHAR — but the cipher layer needs work and the key-management story needs documenting.

**3. Multi-tenancy.** Not on the schema roadmap. Stated explicitly in Chapter 36 §36.10: an institute that buys CIHRMS gets its own database. Co-hosting two institutes on one schema would require a `tenant_id` on every row table, the visibility scopes would need to apply it everywhere, the cache keys would need to include it, and the queues would need to either be tenant-scoped or carry the tenant in the payload. None of that is in scope for the foreseeable phase plan; the operational simplicity of one-institute-per-deploy is more valuable to the target buyers than the cost savings of co-hosting.

A fourth, smaller item that did make it in: **`finance_sequences` covers AP/AR/JE references but not employee numbers**. `employees.employee_no` is still generated by `EmployeeIdentifierService` with its own row-locked counter (different table). The unification onto `finance_sequences` (or a renamed `entity_sequences`) is a tidy-up task with no schema-breaking implications.

---

## 38.14  Reading order for the next chapters

Anyone reading 38 cover-to-cover should follow up with:

- **Ch 39 — RBAC Internals** to see how `roles`, `permissions`, `user_roles`, and the JSON `users.permissions` column actually resolve into a check at the controller level.
- **Ch 40 — Audit Chain** for the chain-write race condition, the `audit:verify-chain` walker, and the recovery procedure when the chain breaks.
- **Ch 41 — Queues & Jobs** for what writes into `audit_logs`, `analytics_events`, `notifications`, and `integration_events`, and how the five named queues isolate them.
- **Ch 43 — Testing Strategy** for the per-user `permissions` JSON column trick and the Feature-test base classes that exercise the schema end-to-end.
- **Ch 44 — Operational Runbook** for `migrate:fresh` rollouts, the `php artisan down --secret=...` pattern that gates production migrations, and the SQLite-to-Postgres switchover checklist.

The single most important thing to remember while reading those is the convention this chapter has tried to make load-bearing: **every domain operation goes through a Service, every Service writes inside a `DB::transaction()`, every mutation produces a `WriteAuditLog` dispatch, and every state column on a table is an enum cast on its model.** The schema is plain; the discipline is the product.
