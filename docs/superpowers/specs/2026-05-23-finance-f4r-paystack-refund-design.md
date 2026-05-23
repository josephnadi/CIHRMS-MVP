# Finance F4-R — Paystack Refund Operator Flow

**Date:** 2026-05-23
**Status:** Design approved; spec written for review
**Branch base:** `feat/finance-f4r-paystack-refund` (off main `b0bb8d3` — F1+F2+F3+F4+F5+CI fixes all merged)
**Phase position:** Follow-up to F4. Closes the deferred refund-UI gap noted in `project_finance_f4.md`.

---

## 1. Purpose

F4 shipped Paystack hosted-checkout for inbound payments. The `gateway.refund` permission was reserved in F4 but the operator UI was deferred — refunds could only be triggered by super_admin via the wildcard, with no built-in flow. F4-R closes that gap by adding:

- A `PaystackGatewayService::refundTransaction()` method that wraps Paystack's `/refund` API.
- A `RefundService` that orchestrates: validate state → call Paystack → on success, reverse the F3 AR receipt via the existing `ArReceiptService::void()` method.
- A `refund.processed` webhook handler that confirms async settlement.
- Operator UI on `PaymentIntents/Show.vue` + `ArInvoices/Show.vue` for finance officers (with `2fa:fresh` gate).

F4-R produces no new accounting logic. Reversal flows entirely through F3's `void()` and F2's `JournalPostingService::reverse()` — same engines as a manual receipt void, just initiated by a Paystack refund.

## 2. Scope and non-scope

### In scope
- Full refund only (one refund per intent).
- State machine: only intents in `success` status with no existing `refunded_at` are refundable.
- Synchronous `/refund` POST + async `refund.processed` webhook handling.
- Operator UI on `PaymentIntents/Show.vue` (primary entry point) and `ArInvoices/Show.vue` (secondary entry point).
- `gateway.refund` permission explicitly granted to `finance_officer` (currently super_admin only via wildcard).
- `2fa:fresh` gate on the POST endpoint.

### Explicitly out of scope (deferred)
- **Partial refunds.** Requires per-line allocation reversal and multiple refund records per intent. The Paystack API supports them; we don't. If/when needed, a separate spec.
- **Refund of non-Paystack receipts.** F3 already has manual void / write-off flows for that.
- **Refund reversal ("undo my refund").** Paystack refunds aren't reversible upstream. If an operator refunds in error, they must collect the money again through a new payment link.
- **Refunding cancelled/expired intents.** Only `success` intents can be refunded.
- **Bulk refunds** ("refund all Paystack payments for invoice X"). One-at-a-time only.

## 3. Architecture

```
                    Refund Flow
                    -----------
operator click
       │
       ▼
┌──────────────────┐
│ Refund modal     │ — reason textarea, 2FA prompt if not fresh
└────────┬─────────┘
         │ POST /finance/payment-intents/{intent}/refund
         ▼
┌──────────────────┐
│ RefundController │ — thin: delegate to service
└────────┬─────────┘
         │
         ▼
┌──────────────────┐    Paystack /refund POST
│  RefundService   │ ───────────────────────────► api.paystack.co
│                  │    {transaction, amount, merchant_note}
│                  │
│                  │   on 200 response:
│                  │ ◄─── refund_paystack_ref
│                  │
│                  │   DB::transaction:
│                  │   1. ArReceiptService::void()  ──► JE reversal via JournalPostingService
│                  │   2. PaymentIntent.update({status: refunded, refund_*})
└──────────────────┘

                    Refund Settlement
                    -----------------
Paystack /webhooks/paystack
       │ event: refund.processed
       ▼
┌──────────────────────────┐
│ PaystackWebhookProcessor │ — new handler
│   ::handleRefundProcessed│
└────────┬─────────────────┘
         │
         ▼
PaymentIntent.update({refund_settled_at: now})
```

Each unit is independently testable:
- `PaystackGatewayService::refundTransaction()` — HTTP wrapper; mocked via `Http::fake()`.
- `RefundService::refund()` — orchestration; mocks `PaystackGatewayService`.
- `PaystackWebhookProcessor::handleRefundProcessed()` — pure handler; takes an event row.

## 4. Schema

### 4.1 Modified enum

**`PaymentIntentStatus`** — append one case:

```php
case Refunded = 'refunded';
```

Label: `'Refunded'`.

### 4.2 Modified table — `payment_intents`

Add six nullable columns via a single migration:

