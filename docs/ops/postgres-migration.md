# Postgres production migration runbook

CIHRMS supports SQLite (dev), MySQL/MariaDB (community), and PostgreSQL (production). This document is the **operator runbook** for migrating an existing CIHRMS deployment from SQLite or MySQL onto PostgreSQL 14+ for production.

**Why PostgreSQL for production:**
- SQLite's per-file write lock prevents concurrent writers; payroll batch jobs, queue workers, and audit-trail writes need real row-level locking.
- The IPPD / GIFMIS / Auditor-General export jobs (Phase 2) will use `COPY`-style streaming, which only Postgres provides without buffering whole datasets in PHP memory.
- The `audit_logs` hash chain uses `lockForUpdate` to serialise inserts. SQLite blocks the entire database during a write transaction; Postgres only blocks the affected row.
- DPA 2012 Act 843 retention requirements are easier to enforce with Postgres' `pg_partman` table partitioning when the audit table grows past 50M rows.

---

## Prerequisites

- PostgreSQL **14 or later** (16 recommended) installed and reachable
- A dedicated role + database: `CREATE ROLE cihrms LOGIN PASSWORD '...'; CREATE DATABASE cihrms OWNER cihrms ENCODING 'UTF8';`
- PHP extensions: `pdo_pgsql`, `pgsql`
- A maintenance window of ~30 minutes (most of it data copy)
- A current backup of the source database (mandatory)

---

## Step 1 — Add Postgres credentials to `.env`

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=cihrms
DB_USERNAME=cihrms
DB_PASSWORD=...redacted...
```

`config/database.php` already ships with the standard `pgsql` connection block — no code changes needed.

---

## Step 2 — Verify schema migrates cleanly on the target

On a **scratch** database (not production):

```bash
php artisan migrate:fresh --force
php artisan db:seed --class=RolePermissionSeeder --force
```

This should complete without errors. If it doesn't, capture the failing migration name and stop — file an issue before continuing.

The CIHRMS migrations are vetted to be portable:
- JSON columns use Laravel's `$table->json()`, which Postgres stores as native `json` type.
- All date functions in user code go through [`App\Support\DbExpr`](../../app/Support/DbExpr.php), which emits `to_char()` / `EXTRACT()` on Postgres rather than SQLite's `strftime()`.
- Boolean defaults use Laravel's `->default(true)` / `->default(false)` which translate correctly.

---

## Step 3 — Stop the workers + put the app in maintenance mode

```bash
php artisan down --secret=letmein-during-pg-migration
sudo systemctl stop cihrms-queue cihrms-scheduler
```

Stop the queue first so no new audit-log writes hit the source DB while you're dumping it.

---

## Step 4 — Dump the source database

### From SQLite (development upgrade)

```bash
php artisan db:dump-for-postgres > /tmp/cihrms-dump.sql
```

That custom artisan command is in [`app/Console/Commands/DumpForPostgres.php`](../../app/Console/Commands/DumpForPostgres.php) — it emits an INSERT-only SQL file using Postgres-compatible quoting (no double-quoted column literals, boolean `true/false` instead of `1/0`, ISO-8601 timestamps).

### From MySQL/MariaDB

Use `pgloader` — it handles the type translation automatically:

```bash
pgloader \
    mysql://user:pass@127.0.0.1/cihrms \
    pgsql://cihrms:cihrms@127.0.0.1/cihrms_target
