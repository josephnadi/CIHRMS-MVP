# Phase 1 — Automated Bank Payouts (Hubtel) & Closed Bank Loop — Design

**Date:** 2026-07-20
**Status:** Approved for planning
**Goal:** Make outbound bank transfers as automated as mobile money already is — release an approved payout batch, money leaves via the Hubtel API, and the transfer confirmation posts to the GL and reconciles itself — eliminating the manual export-file / bank-portal-upload / statement-download / manual-match loop.

## Context (current state)

The disbursement engine already exists and is mature:
- `App\Services\Disbursement\Contracts\DisbursementProvider` — `send(Disbursement): DisbursementResult` + `refreshStatus(Disbursement)`.
- Live providers: `MtnMomoProvider`, `VodafoneCashProvider`, `AirtelTigoProvider` (real REST APIs); `GhIpssAchProvider` (bulk-file rail via `GhIpssBatchFileBuilder` + `disbursement:ghipss-export` command).
- `BatchDisbursementService` resolves a channel per employee and dispatches; `dispatch(PayrollRun)` / `dispatchOne(Disbursement)`; provider registry keyed by `DisbursementChannel` value.
- `Disbursement` model: `channel`, `status`, amounts, `beneficiary_account/name`, `provider_reference`, `provider_response` (array), `sent_at/settled_at/failed_at`, `failure_reason`, `retry_count`.
- `DisbursementStatus`: Pending → Sent → Settled | Failed | Reversed.
- `DisbursementChannel`: GhipssAch, MtnMomo, VodafoneCash, AirtelTigo, Cash, Cheque.
- Payroll runs and offboarding settlements already create disbursements (`BatchDisbursementService`, `OffboardingService::…createForSettlement`).
- Inbound Paystack webhook wiring to mirror: `PaystackWebhookController@handle` + `paystack.signature` HMAC middleware + `ProcessPaystackWebhook` job + `PaystackWebhookEvent` model (idempotent event storage).
- Reconciliation: `ReconciliationService`, `ReconciliationMatcher::matchUnreconciled(BankStatement)`; GL via `PostingService`; refs via `SequenceService`.

The gap: the bank leg (GhIPSS) is a manual file export + portal upload + statement download + manual reconcile. Vendor AP payments are recorded, not executed. This phase closes the bank leg via an API aggregator (Hubtel) and adds a maker-checker control layer for real outbound money.

## Decisions (locked)

- **Mechanism:** payout API aggregator.
- **Vendor:** Hubtel (bank + MoMo payouts, GHS-native, Ghana local fit). Built against the existing `DisbursementProvider` contract so a second aggregator remains a clean drop-in.
- **Control model:** maker-checker + threshold. A maker prepares a batch; a *different* user releases it; batches ≥ a configurable GHS threshold require the higher approver (CEO). Nothing calls Hubtel before release.
- **Scope:** foundation + wire the flows already on the rails (payroll + settlements). AP vendor payments (1a), ad-hoc/manual payout (1b), refunds (1c) are follow-on slices reusing the same foundation.

## Non-Goals (this phase)

- AP vendor payment straight-through, ad-hoc payout UI, AR/fee refunds (defined as slices 1a/1b/1c for follow-up plans).
- Replacing the MoMo providers or the GhIPSS file provider (both remain; Hubtel is additive).
- Inbound collections changes (Paystack stays as-is).
- A second aggregator adapter (contract is structured to allow it later).

## Architecture

Additive. New Hubtel provider + a `PayoutBatch` control layer + a Hubtel webhook mirroring the Paystack pattern. Money-out → provider `Sent` → webhook `Settled`/`Failed` → GL posting + auto-reconcile.

```
Maker: create PayoutBatch (draft → pending_release), disbursements Pending
Checker (≠ maker; CEO if ≥ threshold): release
  → PayoutReleaseService: for each disbursement, HubtelBankProvider::send() → Sent (provider_reference = Hubtel tx id)
Hubtel → POST webhook  (hubtel.signature HMAC)
  → HubtelWebhookController → ProcessHubtelWebhook job (idempotent via HubtelWebhookEvent)
     → match provider_reference → Sent→Settled|Failed (settled_at / failure_reason)
     → PostingService: settlement GL entry
     → ReconciliationService: mark bank movement reconciled
Scheduled fallback: refreshStatus() sweep for webhooks that never arrive
```

