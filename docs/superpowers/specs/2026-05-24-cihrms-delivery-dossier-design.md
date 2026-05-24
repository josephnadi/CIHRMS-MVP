# CIHRMS Delivery Dossier — Design Spec

**Date:** 2026-05-24
**Owner:** CIHRMS Engineering Team
**Status:** Design — awaiting user approval
**Source skill:** superpowers:brainstorming
**Next skill:** superpowers:writing-plans

---

## 1. Purpose

Produce a single, professionally written PDF dossier that explains the CIHRMS product end-to-end, advocates for its readiness to ship, and benchmarks it against the standards a Ghana public-sector buyer (or international donor / commercial buyer) would assess it against.

The dossier serves three jobs in one document:

1. **Educate** — both technical and non-technical readers understand what the product is, what every feature does, and why it exists.
2. **Advocate** — make a confident, evidence-led case that the product is market-ready today.
3. **Disclose** — honestly map remaining gaps against named standards, with a 4-phase roadmap to close them.

## 2. Audience

Two reader profiles served by one document:

- **Non-technical:** ministers, board members, HR directors, procurement officers, donor program officers, journalists. They will read the Executive Summary, skim Part I (Product), and read Part III (Standards & Readiness) carefully.
- **Technical:** IT directors, integration architects, auditors, security reviewers, engineering leads at partner firms. They will read Part II (Engineering Annex) and the standards matrices in Part III.

Both audiences read the front matter and Part III; Part I is for the non-technical reader, Part II is for the technical reader.

## 3. Constraints and decisions captured during brainstorming

| Decision | Choice | Why |
|---|---|---|
| Audience structure | One PDF, two parts | One deliverable to share, but each audience has a "home" part. |
| Feature depth | Every button literally (250+ pp) | User explicitly chose comprehensive reference; structured as scannable per-screen tables so length is navigable, not slog. |
| Screenshots | Real, one annotated set per module (~25-30 shots) | Visual proof without exploding effort. |
| Standards bundle | Recommended (8 Ghana + 5 international) | Comprehensive without bloat: IPPD/GIFMIS/Ghana Card/NPRA/GRA/DPA/Cybersecurity Act/CHRAJ + ISO 30414/ISO 27001/WCAG 2.1 AA/GDPR/IFRS. |
| Toolchain | Markdown → Pandoc → `.docx` (user exports PDF in Word) | User chose Word for last-mile editability. |
| Brand polish | Match product (obsidian + cobalt, Plus Jakarta Sans + Instrument Serif) via `reference.docx` | Pitch-quality look; Word ceiling limits exact HTML fidelity but typography and palette will match. |
| Authorship | CIHRMS Engineering Team | Depersonalized byline for co-signing. |
| Ch 30 pricing (funding & sequencing chapter) | Effort units only (engineering-weeks) | Avoids committing to GHS figures inside the dossier. |
| Execution model | Full commitment to all 5 waves with checkpoints between | User chose end-to-end build; checkpoints absorb risk. |

## 4. Document shape

