# Chapter 37 — The Canonical Pattern (Enum → FormRequest → Service → Event → Listener → Resource)

> Every new module added to CIHRMS, from Leave through Finance to Whistleblower, is built on the same six-layer skeleton. The convention is older than the codebase memory file that records it (`project_architecture.md`, 2026-05-13) and it now binds 30-odd modules together. The skeleton is not enforced by tooling — it is enforced by PR review, by the test patterns in Chapter 43, and by the fact that anyone who deviates ends up rewriting the deviation when the audit chain or the analytics fan-out turns out to need what the convention provided. This chapter walks the layers, justifies each one, shows a complete worked example through the Leave domain, and is honest about the places the pattern bends.

---

## 37.1  The pattern in one diagram

```
HTTP request
     │
     ▼
 ┌─────────────────────────────────────────────────────────────────┐
 │  Controller (thin)                                              │
 │  - typehints the FormRequest                                    │
 │  - delegates to a Service                                       │
 │  - returns a Resource / Inertia render / RedirectResponse       │
 └─────────────────────────────────────────────────────────────────┘
     │
     ▼
 ┌─────────────────────────────────────────────────────────────────┐
 │  1. FormRequest                                                 │
 │     - authorize()  → row-level RBAC, uses hasPermission()       │
 │     - rules()      → Rule::enum(...) for any closed vocabulary  │
 └─────────────────────────────────────────────────────────────────┘
     │
     ▼
 ┌─────────────────────────────────────────────────────────────────┐
 │  2. Service                                                     │
 │     - wraps writes in DB::transaction()                         │
 │     - calls SequenceService::next() for refs                    │
 │     - dispatches Events on success                              │
 │     - never validates, never authorises                         │
 └─────────────────────────────────────────────────────────────────┘
     │
     ▼
 ┌─────────────────────────────────────────────────────────────────┐
 │  3. Enum (cast on the Model)                                    │
 │     - closed vocabulary for status / type / category            │
 │     - label() method for the UI                                 │
 └─────────────────────────────────────────────────────────────────┘
     │
     ▼
 ┌─────────────────────────────────────────────────────────────────┐
 │  4. Event (Dispatchable + SerializesModels)                     │
 │     - readonly public properties                                │
 │     - actor + entity, nothing else                              │
 │     - contract for downstream consumers, not a function call    │
 └─────────────────────────────────────────────────────────────────┘
     │
     ▼
 ┌─────────────────────────────────────────────────────────────────┐
 │  5. Listener (ShouldQueue)                                      │
 │     - $queue = 'analytics' | 'integrations' | 'notifications'   │
 │     - matches the event by instanceof, writes the side effect   │
 │     - never blocks the request                                  │
 └─────────────────────────────────────────────────────────────────┘
     │
     ▼
 ┌─────────────────────────────────────────────────────────────────┐
 │  6. Resource                                                    │
 │     - shapes the wire payload                                   │
 │     - drops sensitive fields the viewer can't see               │
 │     - relations behind whenLoaded() to avoid N+1                │
 └─────────────────────────────────────────────────────────────────┘
     │
     ▼
 Inertia / JSON response
```

The numbering above is the *logical* order — what fires when an HTTP request walks through the system. The *authoring* order is the same: enum first (the vocabulary), FormRequest second (the gate), Service third (the operation), Event fourth (the announcement), Listener fifth (the consequence), Resource sixth (the projection back out). New modules are reviewed against this order; a PR that delivers a Service before its Enum is in landed code is asked to fix the ordering, not because it would not work, but because the Enum becomes the spec for everything else.

---

## 37.2  Why each layer exists

**Enums (`app/Enums/`, 88 files).** Every closed vocabulary in the system is an enum, not a string and not a database lookup table. Leave statuses are `pending|approved|rejected` because `LeaveStatus::cases()` says so; payroll cycle states, loan statuses, ticket priorities, asset conditions, benefit claim outcomes — all the same. The enum is the source of truth for both the database (via the `Eloquent` `casts()` array) and the FormRequest validator (via `Rule::enum(...)`). When a new status is needed you add a case to one file and the validator, the database column, the resource, and the analytics listener all pick it up. The `label()` method on each enum is the only place the human-facing string lives — the UI never hard-codes "Pending"; it reads `status_label` off the resource, which read `LeaveStatus::label()` off the enum. This is the single largest source of consistency in the codebase.

