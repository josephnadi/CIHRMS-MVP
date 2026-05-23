# CIHRMS — Production Deployment Hardening Notes

Phase 1 of the government-readiness work introduces six new work-streams. This
note captures the **operational** changes (Postgres, queues, observability,
backups, identity provider, 2FA) that the deployment pipeline must apply.

## 1. Database — PostgreSQL

The `pgsql` connection block already exists in `config/database.php`. Set:

```bash
DB_CONNECTION=pgsql
DB_HOST=db.cihrms.local
DB_PORT=5432
DB_DATABASE=cihrms
DB_USERNAME=cihrms_app
DB_PASSWORD=<rotated>
DB_SSLMODE=require           # NITA-hosted DBs mandate TLS
```

Migration order has been verified against PostgreSQL — all new tables use
`bigint` PKs, `decimal(N,M)` for money, no enum columns at DB level (we use
varchar + PHP enum casts), all FKs are explicit.

## 2. Queues — Horizon

New named queues introduced in Phase 1:

| Queue       | Purpose                                                       | Workers (suggested) |
|-------------|---------------------------------------------------------------|---------------------|
| `audit`     | Existing; hashes tamper-evident chain                         | 2 (low priority)    |
| `analytics` | Existing; records `AnalyticsEvent` rows                       | 4                   |
| `payroll`   | NEW — `GenerateStatutoryReturns` after payroll-run approval   | 1 (high memory)     |
| `identity`  | NEW — `VerifyEmployeeIdentity` (Ghana Card lookup)            | 2                   |
| `notifications` | Existing (Wave 12)                                        | 3                   |

Set `QUEUE_CONNECTION=database` (or `redis`) and run a supervised worker per
named queue. Horizon dashboard should be gated behind `super_admin` only.

## 3. Identity provider

Default `IDENTITY_PROVIDER=manual_upload` until NIA institutional
onboarding completes. To switch:

```bash
# NIA official (post-MoU)
IDENTITY_PROVIDER=nia_official
NIA_BASE_URL=https://api.nia.gov.gh
NIA_API_KEY=<rotated>

# Or third-party KYC aggregator
IDENTITY_PROVIDER=third_party_kyc
KYC_BASE_URL=https://api.uqudo.com
KYC_API_KEY=<rotated>
KYC_VENDOR=uqudo
```

## 4. Two-factor enforcement

All users in privileged roles (`super_admin`, `hr_admin`, `finance_officer`)
have `two_factor_required = true` set by the seeder. First login after
deployment redirects them to `/two-factor/enroll`. Sensitive actions
(payroll approve, payroll reverse) use the `2fa:fresh` middleware which
requires a successful TOTP challenge in the last 5 minutes.

## 5. Tamper-evident audit log

Add a nightly cron entry — failure exits non-zero and pages oncall:

```cron
15 1 * * *  cd /var/www/cihrms && php artisan audit:verify-chain >> /var/log/cihrms/audit-chain.log 2>&1
```

## 6. Backups

Recommended packages: `spatie/laravel-backup` for DB + storage dumps to S3.
Retention: 30 daily, 12 monthly. Backup health-check endpoint should be
exposed for the uptime monitor.

## 7. Sensitive environment variables

```bash
APP_KEY=<generated>           # required for Crypt::encryptString of 2FA secrets, Ghana Card numbers
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
```

## 8. CSA / DPC registration

Two outstanding compliance prerequisites before any government pilot:
1. Register as a data controller with the **Data Protection Commission** under DPA 2012 (Act 843).
2. Register with the **Cyber Security Authority** under the Cybersecurity Act 2020.

These are organisational, not code, but block production go-live.

## 9. Attendance kiosk — face-scan limitation

The shared attendance kiosk at `/kiosk` ships **without face recognition** in the v1 launch. The face-scan tile in [resources/js/Pages/Kiosk/Index.vue](../resources/js/Pages/Kiosk/Index.vue) is intentionally a no-op (status text "Face recognition coming soon"). Vendor integration (Face++, AWS Rekognition, or ZKTeco SDK) is tracked as post-launch work — see C4 in [docs/MARKET_READY_PUNCHLIST.md](MARKET_READY_PUNCHLIST.md).

**What this means for deployments:**

- The kiosk verifies identity by `employee_no` + name lookup only. Someone with knowledge of a coworker's `employee_no` *can* clock in as that coworker. This is trust-weak by design until face-scan ships.
- **Mitigation 1 — biometric devices (recommended for high-trust sites):** wherever attendance fraud risk is material, deploy a hardware biometric reader (fingerprint or face) and let it drive the higher-trust webhook flow at `BiometricWebhookController`. The /kiosk page is for low-trust contexts (front desk, training rooms) where the operator is co-located and observes punches.
- **Mitigation 2 — posted policy:** operators should post a visible notice at each kiosk station stating that clock-ins are CCTV-monitored and that buddy-punching is a disciplinary offence under the organisation's attendance policy.
- **Mitigation 3 — variance audits:** schedule periodic spot-checks of kiosk punches against rota / supervisor sign-off. Anomalies (e.g. an employee on approved leave with a kiosk punch) surface in the existing attendance audit log.
- Do NOT advertise the kiosk to staff as biometric. The UI does not claim it is. If post-launch the face-scan path is added, this section will be updated to describe the enrolment flow and PII implications under the DPA.

**Operational checklist (per site):**

- [ ] Confirm with the site lead whether the deployment falls into "low-trust" (kiosk-only) or "high-trust" (requires biometric device) profile.
- [ ] For high-trust sites: provision biometric devices and configure their webhook target before go-live.
- [ ] For all sites: print and post the buddy-punching policy notice at each kiosk station.
- [ ] Add the attendance variance check to the site supervisor's weekly routine.
