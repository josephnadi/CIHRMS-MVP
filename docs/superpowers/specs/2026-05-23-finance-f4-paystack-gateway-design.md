# Finance F4 — Paystack Online Card / Mobile Money Gateway Design

**Status:** Approved (design phase)
**Date:** 2026-05-23
**Owner:** finance_officer role / Finance department
**Depends on:** F1 (chart of accounts + bank accounts), F2 (journal posting engine), F3 (AR invoices + receipts + `ar_receipts.external_ref` column)
**Related memory:** [[cihrms-finance-f3-complete]], [[cihrms-finance-f2-complete]], [[cihrms-architecture-patterns]]

## 1. Context

[F1](2026-05-21-finance-f1-accounts-foundation-design.md) built the GL + bank-account schema. [F2](2026-05-22-finance-f2-accounts-payable-design.md) introduced the double-entry journal posting engine and AP. [F3](2026-05-22-finance-f3-accounts-receivable-design.md) added AR invoices, receipts, and customer statements — including the `ar_receipts.external_ref` column added specifically for F4's webhook payloads.

F4 plugs into F3's rails. A successful Paystack webhook → `ArReceiptService::record()` → existing AR receipt flow → existing journal engine. **No new accounting logic**. F4 adds:

- A thin Paystack API client (`PaystackGatewayService`)
- Payment-intent tracking (`payment_intents` table)
- A signed, idempotent webhook endpoint
- Finance-side UI to generate payment links

Paystack is Ghana's de-facto standard payment gateway. Native GHS support, MTN MoMo + AirtelTigo + VodafoneCash + card + bank transfer in one integration. CIHRMS already uses MTN MoMo / AirtelTigo / VodafoneCash for *outbound* disbursements in F1-era infrastructure; Paystack adds the *inbound* side.

## 2. Scope

### 2.1 In scope

- 2 new tables: `payment_intents`, `paystack_webhook_events`
- 1 new enum (`PaymentIntentStatus`)
- 3 new services: `PaystackGatewayService`, `PaymentIntentService`, `PaystackWebhookProcessor`
- 1 middleware: `VerifyPaystackSignature` for the webhook endpoint
- 2 controllers: `PaymentIntentController` (auth, finance), `PaystackWebhookController` (public, signature-verified)
- 3 new permissions: `gateway.view`, `gateway.create`, `gateway.refund` (refund is super_admin-only via wildcard in F4)
- 1 new Inertia page (`Finance/PaymentIntents/Index.vue`) + a "Send Payment Link" button on the AR Invoice Show page
- 1 sidebar entry: "Payment Links"
- Pest Feature tests covering: intent creation, signature verification, idempotent webhook, end-to-end charge.success → ArReceipt + invoice status flip, malformed/spoofed webhook rejection
- F1/F2/F3 forward-fixes applied: `2fa:fresh` middleware on `gateway.create` from day one

### 2.2 Out of scope (deferred to F4.1 or later phases)

- **Refunds** — Paystack has a separate `/refund` API; the JE pattern is `Dr Refund Expense, Cr Bank GL`. Adds an enum + service method + permission gate. Defer.
- **Recurring / saved cards / subscriptions** — F4 is one-shot payments only.
- **Customer-facing CIHRMS portal** — customers receive the payment link from the finance officer (email/WhatsApp/in person) and pay via Paystack's hosted page. No CIHRMS customer login for F4.
- **Multi-currency** — GHS only; F4 schema reserves the `currency` column for forward compatibility.
- **Partial payment across multiple invoices in one Paystack transaction** — F4 supports a payment intent allocating to exactly ONE invoice. Customers wanting to pay multiple invoices need separate transactions.
- **3-D Secure handling** — Paystack handles in their hosted flow.
- **Webhook event types other than `charge.success`** — F4 stores all events for audit but only acts on `charge.success`. Future phases can wire `transfer.success` (refund), `charge.failed`, etc.
- **Email delivery of payment links** — F4 returns the link to the operator; manual delivery (copy-paste). Email automation is F4.1 or later.
- **Sub-account / split payments** — used for multi-org Paystack scenarios. CIHRMS is single-org.
- **Inline / embedded card form** — F4 uses Paystack's HOSTED checkout (redirect-based) exclusively. Zero PCI scope on our side.
- **Reconciliation against Paystack settlement statements** — F5 will pick this up alongside bank reconciliation.