### Components

**`App\Services\Disbursement\Providers\HubtelBankProvider implements DisbursementProvider`**
- Constructor DI: base URL, client id/secret (or bearer), sender/originator config, timeout.
- `channel(): DisbursementChannel::HubtelBank->value`.
- `send(Disbursement $d): DisbursementResult` — validates `beneficiary_account`; POSTs a transfer with idempotency key `HUBTEL-{d->id}` (retry-safe, no double-pay); on 2xx returns `DisbursementResult::sent($hubtelTxId, [...response])`; on 4xx/timeout returns `failed(reason)` / throws `HubtelUnreachableException` for retryable transport errors.
- `refreshStatus(Disbursement $d): DisbursementResult` — GET transfer status; maps to Settled/Failed/Sent.
- Registered in the `BatchDisbursementService` provider registry under `hubtel_bank`.

**`App\Enums\DisbursementChannel::HubtelBank = 'hubtel_bank'`** (new case). Beneficiaries whose bank payouts should route via Hubtel use this channel.

**`App\Models\PayoutBatch`** (new) — control envelope grouping disbursements from any source.
- Columns: `id`, `reference` (SequenceService), `source_type`/`source_id` (nullable polymorphic — PayrollRun, FinalSettlement, or null for standalone slices), `status` (enum `PayoutBatchStatus`: draft, pending_release, released, completed, failed, cancelled), `total_amount` decimal, `currency` (default GHS), `created_by` (maker, FK users), `released_by` (checker, FK users, nullable), `released_at` nullable, `requires_high_approval` bool, `approved_by` (nullable — the threshold approver, may equal releaser when CEO), timestamps.
- `disbursements()` hasMany via new `disbursements.payout_batch_id`.
- `completed` when all child disbursements are Settled/Failed terminal; `failed` only if release itself failed wholesale.

**`App\Enums\PayoutBatchStatus`** (new): draft, pending_release, released, completed, failed, cancelled.

**`App\Services\Disbursement\PayoutBatchService`** (new)
- `createForPayrollRun(PayrollRun): PayoutBatch` and `createForSettlement(FinalSettlement): PayoutBatch` — wrap existing Pending disbursements into a batch, compute `total_amount`, set `requires_high_approval` = total ≥ `config('finance.payouts.high_approval_threshold')`, status `pending_release`.
- Enforces maker recorded as `created_by`.

**`App\Services\Disbursement\PayoutReleaseService`** (new)
- `release(PayoutBatch $b, User $releaser): array` — guards: releaser has `payouts.release` (and `payouts.release_high` when `requires_high_approval`); releaser ≠ maker (`created_by`); batch is `pending_release`. On pass, sets `released_by/released_at/approved_by`, status `released`, then dispatches each child disbursement via the provider registry (reuses `BatchDisbursementService::dispatchOne`). Returns `{sent, failed, skipped}`. Idempotent — re-release of an already-released batch is a no-op.

**Hubtel webhook (mirrors Paystack exactly)**
- Route `POST /webhooks/hubtel` → `HubtelWebhookController@handle`, middleware `hubtel.signature` (HMAC verify) + `throttle`.
- `VerifyHubtelSignature` middleware; `ProcessHubtelWebhook` job; `HubtelWebhookEvent` model + `hubtel_webhook_events` table (`event_id` unique, `payload`, `signature`, `processed_at`) for idempotent, replay-safe processing.
- Processor: match `provider_reference`; flip `Sent→Settled` (stamp `settled_at`) or `Sent→Failed` (`failure_reason`); post settlement GL via `PostingService`; mark reconciled via `ReconciliationService`. Duplicate `event_id` → no-op.