```
Front matter
  ├─ Cover (full-bleed obsidian, cobalt accent, CIHRMS wordmark)
  ├─ Executive Summary (2 pp — both audiences)
  ├─ Table of Contents (auto-generated, clickable in PDF)
  ├─ How to read this dossier (1 pp — reader map)
  └─ At-a-glance (1 pp — stats, modules, readiness scorecard)

PART I — The Product  (non-technical advocate voice, ~180-220 pp)
  Ch 1   What CIHRMS is and who it's for
  Ch 2   The Sovereign Precision design language
  Ch 3   Employees
  Ch 4   Leave
  Ch 5   Tickets (Service Desk)
  Ch 6   Complaints
  Ch 7   Recruitment + Public Careers
  Ch 8   Payroll
  Ch 9   Finance (F1 Chart of Accounts → F5 Bank Reconciliation)
  Ch 10  Documents (annotations, stamps, letterheads, watermarks)
  Ch 11  Chat
  Ch 12  Notifications
  Ch 13  Audit Logs
  Ch 14  Reports
  Ch 15  Departments
  Ch 16  Profile / Employee Self-Service Portal
  Ch 17  Role-by-role tours (super_admin · ceo · hr_admin · manager
         · employee · finance_officer · it_support · auditor · dept_head)
  Ch 18  Cross-cutting features (RBAC, sound packs, animations,
         accessibility hooks)

PART II — The Engineering  (peer-engineer voice, ~70-100 pp)
  Ch 19  Architecture & stack
  Ch 20  Canonical pattern (Enum → FormRequest → Service → Event → Resource)
  Ch 21  Data model & migrations
  Ch 22  RBAC, policies, per-user permissions overlay
  Ch 23  Security (Sanctum, password-must-change, audit trail, secrets)
  Ch 24  Performance, caching, queues, jobs (Horizon plan)
  Ch 25  Testing strategy (Pest, 973 tests / 3,405 assertions)
  Ch 26  Deployment & operational notes

PART III — Standards & Market Readiness  (auditor voice, ~30-40 pp)
  Ch 27  Standards benchmark (13 frameworks side-by-side)
           Government (Ghana): IPPD2/3, GIFMIS, Ghana Card/NIA,
             NPRA 3-tier, GRA PAYE/SSNIT/NHIA, DPA Act 843,
             Cybersecurity Act 2020, CHRAJ whistleblower
           International: ISO 30414, ISO 27001, WCAG 2.1 AA, GDPR, IFRS
  Ch 28  Why we are ready for market (the advocacy chapter)
  Ch 29  What's left and how to get there (4-phase roadmap)
  Ch 30  How to fund and sequence the roadmap (effort units only)

Back matter
  ├─ Glossary — Ghana-specific terms
  ├─ Glossary — technical terms
  ├─ Module index (every page → chapter ref)
  ├─ Standards cross-reference (every clause → CIHRMS feature)
  └─ Change log + version stamp
```

Approximate landing size: **280–360 pages.**

## 5. Voice and tone

| Part | Voice | Pronouns | Examples |
|---|---|---|---|
| Part I | Plain-English advocate; senior PM | Second person — "you click", "you'll see" | "Open the Leave panel from the sidebar. Click **Request leave**. The form…" |
| Part II | Senior engineer reviewing for a peer | Mostly third person, occasional first-person plural | "We use a single canonical pattern: Enum → FormRequest → Service → Event → Resource. The pattern lives at…" |
| Part III | Neutral auditor's register | Third person, evidence-led | "CIHRMS meets ISO 30414 §4.2.1 for total headcount reporting (see §3.6). Demographic breakdowns by gender and age band are partial — gap addressed in Phase 2." |

Every chapter opens with a 3-line synopsis any reader can absorb in 20 seconds.

## 6. Per-module chapter template (Part I)

Every module chapter follows the same structure so the dossier reads consistently across 14+ modules:

```
Ch N — <Module Name>

  In one paragraph
    What problem it solves · who uses it · why it matters

  Where to find it
    Sidebar location · roles that see it · related modules

  The screens
    Annotated screenshot(s) with numbered callouts

  Every button, every action
    Subsection per screen — list table format:
      | Button / field      | What it does            | Who can use it | Why it exists |
      Optional "Notes" line for edge cases (validation, side-effects, events fired)

  The data behind it
    Plain-English description of what the system stores and why
    (no SQL — Part II's job)

  How it talks to other modules
    e.g., "Leave approval fires LeaveStatusUpdated → Analytics + Notifications"

  Standards touchpoints
    "Relevant to ISO 30414 §X, DPA Act 843 §Y" with forward ref to Part III

  What's planned next
    1-3 line forward look pulled from the gap analysis
```

## 7. Standards comparison template (Ch 27)

One page per framework, identical layout:

```
<Framework Name>

  What it is        2-3 sentences in plain English
  Why it matters    Why a buyer or regulator would ask about this
  CIHRMS today      ● Met  ● Partial  ● Not yet  — with evidence § refs
  Requirement matrix
    | Clause | Requirement       | CIHRMS evidence       | Status     |
  Gap & path        What's missing + which Phase of roadmap addresses it
```

13 such pages — directly answerable when a tender or auditor asks "do you meet X?"

## 8. Roadmap template (Ch 29)

Source: existing `docs/implementation_plan_2.md` + the 14-domain gap analysis. Rendered as a Gantt-style table per phase:

```
Phase 1 — The minimum for any government pitch (8-10 weeks)
  ┌──────────────────────────────┬─────────┬─────────────────────────────┐
  │ Workstream                   │ Effort  │ Dependencies                │
  ├──────────────────────────────┼─────────┼─────────────────────────────┤
  │ Statutory payroll engine     │ 4 ew    │ Positions/Grades            │
  │ Positions/Grades/Steps       │ 2 ew    │ —                           │
  │ Ghana Card adapter (mock)    │ 1 ew    │ —                           │
  │ Tamper-evident audit         │ 1 ew    │ —                           │
  │ Postgres migration           │ 1 ew    │ —                           │
  │ 2FA                          │ 1 ew    │ —                           │
  └──────────────────────────────┴─────────┴─────────────────────────────┘
  Total: ~10 engineering-weeks (2 senior eng + 1 QA in parallel)
```

(ew = engineering-week.) Phases 2–4 follow same shape.

## 9. Toolchain and source layout

**Deliverable root:** `d:\CIHRMS\cihrms-mvp\docs\delivery_dossier\`

```
delivery_dossier/
├─ README.md                       — how to (re)build the .docx and PDF
├─ build.ps1                       — Pandoc build script (PowerShell)
├─ reference.docx                  — Pandoc style template (brand-styled)
├─ assets/
│  ├─ logo.svg                     — sourced from existing project brand
│  ├─ palette.md                   — color tokens reference
│  └─ screenshots/                 — captured PNGs, one folder per chapter
├─ chapters/
│  ├─ 00_front_matter.md
│  ├─ 01_what_cihrms_is.md
│  ├─ 02_design_language.md
│  ├─ 03_employees.md  …  18_cross_cutting.md
│  ├─ 19_architecture.md  …  26_deployment.md
│  ├─ 27_standards_benchmark.md
│  ├─ 28_why_ready.md
│  ├─ 29_roadmap.md
│  ├─ 30_funding_sequencing.md
│  └─ 99_back_matter.md
├─ scripts/
│  ├─ capture_screenshots.ps1      — Playwright-driven capture script
│  └─ annotate.ps1                 — adds numbered callouts (Sharp/Pillow)
├─ build/
│  ├─ CIHRMS_Delivery_Dossier_v1.0.docx
│  └─ CIHRMS_Delivery_Dossier_v1.0.pdf   (user produces from Word)
└─ CHANGELOG.md
```

**Build command (build.ps1):**

```powershell
pandoc chapters/*.md `
  --reference-doc=reference.docx `
  --toc --toc-depth=3 `
  --top-level-division=chapter `
  --metadata title="CIHRMS Delivery Dossier" `
  --metadata author="CIHRMS Engineering Team" `
  --metadata date="2026-05-24" `
  -o build/CIHRMS_Delivery_Dossier_v1.0.docx
```

**Reference.docx will define:**
- Title / Heading 1-4 styles (Plus Jakarta Sans, weight & sizes calibrated to product)
- Body text style (Plus Jakarta Sans, 11pt, 1.35 line height)
- Display style for cover (Instrument Serif)
- `Code` and `Code Block` styles (JetBrains Mono or Consolas fallback)
- Paragraph spacing, table styles (cobalt accent header rows)
- Page header/footer (CIHRMS wordmark left, page number right)
- Cover page (full-page obsidian background, cobalt accent bar, white title)

**Screenshot pipeline:**
1. Spin up CIHRMS dev server (`npm run dev` from `d:\CIHRMS\cihrms-mvp`).
2. Seed demo data so screens display realistic content (database seeder already exists).
3. `capture_screenshots.ps1` uses Playwright (Node) to navigate a route list, log in per role, and save PNGs at 1920×1080.
4. `annotate.ps1` overlays numbered callout markers from a JSON spec.
5. PNGs land in `assets/screenshots/<chapter_slug>/<screen>.png`, referenced from chapter markdown.

## 10. Execution waves

User has committed to full 5-wave execution; each wave ends with a rendered `.docx` for review before the next begins.

| Wave | Scope | Output |
|---|---|---|
| **0** | Spec (this doc) + build skeleton + reference.docx + Ch 3 (Employees) as proof-of-format | `v0.1.docx` — one chapter, full pipeline |
| **1** | Part I chapters 1-18 + supporting screenshots | `v0.5.docx` — non-technical part complete |
| **2** | Part II chapters 19-26 | `v0.7.docx` — engineering annex complete |
| **3** | Part III chapters 27-30 (the standards & readiness pitch) | `v0.9.docx` — all body content complete |
| **4** | Front matter, back matter, glossaries, indexing, polish, screenshot top-ups | `v1.0.docx` — ready to export to PDF |

Each wave is its own commit-worthy deliverable. After Wave 4 the user opens the `.docx` in Word and saves as PDF.

## 11. Out of scope

- GHS pricing figures (effort units only — see decision in §3).
- Translating the dossier (English only for v1.0).
- Live web-hosted version of the dossier (PDF + Word only).
- Automated re-rendering on every code change (build is manual via `build.ps1`).
- Editing the existing PRD/TRD/SYSTEM_ARCHITECTURE docs — those stay as-is; the dossier *synthesizes* from them but does not replace them.
- Per-button screenshots (only per-module annotated shots, as decided).
- Updating the standards benchmark with new framework releases after v1.0 — the dossier is a snapshot dated 2026-05-24.

## 12. Risks & mitigations

| Risk | Mitigation |
|---|---|
| 300-page document goes stale fast | Versioned filename + CHANGELOG; markdown source allows future re-renders. |
| Voice drift across 30 chapters | Per-chapter template (§6) + a style sheet in `README.md` chapter checklist. |
| Word's brand fidelity ceiling disappoints | Spec acknowledges this; screenshots carry product polish. If unacceptable, fallback path is HTML+CSS → Chrome headless. |
| Screenshot capture breaks on UI changes | Capture script is checked in; re-run any time after a UI change. |
| "Every button" depth becomes unreadable | Per-screen tables are scannable; readers can skip prose. Module index at end lets readers jump to specifics. |
| Standards research could go too deep | Per-framework template caps each at one page. |
| Wave 1 format turns out wrong after lots of work | Wave 0 proves format on Ch 3 before bulk work begins. |

## 13. Acceptance criteria for v1.0

The dossier is "done" when all of the following hold:

- [ ] All chapters listed in §4 exist and follow the template in §6/§7/§8.
- [ ] Every module chapter contains at least one annotated screenshot.
- [ ] Standards benchmark contains all 13 frameworks listed in §3.
- [ ] Roadmap renders all 4 phases as in §8.
- [ ] `build.ps1` produces `CIHRMS_Delivery_Dossier_v1.0.docx` cleanly with no Pandoc errors.
- [ ] Word opens the `.docx`, applies branded styles, and can export to PDF without manual fixup.
- [ ] PDF is between 280 and 360 pages.
- [ ] Cover page renders with brand colors and CIHRMS wordmark.
- [ ] TOC is clickable in the exported PDF.
- [ ] Module index and standards cross-reference resolve correctly.
- [ ] User has approved each wave's `.docx` before the next began.

## 14. Inputs to mine (existing docs the dossier synthesizes from)

| Source | Used by |
|---|---|
| `docs/PRD.md` | Ch 1, Ch 17 |
| `docs/TRD.md`, `docs/SYSTEM_ARCHITECTURE.md`, `docs/SYSTEM_DESIGN_DIAGRAMS.md` | Part II |
| `docs/PHASE_1_DELIVERY.md`, `docs/PHASE_2_TIME_ATTENDANCE_DELIVERY.md` | Ch 9 (Finance), Part III readiness claims |
| `docs/MARKET_READY_PUNCHLIST_V2.md` | Ch 28 (Why ready) |
| `docs/implementation_plan_2.md` + gap analysis memory | Ch 29, Ch 30 |
| `docs/wcag_aa_checklist.md` | Ch 27 (WCAG framework page) |
| `docs/PROJECT_STATE.md`, `docs/QA_REPORT.md` | At-a-glance, Part II testing chapter |
| Memory: `project_government_gap_analysis.md` | Part III in full |
| Memory: `project_cihrms_design.md` | Ch 2 (Design Language) |
| Memory: `project_finance_f1.md` through `project_finance_f5.md` | Ch 9 (Finance) |
| Memory: `project_audit_v2_complete.md` | At-a-glance, Ch 28 |

## 15. Open questions deferred to writing-plans

- Exact route list for screenshot capture (built during Wave 0 against the live app).
- Final color hex values for `reference.docx` (pulled from project Tailwind config during Wave 0).
- Whether Playwright is the right capture tool vs. lighter alternatives (decided when scripting begins).
- Annotation tool choice (Sharp vs. Pillow vs. manual export from a design tool).

These are tactical, not design-level, and belong in the implementation plan that follows this spec.

---

**Next step:** user reviews this spec. On approval, the `superpowers:writing-plans` skill produces the implementation plan that turns each wave above into a sequence of executable tasks.
