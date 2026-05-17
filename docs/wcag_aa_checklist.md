# WCAG 2.1 AA — Developer Checklist (CIHRMS)

This is the working set of accessibility rules every PR must satisfy before
merge. The artisan auditor (`php artisan a11y:audit`) catches the cheap
violations; the rest are reviewer-enforced. Pair with axe DevTools in the
browser for visual checks.

The Ghana Persons with Disability Act 715 (2006) plus the public-sector
procurement guidance both reference WCAG AA as the baseline for government
service delivery — meeting AA is a procurement gate, not a nice-to-have.

---

## 1. Perceivable

### 1.1.1 Non-text Content
- Every `<img>` has an `alt` attribute. Use `alt=""` for purely decorative
  images. Use a meaningful description for content images. Bind dynamically
  via `:alt="employee.fullName"` when the value depends on data.
- Icon-only buttons get `aria-label="Delete row"` — the SVG inside is not
  enough.
- Charts/graphs ship a text summary near the canvas (e.g. "Headcount rose
  4% in Q2") or a screen-reader-only `<table>` with the underlying data.

### 1.3.1 Info and Relationships
- Use semantic landmarks: `<header>`, `<nav>`, `<main id="main-content">`,
  `<aside>`, `<footer>`. The single `<main>` is the SkipLink target.
- Tables that present tabular data use `<th scope="col">` / `<th scope="row">`.
- Don't fake checkboxes with `<div role="checkbox">` unless you implement
  the full keyboard contract — prefer the native control.

### 1.4.3 Contrast (Minimum)
- Body text: ≥ 4.5:1 against its background.
- Large text (≥ 18pt or 14pt bold): ≥ 3:1.
- UI components and meaningful icons: ≥ 3:1.
- The CIHRM palette is pre-verified; do not introduce new ink colours
  without running them through https://webaim.org/resources/contrastchecker/.

### 1.4.11 Non-text Contrast
- Focus rings (`:focus-visible`) on buttons and inputs must reach 3:1
  against the surface they sit on. The default `2px solid #1d4ed8` ring in
  `app.css` meets this on white; the `.sidebar :focus-visible` override
  switches to white on the obsidian sidebar.

### 1.4.12 Text Spacing
- Don't lock line-height / letter-spacing with `!important`. Users who
  apply bookmarklets for dyslexia or low vision must be able to override.

---

## 2. Operable

### 2.1.1 Keyboard
- Every interactive element is reachable and operable with the keyboard
  alone. Try `Tab`, `Shift+Tab`, `Enter`, `Space`, arrow keys.
- Don't bind logic to `mousedown` / hover only.

### 2.1.2 No Keyboard Trap
- Modals and slide-panels use `useFocusTrap(containerRef, openRef, {
  onEscape })` — this traps focus *while the dialog is open* and releases
  on close, which is the correct kind of trap.

### 2.4.1 Bypass Blocks
- Every layout renders `<SkipLink />` as its first focusable child, with
  `<main id="main-content" tabindex="-1">` as the target.

### 2.4.3 Focus Order
- Never use `tabindex` greater than `0`. Use `tabindex="-1"` to mark a
  programmatically-focusable element (like `<main>` for the skip link)
  and `tabindex="0"` to insert a non-button into tab order; nothing else.

### 2.4.4 Link Purpose
- Link text must make sense out of context. "Click here" and "more" are
  banned. Prefer "Download the 2026 payroll register" over "Click here to
  download the register".

### 2.4.7 Focus Visible
- Never set `outline: 0` without supplying an equally visible
  `:focus-visible` replacement. The global ring in `app.css` should not
  be overridden unless paired with a higher-contrast alternative.

---

## 3. Understandable

### 3.1.1 Language of Page
- `<html lang="{{ app()->getLocale() }}">` — already wired up in
  `app.blade.php`. Switch this whenever the LocaleSwitcher fires.

### 3.2.2 On Input
- Don't auto-submit a form when a `<select>` changes unless the user has
  been warned, or the change can be undone. Cosmetic filters (theme,
  density) may auto-apply; data submission cannot.

### 3.3.1 Error Identification
- Form validation errors:
  - Render error text adjacent to the field.
  - Connect input ↔ error via `aria-describedby="field-error"`.
  - Set `aria-invalid="true"` on the failed field.
  - Push a summary into the live announcer:
    `announce('Save failed — fix 2 fields.', 'assertive')`.

### 3.3.2 Labels or Instructions
- Every form control has a visible `<label for="...">` *or* an explicit
  `aria-label`. The auditor flags missing pairs.

---

## 4. Robust

### 4.1.2 Name, Role, Value
- Custom widgets get their ARIA role wired up. If you can use a native
  `<button>` / `<details>` / `<dialog>`, do — they ship correct semantics
  for free.

### 4.1.3 Status Messages
- Toasts, saved-state badges, and "loading" indicators get announced via
  `announce(text, priority)`:
  - `polite` (default) — saves, navigation, success.
  - `assertive` — errors, security prompts, things that interrupt.
- `<AriaLiveAnnouncer />` is mounted globally in every layout.

---

## Project conventions

### Reduced motion
- All transitions / animations sit behind the
  `@media (prefers-reduced-motion: reduce)` block in `app.css`. Don't add
  inline `transition:` declarations that bypass that.

### High contrast theme
- Setting `html[data-theme="high-contrast"]` swaps the design tokens to a
  7:1 AAA palette. Reach for it for the colour-blind / low-vision toggle in
  Settings.

### Patterns to reuse
| Pattern                    | Where                                                       |
| -------------------------- | ----------------------------------------------------------- |
| Skip link                  | `<SkipLink />` at the top of every layout                   |
| Live announcer             | `<AriaLiveAnnouncer />` once per layout; `announce(text)`   |
| Focus trap (modal/panel)   | `useFocusTrap(panelRef, isOpenRef, { onEscape })`           |
| sr-only / sr-only-focusable | CSS utility classes in `app.css`                           |

### Tooling
- `php artisan a11y:audit` — fast static sweep, run pre-commit and in CI.
- `php artisan a11y:audit --json` — machine-readable output for CI bots.
- `php artisan a11y:audit --severity=warning` — fail the run on warnings
  too (stricter mode for release branches).
- Browser axe DevTools — run before merging anything touching layout.
- VoiceOver (Mac) / NVDA (Windows) — smoke-test the happy path of any new
  page at least once.

### Out of scope (handled at platform level)
- Colour-blind safe alternative for status colours — green/red badges
  pair with an icon (✓ / ✕) so colour isn't load-bearing.
- Captioning of recorded training videos — `learning_assets` table
  carries a `caption_track_path` column; the upload form requires it for
  video assets.