**Scheduled fallback** — a console command (registered in `routes/console.php` scheduler like the Paystack intent-expiry job) sweeps `Sent` Hubtel disbursements older than N minutes and calls `refreshStatus()` to catch missed webhooks.

**RBAC** — new permissions `payouts.initiate`, `payouts.release`, `payouts.release_high`; added to `App\Enums\Permission`, `User::ROLE_PERMISSIONS`, and `RolePermissionSeeder` (finance roles get initiate/release; CEO/super_admin hold wildcard; `release_high` granted to CEO tier). Route + UI gated accordingly.

**UI** — a Payouts screen (Inertia/Vue): list batches with status, a batch detail showing member rows + amounts, and the maker (create) / checker (release) actions gated by permission, with the threshold approver surfaced when `requires_high_approval`. Reuses existing StatCard/table chrome and the silent-validation + close-on-success form conventions.

## Data flow / integrity

- Amounts on the batch are the sum of child `net_to_recipient`; guarded against drift at release (recompute + compare).
- All money-moving transitions inside a DB transaction where they touch the GL; provider network calls happen outside the DB lock but are idempotent.
- Every transition (create, release, settle, fail) is written to the immutable audit log.
- `provider_response` persisted verbatim for audit/dispute.

## Error handling

- Hubtel 5xx/timeout on `send()` → disbursement stays `Pending`, batch stays `released`; safe retry (idempotency key prevents double-pay). `HubtelException` / `HubtelUnreachableException` mirror the Paystack pair.
- Per-row failure marks only that row `Failed` with reason; batch reports partial success (never all-or-nothing).
- Webhook signature mismatch → 401, logged, not processed. Unknown `provider_reference` → logged, event stored, no-op.
- Division/precision: amounts decimal:2 throughout; no float arithmetic on money.

## Testing (Pest)

- **`FakeHubtelClient`** (no live HTTP): `send()` builds the correct payload, sets `provider_reference`, is idempotent on retry (same key → single logical transfer); status mapping correct.
- **Maker-checker**: release by the maker → 403; release without `payouts.release` → 403; batch ≥ threshold released without `payouts.release_high` → 403; a `pending_release` batch releases and dispatches; an unreleased batch never calls the provider; re-release is a no-op.
- **Webhook**: signed success flips `Sent→Settled`, posts a balanced GL entry, marks reconciled; signed failure flips `Sent→Failed` with reason; duplicate `event_id` → no-op; bad signature → 401.
- **Threshold routing**: total below vs above `high_approval_threshold` sets `requires_high_approval` correctly and routes to the right approver.
- **Provider registry**: a `hubtel_bank` disbursement resolves to `HubtelBankProvider`; MoMo/GhIPSS flows unchanged (regression).
- **Scheduled fallback**: a stale `Sent` row gets its status refreshed.

## Deliverables

New: `HubtelBankProvider`, `HubtelException`/`HubtelUnreachableException`, `PayoutBatch` model + migration + `PayoutBatchStatus` enum, `disbursements.payout_batch_id` migration, `PayoutBatchService`, `PayoutReleaseService`, `HubtelWebhookController` + `VerifyHubtelSignature` + `ProcessHubtelWebhook` + `HubtelWebhookEvent` + migration, scheduled refresh command, 3 permissions (enum + role map + seeder), Payouts Vue screen + route, config `finance.payouts.*`, tests per above. Modified: `DisbursementChannel` enum, `BatchDisbursementService` registry, payroll/settlement dispatch to route through `PayoutBatch` release instead of immediate dispatch.

## Follow-on slices (separate plans)

- **1a AP vendor payments** — approved `VendorInvoice` → create standalone `PayoutBatch` (source = vendor invoice) → same release/settle/reconcile path.
- **1b ad-hoc/manual payout** — a screen to raise a single approved payout (bridge until the Phase-2 expense module) → standalone `PayoutBatch`.
- **1c AR/fee refunds** — outbound refund → standalone `PayoutBatch`.

All three reuse `PayoutBatch`, `HubtelBankProvider`, release controls, and the webhook path unchanged.
