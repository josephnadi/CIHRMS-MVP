# Onboarding Lifecycle — Design

**Date:** 2026-06-19
**Status:** Approved design — ready for implementation plan
**Context:** Gov-grade audit found onboarding is the largest functional hole — recruitment (job → applicant → offer → e-sign) and off-boarding are both built, but a new hire falls off a cliff between offer-acceptance and a productive first day. This adds a symmetric onboarding lifecycle that mirrors the off-boarding module.

## Decisions (locked)

| Decision | Choice |
|---|---|
| Case creation | **Manual + auto on hire** — a manual "Start onboarding" action AND auto-initiate when an `Employee` is created with a `hire_date` (via the existing `EmployeeCreated` event). Idempotent: one open case per employee. |
| Employee status | **No new status** — `EmployeeStatus` is untouched (stays Active on creation); onboarding progress lives entirely on the case. `complete()` just closes the case. (Avoids auditing every active-employee query.) |
| Task integration | **Sign-off + auto-enrol** — every task is a manual sign-off (like clearance), PLUS on initiate the hire is auto-enrolled in all published `CourseCategory::Onboarding` courses. Provisioning (IT/assets) stays a manual task owner sign-off. |
| Templates | Hardcoded `DEFAULT_ONBOARDING_TEMPLATE` (mirrors off-boarding's `DEFAULT_CLEARANCE_TEMPLATE`); configurable per-role templates are out of scope. |

## Architecture (mirrors off-boarding)

```
Employee created (with hire_date) ──EmployeeCreated──► InitiateOnboardingOnHire listener
                                                              │
manual "Start onboarding" (HR) ───────────────────────────────┤
                                                              ▼
                                    OnboardingService::initiate(employee, by)
                                       ├─ create OnboardingCase (InProgress, ON-YYYY-NNNNN)
                                       ├─ seed DEFAULT_ONBOARDING_TEMPLATE → OnboardingTask rows
                                       └─ auto-enrol published Onboarding courses (LearningService::enrol)
                                                              │
                  completeTask / skipTask (per task owner) ───┤  → maybeAdvanceCaseStatus
                                                              ▼
                                    complete() (all required tasks done) → status Completed
```

- **`OnboardingCase`** (↔ `OffboardingCase`): `reference` (unique, `ON-YYYY-NNNNN` via `SequenceService`), `employee_id`, `initiated_by`, `status`, `hire_date`, `target_completion_date`, `completed_at`, `completed_by`, soft deletes. Relations: `tasks()` hasMany, `employee()`, `initiator()`, `completer()`. `isComplete()` (all required tasks Completed/Skipped), `progress()` (0–1), scope `open()`.
- **`OnboardingTask`** (↔ `ClearanceItem`): `onboarding_case_id`, `area`, `label`, `status`, `is_required`, `responsible_user_id`, `completed_by`, `completed_at`, `notes`. Relations: `case()`, `responsible()`, `completer()`.
- **Enums**: `OnboardingStatus` (Draft, InProgress, Completed, Cancelled; `isTerminal()`), `OnboardingArea` (ItProvisioning, HrOrientation, PolicyAcknowledgement, Learning, Mentorship, DeptIntroduction, Other; `label()`), `OnboardingTaskStatus` (Pending, Completed, Skipped).
- **`OnboardingService`**: `initiate`, `seedDefaultTasks`, `autoEnrolOnboardingCourses`, `completeTask`, `skipTask`, `maybeAdvanceCaseStatus`, `complete`, `cancel`, `openCaseFor`.
- **Listener** `InitiateOnboardingOnHire` (auto-discovered) on `EmployeeCreated`: if `employee->hire_date` set and no open case, `initiate()`. Failures are swallowed/logged so they never block employee creation.
- **HTTP**: `OnboardingController` (index/show/store/completeTask/skipTask/complete/cancel), `OnboardingCasePolicy` (viewAny/view/initiate/complete/manage), routes under `onboarding` prefix, `OnboardingCaseResource`/`OnboardingTaskResource`.
- **Permissions** (mirror off-boarding): `onboarding.view`, `onboarding.initiate`, `onboarding.complete` (sign off tasks + complete case), `onboarding.manage` (cancel/admin). Granted to the same HR roles that hold `offboarding.*`.
- **UI**: `Onboarding/Index.vue` (case list + status/progress + stats) and `Onboarding/Show.vue` (tasks grouped by area with complete/skip + a Complete-case action), plus a nav entry.

## Default task template

| Area | Task | Required |
|---|---|---|
| IT provisioning | Issue laptop, phone & access badge | yes |
| IT provisioning | Create email & system accounts | yes |
| HR orientation | HR orientation & staff handbook walkthrough | yes |
| HR orientation | Collect statutory documents (Ghana Card, SSNIT no., TIN) | yes |
| Policy acknowledgement | Acknowledge code of conduct & key policies | yes |
| Learning | Complete mandatory onboarding courses | yes |
| Mentorship | Assign onboarding buddy / mentor | no |
| Dept introduction | Department introduction & first-week plan | yes |

## Error handling & integrity

- **Idempotent initiate**: one open case per employee — `initiate` returns the existing open case (or throws) rather than creating a duplicate; the listener checks `openCaseFor` first.
- **Listener never blocks hire**: the `EmployeeCreated` handler wraps `initiate` in try/catch (logs on failure) so a missing course table or seed gap can't break employee creation.
- **Complete guard**: `complete()` requires every `is_required` task to be Completed or Skipped, else `DomainException`.
- **Auto-enrol is best-effort**: enrolment uses `LearningService::enrol` (itself idempotent via `firstOrCreate`); zero onboarding courses is fine (the "complete onboarding courses" task remains a manual sign-off).

## Testing (Pest)

- Service: initiate seeds the template + creates InProgress case + auto-enrols onboarding courses; idempotent (second initiate returns same open case); completeTask/skipTask transitions + auto-advance; complete blocked until required tasks done, then Completed; cancel.
- Listener: creating an Employee with a hire_date auto-initiates exactly one case; without hire_date does not; a thrown error inside initiate doesn't break employee creation.
- HTTP: permission gates (initiate/complete/manage vs forbidden); store/completeTask/complete round-trip; resource shape.
- Accessibility: any new form inputs carry `aria-label`.

## Conventions

- Enum → FormRequest → Service → Resource; DB-backed permissions; per-user JSON `permissions` for test grants; mirror off-boarding file-for-file; `declare(strict_types=1)`; `casts()` method form.

## Out of scope (future)

- Configurable per-department/role onboarding templates; pre-boarding (pre-start-date) tasks; deep automation (a task that actively assigns an asset or sets `manager_id`); an `EmployeeStatus::Onboarding/Probation` state; new-hire self-service onboarding portal; onboarding analytics dashboard.