```

---

## Step 5 — Apply schema + data on the target

```bash
DB_CONNECTION=pgsql php artisan migrate --force
DB_CONNECTION=pgsql psql -h 127.0.0.1 -U cihrms -d cihrms -f /tmp/cihrms-dump.sql
```

After the dump loads, **reset every sequence** to the max id of its table — Postgres serial sequences don't auto-advance from a bulk load:

```bash
DB_CONNECTION=pgsql php artisan db:reset-sequences
```

(See [`app/Console/Commands/ResetSequences.php`](../../app/Console/Commands/ResetSequences.php).)

---

## Step 6 — Backfill the audit chain

The hash chain is sequenced from the previous row's hash. After a bulk import, no rows have their hashes computed. Run:

```bash
DB_CONNECTION=pgsql php artisan audit:backfill-chain
DB_CONNECTION=pgsql php artisan audit:verify-chain
```

The verify step must exit 0. If it doesn't, the dump arrived out of order — stop and investigate.

---

## Step 7 — Smoke-test the running app

Point the live app at Postgres and run the standard probe set before lifting maintenance mode:

```bash
DB_CONNECTION=pgsql php artisan tinker --execute='echo App\Models\User::count();'
DB_CONNECTION=pgsql php artisan tinker --execute='echo App\Models\Employee::count();'
curl -sf "https://.../up" | head -1               # health endpoint
curl -sf "https://.../api/v1/health" | jq .       # API health
```

A green health endpoint with the expected row counts is the gate.

---

## Step 8 — Restart workers + lift maintenance mode

```bash
sudo systemctl start cihrms-queue cihrms-scheduler
php artisan up
```

Watch logs for the next 30 minutes. The earliest failure modes are:
- **Sequence collisions** — if `ResetSequences` was skipped, the next insert collides with the imported max id. Fix with `db:reset-sequences`.
- **Audit chain breaks** — if any row was inserted between dump and load, the chain is shorter on target. Run `audit:backfill-chain` again to extend.
- **JSON column casts** — Laravel's `'array'` cast handles both Postgres `json` and SQLite TEXT identically. No action required unless you've added custom JSONB queries (Postgres-only operators like `@>` and `?`).

---

## Step 9 — Rotate connection credentials + lock down direct DB access

Once the migration is verified:
- Rotate the Postgres password (`ALTER ROLE cihrms PASSWORD '...';`) and update `.env`.
- Restrict `pg_hba.conf` to the app server's IP only.
- Disable any temporary superuser grants used during the load.
- Schedule the daily `pg_dump` + `pg_basebackup` to S3 (or NITA-approved object storage).

---

## Rollback

If the deploy fails verification at Step 7, revert is fast:

```bash
# 1. Switch .env back to the source DB
DB_CONNECTION=sqlite  # or mysql
# 2. Restart workers
sudo systemctl restart cihrms-queue cihrms-scheduler
# 3. Lift maintenance mode
php artisan up
```

No data was modified on the source DB during the migration — Steps 4–6 only read from it. Roll-forward fixes are the right move 99% of the time; rollback is the fire-escape.

---

## Known gotchas observed during the cut-over

| Symptom | Cause | Fix |
|---|---|---|
| `relation "users_id_seq" does not exist` after insert | Sequence wasn't reset; the import set `id` explicitly but the sequence stayed at 1 | `php artisan db:reset-sequences` |
| Audit verify exits non-zero with `chain_position mismatch` at row N | Rows arrived in a different order than they were written | `php artisan audit:backfill-chain` extends from the verified tail |
| Login fails with `column "deleted_at" must appear in GROUP BY` | A custom analytics query references `deleted_at` outside an aggregate | Wrap in `MAX(deleted_at)` or remove from SELECT |
| `LIKE` searches case-sensitive in production but case-insensitive in dev | SQLite is case-insensitive by default; Postgres isn't | Use `ILIKE` via `DB::raw` or normalise to lowercase columns |

---

## CI matrix

The [GitHub Actions workflow](../../.github/workflows/ci.yml) runs the entire Pest suite on **both SQLite and Postgres 16** on every PR. If a query goes Postgres-incompatible, CI catches it before merge. Add new tests under `tests/Feature/` as usual; the matrix runs them on both drivers automatically.

---

## Further reading

- [Postgres 16 docs](https://www.postgresql.org/docs/16/)
- [Laravel 13 database docs](https://laravel.com/docs/13.x/database)
- [`DbExpr` helper](../../app/Support/DbExpr.php) — extend it whenever you add a date function in a query
- [Audit hash chain doc](../audit/hash-chain.md) *(write next)*