## 3. Data Model

### 3.1 Enum (`App\Enums\`)

**`PaymentIntentStatus`** — backed string enum with `label()` method.

```
created     — Intent row written; Paystack `transaction/initialize` call has NOT happened yet (transient state)
pending     — Paystack returned an authorization_url; awaiting customer payment
success     — Paystack confirmed payment via webhook; ArReceipt has been posted
failed      — Paystack reported charge.failed
abandoned   — Customer left the hosted page without paying (Paystack signals via webhook timeout)
expired     — Stale intent (>24h pending) cleaned up by scheduled job
```

Follows the established CIHRMS pattern: `declare(strict_types=1)`, namespace `App\Enums`, exhaustive `match` in `label()`.

### 3.2 Migrations (2 tables)

Migrations dated `2026_05_23_100001` and `2026_05_23_100002` (after F3's `2026_05_23_000001` etc.).

**`payment_intents`**

```
id (PK)
reference                 VARCHAR(40)   UNIQUE NOT NULL    -- auto-gen "PI-2026-000001"
customer_id               FK customers.id NOT NULL ON DELETE RESTRICT
ar_invoice_id             FK ar_invoices.id NULLABLE ON DELETE RESTRICT  -- nullable for "customer credit" intents (not used in F4 but reserved)
amount                    DECIMAL(18,2) NOT NULL
currency                  CHAR(3)       NOT NULL DEFAULT 'GHS'
status                    VARCHAR(20)   NOT NULL DEFAULT 'created'
paystack_reference        VARCHAR(100)  NULLABLE UNIQUE    -- Paystack's transaction reference (set after initialize)
paystack_access_code      VARCHAR(100)  NULLABLE
authorization_url         VARCHAR(500)  NULLABLE           -- the hosted checkout URL we display to operators
callback_url              VARCHAR(500)  NULLABLE           -- where Paystack redirects the customer after pay (defaults to a no-op "thanks" page)
ar_receipt_id             FK ar_receipts.id NULLABLE ON DELETE SET NULL  -- set when webhook posts the receipt
narration                 VARCHAR(500)  NULLABLE
paid_at                   TIMESTAMP     NULLABLE
expires_at                TIMESTAMP     NULLABLE           -- intents auto-expire after 24h pending
last_paystack_response    JSON          NULLABLE           -- last Paystack API response for debugging
created_by                FK users.id   NOT NULL
created_at, updated_at, deleted_at (SoftDeletes)
INDEX (status), INDEX (customer_id), INDEX (paystack_reference)
```

**`paystack_webhook_events`**

```
id (PK)
paystack_event_id         VARCHAR(100)  UNIQUE NOT NULL    -- the `data.id` from the webhook payload — idempotency key
event_type                VARCHAR(100)  NOT NULL           -- e.g. "charge.success", "charge.failed"
paystack_reference        VARCHAR(100)  NULLABLE INDEX     -- transaction reference if present in payload
payload                   JSON          NOT NULL           -- the full webhook body, parsed
signature                 VARCHAR(255)  NOT NULL           -- the x-paystack-signature header value (for audit)
payment_intent_id         FK payment_intents.id NULLABLE ON DELETE SET NULL  -- resolved during processing
ar_receipt_id             FK ar_receipts.id NULLABLE ON DELETE SET NULL       -- set if processing produced a receipt
processed_at              TIMESTAMP     NULLABLE           -- null = received but not yet handled
processing_error          TEXT          NULLABLE           -- if processing threw, the message lives here
received_at               TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
INDEX (event_type), INDEX (processed_at)
```

The UNIQUE on `paystack_event_id` is **the** idempotency guard. A replay attempt finds the existing row and short-circuits before any side effects.

### 3.3 Models

- `App\Models\PaymentIntent` — `HasFactory, SoftDeletes`. Casts: `status` to `PaymentIntentStatus`, `amount` decimal:2, `paid_at`/`expires_at` datetime, `last_paystack_response` array. Relations: `customer()`, `invoice()` (ArInvoice), `receipt()` (ArReceipt), `creator()` (User). Scope: `scopePending`, `scopeStale` (pending + older than 24h).
- `App\Models\PaystackWebhookEvent` — no `HasFactory`, no `SoftDeletes` (event log table; immutable). Casts: `payload` array, `received_at`/`processed_at` datetime. Relations: `paymentIntent()`, `receipt()`.

## 4. Architecture: the Paystack integration boundary

### 4.1 Hosted checkout — single design decision

We use Paystack's HOSTED checkout exclusively. No embedded card form, no inline JS widget. Why:

- Zero PCI scope on our side
- Paystack handles MoMo + card + bank + USSD in one flow
- 3-D Secure, OTP, retries, error states — all theirs
- Less front-end code to maintain
- Forward-compatible: when Paystack adds new payment methods (e.g. ApplePay), we get them for free

### 4.2 End-to-end flow

```
Finance officer                  CIHRMS                       Paystack                Customer
       │                            │                             │                       │
       │ 1. POST /finance/          │                             │                       │
       │    payment-intents         │                             │                       │
       │    {invoice, amount}       │                             │                       │
       │ ──────────────────────────▶│                             │                       │
       │                            │ 2. Create payment_intent    │                       │
       │                            │    (status=created)         │                       │
       │                            │                             │                       │
       │                            │ 3. POST /transaction/       │                       │
       │                            │    initialize               │                       │
       │                            │ ───────────────────────────▶│                       │
       │                            │                             │                       │
       │                            │ 4. {authorization_url,      │                       │
       │                            │     access_code, reference} │                       │
       │                            │◀─────────────────────────── │                       │
       │                            │                             │                       │
       │                            │ 5. Update intent            │                       │
       │                            │    (status=pending,         │                       │
       │                            │     paystack_reference,     │                       │
       │                            │     authorization_url)      │                       │
       │                            │                             │                       │
       │ 6. {payment_intent +       │                             │                       │
       │    authorization_url}      │                             │                       │
       │◀───────────────────────────│                             │                       │
       │                            │                             │                       │
       │ 7. Copy/share link         │                             │                       │
       │    with customer           │                             │                       │
       │ ──────────────────────────────────────────────────────────────────────────────────▶│
       │                            │                             │                       │
       │                            │                             │ 8. GET hosted page    │
       │                            │                             │◀──────────────────────│
       │                            │                             │                       │
       │                            │                             │ 9. Customer pays      │
       │                            │                             │    (MoMo/card/bank)   │
       │                            │                             │                       │
       │                            │ 10. POST /webhooks/paystack │                       │
       │                            │     (charge.success)        │                       │
       │                            │◀────────────────────────────│                       │
       │                            │                             │                       │
       │                            │ 11. VerifyPaystackSignature │                       │
       │                            │     middleware checks HMAC  │                       │
       │                            │                             │                       │
       │                            │ 12. Insert paystack_webhook │                       │
       │                            │     _event row (unique on   │                       │
       │                            │     paystack_event_id)      │                       │
       │                            │                             │                       │
       │                            │ 13. Dispatch                │                       │
       │                            │     ProcessPaystackWebhook  │                       │
       │                            │     job (queued)            │                       │
       │                            │                             │                       │
       │                            │ 14. Return 200 to Paystack  │                       │
       │                            │ ───────────────────────────▶│                       │
       │                            │                             │                       │
       │                            │ 15. Job processes:          │                       │
       │                            │     • find intent by ref    │                       │
       │                            │     • lockForUpdate intent  │                       │
       │                            │     • assert status=pending │                       │
       │                            │     • verifyTransaction()   │                       │
       │                            │       belt-and-braces       │                       │
       │                            │     • ArReceiptService::    │                       │
       │                            │       record() with         │                       │
       │                            │       external_ref =        │                       │
       │                            │       paystack_reference    │                       │
       │                            │     • intent.status=success │
       │                            │     • intent.ar_receipt_id  │
       │                            │     • event.processed_at,   │
       │                            │       event.ar_receipt_id   │
       │                            │                             │
       │                            │ 16. ArReceiptService posts  │
       │                            │     the receipt JE          │
       │                            │     (existing F3 logic)     │
```

### 4.3 Critical invariants

- **`ArReceiptService::record()` is the SOLE entry point for AR receipts.** F4 does NOT add a parallel "gateway receipt" path. The webhook processor calls the same service with the same arguments a finance officer would supply. The Paystack-specific data lives on `ar_receipts.external_ref` and on the `payment_intents` + `paystack_webhook_events` rows.
- **Webhook signature verification happens BEFORE payload parsing.** The middleware reads `Content-Length` raw body bytes, computes HMAC-SHA512 with the webhook secret, compares with the `X-Paystack-Signature` header. If mismatch → 400. The controller never sees malformed payloads.
- **Webhook is idempotent at the database layer.** The `paystack_webhook_events.paystack_event_id` UNIQUE constraint means a duplicate POST silently no-ops at INSERT time. The processor then sees `processed_at IS NOT NULL` on the existing row and short-circuits.
- **Webhook returns 200 even if processing fails downstream.** Paystack retries on non-2xx; if we return 500, Paystack will keep retrying. Instead we ALWAYS return 200 after persisting the event row, and the queued job handles processing asynchronously. Job failures are recorded in `processing_error` and surface in the Payment Intents page.
- **Verify-on-receive (belt-and-braces).** Inside the job, before calling `ArReceiptService::record()`, we call `PaystackGatewayService::verifyTransaction($paystack_reference)` to confirm Paystack itself agrees the transaction succeeded. This guards against malicious webhook spoofing past the signature check (e.g. if the secret leaks).

## 5. Services

### 5.1 `PaystackGatewayService`

Thin wrapper around the Paystack REST API. Uses Laravel's `Http` facade with `baseUrl` and `withToken`. Throws `PaystackException` on non-2xx responses; throws `PaystackUnreachableException` on connection failures.

```php
public function initializeTransaction(array $data): array
// POST /transaction/initialize
// $data: [email, amount (kobo/pesewas — *100), reference, callback_url, metadata]
// Returns: ['authorization_url', 'access_code', 'reference']

public function verifyTransaction(string $reference): array
// GET /transaction/verify/{reference}
// Returns the full transaction object including status, amount, customer, paid_at, channel

public function listEvents(?string $since = null): array
// GET /transaction (with date filter) — used by reconciliation job (F5+)
// Not required for F4 webhook flow but exposed here as a future-friendly seam
```

The service is responsible for amount conversion: Paystack works in pesewas (1 GHS = 100 pesewas); CIHRMS works in GHS. Conversion lives entirely in this service. Callers pass `float $ghs`; service multiplies by 100 and rounds.

### 5.2 `PaymentIntentService`

Application-side orchestration.

```php
public function __construct(
    private readonly PaystackGatewayService $gateway,
) {}

public function createForInvoice(
    ArInvoice $invoice,
    float $amount,
    User $creator,
    ?string $callbackUrl = null,
): PaymentIntent
```

Behavior:
1. Validates: invoice status ∈ {Approved, PartiallyPaid}; amount ≤ invoice.outstandingAmount(); customer has `email` (Paystack requires).
2. Inside `DB::transaction`:
   a. Creates `payment_intents` row with status = `created`, reference auto-gen.
   b. Calls `$this->gateway->initializeTransaction($paystackData)`.
   c. Updates intent: `paystack_reference`, `paystack_access_code`, `authorization_url`, `status = pending`, `expires_at = now()+24h`.
   d. Returns the intent (with `authorization_url` for the operator).
3. If gateway call throws, transaction rolls back; intent never goes to `pending`.

```php
public function expireStale(): int
// Marks all pending intents older than expires_at as 'expired'.
// Called by a scheduled job in app/Console/Kernel.php (cron every hour).
// Returns count of expired rows.
```

### 5.3 `PaystackWebhookProcessor`

The webhook event handler. Receives a previously-persisted `PaystackWebhookEvent` and processes it idempotently.

```php
public function __construct(
    private readonly PaystackGatewayService $gateway,
    private readonly ArReceiptService $receipts,
) {}

public function process(PaystackWebhookEvent $event): ?ArReceipt
```

Behavior:
1. If `$event->processed_at !== null`, return `$event->receipt` (idempotency short-circuit).
2. Switch on `$event->event_type`:
   - `'charge.success'` → call `$this->handleChargeSuccess($event)`
   - all other types → set `processed_at` to now, set `processing_error` to "no-op for type X", return null
3. `handleChargeSuccess`:
   a. Look up `PaymentIntent::where('paystack_reference', $event->paystack_reference)->lockForUpdate()->firstOrFail()`.
   b. If `intent.status === Success`, short-circuit (already processed via a different path — extremely unlikely but safe).
   c. If `intent.status` ∉ {Pending}, set `event.processing_error = "intent status {$intent->status->value}"` and return null. Do NOT post a receipt for a `failed`/`abandoned`/`expired` intent.
   d. Call `$this->gateway->verifyTransaction($event->paystack_reference)`. Assert `data.status === 'success'` and `data.amount === intent.amount * 100`. If mismatch → set `processing_error`, leave intent at `pending`, return null. Paystack will retry; if discrepancy persists, manual intervention.
   e. Resolve the receiving bank account: `OrgBankAccount::forPurpose(config('services.paystack.receipt_bank_purpose'))->active()->firstOrFail()`. If missing → throw with a clear message (the job will retry; the Hub will show a warning).
   f. Call `$this->receipts->record([...], $event->paymentIntent->creator)` with:
      - `customer_id` = intent.customer_id
      - `receipt_date` = today
      - `amount` = intent.amount
      - `org_bank_account_id` = the receiving bank account's id
      - `external_ref` = intent.paystack_reference
      - `narration` = "Paystack — {$intent->reference}"
      - `allocations` = [{ ar_invoice_id: intent.ar_invoice_id, allocated_amount: intent.amount }]
   g. Set `intent.status = Success`, `intent.paid_at = now`, `intent.ar_receipt_id = $receipt->id`.
   h. Set `event.processed_at = now`, `event.ar_receipt_id = $receipt->id`, `event.payment_intent_id = $intent->id`.
   i. Return `$receipt`.

All of (a)-(i) wrapped in `DB::transaction`. The receipt JE posts inside that transaction (via F3's `ArReceiptService` which has its own transaction — Laravel handles nested transactions correctly).

### 5.4 `VerifyPaystackSignature` middleware

Plain Laravel middleware. Reads the raw request body via `$request->getContent()`, computes HMAC-SHA512 with `config('services.paystack.webhook_secret')`, compares with `$request->header('x-paystack-signature')` using `hash_equals()`. If mismatch → return 400 with `{"error": "invalid_signature"}`. Otherwise pass through.

Registered as a route-level middleware (`paystack.signature`), not global.

## 6. Permissions

Add to `RolePermissionSeeder` and `User::ROLE_PERMISSIONS`:

| Slug | Group | Granted to |
|---|---|---|
| `gateway.view` | Finance | finance_officer, auditor |
| `gateway.create` | Finance | finance_officer |
| `gateway.refund` | Finance | super_admin only (via legacy `*` wildcard; not in finance_officer/auditor lists) |

3 slugs total. `auditor` gets 1 view-only.

**The webhook endpoint is PUBLIC** — no permission middleware. Authentication is via HMAC signature.

## 7. Routes

Inside the existing `Route::middleware(['auth', 'audit'])->group(...)` block, in the `finance.` prefix group:

```php
// F4 — Payment Intents (operator side)
Route::middleware('permission:gateway.view')->group(function () {
    Route::get('payment-intents', [PaymentIntentController::class, 'index'])->name('payment-intents.index');
    Route::get('payment-intents/{paymentIntent}', [PaymentIntentController::class, 'show'])->name('payment-intents.show');
});
Route::middleware(['permission:gateway.create', '2fa:fresh'])->group(function () {
    Route::post('payment-intents', [PaymentIntentController::class, 'store'])->name('payment-intents.store');
});
```

OUTSIDE the `auth` group (public, signature-verified):

```php
Route::post('/webhooks/paystack', [PaystackWebhookController::class, 'handle'])
    ->middleware(['paystack.signature', 'throttle:120,1'])
    ->name('webhooks.paystack');
```

The `paystack.signature` middleware alias is registered in `bootstrap/app.php`.

## 8. Configuration

`.env` keys:

```
PAYSTACK_URL=https://api.paystack.co
PAYSTACK_PUBLIC_KEY=pk_test_xxx
PAYSTACK_SECRET_KEY=sk_test_xxx
PAYSTACK_WEBHOOK_SECRET=whsec_xxx
PAYSTACK_RECEIPT_BANK_PURPOSE=receipts
PAYSTACK_CALLBACK_DEFAULT_URL=https://cihrm.example/payment-callback
```

`config/services.php` entry:

```php
'paystack' => [
    'url'                  => env('PAYSTACK_URL', 'https://api.paystack.co'),
    'public_key'           => env('PAYSTACK_PUBLIC_KEY'),
    'secret_key'           => env('PAYSTACK_SECRET_KEY'),
    'webhook_secret'       => env('PAYSTACK_WEBHOOK_SECRET'),
    'receipt_bank_purpose' => env('PAYSTACK_RECEIPT_BANK_PURPOSE', 'receipts'),
    'callback_default_url' => env('PAYSTACK_CALLBACK_DEFAULT_URL'),
],
```

Deployment notes:
- **Test mode toggle**: just use `pk_test_*` / `sk_test_*` keys.
- **Webhook URL** to register in Paystack dashboard: `https://<app-domain>/webhooks/paystack`.
- **Receiving bank**: at least one `org_bank_accounts` row must have `purpose = receipts`. The Hub will show a warning if missing (Task 13 of the plan).

## 9. Frontend (Inertia pages)

### 9.1 New page: `Finance/PaymentIntents/Index.vue`

Mirrors the AR Receipts page structurally. Columns: reference, customer, invoice, amount, status (chip), created_at, paid_at, action (copy link button).

Filter chips: All / Pending / Success / Failed / Abandoned / Expired.

"Send Payment Link" button opens a SlidePanel with:
- Customer picker (filtered to active customers with email)
- AR Invoice picker (filtered to status ∈ {Approved, PartiallyPaid} for the selected customer)
- Amount (defaults to invoice outstanding; editable down to allow partial payment via gateway)
- Optional narration
- "Generate Link" button → posts to `/finance/payment-intents` → on success, shows the `authorization_url` with copy-to-clipboard and a "Send via email/WhatsApp" hint (text only, not wired in F4)

### 9.2 Modification to `Finance/ArInvoices/Show.vue`

Add a "Send Payment Link" button next to the existing actions. Gated by `canCreatePayment` computed (uses `usePage()` like the other gates) AND invoice status ∈ {Approved, PartiallyPaid}. Click → POST `/finance/payment-intents` with `{ ar_invoice_id, amount: invoice.outstanding }` → shows the returned `authorization_url`.

### 9.3 Sidebar

Add one new entry under the existing Finance section: "Payment Links" → `finance.payment-intents.index`, gated by `can('gateway.view')`, icon `link`. Add `finance-payment-intents` to `SIDEBAR_ICON_COLORS` with `#3949ab` (matching the finance family).

## 10. FormRequests

**`StorePaymentIntentRequest`** (gates `gateway.create`):

```php
public function rules(): array
{
    return [
        'ar_invoice_id' => ['required', 'integer', 'exists:ar_invoices,id'],
        'amount'        => ['required', 'numeric', 'min:0.01'],
        'callback_url'  => ['nullable', 'url', 'max:500'],
        'narration'     => ['nullable', 'string', 'max:500'],
    ];
}
```

Additional service-level checks (in `PaymentIntentService::createForInvoice()`): invoice status ∈ {Approved, PartiallyPaid}; amount ≤ outstanding; customer.email is non-empty.

## 11. Resources

**`PaymentIntentResource`** — `id, reference, status (value+label), customer (id/code/name), invoice (id/reference), amount, currency, authorization_url, paystack_reference, paid_at, expires_at, ar_receipt_id, narration, created_at`.

## 12. Seeders

No new seeders. `RolePermissionSeeder` is extended with the 3 new perms.

## 13. Finance Hub upgrade

Add a small "Gateway Health" indicator to the Hub. Tile shows:
- ✅ green if at least one `org_bank_accounts` row has `purpose = receipts AND is_active = true`
- ⚠️ amber if the receipts bank is missing — clickable to take operator to bank accounts page

The check is implemented as a private method on `FinanceHubService::gatewayHealth(): array` returning `['status' => 'ok'|'missing_bank', 'message' => '...']`. Added to the `build()` return.

Not blocking F4 functionally (the webhook processor will throw if missing, which Paystack will retry), but operationally important.

## 14. Testing

Pest Feature tests under `tests/Feature/Finance/`:

**`PaymentIntentServiceTest.php`** — service-level:
- `createForInvoice()` happy path: creates intent with status = pending, paystack_reference populated, authorization_url returned
- Refuses if invoice status ∉ {Approved, PartiallyPaid}
- Refuses if amount > outstanding
- Refuses if customer has no email
- Paystack API call mocked via `Http::fake()`; verifies the correct payload (amount in pesewas, email, reference, callback_url, metadata)
- `expireStale()` flips pending+old intents to expired

**`PaystackWebhookProcessorTest.php`** — the core test:
- `charge.success` with matching intent → posts ArReceipt via service, sets external_ref, flips intent to success
- Same event re-processed → no-op, returns existing receipt
- `charge.success` with no matching intent (e.g. spoofed reference) → records the event with `processing_error`, no receipt
- `charge.success` with mismatched amount → `processing_error`, no receipt, intent stays pending
- `charge.failed` → no receipt; event recorded with no-op note
- Belt-and-braces: gateway `verifyTransaction` mocked to return success vs. mismatch; assert receipt posts only on success

**`PaystackWebhookEndpointTest.php`** — HTTP integration:
- Valid signature → 200 + event row persisted + job dispatched
- Invalid signature → 400 + no event row + no job dispatched
- Missing signature header → 400
- Replayed payload (same `data.id`) → 200 (idempotent), one event row only
- Throttle: 121st request in 60s → 429

**`PaymentIntentTest.php`** — controller / endpoint:
- `finance_officer` can list + create
- `auditor` can list (gateway.view) but not create (403)
- `employee` gets 403 on list
- `2fa:fresh` middleware on POST (assertion that endpoint exists with the middleware applied — full 2FA flow not exercised due to test fixture complexity, consistent with F3 deferral)

**`F4PermissionsSeedTest.php`** — the 3 new slugs exist + are granted correctly.

**`FinanceHubTest.php` extension** — assert `gatewayHealth` key present in the hub response.

Approximate new tests: ~15. Total Finance suite target: ~180.

## 15. Risks and Trade-offs

- **Webhook signing secret is critical.** Leakage means anyone can post arbitrary `charge.success` events. The belt-and-braces `verifyTransaction()` inside the processor catches this (Paystack would say `status != 'success'`), but **secret rotation** procedures are outside F4 scope. Document in `.env.example` that the secret must match the Paystack dashboard setting and be rotated on incidents.
- **Network unreliability with Paystack API.** `PaystackGatewayService` throws `PaystackUnreachableException` on timeouts. `PaymentIntentService::createForInvoice()` lets the exception propagate to the controller, which returns 503 with a clear message. Operator retries.
- **Webhook delivery is at-least-once.** Idempotency table protects. **Webhook processing CAN be at-most-once-effective**: if the job runs, posts the receipt, then crashes before updating the intent, on next run the intent is still `pending` and the receipt is duplicated. Mitigation: the receipt-post is INSIDE the same transaction that updates the intent status, so a crash rolls back the receipt. **Caveat**: if the queued job processes the event row but a subsequent attempt sees `processed_at IS NOT NULL` AND the intent at `pending`, manual reconciliation is needed. Add a Pest test that asserts intent and event are mutually consistent (both `processed`/`success` or both not).
- **Customer needs an email** for Paystack `transaction/initialize`. F3 customers may have null email. Service rejects with a clear error; operator must update the customer record first. Future enhancement: allow generic `noreply+cust_{id}@cihrm.example` fallback. Out of F4 scope.
- **Currency is hardcoded GHS.** Multi-currency Paystack accounts exist but require sub-account setup. F4 reads `intent.currency` but always sends `GHS` to Paystack. Schema reserves the column for forward compatibility.
- **Refunds via Paystack are NOT in F4.** Operator-initiated refunds (e.g. for duplicate payment) require contacting Paystack support OR using the Paystack dashboard. CIHRMS-side ledger correction: post a reversal of the AR receipt JE via `ArReceiptService::void()` (existing F3 capability) — but the cash is still in the org's bank until Paystack settles. F4.1 will wire the proper Paystack `/refund` API + a Refund Expense GL account.
- **Paystack's hosted page is a redirect, not an iframe.** Customer leaves CIHRMS. The `callback_url` brings them back — F4 sets this to `config('services.paystack.callback_default_url')` which is a static "Thank you for your payment" page (added in Task 12 of the plan). Future enhancement: per-invoice callback URLs.
- **Scheduled `expireStale()` job needs cron.** F4 adds the job to `app/Console/Kernel.php`. Deployments without cron will accumulate stale `pending` intents — they're harmless (no double-spend risk) but clutter the Payment Intents page. The job runs hourly.
- **`paystack_event_id` UNIQUE constraint catches replays at the DB layer**, but if Paystack ever sends two webhook deliveries with different event IDs for the same transaction (e.g. retry sends a NEW envelope with the same `data` but a new `data.id`), idempotency would break. Mitigation: the processor also checks `paystack_reference` against existing `ar_receipts.external_ref` — if a receipt already exists for this reference, short-circuit. Added to the processor's flow as a defense-in-depth check.

## 16. F1/F2/F3 lessons forward-applied

This is intentional documentation of what F4 does because of prior-phase reviews:

1. **`2fa:fresh` middleware on `gateway.create`** — from day one, applied at the route layer. F3's deferral of 2FA was a tracked debt; F4 doesn't repeat it.
2. **`gateway.refund` permission exists but is super_admin-only** — preserves the F2 lesson about restricting refund/write-off actions.
3. **Idempotency at the storage layer** (`paystack_webhook_events.paystack_event_id` UNIQUE) — explicit, not relying on application-only checks. Mirrors F2's "JE balance enforced at service AND has a contract test" pattern.
4. **`ArReceiptService::record()` is the single mutator** — F4 routes through it instead of writing receipts directly, mirroring F2's "JournalPostingService is the sole mutator of gl_account_balances" pattern.
5. **`lockForUpdate` on the intent row** during webhook processing — applies F3's `lockForUpdate` lesson to the new resource.
6. **Vendor-file patches forbidden** — F4 must not edit `vendor/` files. Webhook signature verification uses Laravel's own primitives.
7. **No `dd()`/`var_dump()`/`dump()` in committed F4 code** — explicitly checked in the final review per F1-F3 standard.

## 17. Acceptance criteria (F4 done means)

1. A `finance_officer` can generate a payment link for an Approved/PartiallyPaid AR invoice. The link is a Paystack-hosted URL.
2. The `payment_intents` row is created with status = `pending` and `paystack_reference` populated.
3. Posting a signed `charge.success` payload to `/webhooks/paystack` (Pest test fixture with valid HMAC) returns 200 and dispatches the queued job.
4. The queued job creates an `ArReceipt` via `ArReceiptService::record()` with `external_ref = paystack_reference`.
5. The AR invoice status flips to PartiallyPaid or Paid as appropriate.
6. The payment intent status flips to `success`; `paid_at` is set; `ar_receipt_id` links back to the receipt.
7. Re-posting the same `charge.success` event (idempotency test) returns 200 + creates NO second receipt.
8. Posting an unsigned or wrongly-signed webhook returns 400 + creates NO records.
9. The Payment Intents page lists all intents with status filter. `gateway.view` gates access. `auditor` can read; `employee` gets 403.
10. The webhook endpoint is reachable WITHOUT auth (no 302 redirect to login).
11. `2fa:fresh` middleware blocks `POST /finance/payment-intents` when 2FA assertion is stale.
12. `migrate:fresh --seed` does not error on F4 migrations (acknowledging the unrelated `departments_name_unique` Postgres bug). New tables exist; 3 new perm rows exist.
13. Pest Finance suite stays green: F1 49 + F2 80 + F3 36 + F4 ~15 ≈ **~180 tests**.

## 18. Out of scope (deferred to F4.1, F5, or later)

- Paystack refund API integration + Refund Expense GL
- Recurring / subscription billing
- Saved cards
- Customer-facing CIHRMS portal (login + invoice list + pay)
- Email / WhatsApp delivery of payment links
- Multi-currency support with FX conversion
- Sub-account / split payments for multi-org Paystack
- Inline / embedded card form (PCI scope)
- Paystack settlement reconciliation (F5)
- Bank statement → ArReceipt automatic matching (F5)
- Per-invoice callback URLs (F4 uses a single configured default)
- USSD-only payments without a hosted page (Paystack supports; not exposed in F4)
- Reattempt failed payments from the Payment Intents page (operator must create a new intent)
- Bulk payment link generation (one-at-a-time in F4)

## 19. References

- F3 spec: [docs/superpowers/specs/2026-05-22-finance-f3-accounts-receivable-design.md](2026-05-22-finance-f3-accounts-receivable-design.md)
- F3 plan: `docs/superpowers/plans/2026-05-23-finance-f3-accounts-receivable.md`
- Paystack API reference: https://paystack.com/docs/api (Transactions, Webhooks)
- HMAC-SHA512 webhook verification: https://paystack.com/docs/payments/webhooks#verifying-events