**FormRequests (`app/Http/Requests/`, 130 files).** Every mutating route is fronted by a typed FormRequest, and the controller method signature is what triggers Laravel to resolve, validate, and authorise it before the controller body ever runs. The FormRequest does two jobs and no others: `authorize()` checks the per-row RBAC gate (the coarse `permission:leave.approve` middleware is at the route level; the FormRequest's `authorize()` is for the gate that needs the actual entity, e.g. "can this user approve *this* leave?"), and `rules()` validates the input. Business rules never appear here — "can the requested leave dates be in the past" is a validator rule because it is input shape; "does the requester have enough remaining annual balance" is a Service concern because it requires a database lookup of state. The split keeps the validator side-effect-free.

**Services (`app/Services/`, 121 files).** A Service is where all the business logic for a domain lives. It is a plain PHP class, constructor-injected into the controller, with public methods that take FormRequests (or primitives) and return Models, Resources, or DTOs. Services wrap writes in `DB::transaction()`, call `SequenceService::next()` for any reference numbers, and end the success branch with `event(new SomethingHappened(...))`. The convention is: if a controller is reaching into Eloquent to write, that is a bug. The 124 / 121 ratio between Models and Services from Chapter 36 is not a coincidence — almost every domain Model has a Service that owns its writes. Services do not validate (the FormRequest already did) and they do not authorise (the FormRequest's `authorize()` and any route middleware already did). They do one thing: apply the operation atomically and announce it.

**Events (`app/Events/`, 62 files).** Domain events are the application's outbound contract. They carry the entity (a readonly model property) and the actor (a readonly nullable User) and nothing else — no rendered HTML, no computed totals, no preformatted notification body. The dispatcher does not know what listeners exist; the listeners do not know whether they have peers. This means a new analytics requirement, a new webhook subscriber, a new notification channel can be added by writing one new listener and registering it — the Service that fires `LeaveRequested` is untouched. The codebase has 62 events and only 16 listeners precisely because many events have *no current consumer*: they exist for `FanOutWebhooks` (which broadcasts every domain event to subscribed integrators) and for future analytics dimensions. The convention is to fire the event even if nothing listens to it; the cost is a single object allocation and the benefit is that adding a consumer later does not require revisiting the writer.

**Listeners (`app/Listeners/`, 16 files).** Listeners are the in-process consumers of events. Every one of them implements `ShouldQueue` and sets `$queue` to one of `analytics`, `integrations`, `notifications`, `identity`, or omits it (defaulting to `default`). The base contract is `handle(SomeEvent $event): void` — pull what you need off the readonly properties, do the side effect (write a row, call a third party, send a notification, mint a journal), let the queue worker take the latency hit instead of the user. The single fattest listener — `RecordAnalyticsEvent` — does a `match(true)` over `$event instanceof X` and writes one row to `analytics_events` per event type. Listeners never throw on validation errors (the data is already validated); they swallow third-party failures into structured logs and rely on the queue's retry envelope to do the rest.

**Resources (`app/Http/Resources/`, 77 files).** Resources are the *outbound* shape contract — the dual of FormRequests. They turn a Model into the JSON the Inertia page (or the API consumer) receives. The convention has three parts: cast scalar properties through their enums to surface both the raw value and the `label()` (`'status' => $this->status?->value, 'status_label' => $this->status?->label()`); guard relations behind `whenLoaded()` to avoid N+1 surprises if the caller forgot to eager-load; and *omit* sensitive fields entirely (not just `null` them) when the policy says the viewer cannot see them. The fact that the field does not exist on the wire is the same statement as the policy that hid it — there is no client-side guard to forget.

---

## 37.3  Worked example: a Leave request, end to end

This is the canonical example for the pattern. The Leave module is small enough to fit in this chapter, old enough to have settled (it was written in 2026-04 and has not been substantially rewritten since), and exercises every layer.

### 37.3.1  Layer 1 — Enums

`app/Enums/LeaveStatus.php`:

```php
<?php

namespace App\Enums;

enum LeaveStatus: string
{
    case Pending  = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match($this) {
            self::Pending  => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }
}
```

`app/Enums/LeaveType.php`:

```php
<?php

namespace App\Enums;

enum LeaveType: string
{
    case Annual    = 'annual';
    case Sick      = 'sick';
    case Maternity = 'maternity';
    case Paternity = 'paternity';
    case Unpaid    = 'unpaid';
    case Emergency = 'emergency';
    case Study     = 'study';

    public function label(): string
    {
        return match($this) {
            self::Annual    => 'Annual Leave',
            self::Sick      => 'Sick Leave',
            self::Maternity => 'Maternity Leave',
            self::Paternity => 'Paternity Leave',
            self::Unpaid    => 'Unpaid Leave',
            self::Emergency => 'Emergency Leave',
            self::Study     => 'Study Leave',
        };
    }
}
```

Two facts to notice. First, the enum is the *only* place the string value, the case name, and the human label are co-located. Second, both enums become casts on `App\Models\LeaveRequest`:

```php
protected function casts(): array
{
    return [
        'start_date' => 'date',
        'end_date'   => 'date',
        'status'     => LeaveStatus::class,
        'type'       => LeaveType::class,
    ];
}
```

— which means anywhere downstream that reads `$leave->status` gets a `LeaveStatus` instance, not a string. The model's `scopePending()` filter (`->where('status', LeaveStatus::Pending)`) works because Laravel's query builder casts the enum to its scalar value at the boundary.

### 37.3.2  Layer 2 — FormRequest

`app/Http/Requests/Leave/StoreLeaveRequest.php`:

```php
<?php

namespace App\Http\Requests\Leave;

use App\Enums\LeaveType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('leave.request');
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'exists:employees,id'],
            'type'        => ['required', Rule::enum(LeaveType::class)],
            'start_date'  => ['required', 'date', 'after_or_equal:today'],
            'end_date'    => ['required', 'date', 'after_or_equal:start_date'],
            'reason'      => ['nullable', 'string', 'max:2000'],
        ];
    }
}
```

`Rule::enum(LeaveType::class)` is the link back to the enum — there is no separate `in:annual,sick,...` constant to drift out of sync. The validator will reject any value not in `LeaveType::cases()`, and adding a new case (say `LeaveType::Bereavement`) immediately admits it without touching this file.

Sibling FormRequest `UpdateLeaveStatusRequest`:

```php
class UpdateLeaveStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('leave.approve');
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(LeaveStatus::class)],
        ];
    }
}
```

Two FormRequests, two permissions. `leave.request` is what a normal employee carries; `leave.approve` is HR / manager. The controller method signatures decide which gate fires — they never collapse into one polymorphic endpoint.

### 37.3.3  Layer 3 — Controller (thin)

`app/Http/Controllers/LeaveRequestController.php` is what the routes resolve to. The two write methods are five lines each:

```php
public function store(StoreLeaveRequest $request): RedirectResponse
{
    $this->leaves->request($request);

    return back()->with('success', 'Leave request submitted successfully.');
}

public function updateStatus(UpdateLeaveStatusRequest $request, LeaveRequest $leaveRequest): RedirectResponse
{
    $this->leaves->updateStatus($request, $leaveRequest);

    return back()->with('success', 'Leave status updated.');
}
```

The controller's job is to wire the typed FormRequest to the Service and the result to the response. It does not validate (already done), it does not authorise (already done), it does not write (the Service will), it does not transform (the Resource will). The Service is constructor-injected:

```php
public function __construct(private readonly LeaveService $leaves) {}
```

The `destroy()` method is the only place the controller does its own work, and it does the minimum — call `$this->authorize('cancel', ...)` against the Policy and then `$leaveRequest->delete()`. That delete is a soft delete because `LeaveRequest` uses the `SoftDeletes` trait (§37.5), so the cancellation is reversible and audit-visible.

### 37.3.4  Layer 4 — Service

`app/Services/LeaveService.php`:

```php
class LeaveService
{
    public function request(StoreLeaveRequest $request): LeaveRequest
    {
        $leave = LeaveRequest::create($request->validated());

        event(new LeaveRequested($leave, $request->user()));

        return $leave;
    }

    public function updateStatus(UpdateLeaveStatusRequest $request, LeaveRequest $leaveRequest): LeaveRequest
    {
        $status = LeaveStatus::from($request->validated('status'));

        DB::transaction(function () use ($status, $leaveRequest, $request) {
            $leaveRequest->update([
                'status'      => $status,
                'approved_by' => $status === LeaveStatus::Approved ? $request->user()->id : null,
            ]);

            if ($status === LeaveStatus::Approved) {
                $balance = LeaveBalance::lockForUpdate()->firstOrCreate(
                    [
                        'employee_id' => $leaveRequest->employee_id,
                        'type'        => $leaveRequest->type->value,
                        'year'        => $leaveRequest->start_date->year,
                    ],
                    ['total_days' => 21.0, 'used_days' => 0.0]
                );
                $balance->increment('used_days', $leaveRequest->durationInDays());
            }
        });

        event(new LeaveStatusUpdated($leaveRequest, $request->user()));

        return $leaveRequest;
    }
}
```

A few things to note in this 30-line block, because they are the convention writ small:

1. **`request()` is the trivial case** — one insert, one event, return. There is no transaction wrapper because there is exactly one write. The Service still owns the event dispatch, not the controller; this matters when the next iteration adds (say) a balance check before insert and the controller stays untouched.
2. **`updateStatus()` is the non-trivial case.** It opens a `DB::transaction()` because it does two writes (the leave row update and the balance increment) and they must be atomic. The balance row is fetched with `lockForUpdate()` to prevent the concurrent-approve race, then `firstOrCreate`-ed with defaults if this is the employee's first leave of the year, then incremented. If anything throws inside the transaction, both writes roll back and the event does not fire.
3. **Event after, not during.** `event(new LeaveStatusUpdated(...))` fires *outside* the `DB::transaction` closure. This is deliberate — if the transaction rolls back, the event does not announce a state that was rolled back. The order is: write inside the transaction; commit; announce. A failure mode worth being aware of: if the process dies after commit but before `event()`, the side effects in the listeners (notification, analytics row, webhook fan-out) will not fire. The convention accepts that gap in exchange for never announcing a non-existent state.
4. **`LeaveStatus::from(...)` on the validated input.** The FormRequest accepts the string; the Service immediately hoists it to its enum form. Anywhere downstream that compares (e.g. `$status === LeaveStatus::Approved`) is comparing typed values, not stringly-typed strings.
5. **No validation in the Service.** `$request->validated()` is trusted. If the input is structurally bad it would have failed in the FormRequest and we would not be here. The Service's only failure modes are domain-level — a soft-deleted employee, a stale balance row, a database error — none of which are user-input shape.

### 37.3.5  Layer 5 — Events

`app/Events/LeaveRequested.php`:

```php
<?php

namespace App\Events;

use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LeaveRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly LeaveRequest $leaveRequest,
        public readonly ?User $actor,
    ) {}
}
```

Nine lines. The companion event `LeaveStatusUpdated` is identical in structure, with the same two readonly fields. The pattern is rigid on purpose: every event carries the entity (typed Model) and the actor (nullable User, because the system can act on its own behalf — scheduled jobs, identity verification listeners, the audit chain itself). The `SerializesModels` trait lets the event be queued safely; the worker re-resolves the model by primary key, so a stale serialized blob is impossible.

What the event does *not* carry: rendered notification bodies, computed deltas, "old" and "new" snapshots, or any data the listener could fetch off the entity itself. The discipline is that the listener can ask the entity for whatever it needs; the event is a pointer.

### 37.3.6  Layer 6 — Listeners

`LeaveRequested` has two listeners in the current build. The first is `RecordAnalyticsEvent` — a shared, polymorphic listener that handles every event the analytics layer cares about:

```php
class RecordAnalyticsEvent implements ShouldQueue
{
    public string $queue = 'analytics';

    public function handle(object $event): void
    {
        [$eventName, $meta] = match(true) {
            $event instanceof EmployeeCreated => [...],
            $event instanceof LeaveRequested => [
                'leave.requested',
                ['leave_id' => $event->leaveRequest->id, 'type' => $event->leaveRequest->type?->value],
            ],
            $event instanceof LeaveStatusUpdated => [
                'leave.status_updated',
                ['leave_id' => $event->leaveRequest->id, 'status' => $event->leaveRequest->status?->value],
            ],
            // ...two dozen more arms...
            default => [class_basename($event), []],
        };

        AnalyticsEvent::create([
            'user_id' => $event->actor?->id,
            'event'   => $eventName,
            'meta'    => $meta,
        ]);
    }
}
```

This is one of the few places where the codebase has a long `match` arm and accepts it: the alternative — one analytics listener per event — would mean 62 near-identical files. The polymorphic listener trades surface area for one-place-to-edit ergonomics. The `default` arm means a brand-new event without an arm in the match still writes a generic row with `class_basename`, so analytics is never silently lossy.

The second listener is `NotifyManagerOfLeaveRequest`:

```php
class NotifyManagerOfLeaveRequest implements ShouldQueue
{
    public string $queue = 'integrations';

    public function __construct(protected MessagingDispatcher $dispatcher) {}

    public function handle(LeaveRequested $event): void
    {
        $leave    = $event->leaveRequest->loadMissing(['employee.user', 'employee.manager.user']);
        $employee = $leave->employee;
        if (! $employee) return;

        $manager = $employee->manager?->user;
        // ...build the message body and params...

        if ($manager) {
            try {
                $this->dispatcher->send($manager, $body, [...]);
            } catch (\Throwable $e) {
                Log::warning('[messaging] manager notify failed', ['error' => $e->getMessage()]);
            }
        }

        if (config('integrations.feature_flags.slack_leave_approvals')) {
            $this->dispatcher->broadcast($body, [...]);
        }
    }
}
```

Two listeners, two queues. `$queue = 'analytics'` for the AnalyticsEvent write, `$queue = 'integrations'` for the messaging fan-out. The user-facing leave request returns before either runs. The HR officer who needs to see "leave requested" on the dashboard has their AnalyticsEvent row in well under a second; the Slack message to the manager arrives a few seconds later when the Slack API gets around to acknowledging it. Neither blocks the requester. And note the `try / catch` around the dispatcher call — the integration is allowed to fail (Slack down, Teams misconfigured, WhatsApp template revoked) and the listener swallows it into a `Log::warning` rather than throwing back into the queue retry envelope, because a retry storm on a misconfigured webhook is worse than a missed notification.

There is also a third, implicit consumer for every domain event: the `FanOutWebhooks` listener on the `default` queue. Any institute that subscribes to the `leave.requested` event via `/settings/api-tokens` will get a signed POST to their endpoint with the event payload. The Service did not need to know; the new subscription is just a row in `webhook_subscriptions`.

### 37.3.7  Layer 7 — Resource

`app/Http/Resources/LeaveRequestResource.php`:

```php
class LeaveRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'type'         => $this->type?->value,
            'type_label'   => $this->type?->label(),
            'start_date'   => $this->start_date?->toDateString(),
            'end_date'     => $this->end_date?->toDateString(),
            'duration_days' => $this->durationInDays(),
            'reason'       => $this->reason,
            'status'       => $this->status?->value,
            'status_label' => $this->status?->label(),
            'employee'     => $this->whenLoaded('employee', fn () => [
                'id'          => $this->employee->id,
                'employee_no' => $this->employee->employee_no,
                'position'    => $this->employee->position,
            ]),
            'approver' => $this->whenLoaded('approver', fn () => [
                'id'   => $this->approver->id,
                'name' => $this->approver->name,
            ]),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
```

The conventions visible in 25 lines:

- **Enum casts come out as both `value` and `label`.** The Vue side uses `value` for filtering and badge classes, `label` for display. The label is never computed in the component; the enum owns it.
- **Dates are ISO strings on the wire.** Carbon instances do not cross the network. `toDateString()` for date-only, `toISOString()` for timestamps.
- **`whenLoaded()` guards every relation.** If the controller forgot to eager-load `employee` (the `LeaveService::list()` query does), the resource omits the key entirely rather than triggering a per-row query for 20 paginated rows. The index view that this resource backs would otherwise be a 21-query N+1.
- **`durationInDays()` is a model method, not a resource calculation.** The resource trusts the model; the model trusts the database casts; the database trusts the FormRequest. Validation pushes up; transformation pulls down.

A note on sensitive-field guarding: this particular resource does not need it — a leave request's fields are non-sensitive — but `EmployeeResource` is the textbook case (§36.4): it omits `salary` entirely when `EmployeePolicy::viewSalary($user, $employee)` returns false. The key does not appear in the array; the Vue component sees `undefined` and renders nothing. The guard lives at the boundary, not in the page.

### 37.3.8  The complete path

Putting it together, an employee filing leave on 2026-05-25 walks this path:

1. Browser POSTs `/leave` with `{employee_id, type, start_date, end_date, reason}`.
2. `Authenticate` middleware resolves the session, `EnsurePermission('leave.request')` middleware lets them through.
3. `AuditTrail` middleware captures the request shape post-response (the payload is sanitised — see §36.4).
4. Laravel resolves `StoreLeaveRequest` for the controller method signature: `authorize()` confirms `hasPermission('leave.request')`, `rules()` validates the enum value, the date ordering, the reason length.
5. `LeaveRequestController::store(StoreLeaveRequest $request)` calls `$this->leaves->request($request)`.
6. `LeaveService::request()` inserts the row (status defaults to `Pending` via DB default), fires `LeaveRequested`.
7. Two listeners pick it up: `RecordAnalyticsEvent` on `analytics`, `NotifyManagerOfLeaveRequest` on `integrations`. A third subscriber, `FanOutWebhooks`, picks it up on `default` if any webhook is subscribed.
8. The controller returns `back()->with('success', ...)`.
9. The original Inertia visit resolves with the new `leaves` collection on the next render of `Leave/Index`, each row shaped by `LeaveRequestResource`.

The HR officer who has to approve it then takes the symmetric path through `UpdateLeaveStatusRequest` → `LeaveService::updateStatus()` → `LeaveStatusUpdated` → analytics + notification. Both listeners run async on their named queues. The hash chain in `audit_logs` (Chapter 40) gets a row for each mutating HTTP request along the way.

---

## 37.4  What does NOT go where

The pattern is most useful as a set of negative rules — places the layers are not allowed to creep into each other.

| Layer | NEVER contains | Reason |
|---|---|---|
| Controller | Direct Eloquent writes; `DB::transaction(...)`; business logic (balance maths, status transitions, journal posting). | The controller is one of two surfaces (web + API). Logic in the controller has to be duplicated when the API needs the same operation. |
| Controller | Validation rules or `validate()` calls. | The FormRequest exists for this; doing it twice is a sign the FormRequest is missing. |
| FormRequest | Database lookups beyond `exists:` validators. | The validator runs *before* the Service, so it does not know about uncommitted state; doing lookups here means the same query runs twice. |
| FormRequest | Business rules (balance checks, dependency ordering, budget thresholds). | These are domain-level; they belong to the Service and need to be tested with a transactional fixture. |
| Service | `request()->validate()` or `$this->authorize()`. | Both already happened. Repeating them couples the Service to the HTTP boundary, making it un-callable from a console command or a scheduled job. |
| Service | Building notification text or webhook payloads. | These are presentation concerns. The Service fires an event; the listener formats. |
| Service | Returning rendered JSON or Inertia responses. | Services return Models, DTOs, or paginators. The controller wraps them in Resources or `Inertia::render`. |
| Event | Computed values that the entity could re-derive. | The listener can ask the model; pre-computing means the event becomes a leaky cache. |
| Event | Constructor logic beyond property assignment. | An event is a value; if it needs to *do* something, that thing belongs to the Service or the listener. |
| Listener | Throwing on business-rule failures. | The data has been validated and committed. A listener that throws ends up retried by the queue; a retry storm on a misconfigured Slack endpoint is the worst kind of side-effect debugging. Listeners log and move on. |
| Listener | Reading from one event and writing to another via `event()`. | Event chains hide flow. If the Service that fired `Foo` also wanted to fire `Bar`, fire both in the Service. |
| Resource | Database queries that were not eager-loaded. | The N+1 footgun. `whenLoaded()` is the guard. |
| Resource | Policy checks via `Gate::allows()`. | The resource gets the request; check `request()->user()->can(...)` instead, and prefer omitting the key over rendering `null`. |
| Resource | Mutating the underlying model. | A resource is a view. If it needs to write, the Service should have done it. |

The cost of these rules is the duplication called out in Chapter 36 (124 Models vs 121 Services, 130 FormRequests vs 97 Controllers): more files, more boilerplate. The benefit is that a reviewer reading a Service knows where the validation lives (the FormRequest of the same name), where the transformer lives (the Resource of the same name), where the side effects fan out (the listeners that match the Event), and that nothing in the controller is doing work the controller should not be doing.

---

## 37.5  Soft deletes everywhere

Every core domain Model uses the `SoftDeletes` trait. `LeaveRequest` is typical:

```php
use Illuminate\Database\Eloquent\SoftDeletes;

class LeaveRequest extends Model
{
    use HasFactory, SoftDeletes;
    // ...
}
```

The migration has `$table->softDeletes()`. A `delete()` call writes the `deleted_at` timestamp; the row stays in the table, the default Eloquent query excludes it, and any approver, manager, or auditor pulling history can still see it via `withTrashed()`.

The convention is not absolute — pivot tables and write-once ledger tables (e.g. `journal_entries`, `audit_logs`, `payment_attempts`) deliberately do *not* soft-delete because the data is append-only by construction. But anything an end user creates, edits, or cancels — leave requests, employees, departments, courses, policies, tickets, complaints, loans — soft-deletes. The reasoning:

1. **The audit chain references entity IDs by primary key.** A hard delete would leave an `audit_logs` row pointing to a row that no longer exists. The soft delete keeps the referent alive.
2. **Reversibility is cheaper than recreation.** A leave request withdrawn in error can be `restore()`d in one line; a hard-deleted one has to be re-keyed and re-approved.
3. **Visibility scopes still apply on trashed rows.** A soft-deleted Employee is still scoped to their department for the manager who could see them when alive; a hard delete would mean "they were never here", which is the wrong story for a leaver who needs an exit interview, final payslip, and document handover.

The trade-off is the obvious one: indexes get larger, the `WHERE deleted_at IS NULL` clause shows up on every query, and a careless `firstOrCreate` against a soft-deleted-but-not-purged row hits the "duplicate" arm because the lookup excluded it. The codebase pays the carry cost.

---

## 37.6  SequenceService — the only way to mint a reference number

Anything the system mints a human-facing reference for — invoice numbers, payment references, journal entry numbers, expense claim numbers, loan account numbers, off-boarding case IDs — goes through `App\Services\Finance\SequenceService::next($key)`:

```php
class SequenceService
{
    public function next(string $key): int
    {
        return DB::transaction(function () use ($key) {
            $row = DB::table('finance_sequences')
                ->where('key', $key)
                ->lockForUpdate()
                ->first();

            if (!$row) {
                DB::table('finance_sequences')->insert([
                    'key'           => $key,
                    'current_value' => 0,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
                $current = 0;
            } else {
                $current = (int) $row->current_value;
            }

            $next = $current + 1;

            DB::table('finance_sequences')
                ->where('key', $key)
                ->update(['current_value' => $next, 'updated_at' => now()]);

            return $next;
        });
    }
}
```

The convention is documented in `project_finance_sequences.md` (PR #21, 2026-05-22) and is now enforced in PR review: any new module that wants a reference number must inject `SequenceService` and call `next($key)`, then format the resulting integer with whatever prefix/padding the domain requires (`INV-2026-000123`, `JE-2026-04-0007`, etc.). The `key` is per-stream (`invoice`, `journal_entry_2026_04`, `loan_account`).

What this replaces: the older convention, removed in the May 2026 V2 audit, was `Model::where(...)->count() + 1`. That is unsafe under any concurrency — two requests count `42`, both write `43`, the unique index fires on the second one, the second user sees a 500. The `lockForUpdate()` inside a `DB::transaction()` is what closes the race; the row-level lock on `finance_sequences` serialises the increment for any given key without serialising the rest of the request.

The Leave module currently does *not* mint a reference number — leave requests are identified by their auto-increment ID and the requester's name on screen, not by a public ref. But the moment Leave grows a "case number" that gets quoted in a payroll proration or a letter to a department head, that case number will come from `SequenceService::next('leave_case_2026')`, not from `LeaveRequest::count() + 1`. The convention applies prospectively.

---

## 37.7  Where the pattern bends, and why

The convention covers the 95% case. There are five named bends in the current codebase; each is documented here because a reader who sees them in isolation needs to know they are exceptions, not new defaults.

**1. Webhook controllers — `App\Http\Controllers\Webhooks\WebhookController` and friends.** Inbound webhooks (Paystack, Zoho, third-party providers) cannot present a Laravel session, so they bypass `auth`. They also cannot include a CSRF token, so the route excludes CSRF. The `VerifyWebhookSignature` or `VerifyPaystackSignature` middleware substitutes for both — the signature is the bearer of identity. The controller still delegates to a Service (`ProcessPaystackWebhookJob`, dispatched from the handler) and the rest of the chain (events, listeners, resources where applicable) runs the same. What is bent is the *front gate*: no FormRequest `authorize()`, no `permission:` middleware. The signature middleware is the authorisation.

**2. SAML ACS endpoint.** Same shape as a webhook — the IdP POSTs an XML assertion to `/auth/sso/{slug}/callback`. `bootstrap/app.php` lists this as the documented CSRF exception (`validateCsrfTokens(except: ['auth/sso/*/callback'])`). The XML signature verification inside `SamlSsoAdapter` is what protects the route. No FormRequest fronts it.

**3. `App\Http\Controllers\PublicDpaController`.** Data Protection Act enquiries are submitted by *non-users* — a member of the public exercising their right of access or erasure. The route is unauthenticated by necessity. There is still a FormRequest validating the input, but `authorize()` returns `true` because there is no user to gate. CAPTCHA and rate limiting (declared in the route group) substitute for `auth`. The Service path downstream is identical to any internal request.

**4. `App\Http\Controllers\KioskController` — auth by staff-name substring.** Wall-mounted clock-in kiosks present an enrolment-grade barrier (a stripped-down browser on a known device IP) but the user identifies themselves by typing the start of their name and selecting from a 5-row suggest. There is no password challenge — the kiosk authenticates by physical access, the user identifies by substring. `KioskVerifyRequest` and `KioskClockRequest` validate the input shape and pin the kiosk session; the Service (`AttendanceService`) is the same one the desktop attendance flow uses, so the audit chain captures the kiosk-mediated clock-in identically to a desktop one. What is bent is the per-action `authorize()` — the kiosk session is the gate, not a per-row permission.

**5. The polymorphic analytics listener.** `RecordAnalyticsEvent::handle(object $event)` typehints `object` rather than a specific event class. This is the only listener in the codebase that does so, and it is a deliberate compromise: writing one analytics listener per event would be 62 files of near-identical insert-into-`analytics_events` logic. The `match(true) { $event instanceof X => ... }` arm is the cost; the upside is that the analytics surface is one file to edit when a new event needs an analytics row, not 62 files to inspect for stale patterns. The general rule still holds — every other listener takes a single concrete event type — but the analytics aggregator was given an exemption.

A sixth, narrower deviation worth mentioning: some Services *do* run console-side work via `Schedule::command(...)` rather than HTTP. The Service interface is the same (constructor-injected, transactional, event-dispatching), but the entry point is `app/Console/Commands/*` instead of a controller. Examples: `users:issue-password-resets`, `payroll:close-cycle`, `audit:verify`. The skeleton from §37.1 is unchanged — there is no FormRequest, but every other layer is in place. The command class is the controller-equivalent.

---

## 37.8  How tests target each layer

Chapter 43 is the full testing chapter; this section is the slice of it that maps to the canonical pattern.

The codebase leans heavily on Feature tests (182 vs 10 Unit, per Chapter 36) precisely because the pattern's value is the chain, not the individual links. A Feature test against `tests/Feature/LeaveTest.php` covers the full skeleton in one HTTP call:

```php
test('employee can submit a leave request', function () {
    $response = $this->actingAs($this->employeeUser)
        ->post(route('leave.store'), [
            'employee_id' => $this->employee->id,
            'start_date'  => now()->addWeek()->toDateString(),
            'end_date'    => now()->addWeek()->addDays(4)->toDateString(),
            'type'        => LeaveType::Annual->value,
            'reason'      => 'Family vacation',
        ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('leave_requests', [
        'employee_id' => $this->employee->id,
        'type'        => LeaveType::Annual->value,
        'status'      => LeaveStatus::Pending->value,
    ]);
});
```

This test exercises: route resolution → permission middleware → `StoreLeaveRequest::authorize()` (the `employee` role has `leave.request` from the role seeder) → `StoreLeaveRequest::rules()` (date format, enum, employee exists) → `LeaveRequestController::store()` → `LeaveService::request()` → DB insert → `LeaveRequested` event → success redirect. The downstream listeners run synchronously in tests by default (or are caught by `Event::fake()` if the test cares).

The companion test exercises the approval path:

```php
test('approving a leave request stamps approver and increments balance', function () {
    $leave = LeaveRequest::factory()->pending()->create([
        'employee_id' => $this->employee->id,
        'start_date'  => '2026-06-01',
        'end_date'    => '2026-06-05',
        'type'        => LeaveType::Annual->value,
    ]);

    $this->actingAs($this->hr)
        ->patch(route('leave.update', $leave), [
            'status' => LeaveStatus::Approved->value,
        ])
        ->assertRedirect();

    $leave->refresh();
    expect($leave->status->value)->toBe(LeaveStatus::Approved->value);
    expect($leave->approved_by)->toBe($this->hr->id);

    $balance = LeaveBalance::where('employee_id', $this->employee->id)
        ->where('type', LeaveType::Annual->value)
        ->where('year', 2026)
        ->first();

    expect($balance)->not->toBeNull();
    expect((float) $balance->used_days)->toBeGreaterThan(0);
});
```

This one rides the transaction-and-balance branch of `LeaveService::updateStatus()` and asserts both writes (status update and balance increment) and the approver stamp.

The layer-by-layer targeting falls out naturally:

- **Enum layer.** Unit-testable in isolation. `expect(LeaveStatus::Approved->label())->toBe('Approved')` is the smallest possible test; what matters is exhaustiveness — every case has a label.
- **FormRequest layer.** Targeted indirectly by Feature tests that POST invalid input and assert `assertInvalid(['type' => 'enum'])`. The validator's `Rule::enum(...)` is itself well-tested by the framework; we test our wiring of it.
- **Controller layer.** Targeted by the Feature test's HTTP assertion (`assertRedirect()`, `assertInertia(fn ($page) => $page->component('Leave/Index'))`). There are no controller unit tests in the codebase; the controllers are too thin to be worth isolating.
- **Service layer.** Most Services are *exercised* by their controller tests rather than tested in isolation. Where a Service has internal branching that the HTTP layer cannot easily reach — `EmployeeIdentifierService::nextStaffId()` is the classic example — there is a unit test. For LeaveService, the HTTP test covers both branches (`Approved` increments balance; `Rejected` does not, asserted by the third test in the file).
- **Event layer.** Tested by `Event::fake([LeaveRequested::class])` followed by `Event::assertDispatched(LeaveRequested::class, fn ($e) => $e->leaveRequest->id === $leave->id)`. The Leave suite does not currently fake events because the integration listeners do not crash the test; richer modules (Payroll, Identity) do fake to keep the test envelope tight.
- **Listener layer.** Listeners are unit-tested where their logic is non-trivial. `RecordAnalyticsEvent` has tests that construct events of each type and assert the `AnalyticsEvent` row shape. `NotifyManagerOfLeaveRequest` is exercised via a feature test that fakes the `MessagingDispatcher` and asserts the dispatched payload.
- **Resource layer.** Implicitly tested by `assertInertia` props assertions — if `LeaveRequestResource` stops including `status_label`, the Vue page would break and the Inertia prop assertion would too. Resources rarely get their own unit tests; their job is too thin and the integration assertions cover the shape.

The fourth test in the file confirms the index renders the Inertia component with the expected pagination shape:

```php
test('leave index renders inertia view with paginated leaves', function () {
    LeaveRequest::factory()->count(3)->create(['employee_id' => $this->employee->id]);

    $this->actingAs($this->hr)
        ->get(route('leave.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Leave/Index')
            ->has('leaves.data', 3)
        );
});
```

This is the test that catches a Resource that started returning a different shape or a controller that stopped wrapping the collection.

---

## 37.9  When the pattern is a poor fit

The honest version of this chapter would not end without saying where the convention rubs.

**Read-heavy modules.** Reports and analytics screens do not write, do not validate input (the filters are GET parameters), and do not need to fire events. The pattern still applies — there is a FormRequest for filter validation, a Service that owns the aggregation query, a Resource that shapes the output — but the Event and Listener layers are inert. A reader looking at `ReportService::auditChainSummary()` will see a Service with no `event()` call and no transaction wrapper, and that is fine. The convention is not "every layer must fire on every operation"; it is "every layer must be in the right place when it fires."

**Trivial CRUD.** Modules like `OrganizationProfileController` (a single-row settings panel) get the full stack — FormRequest, Service, Resource — but the Service is a one-method shim and the Event/Listener layers are absent because no downstream consumer cares about "the org name was changed." This is acceptable; the convention's value is consistency for readers, not strict adherence for its own sake.

**Computed properties on Resources.** Sometimes a derived field really does belong in the Resource because it depends on the request user, not the entity (e.g. "can this viewer edit this row"). The Resource is the right layer for that — it has the `Request` in hand and the resource is the projection back to the viewer. The discipline is to compute, not query: `$this->approver_id === $request->user()->id` is fine in a resource; reaching into the database for additional facts is not.

**Bulk operations.** Importing 2,000 employees from a CSV cannot fire 2,000 events without overwhelming the queue. The convention bends here too — `EmployeeImportService::import(UploadedFile $file)` runs the writes inside one transaction and fires a single `EmployeesBulkImported` event at the end, with the count and the import ID. Listeners that care can pull the import row to enumerate individual employees if they need to. This is a pragmatic deviation, called out in PR review when bulk operations land.

---

## 37.10  Reading on from here

The next chapter (38) walks the database schema that the Models in the canonical pattern map to — 116 migrations grouped by domain, the foreign-key map, and the soft-delete strategy in tabular form. Chapter 39 unfolds the RBAC layer that `FormRequest::authorize()` and `EnsurePermission` middleware sit on top of; Chapter 40 unfolds the audit chain that captures every Service write. Chapter 41 unfolds the queue infrastructure that the listeners ride on, including the retry envelope and the failed-job recovery flow.

A reader who has followed §37.3 end to end has read the canonical example of how every other module in the system works. From here, the chapters fill in the layers below and the operational machinery around.
