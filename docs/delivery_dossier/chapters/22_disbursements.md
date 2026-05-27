# Chapter 22 — Disbursements

> *In one paragraph.* Disbursements is the rail that moves real money out of the institute and into the hands of an employee, a vendor, or a settled loan account. The Payroll engine (Chapter 19) computes a net-pay figure; Disbursements is what turns that figure into a credit on a bank statement, a notification on a MoMo phone, or a row on a paper cheque register. Every disbursement is a per-attempt record — channel, gross amount, the 1.5% E-Levy where the rail attracts it, a provider reference, a status, and a final settlement timestamp — so the audit trail is one row per push, not one row per employee. The screen in this chapter is the **Disbursement Ledger**: a read-only queue where Finance watches MoMo and GhIPSS settlements happen, sees what failed and why, and reconciles against provider responses.

## Where to find it

- **Sidebar location:** Reached from the **Finance** dashboard (Ch 20) — the Disbursement-channel card and the "Disbursement" quick-action tile both deep-link to `/disbursements`. There is no top-level sidebar entry in the MVP; the screen is a Finance utility, not a daily-driver dashboard. The Payroll module (Ch 19) also links to it from a per-run "Dispatch & Reconcile" footer (see "How it talks to other modules" below).
- **Roles that see it:**
    - **super_admin** and **ceo** — full view, plus the Dispatch and Reconcile actions.
    - **finance_officer** and **hr_admin** — anyone with `payroll.view_all` can read the ledger; only those with `payroll.disburse` can press Dispatch or Reconcile.
    - **dept_head / manager / employee** — no access. Disbursement is org-wide treasury; it is not scoped by department.
- **Related modules:** Payroll (Ch 19) — every batch starts as an approved payroll run; Payments (Ch 18) — one-off off-cycle payments share the same provider clients but go through `PaymentService` rather than `BatchDisbursementService`; Loans (Ch 21) — loan-issuance disbursements use the same rails when they ship; Finance F1–F5 (Ch 20) — the journal voucher that mirrors a settled batch posts via `MintGifmisJournal` on `PayrollRunPaid`; Identity (Ch 25) — high-value money movement is gated behind a fresh 2FA challenge; Audit Logs (Ch 24) — every Dispatch and Reconcile click is logged.

## The screens

![Disbursement ledger — stat band, channel filter, per-row provider reference](../assets/screenshots/22_disbursements/ledger.png)

*Callouts: ❶ Stat band — four counters (Pending, Sent — awaiting settlement, Settled, Failed) plus two cumulative tiles (MoMo settled YTD, E-Levy paid YTD). All numbers are scoped to the rows the signed-in Finance user can see — which, today, means the whole institute. · ❷ Filter strip — numeric "Run ID" input, channel dropdown (GhIPSS Bank · MTN MoMo · Vodafone Cash · AirtelTigo Money · Cash · Cheque), and status dropdown (Pending · Sent · Settled · Failed · Reversed). Filters apply immediately on change; the URL updates so a link can be shared. · ❸ Table row — payroll run reference (monospace), employee name + masked beneficiary account, channel chip (colour-coded), gross amount, E-Levy column (dash on non-MoMo rails), net to recipient, status badge, and the provider reference returned by MTN/Vodafone/AirtelTigo (also monospace). Failed rows tint pink and show the failure reason in red beneath the status badge.*

> The screenshot file referenced above will be captured in Wave 1 (task W1.22). Until then the build will substitute a "missing image" placeholder — that is expected and does not break the build.

## Every button, every action

### Disbursement ledger (`/disbursements`)

| Button / field | What it does | Who can use it | Why it exists |
|---|---|---|---|
| **Run ID** (number input) | Filters the table to a single payroll run. Press Enter to apply. | `payroll.view_all` | The most common drill-down — "show me the disbursements for the run we just approved". A numeric input rather than a search-by-name is intentional: the run reference (PR-YYYY-MM-NN) is what every other screen quotes, and pasting the ID is the fastest path. |
| **All channels** dropdown | Filters to one of the six channel values: `ghipss_ach`, `mtn_momo`, `vodafone_cash`, `airtel_tigo`, `cash`, `cheque`. | Same | Finance watches MoMo channels closely on month-end (because of E-Levy exposure and the spike in failure rates around the second-to-last business day) — a one-click filter makes that easy. |
| **All statuses** dropdown | Filters to `pending`, `sent`, `settled`, `failed`, or `reversed`. | Same | "Failed" is the most-asked-for view; this is how. |
| **Row click / hover highlight** | The row tints on hover. Failed rows are already tinted pink. No click action — the ledger is read-only. | Anyone with view | Disbursements aren't edited by hand; they're modified by provider webhooks and by the Dispatch/Reconcile commands. A non-actionable row is the correct affordance. |
| **Status badge** | Reads `pending` (slate), `sent` (amber), `settled` (emerald), `failed` (rose), or `reversed` (slate). | Same | Visual scan of a 50-row page should let an operator answer "is this run done?" in under two seconds. |
| **Provider reference column** | The opaque identifier returned by the rail: `MOMO-{uuid}` (MTN), Vodafone `transactionId`, AirtelTigo `transactionId`, or the deterministic `GHIPSS-{run_id}-{disbursement_id}` for ACH. Empty (`—`) until the row leaves Pending. | Same | When a payee disputes a credit, this is the string Finance quotes to the provider's support desk. |
| **Pagination** (bottom) | 50 rows per page. Standard Inertia links. | Same | A monthly payroll run with 1,200 employees produces 1,200 rows; pagination keeps the page snappy. |

