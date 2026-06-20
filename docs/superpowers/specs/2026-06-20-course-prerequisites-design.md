# Course Prerequisites — Design

**Date:** 2026-06-20
**Status:** Approved design — ready for implementation plan
**Context:** Follow-on to LMS compliance enforcement (deferred there). Adds course prerequisites — "you must complete course A before you can self-enrol in course B."

## Decision (determined, not a fork)

Prerequisites are enforced on the **employee self-enrol path only** (`LearningController::enrol`), and **bypassed by admin/automated assignment** (`LearningService::enrol`, which compliance auto-assign and onboarding auto-enrol both call). This is deliberate: an employee can't jump ahead, but a manager/compliance rule assigning a course is an intentional act and must not be silently blocked (and must never break the onboarding/compliance auto-enrol just shipped). Enforcement is **hard** for self-enrol (blocked with a clear message), advisory in the catalog UI (locked state).

## Architecture

- **`course_prerequisites`** pivot: `course_id`, `prerequisite_course_id` (both FK `courses`, cascade), unique `(course_id, prerequisite_course_id)`. A self-referential many-to-many on `Course`.
- **`Course::prerequisites()`** belongsToMany (self, via the pivot). **`Course::unmetPrerequisitesFor(Employee): Collection`** — prerequisite courses the employee has no `Completed` enrolment for.
- **Self-enrol enforcement**: `LearningController::enrol` calls `unmetPrerequisitesFor`; if non-empty, returns back with an error listing the titles — no enrolment created. `LearningService::enrol` is unchanged (admin/auto path bypasses).
- **Admin authoring**: `LearningService::createCourse`/`updateCourse` accept `prerequisite_ids` and `sync()` the pivot; the store/update FormRequest validates them (must be existing course ids, not the course itself).
- **Catalog UI**: each course carries its `prerequisites` (id+title); the page receives the viewer's `completedCourseIds` and marks a course **locked** when any prerequisite isn't completed — showing the prereq names and disabling the enrol button. The course create/edit form gets a prerequisite multi-select.

## Error handling & integrity

- **No breakage of existing flows**: `LearningService::enrol` (used by compliance auto-assign + onboarding auto-enrol) is untouched and never throws on prerequisites — those assignments are deliberate. Only the self-enrol controller enforces.
- **No self-prerequisite / cycles**: the FormRequest rejects a course listing itself; deeper cycle prevention (A→B→A) is out of scope (a 1-level guard plus admin judgement suffices for MVP; document it).
- **Completed = met**: a prerequisite is satisfied by any `EnrolmentStatus::Completed` enrolment for that course.
- **Backwards-compatible**: courses with no prerequisites behave exactly as today; the existing learning suite stays green.

## Testing (Pest)

- `prerequisites()` relation + `unmetPrerequisitesFor` (returns only not-completed prereqs; empty when all completed).
- Self-enrol blocked when a prerequisite is incomplete (no enrolment created, error flashed); allowed once completed; admin/auto `LearningService::enrol` bypasses (enrols regardless).
- `createCourse`/`updateCourse` sync prerequisite_ids; FormRequest rejects self-reference + non-existent ids.
- Catalog exposes prerequisites + the locked computation; accessibility (the prereq multi-select carries `aria-label`).

## Out of scope (future)

- Multi-level cycle detection; "prerequisite OR" groups; minimum-score prerequisites; prerequisite enforcement on compliance auto-assign (deliberately bypassed); prerequisite chains surfaced as a learning path.
