# CIHRMS Audit V2 — Wire-Up & Polish Punch List

**Date:** 2026-05-24
**Method:** Three parallel static-analysis agents — backend reliability, frontend coherence, UI/UX consistency.
**Scope:** Whole app — 127 Vue Pages, 381 web routes, ~50 services.
**Excluded from audit:** Finance F1–F5 services (already hardened in PRs #20/#21), Console commands, Auth/Login pages (heavily worked this session), already-merged admin/users pages.

---

## CRITICAL — pages broken or data corruption risk (fix before any further work)

### Frontend pages that are actually broken right now

1. **`resources/js/Pages/Privacy/MyRequests.vue:13`** + **`app/Http/Controllers/PrivacyController.php:30`** — Vue declares `requests: Array` but controller sends `DataSubjectRequestResource::collection($reqs)` which is a paginator-shaped object. Template iterates `v-for="r in requests"` and calls `.filter(...)` directly. **Page crashes immediately when visited.**
2. **`resources/js/Pages/Establishment/Positions/Show.vue`** missing — **`app/Http/Controllers/PositionController.php:54`** renders `Establishment/Positions/Show` but **the Vue file does not exist.** Route `positions.show` returns an Inertia error.
3. **`resources/js/Pages/Privacy/Admin/Show.vue:18`** — fulfill form posts `{ decision_summary }`; `FulfillRequest` requires `summary` (min 20). **Fulfill action always fails validation.**
4. **`resources/js/Pages/Privacy/Admin/Show.vue:21`** — reject form posts only `{ statutory_basis }`; `RejectRequest` also requires `summary`. **Reject action always fails validation.**

### Lazy-loading violations that 500 under dev/test strict mode

5. **`app/Services/AssetService.php:88,95,150`** — `returnAsset` and `completeMaintenance` access `$assignment->asset` / `$maintenance->asset` without eager-loading. Throws `LazyLoadingViolationException`.
6. **`app/Services/Loans/LoanService.php:171`** — `return $repayment->loan->fresh();` lazy-loads `loan`.
7. **`app/Services/IncidentReportService.php:180`** — `attachFile()` does `$attachable->report->id` for a freshly-created message.
8. **`app/Services/GovernanceService.php:73`** — `publish()` reads `$version->policy` without eager-load.
9. **`app/Services/Performance/PerformanceContractService.php:87-88`** — `sign()` reads `$contract->employee` and `$contract->supervisor`.
10. **`app/Services/Performance/CalibrationService.php:112`** — `apply()` iterates `$session->adjustments`.
11. **`app/Services/Attendance/AttendanceService.php:328`** — `approveCorrection()` reads `$correction->employee`.
12. **`app/Services/DocumentService.php:124,131`** — `moveAnnotation()` calls `logEvent($annotation->document, ...)`.

### Cross-driver SQL breaks SQLite tests

13. **`app/Services/TicketService.php:86,87`** — uses `ilike` (Postgres-only). On SQLite (test runner) this 500s when search term is supplied.

### Logic bugs

14. **`app/Models/Conversation.php:81`** — `firstWhere('id', '!==', $me->id)` — `!==` is not a valid Eloquent operator (allowed: `!=`, `<>`). First clause always returns null; the fallback hides it. Use `where(..., '!=', ...)`.
15. **`app/Services/LeaveService.php:31-46`** — `updateStatus()` writes to LeaveRequest, then LeaveBalance, then increments `used_days`, **without `DB::transaction()`**. Partial-state risk on crash.

---

## IMPORTANT — daily-UX warts + lurking reliability gaps

### CEO role missing in feature gates (regression risk after PR #38/#39 landed wildcard for CEO)

16. **`resources/js/Pages/Leave/Index.vue:35`** — `['hr_admin', 'super_admin'].includes(user.value?.role)` — missing `'ceo'`. CEO sees employee view instead of HR queue.
17. **`resources/js/Pages/Leave/Show.vue:16`** — `['hr_admin', 'super_admin', 'manager']` — missing `'ceo'`. CEO can't approve/reject from the show page.
18. **`resources/js/Pages/Dashboard.vue:397-399`** — Header sub-headline falls through to generic copy for CEO (the title was fixed in PR #39 but the sub-text was missed).
19. **`app/Http/Controllers/WhistleblowerAdminController.php:106-107`** — `investigators` list = `User::whereIn('role', ['super_admin','auditor','hr_admin'])`. CEO can't be assigned whistleblower cases.
20. **`app/Http/Controllers/TicketController.php:41`** — `supportStaff()` filters by `['it_support','hr_admin','super_admin','manager']`. CEO not in assignee dropdown.
21. **`app/Http/Controllers/ComplaintController.php:23-28`** — Investigators picker uses legacy role enum directly. CEO omitted.

### `count()+1` race conditions still present (the pattern PR #21 fixed for Finance refs)

22. **`app/Services/Offboarding/OffboardingService.php:404-408`** — `nextReference()` uses `whereYear(...)->count() + 1`. Switch to `SequenceService::next('offboarding', $year)`.
23. **`app/Services/Loans/LoanService.php:223-232`** — `generateReference()` uses `MAX(reference) + 1`. Same fix.
24. **`app/Http/Controllers/Admin/UserController.php:94-103`** — `nextEmployeeNo()` uses `MAX(...) + 1`.
25. **`app/Services/DocumentService.php:243-246`** — `nextRefNo()` uses `count()+1` with a 3-retry loop. Still surfaces 500s under enough concurrency.

### Missing permission gates / authorization holes

26. **`routes/web.php:377`** — `recordProgress` only requires `learning.view`; controller does no ownership check. **Any viewer can edit any employee's enrolment progress.**
27. **`routes/web.php:331`** — `goals.checkins.store` and `goals.update` only gated by `permission:performance.view`; controller does no ownership check. Any viewer can post check-ins / edit any goal.
28. **`routes/web.php:425`** — `/ai/employee-summary` has no permission middleware. Any authenticated user can hit the AI assistant (per-call cost = abuse vector).
29. **`routes/web.php:780-791`** — governance/incidents mutating routes (`store`, `update`, `assign`, `unassign`, `close`, `reopen`) have no `permission:` middleware. They depend on Policy calls in controllers — add defence-in-depth at route layer.

### Missing transactions on multi-step writes

30. **`app/Services/Loans/LoanService.php`** — `apply` / `disburse` need atomic wrapping (loan create + schedule create + balance line).
31. **`app/Http/Controllers/MessagingController.php:88-105`** — `issuePin()` updates PIN row then dispatches SMS, no transaction. If SMS provider fails, PIN rotated but never delivered.
32. **`app/Services/LearningService.php:121-130`** — `recordProgress` updates enrolment then conditionally calls `completeEnrolment` (which has its own transaction). Wrap the outer flow.
33. **`app/Services/RecruitmentService.php:30-34`** — `apply()` stores CV file then creates row. If row insert fails, file is orphaned. Wrap in transaction with rollback-time cleanup.

### Race conditions on shared state

34. **`app/Services/Establishment/PositionService.php:62-73`** — Ceiling check + transaction has no `lockForUpdate` on the position row. Two concurrent assigns can both pass the headcount check.
35. **`app/Services/LeaveService.php:46`** — `$balance->increment('used_days', ...)` is atomic at SQL but not locked. Two parallel approvals can race past any future balance-availability guard.
36. **`app/Models/Conversation.php:55-75`** — `findOrCreateOneOnOne` is query-then-create with no advisory lock or unique index. Two parallel "open chat with X" creates two conversations.

### Filters declared but unwired

37. **`resources/js/Pages/Performance/Contracts/Index.vue:29`** — `localFilters.search` is in the form but `applyFilters()` never sends it (and `PerformanceContractController::index` wouldn't filter on it anyway). Search box is decorative.
38. **`resources/js/Pages/Attendance/Index.vue:23-24`** — Vue reads `props.filters?.status` and `props.filters?.q`, but `AttendanceController::index` only forwards `['department_id']`. Status/search chips don't round-trip.

### Frontend prop-shape gaps

39. **`resources/js/Pages/Leave/Index.vue:21-25`** — declares `pendingCount`, `myRequests`, `employees` props that the controller never sends. Any UI that reads them shows undefined.
40. **`resources/js/Pages/Payroll/Dashboard.vue`** — Orphan Vue page. No controller renders it. Either delete or wire to a route.

---

## NOTABLE — polish that won't block launch but reduces credibility

### Frontend N+1 / wasted work

41. **`app/Services/Disbursement/BatchDisbursementService.php:46,71`** — eager-loads `employee` but reads `$employee?->user?->name` (lazy). 200 extra queries per batch chunk. Use `with('employee.user')`.
42. **`app/Services/PerformanceService.php:165-193`** — `deptEfficiency()` iterates departments and fires extra ticket-count queries per. Classic N+1.
43. **`app/Services/PerformanceService.php:145-154`** — `tenureBuckets()` chunks `select('hire_date')` then iterates. Replace with `selectRaw` aggregation.

### Visual breakage (looks wrong on first sight)

44. **`resources/js/Pages/Identity/Index.vue`** — entire page uses `bg-white`/`bg-slate-50`/`text-slate-600` instead of design tokens. Reads as Tailwind-default while the rest of the app is "Sovereign Precision".
45. **`resources/js/Pages/Establishment/Positions/Index.vue:73-141`** — same problem: `bg-white rounded-2xl shadow-sm border border-slate-100`, `bg-slate-50 text-slate-600` table head.
46. **`resources/js/Pages/Performance/Calibration/Show.vue:50-78`** — header is `text-2xl font-semibold` instead of the canonical `text-[1.6rem] font-black tracking-tight text-primary`. No editorial sub-label, no `space-y-6 animate-reveal-up` wrapper.
47. **`resources/js/Pages/Performance/Calibration/Index.vue:46-53,74-79`** — `distributionBands` is literally commented as placeholder `20/65/15`. `statusTone.applied` uses `bg-cobalt-500/15` but the Tailwind config has no `cobalt` palette — class silently no-ops.
48. **`resources/js/Pages/Dashboard/DeptIt.vue,DeptFinance.vue,DeptMarketing.vue`** — campaign / SLA cards fully hardcode sample data inline (`Q2 Institutional Awareness Drive · GHS 45,000` etc.) Visible to real users.
49. **`resources/js/Pages/Kiosk/Index.vue:194,409`** — "Face recognition coming soon" stub still rendered to end users. Already covered as deferred per C4 — but the kiosk UI should hide the unwired tile rather than promise.
50. **`resources/js/Pages/Learning/MyLearning.vue:343`** — "Personalised recommendations coming soon" label in a shipped tab. Replace with real empty-state copy.

### Status-pill / colour-system duplication

51. **`Loans/Index.vue:92-96`**, **`Recruitment/Index.vue:78-81`**, **`Whistleblower/Admin/Index.vue:40-58`**, **`Performance/Pips/Index.vue:83-90`**, **`Dashboard.vue:375-384`** — each defines its own status-pill colour map with raw `bg-amber-50/text-amber-700/border-amber-200` etc. Five separate truths-of-status. Extract a shared `StatusPill` keyed by token.
52. **`Tickets/Show.vue:42-47`** vs **`Tickets/Index.vue:425-461`** — priority colours don't agree between Show and Index of the same module.
53. **`Reports/Index.vue:28-45`** + **`Settings/ApiDocs/Index.vue:21-50`** — local colour palettes with `#b88a08`, `#059669`, `#dc2626`, `#1a237e` raw hex instead of `brand-*` token names.

### Missing empty / error states

54. **`Performance/Pips/Show.vue:110-114`** — `v-for="m in P.target_metrics ?? []"` over empty array; "Target metrics" header sits orphaned.
55. **`Performance/Contracts/Show.vue:65-80`** — KPI table renders only `<tfoot>` when no KPIs.
56. **`Privacy/Admin/Show.vue:151-165`** — "Redacted" and "Held back" lists collapse silently; heading "Erasure receipt" shows with no children.
57. **`Performance/Calibration/Show.vue:69-90`** — distribution bars static `['5','4','3','2','1']`; no skeleton/"no reviews yet" message.
58. **`Identity/Index.vue:154-166`** — verification register `<tr v-for>` with no empty-state fallback. Default state for a new tenant.
59. **`Disbursements/Index.vue:11-17`** — `<table>` renders with `<thead>` only when disbursements empty.
60. **`Payments/Index.vue:13-19`** — Main payment table lacks zero-state.

### Accessibility gaps in high-traffic pages

61. **`Dashboard.vue:986-1101`** — Clock-in / Clock-out / "Today"/"Week" toggle buttons have no `aria-label`/`aria-pressed`.
62. **`Identity/Index.vue:104-108`** — Employee ID and Ghana-card inputs have NO `<label>` element — only placeholders.
63. **`Whistleblower/Admin/Show.vue:131-134`** — tab buttons have no `role="tab"`/`aria-selected`/`aria-controls`. Same in `Documents/Index.vue`, `Performance/Reviews.vue`.
64. **`Leave/Index.vue:887,1133`** — calendar cells use `bg-green-100`/`bg-red-100` only; on mobile (32px cells) the colour is the only state indicator.

### Layout / wrapper inconsistency

65. **`Performance/Calibration/Index.vue + Show.vue`**, **`Performance/Contracts/Show.vue`**, **`Performance/Pips/Show.vue`**, **`Reports/AuditorGeneral.vue`**, **`Whistleblower/Admin/Show.vue`**, **`Privacy/Admin/Show.vue`**, **`Establishment/Positions/Index.vue`**, **`Identity/Index.vue`**, **`Documents/Index.vue`**, **`Notifications/Channels.vue`** — none use the standard `<div class="space-y-6 animate-reveal-up">` wrapper. Page-to-page reveal is jumpy.

---

## RECOMMENDED FIX ORDER

**Tier 1 — Ship these as one PR (~2h)** — closes everything actually broken:
- Items 1–15 above

**Tier 2 — CEO + role-gate cleanup (~1h)** — one focused PR:
- Items 16–21

**Tier 3 — Concurrency-safe references + missing perm gates (~2h)**:
- Items 22–29 (combine with Tier 4 if you want one bigger PR)

**Tier 4 — Missing transactions + race conditions (~2h)**:
- Items 30–36

**Tier 5 — Unwired filters + prop-shape cleanup (~1h)**:
- Items 37–40

**Tier 6 — Visual + UX polish (~4-6h)** — biggest scope, most defer-able:
- Items 41–65, focused on the 10 highest-traffic pages first

---

## OUT OF SCOPE FOR THIS PASS

- Finance F1–F5 services — already hardened, has integration tests
- Console commands — separate audit
- Marketing / public Careers pages — separate concern
- New features (Performance 9-Box analytics, additional learning catalog filters, etc.)
- Mobile-app shell or PWA-specific work
