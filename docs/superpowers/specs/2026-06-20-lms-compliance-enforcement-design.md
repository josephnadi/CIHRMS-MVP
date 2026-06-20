# LMS Compliance Enforcement — Design

**Date:** 2026-06-20
**Status:** Approved design — ready for implementation plan
**Context:** Gov-grade audit found the Learning module solid (courses, enrolments with progress/auto-complete, skill-granting on completion, certifications with expiry reminders) but with **no compliance layer**: no mandatory-training requirements, no role/department auto-assignment, no due dates, no overdue tracking. This adds that layer without changing the existing learning flow.

## Decisions (locked)

| Decision | Choice |
|---|---|
| Targeting | **all-staff / role / department** — a requirement targets everyone, a `UserRole`, or a `Department`. (Grade/position deferred.) |
| Prerequisites | **Deferred** — this build is mandatory assignment + due dates + overdue tracking + dashboard + reminders. Course-prerequisite enforcement is a future increment. |
| Enforcement | **Soft / advisory** — track, surface overdue on a dashboard + My Learning, send reminders. Never blocks the employee from anything (mirrors Finance budget controls). |
| Recurrence | Out of scope — a requirement assigns once; annual re-assignment is future. |

## Architecture

```
ComplianceRequirement (course + target + due_in_days, is_active)
        │  matches employees by all_staff / role / department
        ▼
ComplianceAssignmentService::assign(requirement, employee)
        ├─ LearningService::enrol(course, employee)   (idempotent)
        └─ stamp enrolment.requirement_id + due_at = now + due_in_days  (once)
        │
   triggers: requirement created/activated → syncRequirement;
             EmployeeCreated → assignForEmployee; scheduled compliance:sync → syncAll
        ▼
   Overdue = enrolment.requirement_id set AND status ≠ Completed AND due_at < now
        ▼
   Compliance dashboard (per requirement: assigned/completed/overdue + overdue people)
   My Learning (employee sees mandatory + overdue badges)
   compliance:remind (scheduled) → notify soon-due/overdue employees
```

- **`compliance_requirements`**: `course_id` (FK), `name`, `target_type` (`ComplianceTarget`: all_staff/role/department), `target_value` (nullable — role slug or department id; null for all-staff), `due_in_days` (int, default 30), `is_active` (bool), timestamps.
- **`enrolments` gains** `requirement_id` (nullable FK `compliance_requirements`, nullOnDelete) + `due_at` (nullable timestamp). An enrolment is "mandatory/compliance" iff `requirement_id` is set. Existing enrolments (and self-enrols) keep `requirement_id` null → unaffected.
- **Enum** `ComplianceTarget` (AllStaff, Role, Department; `label()`).
- **`ComplianceRequirement` model**: `course()`, `enrolments()` hasMany; `matches(Employee): bool` — all_staff ⇒ true; role ⇒ `employee->user?->role?->value === target_value`; department ⇒ `(int) employee->department_id === (int) target_value`. `matchingEmployees()` query.
- **`Enrolment` model gains** `requirement()` belongsTo + scopes `mandatory()` (`whereNotNull requirement_id`), `overdue(?now)` (mandatory + `due_at < now` + status ≠ Completed).
- **`ComplianceAssignmentService`**: `assign(requirement, employee): ?Enrolment` (enrol + stamp once, idempotent); `syncRequirement(requirement): int`; `syncAll(): int`; `assignForEmployee(employee): int` (all active requirements matching the employee — the new-hire hook). Best-effort, never throws into the caller.
- **Triggers**: `ComplianceRequirementController` store/activate calls `syncRequirement`; an `AssignComplianceOnHire` listener on `EmployeeCreated` calls `assignForEmployee` (try/caught, never blocks hire — same pattern as `InitiateOnboardingOnHire`); a scheduled `compliance:sync` command runs `syncAll` daily (catches new requirements, new hires, role/dept changes).
- **Reminders**: a scheduled `compliance:remind` command notifies employees with overdue (and within-N-days-due) mandatory enrolments, via a `ComplianceTrainingDue` notification (mirrors an existing `*Notification`).
- **Permissions**: `learning.compliance.manage` (create/sync requirements, view dashboard) granted to the role(s) holding `learning.manage`. Employees see their own mandatory courses with the existing `learning.view`.
- **UI**: a **Compliance** admin page (requirements list + create + per-requirement assigned/completed/overdue + overdue employees), and a mandatory/overdue surface on the existing **My Learning** page.

## Error handling & integrity

- **Non-blocking everywhere**: assignment failures are caught/logged; the `EmployeeCreated` hook never breaks employee creation; nothing gates the employee.
- **Idempotent assignment**: `LearningService::enrol` is `firstOrCreate`; `assign` only stamps `requirement_id`/`due_at` when not already set, so re-syncing never duplicates or resets due dates.
- **Existing learning untouched**: self-enrolments and pre-existing enrolments have `requirement_id` null → never "mandatory", never overdue. The existing learning suite must stay green.
- **Completion already handled**: the existing `recordProgress`/`completeEnrolment` flow sets status Completed; an overdue mandatory enrolment simply stops being overdue once completed.

## Testing (Pest)

- Requirement `matches()` for all_staff/role/department; `matchingEmployees` returns the right set.
- `assign` enrols + stamps due_at once; idempotent (re-assign doesn't duplicate or move due_at); `syncRequirement`/`syncAll` cover all matching active employees; `assignForEmployee` assigns matching requirements to a new hire.
- `Enrolment::overdue` flags a past-due incomplete mandatory enrolment and excludes completed / non-mandatory ones.
- `EmployeeCreated` auto-assigns matching requirements (and never breaks employee creation on error).
- Endpoint/permission gates (manage vs forbidden); dashboard counts; My Learning shows mandatory/overdue.
- Accessibility: new inputs carry `aria-label`.

## Conventions

- Enum → FormRequest → Service → Resource; DB-backed permissions; per-user JSON `permissions` for test grants; mirror `OnboardingService` auto-enrol + `InitiateOnboardingOnHire` listener patterns; `declare(strict_types=1)`; `casts()` form.

## Out of scope (future)

- Course prerequisites (enforced at enrol); recurring/annual re-assignment; grade/position targeting; manager attestation/extension workflow; assessment/quiz gating; per-course passing-score enforcement; compliance analytics beyond the dashboard.
