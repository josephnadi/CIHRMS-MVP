# Accessibility conformance statement

CIHRMS Ghana is built to **WCAG 2.1 Level AA**. This document records the
specific commitments, the testing methodology that enforces them, the
known limitations, and the channel for accessibility complaints.

**Last verified:** 2026-05-21
**Audit tooling:** `php artisan a11y:audit` (run on every PR via CI)
**Live tree:** 0 errors, 0 warnings as of this commit

---

## What this means in practice

CIHRMS commits to the following user-observable guarantees:

| WCAG criterion | What it means here |
|---|---|
| **1.1.1** Non-text content | Every `<img>` carries `alt` (or `alt=""` for decorative images). Material-Symbols icon glyphs are paired with adjacent visible text or `aria-label`. |
| **1.3.1** Info and relationships | Form fields are programmatically associated with their labels via `<label for>` / `id`, wrapping `<label>`, or `aria-label`. Headings are nested in document order. |
| **1.4.3** Contrast (minimum) | All body text meets ≥4.5:1 contrast. Brand-navy `#0d1452` on white is 13.9:1. Cobalt action-blue `#205295` on white is 7.5:1. Gold-on-navy is reserved for non-text accents (≥3:1). |
| **2.1.1** Keyboard | Every interactive element (links, buttons, form controls, modal dismissals) is reachable and operable via keyboard alone. |
| **2.1.2** No keyboard trap | Modals close on `Escape`; the document scanner + biometric capture release the camera and return focus on close. |
| **2.4.1** Bypass blocks | `<SkipLink>` is the first focusable element on every authenticated page, target `#main-content`. |
| **2.4.3** Focus order | No `tabindex` values > 0 anywhere in the app. The static auditor fails CI on any introduction. |
| **2.4.4** Link purpose | No empty `<a>` tags. Icon-only anchors carry `aria-label`. |
| **2.4.7** Focus visible | `:focus-visible` outlines applied globally (lighter ring on dark sidebar surfaces). |
| **3.3.2** Labels or instructions | Every `<input>`, `<select>`, `<textarea>` has an associated label — verified mechanically by the auditor on every PR. |
| **4.1.2** Name, role, value | Custom buttons (icon-only triggers) carry `aria-label`. Custom interactive widgets carry the right ARIA roles + states. |
| **4.1.3** Status messages | `<AriaLiveAnnouncer>` is registered globally; flash messages and toast notifications announce to a polite live region. |

---

## Testing methodology

### 1. Static auditor (CI-gated, hard-fail)

Located at `app/Services/Accessibility/AccessibilityAuditor.php`. Runs on
every PR via `.github/workflows/ci.yml`. The auditor enforces:

- `img-missing-alt` (WCAG 1.1.1)
- `icon-button-missing-aria-label` (WCAG 4.1.2)
- `input-missing-label` (WCAG 3.3.2)
- `select-missing-label` (WCAG 3.3.2)
- `textarea-missing-label` (WCAG 3.3.2)
- `anchor-empty-text` (WCAG 2.4.4)
- `positive-tabindex` (WCAG 2.4.3)

**Any `error`-severity finding fails the build.** Warnings are reported
but don't block merge — they're for review.

Run locally:

```bash
php artisan a11y:audit                # human-readable table
php artisan a11y:audit --json         # machine-readable
php artisan a11y:audit --severity=warning  # fail on warnings too
```

### 2. Pest regression test (suite-gated)

`tests/Feature/Accessibility/AccessibilityAuditorTest.php` includes a
single integration test that runs the auditor against the live project
tree and asserts **zero error-severity findings**. So a regression
introduced in a branch where someone skipped the CI step still surfaces
via the test suite.

### 3. Manual keyboard + screen-reader smoke tests

Reserved for each major release cycle. Test plan:

- Login → dashboard via keyboard only (`Tab`, `Enter`, `Space`, `Esc`)
- File a leave request via keyboard only
- Verify NVDA / VoiceOver announces the "leave submitted" toast
- Verify the dark-mode toggle is reachable and labelled
- Verify the document viewer's annotation layer is operable

### 4. Browser axe-core audit (planned)

Wiring `@axe-core/playwright` into the E2E job is the next planned audit
addition. The static auditor catches the markup mistakes that are
detectable from source; axe-core would catch runtime issues like
colour-contrast on dynamic states and ARIA-attribute interplay that
only appear once Vue has rendered.

---

## Known limitations

| Area | Limitation | Tracking |
|---|---|---|
| **PDF documents** | Generated payslips + IPPD + GIFMIS exports are not yet tagged-PDF. They render correctly in screen-reader-friendly viewers but lack semantic structure. | Phase 4 |
| **Live charts** | Sparklines and the executive donut rings have aria-label but no detailed data table fallback. | Phase 4 |
| **Mobile USSD** | Out of scope for WCAG — USSD is operator-rendered and outside the browser. We track parity by checking that every USSD-accessible function has an equivalent web flow. |
| **Brand "Sovereign Precision" cyan-on-navy accents** | A handful of decorative `cyan #12d9e3` accents on navy fall below 3:1 contrast but are never load-bearing — they're hairlines and one-pixel sparks, not text or focus indicators. |

---

## Accessibility feedback

CIHRM Ghana welcomes feedback on accessibility barriers. Email
**accessibility@cihrmghana.org** with a description of the issue and
the page where you encountered it. We commit to acknowledging within
5 business days and to fixing or scheduling a fix within the next
release cycle.

---

## Standards referenced

- [WCAG 2.1 — Web Content Accessibility Guidelines](https://www.w3.org/TR/WCAG21/)
- [Ghana Accessibility Standards for Public Services (draft)](https://nca.org.gh) — under consultation by the National Communications Authority
- [Persons with Disability Act 2006 (Act 715)](http://laws.ghanalegal.com) — Ghana statutory baseline