> *Notes:* The ledger does **not** expose `Dispatch` or `Reconcile` buttons in the MVP UI. Both actions are wired as named routes (`disbursements.dispatch` and `disbursements.reconcile`) and are reachable from the Payroll-run detail page (Chapter 19), where it makes sense to dispatch *this run's* disbursements rather than picking a run from the ledger. The named routes both require `payroll.disburse` and a fresh-within-15-minutes 2FA challenge (`2fa:fresh`).

### Dispatch run (`POST /disbursements/runs/{run}/dispatch`)

| Field / behaviour | What it does | Who can use it | Why it exists |
|---|---|---|---|
| **Trigger** | A button on the Payroll-run detail page (Ch 19) — typically labelled "Dispatch disbursements". POSTs to this route with the `PayrollRun` model in the URL. | `payroll.disburse` + `2fa:fresh` (a 2FA challenge within the last 15 minutes) | Money movement is a high-value gate; the 2FA refresh stops a stolen session from emptying the treasury. |
| **Effect** | `BatchDisbursementService::dispatch($run)` iterates every Disbursement row in status `pending` for that run, looks up the provider for the row's channel, calls `provider->send($d)`, and updates the row with the returned status, provider reference, and raw response. Each row's update is its own transaction — one row's transport error does not back out the others. | Same | Idempotency at the row level: re-running Dispatch after a partial failure picks up only the still-pending rows. |
| **Response** | A flash message: "Dispatched: N sent, M failed, K skipped (manual channels)". K is the count of Cash + Cheque rows — they have no automated provider and are intentionally skipped. | Same | A single sentence is enough — Finance opens the ledger immediately afterwards to see the breakdown row by row. |
| **Failure modes** | Provider HTTP non-2xx, connection timeout (15 s default), token-acquisition failure (MTN OAuth + AirtelTigo OAuth + Vodafone HMAC), or a missing beneficiary account / MSISDN. Each is captured into `failure_reason` on the row and the row goes to `failed`. | Same | Failure is a row-level fact, not a batch-level fact. A failed MoMo number does not stop the next employee's GhIPSS transfer from being staged. |

### Reconcile run (`POST /disbursements/runs/{run}/reconcile`)

| Field / behaviour | What it does | Who can use it | Why it exists |
|---|---|---|---|
| **Trigger** | A button on the Payroll-run detail page (Ch 19) — typically labelled "Reconcile with providers". POSTs to this route with the `PayrollRun` model in the URL. | `payroll.disburse` | Reconciliation is read-mostly (it polls providers) so it does not require a fresh 2FA challenge, only the disburse permission. |
| **Effect** | `BatchDisbursementService::reconcile($run)` finds every Disbursement row in this run that has been `sent` for at least 5 minutes (i.e., long enough for the provider to have moved it) and calls `provider->refreshStatus($d)` for each. If the polled status differs from the persisted status the row is updated to `settled` or `failed`; the provider's raw response is saved into `provider_response`. | Same | The webhook path (see below) is the primary signal; this poll is the fallback for when a webhook is lost, throttled, or arrives at a server that's mid-deploy. |
| **Response** | A flash message: "Reconciliation: N disbursement(s) updated.". | Same | Same one-sentence pattern as Dispatch — Finance opens the ledger to see what moved. |

## The data behind it

Two things are stored. The first is per-employee preference; the second is per-attempt history.

**Employee preference** lives on three columns added to the `employees` table by the disbursement migration:

- `disbursement_channel` (string, default `ghipss_ach`) — which of the six rails the employee is paid through.
- `mobile_money_number` (string, nullable) — the MSISDN; required if the channel is one of the three MoMo values; ignored otherwise.
- `mobile_money_network` (string, nullable) — kept for cross-checking when a number is portable across networks; not currently used by the providers (they each only accept numbers from their own network).

**Per-attempt history** is the `disbursements` table itself. One row is created for every (`payroll_run_id`, `payroll_line_id`) pair — a retry produces a new row, not an update to the prior one. The row carries:

- **Routing** — `payroll_run_id`, `payroll_line_id`, `employee_id`, `channel`, `status`.
- **Money** — `gross_amount` (the net pay being sent out), `e_levy` (the 1.5% on MoMo channels, zero otherwise), `provider_fee` (currently always zero, reserved for the day a rail charges us), and `net_to_recipient` = `gross_amount − e_levy − provider_fee`.
- **Beneficiary** — `beneficiary_account` (a bank account number for GhIPSS, an MSISDN for MoMo channels) and `beneficiary_name`.
- **Provider tracking** — `provider_reference` (the rail's opaque ID for the transfer) and `provider_response` (the last full JSON the rail returned, stored verbatim for forensics).
- **Lifecycle** — `sent_at`, `settled_at`, `failed_at`, `failure_reason`, and `retry_count`.
- **Soft delete** so a Disbursement record never vanishes from the audit trail.

The five statuses are exhaustive:

- **`pending`** — the row exists (it was materialised when the payroll run was approved) but the rail hasn't been called yet.
- **`sent`** — the rail accepted the instruction. The money has not necessarily landed yet — MoMo settlements are asynchronous and can take seconds to minutes; GhIPSS settles overnight.
- **`settled`** — confirmed by the rail. For MoMo, this means the provider reported `SUCCESSFUL`/`SUCCESS`/`COMPLETED` on the webhook or the polled status. For GhIPSS, it means the bank-statement reconciliation step (out of scope for v1) flipped the row.
- **`failed`** — the rail rejected the instruction or reported a settlement failure. `failure_reason` is always populated.
- **`reversed`** — a settled disbursement that was later clawed back. Currently set only by the operator console; no public surface in v1.

Three things every reader of this screen needs to keep in mind:

- **Disbursements are not edited by hand.** The ledger is read-only. State transitions are driven by `BatchDisbursementService` (dispatch, reconcile) and by the configured webhook handlers (`PaystackWebhookController` for finance-gateway events, and the per-MoMo-provider webhooks that flip rows to `settled` or `failed` via the same provider clients).
- **One row per attempt.** If a MoMo push fails because the recipient's wallet is closed, a Finance operator who fixes the MSISDN and re-triggers will get a **new** Disbursement row, not a mutation of the failed one. The old row stays as evidence; the new one carries the retry.
- **The E-Levy is real money.** It comes off the gross before the net is sent, and it is paid to the Ghana Revenue Authority through the MoMo provider's own settlement file — CIHRMS does not separately remit it. The Disbursement row records exactly what we deducted, so when GRA's quarterly E-Levy reconciliation arrives, the institute can prove the per-employee math.

## The six channels

`DisbursementChannel` is the enum at `app/Enums/DisbursementChannel.php`. Six values, three behaviours:

| Channel | Value | Automated? | E-Levy? | Provider client |
|---|---|---|---|---|
| **GhIPSS Bank Transfer** | `ghipss_ach` | Bulk file (not REST) | No | `GhIpssAchProvider` — generates a per-row token, then `GhIpssBatchFileBuilder` produces the CSV the sponsor bank uploads. |
| **MTN MoMo** | `mtn_momo` | Yes (OAuth2 + REST) | Yes (1.5%) | `MtnMomoProvider` — MTN MoMo Open API, sandbox/production switched by config. |
| **Vodafone Cash** | `vodafone_cash` | Yes (API key + HMAC) | Yes (1.5%) | `VodafoneCashProvider` — B2C `/b2c/send`; signs every body with HMAC-SHA256. |
| **AirtelTigo Money** | `airtel_tigo` | Yes (OAuth2 + REST) | Yes (1.5%) | `AirtelTigoProvider` — `/disbursement/v1/transfer`. |
| **Cash** | `cash` | No | No | None — Dispatch skips these and Finance pays the employee in person from petty cash. |
| **Cheque** | `cheque` | No | No | None — same as Cash; the cheque is written off-system. |

`DisbursementChannel::isAutomated()` returns true for the first four; `BatchDisbursementService::dispatch()` skips any row whose channel has no registered provider (which is how Cash and Cheque pass through quietly).

### What "wired" means today

The four automated providers are all **coded** — they each implement `DisbursementProvider` (the contract at `app/Services/Disbursement/Contracts/DisbursementProvider.php`), and the `DisbursementServiceProvider` binds the `BatchDisbursementService` singleton with the providers that have `enabled => true` in `config/disbursement.php`. The default config state:

- **`ghipss_ach`** — `enabled` defaults to **true**. Bulk-file generation works against any disk; the sponsor's sort code and originator name are read from env (`GHIPSS_SPONSOR_SORT_CODE`, `GHIPSS_ORIGINATOR_NAME`). The artisan command `php artisan disbursement:ghipss-export {run}` writes the CSV; `--print` streams it to stdout for smoke-testing.
- **`mtn_momo`** — `enabled` defaults to **false** (`MOMO_MTN_ENABLED=false`). Setting the four env vars (`MOMO_MTN_BASE_URL`, `MOMO_MTN_SUBSCRIPTION_KEY`, `MOMO_MTN_API_USER`, `MOMO_MTN_API_KEY`) and flipping the flag to true is all that's needed to engage the live API.
- **`vodafone_cash`** — `enabled` defaults to **false**. Three env vars (`MOMO_VF_BASE_URL`, `MOMO_VF_API_KEY`, `MOMO_VF_SIGNING_SECRET`).
- **`airtel_tigo`** — `enabled` defaults to **false**. Three env vars (`MOMO_AT_BASE_URL`, `MOMO_AT_CLIENT_ID`, `MOMO_AT_CLIENT_SECRET`).

The Paystack stack (`PaystackGatewayService`, `PaystackWebhookProcessor`, `PaystackWebhookController`) is also present in the codebase, but it is the **inbound** rail for the Accounts Receivable hosted checkout (Finance F4 — Ch 20) — customers paying invoices, not employees being paid. It does not push money out and is not bound into `BatchDisbursementService`. The Zoho webhook (`ZohoWebhookController`) is a CRM contact-sync receiver, not a disbursement channel. The mention of "Paystack/Zoho disbursement" in the gap-analysis preamble is therefore best read as "infrastructure for webhooks is in place" rather than "Paystack and Zoho are payout rails" — the actual payout rails are the four channels above.

## E-Levy — how it is computed and disclosed

The **Electronic Transfer Levy Act, 2022 (Act 1075)** levies 1.5% on electronic transfers, including B2C mobile-money payments. CIHRMS applies it at materialisation time, not at dispatch time.

The mechanism is in `BatchDisbursementService::materialise()`:

1. The service asks `StatutoryRate::lookup('E_LEVY_RATE', $run->period_end)` for the effective rate on the run's last day. Statutory rates are effective-dated rows on the `statutory_rates` table (the same machinery that drives SSNIT, NHIA, and Tier-2 percentages — Ch 19).
2. If no row exists, the service falls back to the constant `BatchDisbursementService::E_LEVY_FALLBACK_RATE = 0.015` (also overridable via `E_LEVY_RATE_FALLBACK` env var). The constant matches the current statutory rate; the lookup is the long-term path so an Act amendment can be honoured by seeding a new row, not by deploying code.
3. For every payroll line, the service reads the employee's `disbursement_channel`. If `DisbursementChannel::attractsELevy()` returns true (MTN MoMo, Vodafone Cash, AirtelTigo) the levy is computed as `round($gross * $eLevyRate, 2)` and subtracted from `gross_amount` to produce `net_to_recipient`. On GhIPSS / Cash / Cheque the levy is zero.

Disclosure today:

- The **Disbursement Ledger** (this chapter's screen) shows the per-row `E-Levy` column and a "E-Levy paid (YTD)" tile at the top. So an HR or Finance user can always see what a given employee had withheld.
- The **payslip** (rendered from `PayrollLineResource`) **does not currently show the E-Levy line**. The resource exposes `ssnit_employee`, `nhia_split`, `tier2`, `paye`, `voluntary`, and `net` — but no `e_levy`. This is an honest gap: an employee paid via MoMo who reads their payslip sees net pay, but does not see that 1.5% of that net was further deducted before it reached their phone. The fix is small (one line on `PayrollLineResource` joined to the disbursement row plus a payslip-template field) but it is not yet shipped.
- **Aggregate reporting** to GRA is the MoMo provider's responsibility — the levy is netted off in the provider's own settlement to GRA. CIHRMS therefore does not separately remit E-Levy; it only needs to be able to prove what was deducted, which the per-row `e_levy` column does.

## Batch composition — how a batch is assembled

The batch is implicit: it's all the Disbursement rows belonging to one approved PayrollRun.

The materialisation flow:

1. **`PayrollService::approve($run)`** transitions the run to `approved` and fires the `PayrollRunApproved` event (Ch 19).
2. **`MaterialiseDisbursements`** listener (queued, three retries, `payroll` queue) picks up the event and calls `BatchDisbursementService::materialise($run)`.
3. The service iterates every `PayrollLine` in the run that is in status `calculated`, in chunks of 200 to keep memory bounded, and creates one Disbursement row per line. The channel is read from the employee, the E-Levy is computed if applicable, and the beneficiary account is resolved (`mobile_money_number` for MoMo channels, `bank_account` otherwise).
4. Materialisation is **idempotent**. If a Disbursement already exists for a (`payroll_run_id`, `payroll_line_id`) pair, the iteration skips it. So re-running the listener — by a retry, by a manual replay, by an operator-triggered re-approval — does not double-credit anyone.

The result is that **approval ≠ payment**. An approved run sits with all its Disbursement rows in `pending` until Finance explicitly presses Dispatch on the Payroll-run page. This is deliberate: approval is an HR sign-off ("this is the correct payroll"); dispatch is a Finance sign-off ("we are now moving money") — separation of duties (see Audit & dual-control below).

## Provider clients

All four automated providers implement the same three-method contract:

```php
interface DisbursementProvider {
    public function channel(): string;
    public function send(Disbursement $d): DisbursementResult;
    public function refreshStatus(Disbursement $d): DisbursementResult;
}
```

`DisbursementResult` is a value object with one of three named constructors — `sent($ref, $raw)`, `settled($ref, $raw)`, `failed($reason, $raw)` — so the service that consumes the result never needs to inspect provider-specific JSON shapes.

### MTN MoMo — `MtnMomoProvider`

- Auth: OAuth2 client-credentials against `/disbursement/token/`, with Basic-auth + subscription key.
- Endpoint: `POST /disbursement/v1_0/transfer`, body `{ amount, currency: 'GHS', externalId, payee: { partyIdType: 'MSISDN', partyId }, payerMessage, payeeNote }`.
- The provider generates a fresh UUID as the `X-Reference-Id` header and as the row's `provider_reference`, so even a retry produces a unique idempotency key from MTN's perspective.
- Settlement is asynchronous — `HTTP 202` from `send()` means accepted; the final disposition (`SUCCESSFUL` / `FAILED`) arrives either via the configured webhook callback or via `refreshStatus()` polling.
- The `externalId` written into the MTN request is `PAYROLL-{run_id}-{disbursement_id}`, which is what GRA's E-Levy reconciliation will quote when it cross-checks our books.
- MSISDN normalisation — accepts `0244000001`, `+233244000001`, `233244000001`, normalises to `233244000001` (no plus, no leading zero) before sending.

### Vodafone Cash — `VodafoneCashProvider`

- Auth: `X-API-Key` header + HMAC-SHA256 signature of the JSON body using a shared `signingSecret`.
- Endpoint: `POST /b2c/send`, body `{ reference, msisdn, amount, currency, narration, timestamp, signature }`.
- Settlement signal is `transactionId` in the response; status poll reads `body.status` and maps `success`/`completed` → `settled`, `failed`/`rejected` → `failed`, anything else → `sent`.

### AirtelTigo Money — `AirtelTigoProvider`

- Auth: OAuth2 client-credentials (`client_id` + `client_secret`) against `/oauth/token`.
- Endpoint: `POST /disbursement/v1/transfer`, body `{ externalRef, msisdn, amount, currency, description }`.
- Status poll reads `body.status` and maps `COMPLETED`/`SUCCESS` → `settled`, `FAILED`/`REJECTED` → `failed`, anything else → `sent`.

### GhIPSS ACH — `GhIpssAchProvider`

- This is the odd one out — GhIPSS is a **bulk-file** rail, not a per-row REST endpoint. There is no per-row "transfer" call to make.
- `send()` therefore makes no network call. It assigns a deterministic batch token `GHIPSS-{payroll_run_id}-{disbursement_id}` and marks the row as `sent`. The actual money movement happens when Finance uploads the file produced by `GhIpssBatchFileBuilder` to the sponsor bank's bulk-payment portal.
- The file: a CSV with one header row plus one data row per beneficiary, columns: `sequence_no, beneficiary_account, beneficiary_bank_sort_code, beneficiary_name, amount_ghs, narration, reference, originator_name, originator_sort_code, value_date`. Amounts are GHS with two decimals (not pesewas — Ghanaian banks uniformly accept the decimal form and the CSV is much easier to inspect when something goes wrong). Lines are CRLF-terminated for parser compatibility across GCB/Stanbic/Ecobank.
- Beneficiary narration is truncated to 35 characters per the ACH standard limit, so a bank-statement line never gets clipped mid-word.
- Built via `php artisan disbursement:ghipss-export {run}` (writes to disk) or `--print` (streams to stdout). The output lands at `ghipss-batches/PR-{run_id}-{YYYYMMDD-HHMMSS}.csv` on the configured disk.
- `refreshStatus()` is a no-op — settlement comes back as a statement-reconciliation upload (out of scope for v1), not a polled API. A row stays in `sent` until the bank-statement reconciliation step flips it.

## Webhook callbacks

Each provider — when configured — POSTs settlement events to a callback URL. CIHRMS receives them through the platform's webhook framework (`app/Http/Controllers/Webhooks/*`) plus the provider-specific Paystack webhook on the finance side.

What is actually wired in v1:

- **Paystack webhook** — `PaystackWebhookController` at `POST /webhooks/paystack`, protected by `VerifyPaystackSignature` middleware (HMAC-SHA512 verification of the `X-Paystack-Signature` header) and `throttle:120,1`. The payload is persisted into `paystack_webhook_events` (so a replay is a no-op against the `paystack_event_id` UNIQUE), then `ProcessPaystackWebhook` is dispatched onto the queue. `PaystackWebhookProcessor` consumes `charge.success` (records an AR receipt for the matched PaymentIntent) and `refund.processed` (stamps `refund_settled_at`). Everything else is marked no-op. **This is the inbound rail for AR collections, not for payroll disbursement.**
- **MoMo provider webhooks** — for each of MTN, Vodafone, AirtelTigo, the configured `callback_url` field in their provider portal points at an institute-specific endpoint. The provider clients are coded against the response shape, but the callback handlers (separate from the controller in this chapter) are part of the generic webhook framework — see `WebhookController` and `VerifyWebhookSignature`. In production each provider would be onboarded against its own controller; in the MVP, settlement is most reliably surfaced via the **`reconcile`** poll (5-minute lag) which calls `refreshStatus()` on every `sent` row.
- **Zoho webhook** is unrelated — it is CRM contact synchronisation (`ZohoCrmDriver`), not a payments callback. The Zoho mention in the gap analysis refers to the integrations framework that hosts it, not to a Zoho payout rail.

## Reconciliation

Two reconciliation paths exist, both inside `BatchDisbursementService::reconcile($run)`:

1. **MoMo polling.** For every Disbursement in this run that is in `sent` for at least 5 minutes (a small grace window so the provider has actually had time to move the money), the service calls `provider->refreshStatus($d)`. The provider's poll endpoint returns the current state; on a transition the row is updated to `settled` (with `settled_at = now()`) or `failed` (with `failed_at = now()` and `failure_reason` set), and `provider_response` is overwritten with the latest raw payload.
2. **GhIPSS statement matching** — designed but not shipped in v1. The bank's settlement statement (downloaded from the portal as a CSV) would be diffed against `provider_reference` and the matched rows flipped to `settled`. The work is roadmapped (see "What's planned next") and the matching key is already deterministic — `GHIPSS-{run_id}-{disbursement_id}` — so the reconciler only needs to be written, not also designed.

A Reconcile pass produces a flash message: "Reconciliation: N disbursement(s) updated." — the operator then opens the ledger and sees the green `settled` badges.

## Failure handling

Failure is row-level and explicit. Every `failed` row carries:

- `failure_reason` — a human-readable string. Examples from the actual provider clients: `MoMo HTTP 401: invalid subscription key`, `MoMo transport error: cURL error 28 — Operation timed out`, `Could not acquire MTN MoMo access token.`, `Vodafone Cash HTTP 500`, `No transactionId in response`, `GhIPSS: beneficiary bank account is missing.`, `AirtelTigo token acquisition failed.`.
- `provider_response` — the raw payload the rail returned, for forensic inspection.
- `failed_at` — wall-clock time of the failure.

Recovery paths:

- **Re-dispatch.** A Finance operator with `payroll.disburse` can press Dispatch again on the Payroll-run page. The service iterates only `pending` rows by design, so a `failed` row is not retried automatically. To retry, Finance creates a fresh attempt — either by clearing the failure (operator console) and re-dispatching, or by re-issuing the payment via the off-cycle Payments module (Ch 18). The model deliberately makes a retry a new row to keep the audit trail.
- **Manual override.** Finance can switch an employee's `disbursement_channel` (e.g. from `vodafone_cash` to `cash` if the wallet is closed) on the Employee detail page and then re-materialise via a console command. The MVP does not expose a "change channel and retry" button on the ledger.
- **Rollback.** A settled disbursement that needs to be clawed back becomes a `reversed` row, set from the operator console. No public surface in v1.
- **Provider outage.** Setting `MOMO_MTN_ENABLED=false` (or the equivalent flag for the affected provider) in env and re-deploying drops that channel from the provider registry. Subsequent dispatches treat that channel as "manual" (skipped), and Finance handles the affected employees out-of-band until the rail comes back.

## Audit and dual-control

Three layers, in order of strength:

1. **Approval ≠ Dispatch.** Payroll approval is an HR sign-off; the disbursement push is a separate Finance action. The two roles do not have to be the same person — in fact, the seeded permission grants `payroll.approve` to `hr_admin` and `payroll.disburse` to `finance_officer` (and `super_admin`, `ceo`), so the default seed gives you separation of duties out of the box. Approving and dispatching are two clicks on two different screens by two different roles.
2. **2FA-fresh gate on dispatch.** The `disbursements.dispatch` route is wrapped in `2fa:fresh`, which requires a TOTP challenge passed within the last 15 minutes. A stolen session can browse the ledger but cannot move money. The Reconcile route does not require 2FA-fresh (it does not move new money — it only updates statuses).
3. **Audit logs.** Every Dispatch and Reconcile call is recorded by the platform's audit-log middleware against the user, the run, and the result (see Ch 24). The Disbursement row itself is the per-attempt evidence — never updated in place except for status/timing fields, never hard-deleted (soft-delete only). The `provider_response` column stores the rail's raw response so an external auditor can verify every transition against the rail's own audit pack.

What v1 **does not** enforce:

- There is no maker/checker dual-control on Dispatch itself — one Finance user with `payroll.disburse` and a fresh TOTP can dispatch a multi-million-cedi run without a second-pair-of-eyes co-sign. Phase 3 of the gap-analysis roadmap adds a maker/checker workflow on amounts above a configurable threshold; that is the upgrade most likely to be required by a public-sector audit pack.

## Reports

The ledger stat band is the primary report surface. The six tiles are computed in `DisbursementController::index()` directly off the `disbursements` table — no separate aggregation table:

- **Pending count** — `WHERE status = 'pending'`.
- **Sent count** — `WHERE status = 'sent'`. Watching this number trend down as a run progresses is how Finance knows the dispatch is healthy.
- **Settled count** — `WHERE status = 'settled'`. The target number at end-of-day.
- **Failed count** — `WHERE status = 'failed'`. The number Finance triages.
- **MoMo settled (YTD)** — `SUM(net_to_recipient)` filtered to the three MoMo channels and status `settled`. The cumulative cedi value of every MoMo payslip the institute has actually delivered. Useful for the year-end E-Levy reconciliation.
- **E-Levy paid (YTD)** — `SUM(e_levy)` across every row, regardless of channel (it's zero on the non-MoMo channels anyway). The number GRA will ask for in the quarterly reconciliation.

A dedicated "Disbursement Volume by Channel" report and a "Failure-rate by Provider over Time" chart are roadmapped but not in v1; the ledger filters cover most operational questions in the meantime.

## How it talks to other modules

- **Payroll (Ch 19)** — the upstream source. `PayrollService::approve` fires `PayrollRunApproved`; the `MaterialiseDisbursements` listener creates one Disbursement row per PayrollLine. The Payroll-run detail page is where Finance presses Dispatch and Reconcile (the buttons are not on the ledger itself in v1). `PayrollRunPaid` is the separate downstream event that fires when a run leaves Approved for Paid; it triggers `MintGifmisJournal` to write the journal voucher into Finance F1–F5.
- **Payments (Ch 18)** — off-cycle one-offs (an emergency salary advance, a single ex-gratia payment). They run through `PaymentService` and reuse the same provider clients indirectly. They are not part of a batch and do not appear on the ledger; they get their own Payment record.
- **Loans (Ch 21)** — loan issuance is itself a disbursement. The `loans.disburse` permission protects that path; today it produces a Payment row (Ch 18) rather than a Disbursement row, with the same rails available. Folding loan issuance into the Disbursement table is a small unification roadmapped for Phase 3.
- **Finance F1–F5 (Ch 20)** — every settled disbursement contributes to the journal voucher posted by `MintGifmisJournal` on `PayrollRunPaid`. The JV debits Salaries Payable and credits Bank — the exact bank account being whichever `org_bank_account` is wired up for payroll (the same row that AR receipts land into for Paystack collections, in the inverse direction).
- **Identity (Ch 25)** — disbursement is the second of the two "real-money" gates that depend on 2FA-fresh. The first is `payroll.approve`, the second is `payroll.disburse`. Together they enforce that any session emptying the treasury must have re-proven identity within the last 15 minutes.
- **Audit Logs (Ch 24)** — every Dispatch and Reconcile is logged by user, run, and result. The Disbursement row itself is also a permanent record — soft-delete only, never hard-deleted, with `provider_response` carrying the rail's raw audit payload.

## Standards touchpoints

- **Electronic Transfer Levy Act, 2022 (Act 1075) — E-Levy.** `BatchDisbursementService` applies the levy at 1.5% on every MoMo-channel disbursement, sourced first from `statutory_rates.E_LEVY_RATE` (effective-dated) and falling back to the `E_LEVY_FALLBACK_RATE = 0.015` constant. Per-employee deduction is recorded on the Disbursement row separately from `net_to_recipient` so the audit can see who received less than gross because of the levy. **Gap:** the levy is not yet disclosed on the payslip itself (only in the Disbursement Ledger) — a small fix that is not yet shipped. See Chapter 44.
- **GhIPSS direct-credit rules.** The bulk-payment file produced by `GhIpssBatchFileBuilder` follows the canonical column layout (`sequence_no, beneficiary_account, beneficiary_bank_sort_code, beneficiary_name, amount_ghs, narration, reference, originator_name, originator_sort_code, value_date`), with GHS-decimal amounts, 35-character narration, and CRLF line endings — accepted by GCB Bank ECP, Stanbic bulk-pay, and Ecobank omni-bulk without per-bank tweaks. The sponsor sort code and originator name are read from env so each institute can configure them without forking. See Chapter 44.
- **Bank of Ghana payments oversight.** The provider-client pattern means the institute can swap rails in and out via config without code change — important for honouring BoG directives that periodically restrict or re-license payment-service providers. Each provider's raw response is persisted on the Disbursement row, so any BoG audit request for transaction-level evidence can be served from the `provider_response` JSON. See Chapter 44.
- **Data Protection Act, 2012 (Act 843) — payee account data.** The beneficiary account / MSISDN is masked on the ledger UI (last four digits visible in the monospace cell, the full string lives only in the database). It is treated as a financial identifier under §18 and §40 with the same lawful-basis-of-contract justification as the salary itself. The 2FA-fresh gate on Dispatch and the soft-delete-only audit trail jointly honour the §40 evidence expectations. See Chapter 26 and Chapter 44.
- **PCI DSS.** Disbursements do not touch card data — both inbound (Paystack hosted checkout, Ch 20) and outbound (MoMo + GhIPSS) move money via account/wallet identifiers, never PAN. PCI DSS is therefore out of scope for this rail. See Chapter 44.

## What's planned next

The gap analysis listed MoMo disbursement as Phase 3 work. The Phase 3 milestone is now actually built — the four provider clients, the materialisation listener, the dispatch and reconcile commands, the GhIPSS file generator, the ledger UI, and the 2FA gate. What is left:

1. **Payslip disclosure of E-Levy.** Add `e_levy` to `PayrollLineResource` (joined to the Disbursement row for the payroll line) and add the line to the payslip template. This is the single biggest honest gap on the rail today.
2. **Maker/checker dual-control on Dispatch.** A configurable threshold (e.g. > GHS 50,000) above which Dispatch requires a second Finance user with `payroll.disburse` to co-sign. Currently one user with a fresh TOTP can dispatch any size of run.
3. **Live MoMo provider onboarding.** All four MoMo providers are coded against the live API shapes but ship with `enabled => false` in config. Phase 3 closes by onboarding a sandbox account for each of MTN, Vodafone, AirtelTigo and running a real end-to-end test with a £1-equivalent payroll.
4. **Per-provider settlement webhook handlers.** Today the most reliable settlement signal is the 5-minute `reconcile` poll. Adding dedicated webhook handlers for each MoMo provider closes the lag from minutes to seconds.
5. **GhIPSS statement reconciliation.** The CSV the sponsor bank returns (one row per settled credit) would be diffed against `provider_reference` and matching rows flipped to `settled`. The matching key is already deterministic — `GHIPSS-{run_id}-{disbursement_id}` — so this is implementation, not design.
6. **Loan issuance through the Disbursement table.** Today loan disbursements produce a Payment row; folding them through `BatchDisbursementService` unifies the audit trail and the failure-handling story across payroll and lending.
7. **Channel-mix and failure-rate reporting.** A small set of dashboard tiles for "MoMo failure rate this month" and "channel mix by department" — the data is already on the row, the queries are the work.