| Column | Type | Notes |
|---|---|---|
| `refunded_at` | timestamp, nullable | When the synchronous `/refund` POST returned 200 |
| `refund_amount` | decimal(18,2), nullable | Always equals `amount` in F4-R; reserved for future partial-refund support |
| `refund_reason` | string(500), nullable | Operator-supplied |
| `refund_paystack_ref` | string(100), nullable, indexed | Paystack's refund-transaction id from POST response |
| `refund_settled_at` | timestamp, nullable | Set by webhook handler when `refund.processed` event arrives |
| `refunded_by` | foreignId → users, nullable, `restrictOnDelete` | Operator who initiated the refund |

No new tables. The single-refund-per-intent invariant lets refund audit live on the same row. `refund_paystack_ref` is indexed because the webhook handler looks up intents by it.

Migration filename: `2026_06_05_000001_add_refund_columns_to_payment_intents.php`. (Picked to fire after `create_payment_intents` from F4 and after the `2026_06_04_*` SSO migrations from PR #18.)

## 5. Services

### 5.1 `PaystackGatewayService::refundTransaction()`

```php
/**
 * @param  string $paystackRef  the original transaction reference (intent.paystack_reference)
 * @param  float  $amountGhs    refund amount in GHS (pesewas conversion applied internally)
 * @param  string $reason       merchant note for the Paystack dashboard
 * @return array{
 *   id: int,
 *   transaction: array,
 *   amount: int,       // pesewas
 *   currency: string,
 *   status: string,    // 'pending' | 'processed' | 'processing' | 'failed'
 *   refunded_at: ?string,
 *   ...
 * }
 */
public function refundTransaction(string $paystackRef, float $amountGhs, string $reason): array
```

Implementation mirrors `initializeTransaction()`:
- POST to `/refund`
- Payload: `{transaction: $paystackRef, amount: (int) round($amountGhs * 100), merchant_note: $reason}`
- Use the same auth/timeout/retry chain.
- Translate non-2xx into `PaystackException`.

The returned shape's `id` field is what we store as `refund_paystack_ref`.

### 5.2 `RefundService::refund()`

```php
public function refund(PaymentIntent $intent, User $user, string $reason): PaymentIntent
```

Validation (cheap, before any DB write or gateway call):
1. `$intent->status === PaymentIntentStatus::Success` — else `DomainException("Cannot refund intent {$intent->reference}: status is {$intent->status->value}.")`.
2. `$intent->refunded_at === null` — else `DomainException("Intent {$intent->reference} is already refunded.")`.
3. `$intent->ar_receipt_id !== null` — else `DomainException("Intent {$intent->reference} has no linked AR receipt to reverse.")`.

Execution (single `DB::transaction`):
1. Call `$this->gateway->refundTransaction($intent->paystack_reference, (float) $intent->amount, $reason)`.
   - If Paystack throws, the transaction rolls back and no DB state changes. Operator sees the exception message.
2. Call `$this->receipts->void($intent->receipt, $user, "Paystack refund: {$reason}")`. This reverses the JE and resets invoice `amount_received`.
3. Update the intent:
   ```php
   $intent->update([
       'status'              => PaymentIntentStatus::Refunded->value,
       'refunded_at'         => now(),
       'refund_amount'       => $intent->amount,
       'refund_reason'       => $reason,
       'refund_paystack_ref' => $paystackResponse['id'],
       'refunded_by'         => $user->id,
   ]);
   ```
4. Return `$intent->fresh('receipt')`.

`refund_settled_at` is intentionally left null at this point. The webhook handler sets it when Paystack confirms async settlement.

### 5.3 `PaystackWebhookProcessor::handleRefundProcessed()`

New private method, dispatched from `process()` when `$event->event_type === 'refund.processed'`.

```php
private function handleRefundProcessed(PaystackWebhookEvent $event): null
{
    $refundId = (string) data_get($event->payload, 'data.id');
    if ($refundId === '') {
        $event->update(['processed_at' => now(), 'processing_error' => 'refund.processed missing data.id']);
        return null;
    }

    return DB::transaction(function () use ($event, $refundId) {
        $intent = PaymentIntent::where('refund_paystack_ref', $refundId)
            ->lockForUpdate()
            ->first();

        if (! $intent) {
            $event->update([
                'processed_at'     => now(),
                'processing_error' => "PaymentIntent for refund_paystack_ref '{$refundId}' not found",
            ]);
            return null;
        }

        if ($intent->refund_settled_at === null) {
            $intent->update(['refund_settled_at' => now()]);
        }

        $event->update([
            'processed_at'      => now(),
            'payment_intent_id' => $intent->id,
        ]);

        return null;
    });
}
```

The `match` arm in `process()` becomes:
```php
return match ($event->event_type) {
    'charge.success'   => $this->handleChargeSuccess($event),
    'refund.processed' => $this->handleRefundProcessed($event),
    default            => $this->markNoOp($event),
};
```

Idempotent: if `refund_settled_at` already set, no-op. The webhook UNIQUE on `paystack_event_id` continues to guard replays at the storage layer.

## 6. Permission + 2FA

- `gateway.refund` already exists in the `permissions` table + `User::ROLE_PERMISSIONS` (F4). Currently granted only to super_admin via the `'*'` wildcard.
- F4-R seeds the slug explicitly to `finance_officer` (no other role).
- POST endpoint gated with `2fa:fresh` middleware.

`RolePermissionSeeder` change:
- Already present in `PERMISSIONS`: `'gateway.refund' => ['Finance', 'Refund a processed Paystack payment']`.
- Add to `'finance_officer'` block: `'gateway.refund'`.

`User::ROLE_PERMISSIONS` lock-step mirror in `app/Models/User.php`.

## 7. Routes

Inside the existing `Route::prefix('finance')` group:

```
POST /finance/payment-intents/{paymentIntent}/refund   gateway.refund + 2fa:fresh   RefundController@store
```

Single route. Index/show stay on the existing F4 controller — the refund button reads existing data; only the POST mutates.

## 8. Form Request

`StoreRefundRequest`:

```php
public function authorize(): bool
{
    return $this->user()?->hasPermission('gateway.refund') === true;
}

public function rules(): array
{
    return [
        'reason' => ['required', 'string', 'min:5', 'max:500'],
    ];
}
```

`min:5` discourages accidental empty refunds; `max:500` matches the DB column width.

## 9. Controller

`RefundController` — single action:

```php
public function store(StoreRefundRequest $request, PaymentIntent $paymentIntent): RedirectResponse
{
    try {
        $this->refunds->refund($paymentIntent, $request->user(), $request->validated('reason'));
    } catch (DomainException $e) {
        return back()->withErrors(['reason' => $e->getMessage()]);
    } catch (PaystackException $e) {
        return back()->withErrors(['reason' => 'Paystack: ' . $e->getMessage()]);
    }

    return back()->with('success', 'Refund initiated. Settlement confirmation will arrive via webhook.');
}
```

No separate index/show — the refund result is visible on the existing `PaymentIntents/Show` page after the redirect.

## 10. UI

### 10.1 `PaymentIntents/Show.vue`
(F4 added the basic show; F4-R extends it.)

When `status === 'success'` and `refunded_at === null`:
- Render a "Refund" button next to the existing "Copy link" / status panel.
- Click opens a modal: reason textarea (required, min 5 chars) + Confirm/Cancel.
- Submit → POST `/finance/payment-intents/{id}/refund`.
- On success, page reloads; intent now shows status `Refunded`.

When `status === 'refunded'`:
- Hide the Refund button.
- Show a refund audit block: "Refunded {refunded_at} by {refunded_by.name} — {refund_reason} · Paystack ref {refund_paystack_ref} · {settled_at ? 'Settled ' + settled_at : 'Awaiting settlement'}".

### 10.2 `ArInvoices/Show.vue`
(F4 added the "Send Payment Link" button; F4-R adds a sibling.)

When the invoice has a linked `ar_receipt` AND that receipt has a linked `payment_intent` in `success` status:
- Render a "Refund Paystack payment" button next to "Send Payment Link".
- Click navigates to `finance.payment-intents.show` for the intent, where the operator completes the refund. Single source of UI complexity.

### 10.3 Sidebar
No change. Refunds are reachable from the existing Payment Links + AR Invoices pages.

## 11. Testing

| Test file | Subjects |
|---|---|
| `tests/Unit/Finance/EnumsF4RTest.php` | `PaymentIntentStatus::Refunded` case + label |
| `tests/Feature/Finance/F4RMigrationTest.php` | New 6 columns on `payment_intents`; nullability + index on `refund_paystack_ref` |
| `tests/Feature/Finance/F4RPermissionsSeedTest.php` | `gateway.refund` granted to `finance_officer` |
| `tests/Feature/Finance/PaystackGatewayServiceTest.php` | Extend with `refundTransaction()` cases (success returns expected shape; 4xx → `PaystackException`; pesewas conversion applied) |
| `tests/Feature/Finance/RefundServiceTest.php` | Happy path; refusal on non-success status; refusal on already-refunded; refusal on missing receipt; Paystack failure rolls back (no `refunded_at` set, receipt still processed); full integrity (`refunded_at`, `refund_paystack_ref`, status, receipt voided, invoice `amount_received` restored) |
| `tests/Feature/Finance/PaystackWebhookProcessorTest.php` | Extend with `refund.processed` happy path; unknown `refund_paystack_ref` records error; idempotency (re-firing the same event doesn't overwrite `refund_settled_at`) |
| `tests/Feature/Finance/RefundEndpointTest.php` | `finance_officer` with fresh 2FA can refund; without fresh 2FA blocked; `auditor` 403; reason required + min length; reason validation error returns to form |

Expected ~25 new tests; full Finance suite must remain green.

## 12. Risks and mitigations

| Risk | Mitigation |
|---|---|
| Paystack `/refund` returns 200 but settlement later fails on their side (sends `refund.failed` instead of `refund.processed`) | Map `refund.failed` to a `processing_error` recorded on the webhook event. Operator dashboard surface (future): a watcher for intents with `refunded_at != null && refund_settled_at == null && refunded_at < now - 24h`. Out of F4-R scope. |
| Operator triggers refund, Paystack POST succeeds, receipt void succeeds, then DB update fails (e.g., connection blip mid-transaction) | `DB::transaction` wraps the void + intent update; if either fails, both roll back. The Paystack `/refund` call IS already issued and irreversible — so on transaction rollback, the actual money is refunded but CIHRMS shows no refund. The webhook (`refund.processed`) will eventually arrive with a `refund_paystack_ref` we don't know about → recorded as "intent not found" error. Operator must manually reconcile. **Acceptable risk for F4-R: the race window is sub-second, and the audit log captures the orphaned refund event.** |
| Double-click on Refund button submits twice | Form submit disables the button via `form.processing`. Server-side: `RefundService` validation step 2 (`refunded_at === null`) catches the second call. |
| Reason text leaks PII into Paystack's dashboard | Reason is operator-supplied; default UI placeholder warns "Visible to Paystack support." Out-of-scope to redact. |
| Webhook arrives BEFORE the synchronous response sets `refund_paystack_ref` (very tight race) | The webhook handler looks up intents by `refund_paystack_ref`; if not yet populated, records "not found." Paystack retries webhooks for ~24h with exponential backoff, so the later replay will find it once the row is updated. Idempotency via `paystack_event_id` UNIQUE prevents duplicate processing. |

## 13. Acceptance criteria

F4-R is complete when:

1. All ~25 new tests pass; full Finance suite green (F1+F2+F3+F4+F4-R+F5 ≈ 280 tests).
2. Manual smoke: a Paystack-paid intent shows a Refund button → click → modal → submit → 2FA prompt if not fresh → success message → intent flips to `refunded` → linked AR receipt status flips to `voided` → invoice `amount_received` decreases → JE reversal visible at `journal.show`.
3. Manual smoke: simulate `refund.processed` webhook → `refund_settled_at` populates → UI shows "Settled" badge.
4. Operator without `gateway.refund` (e.g., `auditor`) gets 403 on the POST.
5. Operator with `gateway.refund` but stale 2FA gets bounced through the 2FA flow.
6. `JournalPostingService` unmodified (diff against main is empty for that file).
7. `ArReceiptService::void()` unmodified (F4-R uses it, doesn't change it).
8. `Crypt::encrypt`-style transaction-abort issues from PR #18 don't re-appear (CI Postgres job green).

## 14. Out-of-scope follow-ups (post F4-R)

- Stale-refund watcher (alert when `refunded_at` set but `refund_settled_at` still null after 24h).
- `refund.failed` webhook handler (mark the intent as needing manual intervention rather than `refunded`).
- Bulk refund UI for the rare "we need to refund everyone for May" scenario.
- Partial refunds — would need to revisit the F3 receipt-void flow to support partial reversal, or post a compensating partial JE.

## 15. Related

- F4 (`project_finance_f4.md`) — original Paystack gateway, where this refund flow was deferred
- F3 (`project_finance_f3.md`) — `ArReceiptService::void()` (the reversal mechanism F4-R leans on)
- F2 (`project_finance_f2.md`) — `JournalPostingService::reverse()` (invoked transitively via `void()`)
- `cihrms-architecture-patterns` — Enum → Migration → Model → Service → FormRequest → Controller pattern
- `cihrms-rbac-system` — RBAC layer; `gateway.refund` already exists, F4-R only adjusts the grant set
