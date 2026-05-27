# CIHRMS Delivery Dossier — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Produce a single 280-360 page brand-styled Word `.docx` (which the user exports to PDF) that explains every CIHRMS feature, benchmarks the product against 13 government + international standards, and presents a 4-phase roadmap to fill remaining gaps.

**Architecture:** Markdown source per chapter under `docs/delivery_dossier/chapters/`. Pandoc renders all chapters through a brand-styled `reference.docx` into a single Word document. Screenshots captured via a Playwright script driving the live CIHRMS dev server. Executed in 5 waves, each producing a buildable `.docx` for user review before the next begins.

**Tech Stack:** Pandoc, PowerShell, Node + Playwright (for screenshots), Sharp (for annotations), Microsoft Word (for final PDF export). Source repo is `d:\CIHRMS\cihrms-mvp` (git, currently on `main`).

**Source spec:** `docs/superpowers/specs/2026-05-24-cihrms-delivery-dossier-design.md`

---

## File Structure

All paths relative to `d:\CIHRMS\cihrms-mvp\` unless absolute.

```
docs/delivery_dossier/
├─ README.md                       — build instructions for future maintainers
├─ build.ps1                       — Pandoc invocation (PowerShell)
├─ reference.docx                  — brand-styled Pandoc template
├─ CHANGELOG.md                    — wave-by-wave change log
├─ assets/
│  ├─ logo.svg                     — copied from public/images
│  ├─ palette.md                   — color tokens reference
│  └─ screenshots/
│     ├─ 03_employees/             — one folder per chapter (annotated PNGs)
│     ├─ 04_leave/
│     └─ …
├─ chapters/
│  ├─ 00_front_matter.md
│  ├─ 01_what_cihrms_is.md
│  ├─ 02_design_language.md
│  ├─ 03_employees.md
│  ├─ 04_leave.md
│  ├─ 05_tickets.md
│  ├─ 06_complaints.md
│  ├─ 07_recruitment.md
│  ├─ 08_payroll.md
│  ├─ 09_finance.md
│  ├─ 10_documents.md
│  ├─ 11_chat.md
│  ├─ 12_notifications.md
│  ├─ 13_audit_logs.md
│  ├─ 14_reports.md
│  ├─ 15_departments.md
│  ├─ 16_profile_portal.md
│  ├─ 17_role_tours.md
│  ├─ 18_cross_cutting.md
│  ├─ 19_architecture.md
│  ├─ 20_canonical_pattern.md
│  ├─ 21_data_model.md
│  ├─ 22_rbac.md
│  ├─ 23_security.md
│  ├─ 24_performance.md
│  ├─ 25_testing.md
│  ├─ 26_deployment.md
│  ├─ 27_standards_benchmark.md
│  ├─ 28_why_ready.md
│  ├─ 29_roadmap.md
│  ├─ 30_funding_sequencing.md
│  └─ 99_back_matter.md
├─ scripts/
│  ├─ capture_screenshots.mjs      — Playwright route walker (Node ESM)
│  ├─ annotate.mjs                 — Sharp-based callout overlay
│  └─ routes.json                  — chapter → routes mapping
└─ build/
   ├─ CIHRMS_Delivery_Dossier_v*.docx
   └─ CIHRMS_Delivery_Dossier_v1.0.pdf   (user exports this from Word)
```

---

## Reusable Reference — Chapter Template (used by every Part I chapter task)

Every Part I module chapter (Ch 3-16) follows this exact structure. **Do not invent variations.** Copy this skeleton, fill the placeholders.

```markdown
# Chapter N — <Module Name>

> *In one paragraph.* <What problem it solves · who uses it · why it matters. Three sentences max.>

## Where to find it

- **Sidebar location:** <e.g., "Workforce > Employees">
- **Roles that see it:** <list of roles from RBAC — e.g., super_admin, ceo, hr_admin, manager>
- **Related modules:** <e.g., "Leave (Ch 4), Payroll (Ch 8), Departments (Ch 15)">

## The screens

![<Screen name>](../assets/screenshots/NN_slug/<screen>.png)

*Callouts: ❶ <description> · ❷ <description> · ❸ <description>*

## Every button, every action

### <Screen Name 1>

| Button / field | What it does | Who can use it | Why it exists |
|---|---|---|---|
| **<Button label>** | <Plain-English action> | <roles> | <one-line rationale> |
| **<Button label>** | <…> | <…> | <…> |

> *Notes:* <validation rules · side effects · events fired · edge cases. Only when non-obvious.>

### <Screen Name 2>

*(same table format)*

## The data behind it

<Plain-English description of what the system stores and why. No SQL, no schema dumps — that's Part II's job. Mention the main entity, key relationships, and any rules a non-technical reader needs to understand the screen.>

## How it talks to other modules

- **<Event/integration name>** → <which other module reacts, and what happens>
- **<Event/integration name>** → <…>

## Standards touchpoints

- Relevant to **<standard name §clause>** — see Chapter 27.
- Relevant to **<standard name §clause>** — see Chapter 27.

## What's planned next

<1-3 lines drawn from the gap analysis or roadmap. If nothing planned, write: "No active workstream — module is feature-complete for v1.0 scope.">
```

---

## Reusable Reference — Standards Page Template (used by every framework in Ch 27)

```markdown
## <Framework Name>

**What it is.** <2-3 plain-English sentences.>

**Why it matters.** <Why a buyer or regulator would ask about this.>

**CIHRMS today.** **<● Met / ● Partial / ● Not yet>** — <one-sentence summary with § ref(s) to Part I/II chapters that prove it>.

| Clause | Requirement | CIHRMS evidence | Status |
|---|---|---|---|
| <ID> | <Requirement text> | <Chapter/feature reference> | ● Met |
| <ID> | <Requirement text> | <Chapter/feature reference> | ◐ Partial |
| <ID> | <Requirement text> | <Chapter/feature reference> | ○ Not yet |

**Gap & path.** <What's missing + which Phase of the roadmap (Ch 29) addresses it.>
```

---

## Reusable Reference — Module Inventory Commands

Used by Wave 1 module chapter tasks (W1.3 onward). Substitute `<ModuleName>` and `<route-keyword>`.

```powershell
# 1. List Inertia pages for the module
Get-ChildItem d:\CIHRMS\cihrms-mvp\resources\js\Pages\<ModuleName> -Recurse -File |
  Select-Object FullName

# 2. List routes matching the module keyword
Select-String -Path d:\CIHRMS\cihrms-mvp\routes\web.php `
  -Pattern "<route-keyword>" -CaseSensitive:$false

# 3. List Controllers/Services/FormRequests for the module
Get-ChildItem d:\CIHRMS\cihrms-mvp\app -Recurse -File |
  Where-Object { $_.Name -match "<route-keyword>" -and $_.Extension -eq ".php" } |
  Select-Object FullName
```

---

## Reusable Reference — Build-and-Commit Pattern

Used by every Wave 1/2/3 chapter task. Substitute `<NN>`, `<title-slug>`, `<commit-subject>`, `<version>`.

```powershell
cd d:\CIHRMS\cihrms-mvp\docs\delivery_dossier
pwsh -File build.ps1 -Version <version>
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/chapters/<NN>_<title-slug>.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): Ch <NN> <commit-subject>"
```

Expected: `build/CIHRMS_Delivery_Dossier_<version>.docx` updates; one new commit on `dossier/v1.0`.

---

## Wave 0 — Foundation

Builds the empty dossier scaffolding, the brand-styled Pandoc template, the build script, and one fully-walked proof-of-format chapter (Ch 3 Employees). End-state: `build/CIHRMS_Delivery_Dossier_v0.1.docx` exists and looks branded when opened in Word.

### Task W0.1: Verify and install Pandoc

**Files:** none modified.

- [ ] **Step 1: Check if Pandoc is installed**

Run:
```powershell
Get-Command pandoc -ErrorAction SilentlyContinue
```
Expected: returns command path, or returns nothing if not installed.

- [ ] **Step 2: Install Pandoc if missing**

If Step 1 returned nothing, run:
```powershell
winget install --id JohnMacFarlane.Pandoc -e --accept-source-agreements --accept-package-agreements
```
Then open a fresh PowerShell window (so PATH refreshes).

- [ ] **Step 3: Verify install**

Run:
```powershell
pandoc --version | Select-Object -First 1
```
Expected: prints e.g., `pandoc.exe 3.5` (any 3.x is fine).

- [ ] **Step 4: Verify Node + Sharp install path is open**

Run:
```powershell
node --version; npm --version
```
Expected: Node >= 18, npm present. (No commit — this is a check task.)

### Task W0.2: Create a dossier branch

**Files:** git only.

- [ ] **Step 1: Create and switch to a dossier branch**

Run:
```powershell
git -C d:\CIHRMS\cihrms-mvp checkout -b dossier/v1.0
git -C d:\CIHRMS\cihrms-mvp status
```
Expected: now on branch `dossier/v1.0`, working tree clean.

### Task W0.3: Scaffold the dossier directory tree

**Files:**
- Create: `docs/delivery_dossier/` and subdirs (see File Structure above)
- Create: `docs/delivery_dossier/.gitignore`

- [ ] **Step 1: Create the directory tree**

Run:
```powershell
$root = "d:\CIHRMS\cihrms-mvp\docs\delivery_dossier"
$dirs = @(
  "$root",
  "$root\assets",
  "$root\assets\screenshots",
  "$root\chapters",
  "$root\scripts",
  "$root\build"
)
foreach ($d in $dirs) { if (-not (Test-Path $d)) { New-Item -ItemType Directory -Path $d | Out-Null } }
Get-ChildItem $root -Recurse -Directory | Select-Object FullName
```
Expected: 6 directories listed.

- [ ] **Step 2: Write `.gitignore` to keep build artifacts out**

Write to `docs/delivery_dossier/.gitignore`:
```
build/*.docx
build/*.pdf
!build/.gitkeep
```

- [ ] **Step 3: Write `.gitkeep` files for empty dirs**

Run:
```powershell
New-Item -ItemType File "$root\build\.gitkeep" -Force | Out-Null
New-Item -ItemType File "$root\assets\screenshots\.gitkeep" -Force | Out-Null
```

- [ ] **Step 4: Commit the scaffold**

Run:
```powershell
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier
git -C d:\CIHRMS\cihrms-mvp commit -m "chore(dossier): scaffold delivery_dossier directory tree"
```

### Task W0.4: Copy brand assets

**Files:**
- Create: `docs/delivery_dossier/assets/logo.svg` (or `.png` if no SVG exists)
- Create: `docs/delivery_dossier/assets/palette.md`

- [ ] **Step 1: Locate existing CIHRMS logo**

Run:
```powershell
Get-ChildItem d:\CIHRMS\cihrms-mvp\public\images -Filter "*logo*" -Recurse | Select-Object FullName
Get-ChildItem d:\CIHRMS\cihrms-mvp\docs -Filter "*logo*" -Recurse | Select-Object FullName
```
Expected: at least one logo file (likely `cihrm_logo-min.png` from `docs/`).

- [ ] **Step 2: Copy the logo into the dossier assets**

Run (adjust source path to whatever Step 1 found):
```powershell
Copy-Item "d:\CIHRMS\cihrms-mvp\docs\cihrm_logo-min.png" `
          "d:\CIHRMS\cihrms-mvp\docs\delivery_dossier\assets\logo.png"
```

- [ ] **Step 3: Extract brand color tokens**

Read the active Tailwind config and the design memory:
```powershell
Get-Content d:\CIHRMS\cihrms-mvp\tailwind.config.js | Select-String -Pattern "colors|obsidian|cobalt|primary" -Context 0,5
```
Cross-reference with memory `project_cihrms_design.md`. Extract the obsidian (dark background), cobalt (accent), and supporting text colors.

- [ ] **Step 4: Write `assets/palette.md`** with the resolved hex values

Write to `docs/delivery_dossier/assets/palette.md`:
```markdown
# CIHRMS Sovereign Precision — Color Tokens

> Source: `tailwind.config.js` + `docs/SYSTEM_ARCHITECTURE.md` + memory `project_cihrms_design.md`.

| Token | Hex | Use |
|---|---|---|
| Obsidian (sidebar / cover background) | `#XXXXXX` | Cover background, headers |
| Cobalt (accent) | `#XXXXXX` | Headings, callout bars, links |
| Ink (primary text) | `#XXXXXX` | Body text |
| Mist (secondary text) | `#XXXXXX` | Captions, footnotes |
| Cloud (paper background) | `#FAFAFA` | Page background |
| Success | `#XXXXXX` | "Met" badges in standards matrix |
| Warning | `#XXXXXX` | "Partial" badges |
| Danger | `#XXXXXX` | "Not yet" badges |
```
Fill in the `XXXXXX` values from the Tailwind config in Step 3.

- [ ] **Step 5: Commit**

Run:
```powershell
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/assets
git -C d:\CIHRMS\cihrms-mvp commit -m "chore(dossier): copy logo and document brand palette"
```

### Task W0.5: Build the brand-styled `reference.docx`

**Files:**
- Create: `docs/delivery_dossier/reference.docx`

- [ ] **Step 1: Generate a base reference.docx from Pandoc**

Run:
```powershell
cd d:\CIHRMS\cihrms-mvp\docs\delivery_dossier
pandoc --print-default-data-file reference.docx > reference.docx
```
Expected: `reference.docx` created (~30 KB).

- [ ] **Step 2: Open `reference.docx` in Word and customize styles**

This step is **manual in Microsoft Word.** Open `docs/delivery_dossier/reference.docx` and modify the following named styles (Home > Styles pane > Modify):

| Style | Font | Size | Weight | Color | Other |
|---|---|---|---|---|---|
| Title | Instrument Serif | 32 pt | Regular | Obsidian hex | Center, 24 pt before |
| Subtitle | Plus Jakarta Sans | 14 pt | Light | Cobalt hex | Center |
| Heading 1 | Plus Jakarta Sans | 22 pt | Bold | Obsidian | 24 pt before, 12 pt after, page-break-before |
| Heading 2 | Plus Jakarta Sans | 18 pt | Semibold | Obsidian | 18 pt before, 8 pt after |
| Heading 3 | Plus Jakarta Sans | 14 pt | Semibold | Cobalt | 12 pt before, 6 pt after |
| Heading 4 | Plus Jakarta Sans | 12 pt | Semibold | Ink | 10 pt before |
| Normal | Plus Jakarta Sans | 11 pt | Regular | Ink | 1.35 line height, 6 pt after |
| Block Text (callouts) | Plus Jakarta Sans | 10.5 pt | Regular | Ink | Left border 3 pt cobalt, left indent 0.25" |
| Source Code | Consolas | 10 pt | Regular | Ink | Background `#F4F4F5` |
| Caption | Plus Jakarta Sans | 9.5 pt | Italic | Mist | Center under figures |
| Header | Plus Jakarta Sans | 9 pt | Regular | Mist | "CIHRMS Delivery Dossier" left, page number right |
| Footer | Plus Jakarta Sans | 9 pt | Regular | Mist | © 2026 CIHRMS Engineering Team, center |

Set page size to **A4 portrait**, margins **2.0 cm top/bottom, 2.2 cm left/right**.

Use the hex values from `assets/palette.md`. If Plus Jakarta Sans / Instrument Serif are not installed in Windows Fonts, install them from Google Fonts first.

- [ ] **Step 3: Save the modified `reference.docx`**

Save as `docs/delivery_dossier/reference.docx` (overwrite). Close Word.

- [ ] **Step 4: Commit**

Run:
```powershell
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/reference.docx
git -C d:\CIHRMS\cihrms-mvp commit -m "chore(dossier): add brand-styled Pandoc reference.docx"
```

### Task W0.6: Write `build.ps1`

**Files:**
- Create: `docs/delivery_dossier/build.ps1`

- [ ] **Step 1: Write the build script**

Write to `docs/delivery_dossier/build.ps1`:
```powershell
#!/usr/bin/env pwsh
# Builds the CIHRMS Delivery Dossier .docx from chapter markdown files.
# Usage:   pwsh -File build.ps1 [-Version v0.1]

param([string]$Version = "v0.1")

$ErrorActionPreference = "Stop"
$root = $PSScriptRoot
$chapters = Get-ChildItem "$root\chapters" -Filter "*.md" | Sort-Object Name
if ($chapters.Count -eq 0) { throw "No chapter files found in $root\chapters" }

Write-Host "Building dossier from $($chapters.Count) chapter file(s)..."

$outputPath = "$root\build\CIHRMS_Delivery_Dossier_$Version.docx"

pandoc $chapters.FullName `
  --reference-doc="$root\reference.docx" `
  --toc --toc-depth=3 `
  --top-level-division=chapter `
  --resource-path="$root" `
  --metadata title="CIHRMS Delivery Dossier" `
  --metadata subtitle="Features, Standards, and Market Readiness" `
  --metadata author="CIHRMS Engineering Team" `
  --metadata date="2026-05-24" `
  -o $outputPath

if (-not (Test-Path $outputPath)) { throw "Build failed — output not created." }
$size = [math]::Round((Get-Item $outputPath).Length / 1KB, 1)
Write-Host "Built $outputPath ($size KB)"
```

- [ ] **Step 2: Commit**

Run:
```powershell
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/build.ps1
git -C d:\CIHRMS\cihrms-mvp commit -m "chore(dossier): add Pandoc build script"
```

### Task W0.7: Write the dossier README

**Files:**
- Create: `docs/delivery_dossier/README.md`

- [ ] **Step 1: Write the README**

Write to `docs/delivery_dossier/README.md`:
```markdown
# CIHRMS Delivery Dossier — Build Instructions

This folder produces `CIHRMS_Delivery_Dossier_v*.docx` — the canonical end-to-end
explanation of the CIHRMS product, intended for both technical and non-technical
stakeholders.

## Prerequisites

- **Pandoc** ≥ 3.0  (`winget install --id JohnMacFarlane.Pandoc -e`)
- **Microsoft Word** (any modern version — used to export the final PDF)
- **Plus Jakarta Sans** and **Instrument Serif** fonts installed in Windows Fonts
- **Node** ≥ 18 + **npm** (only needed if rebuilding screenshots)

## Build the .docx

```powershell
cd d:\CIHRMS\cihrms-mvp\docs\delivery_dossier
pwsh -File build.ps1 -Version v1.0
```

Output lands in `build/CIHRMS_Delivery_Dossier_v1.0.docx`.

## Export the PDF

1. Open `build/CIHRMS_Delivery_Dossier_v1.0.docx` in Microsoft Word.
2. (Optional) Make last-mile edits.
3. `File → Save As → PDF`. Save next to the .docx as `CIHRMS_Delivery_Dossier_v1.0.pdf`.

## Re-capture screenshots

Only needed if the CIHRMS UI has changed materially:

```powershell
cd d:\CIHRMS\cihrms-mvp
npm install --no-save playwright sharp           # one-time
npx playwright install chromium                  # one-time
cd docs\delivery_dossier\scripts
node capture_screenshots.mjs
node annotate.mjs
```

## Source layout

- `chapters/*.md` — content, one file per chapter (numbered)
- `reference.docx` — Pandoc style template (brand colors and fonts)
- `assets/` — logo, palette reference, captured screenshots
- `scripts/` — Playwright capture + annotation
- `build/` — generated artifacts (gitignored)
- `CHANGELOG.md` — version history

## Conventions

- Chapter files are numbered `NN_slug.md` so Pandoc concatenates in order.
- Every Part I module chapter uses the template in
  `docs/superpowers/plans/2026-05-24-cihrms-delivery-dossier-plan.md`
  ("Chapter Template" section). Do not invent variations.
- Voice per part: Part I = plain-English advocate, Part II = peer engineer,
  Part III = neutral auditor. See spec §5.

## Versioning

Bumped at each wave boundary in `CHANGELOG.md`. Don't ship a version that hasn't
been reviewed and approved by the user.
```

- [ ] **Step 2: Commit**

Run:
```powershell
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/README.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): add README with build instructions"
```

### Task W0.8: Initialize CHANGELOG

**Files:**
- Create: `docs/delivery_dossier/CHANGELOG.md`

- [ ] **Step 1: Write the CHANGELOG**

Write to `docs/delivery_dossier/CHANGELOG.md`:
```markdown
# CIHRMS Delivery Dossier — Change Log

All notable changes to this dossier are listed here.

## [v0.1] — 2026-05-24 — Wave 0: Foundation
- Scaffolded `docs/delivery_dossier/` tree.
- Built brand-styled `reference.docx` from the Sovereign Precision palette.
- Added `build.ps1` Pandoc build script.
- Wrote Ch 3 (Employees) as proof-of-format.
```

- [ ] **Step 2: Commit**

Run:
```powershell
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/CHANGELOG.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): seed CHANGELOG with v0.1"
```

### Task W0.9: Write a minimal front matter placeholder

**Files:**
- Create: `docs/delivery_dossier/chapters/00_front_matter.md`

This is a placeholder for Wave 0 only — the real cover, exec summary, etc. are written in Wave 4. The placeholder lets Pandoc generate a valid `.docx` so we can prove the styling works.

- [ ] **Step 1: Write the placeholder front matter**

Write to `docs/delivery_dossier/chapters/00_front_matter.md`:
```markdown
---
title: CIHRMS Delivery Dossier
subtitle: Features, Standards, and Market Readiness
author: CIHRMS Engineering Team
date: 2026-05-24
---

# CIHRMS Delivery Dossier {.unnumbered}

*Features, Standards, and Market Readiness*

CIHRMS Engineering Team · 2026-05-24

\newpage

# About this document {.unnumbered}

> This is a Wave 0 proof-of-format build. Real cover, executive summary,
> table of contents, and reader's map will land in Wave 4.

\newpage
```

- [ ] **Step 2: Commit**

Run:
```powershell
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/chapters/00_front_matter.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): placeholder front matter for Wave 0"
```

### Task W0.10: Write Ch 3 (Employees) as proof-of-format

**Files:**
- Create: `docs/delivery_dossier/chapters/03_employees.md`
- Create: `docs/delivery_dossier/assets/screenshots/03_employees/` (empty — screenshots come later in Wave 1)

This task proves the entire pipeline works: a fully-walked chapter with the template applied, built into a real `.docx`. **No screenshots yet** — leave placeholder image references; they resolve once Wave 1 captures real images.

- [ ] **Step 1: Inventory the Employees module**

Run:
```powershell
Get-ChildItem d:\CIHRMS\cihrms-mvp\resources\js\Pages\Employees -Recurse -File | Select-Object FullName
Get-ChildItem d:\CIHRMS\cihrms-mvp\app\Http\Controllers -Filter "*Employee*" | Select-Object FullName
```
Expected: list of Inertia pages (e.g., Index.vue, Show.vue, Create.vue, Edit.vue) and EmployeeController.

```powershell
Select-String -Path d:\CIHRMS\cihrms-mvp\routes\web.php -Pattern "employee" -CaseSensitive:$false
```
Expected: every employees route, with method + URI + name.

- [ ] **Step 2: Read source material**

Read each of these files completely before drafting:
- `app/Http/Controllers/EmployeeController.php` (or equivalent)
- `app/Services/EmployeeService.php`
- `app/Http/Requests/StoreEmployeeRequest.php`, `UpdateEmployeeRequest.php`
- `app/Models/Employee.php`
- `resources/js/Pages/Employees/Index.vue`, `Show.vue`, `Create.vue`, `Edit.vue`
- `docs/PRD.md` (the Employees section)
- Memory: `project_employee_portal.md`
- Memory: `project_rbac.md` (to know which roles see what)

- [ ] **Step 3: Draft `03_employees.md` using the Chapter Template**

Apply the **Chapter Template** at the top of this plan. Fill the placeholders with content drawn from Step 2. Image references should be `../assets/screenshots/03_employees/<screen>.png` — these files don't exist yet; that's fine for Wave 0.

Concrete content checklist for Ch 3:
- **In one paragraph:** Employees is the master record of the workforce — every person in the system has one. HR creates and edits, managers and the employees themselves view; finance reads it to drive payroll.
- **Where to find it:** Sidebar > Workforce > Employees. Visible to super_admin, ceo, hr_admin, manager (subset for direct reports), employee (self only via Profile portal — Ch 16).
- **The screens:** Employee directory (table), Employee detail (tabs), Create form, Edit form. Add four image references even though images don't exist yet.
- **Every button, every action:** one subsection per screen. Cover at minimum: filter/search bar, role-based action buttons (Create, Edit, Deactivate, Export), pagination, bulk-select if present, all form fields (Name, Staff ID, Email, Department, Position, Hire date, Employment type, Salary basis, etc.), Save / Cancel / Delete with confirmations.
- **The data behind it:** the expanded Employee schema (personal, emergency contact, bank, skills, identity numbers) per memory `project_employee_portal.md`.
- **How it talks to other modules:** EmployeeCreated event → Analytics + Notifications; Department FK to Departments (Ch 15); referenced by Leave (Ch 4), Tickets (Ch 5), Payroll (Ch 8), every approval chain.
- **Standards touchpoints:** ISO 30414 §4.1 (workforce composition), DPA Act 843 §17 (personal data processing), Ghana Card adapter (Phase 1).
- **What's planned next:** Phase 1 adds positions/grades/steps + Ghana Card field validation.

- [ ] **Step 4: Build v0.1.docx**

Run:
```powershell
cd d:\CIHRMS\cihrms-mvp\docs\delivery_dossier
pwsh -File build.ps1 -Version v0.1
```
Expected: `build/CIHRMS_Delivery_Dossier_v0.1.docx` created, ~50-200 KB.

- [ ] **Step 5: Smoke check the .docx in Word**

Open `build/CIHRMS_Delivery_Dossier_v0.1.docx` in Microsoft Word manually. Confirm:
- Cover page uses Instrument Serif title.
- "About this document" page appears.
- "Chapter 3 — Employees" appears with Plus Jakarta Sans heading.
- Body text is Plus Jakarta Sans 11 pt.
- Tables in "Every button" render with cobalt header rows.
- Image placeholders show "image not found" (expected — captures happen in Wave 1).

If any styling is off, return to Task W0.5 and adjust `reference.docx`, then re-run W0.10 Step 4.

- [ ] **Step 6: Commit Ch 3**

Run:
```powershell
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/chapters/03_employees.md docs/delivery_dossier/assets/screenshots/03_employees
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): Ch 3 Employees as proof-of-format"
```

### Task W0.11: Wave 0 review checkpoint — STOP

- [ ] **Step 1: Hand v0.1.docx to the user**

Tell the user: "Wave 0 is complete. Open `docs/delivery_dossier/build/CIHRMS_Delivery_Dossier_v0.1.docx` in Word and tell me:
1. Does the brand styling look right?
2. Does the Ch 3 (Employees) template format work for you, or should I adjust it before applying it to 13 more chapters?

Approve, request changes, or rework — I will not start Wave 1 until you confirm."

**Do not proceed to Wave 1 until the user approves.**

---

## Wave 1 — Part I (Product) chapters

Writes chapters 1, 2, 4-18 (Ch 3 already done in Wave 0). Captures screenshots and embeds them. Ends with a buildable v0.5.docx.

### Task W1.1: Write Ch 1 — "What CIHRMS is and who it's for"

**Files:** Create `docs/delivery_dossier/chapters/01_what_cihrms_is.md`

This is the opening chapter of Part I. Sets the stage for everything that follows.

- [ ] **Step 1: Read source material**

- `docs/PRD.md` (full)
- `docs/PROJECT_STATE.md`
- Memory: `project_cihrms.md`, `project_stack.md`

- [ ] **Step 2: Draft the chapter**

Write `01_what_cihrms_is.md`. Cover:
- **What CIHRMS is** — Ghana-focused HRMS, Laravel + Vue, web-based, single tenant per organization
- **Who it's for** — public sector MDAs as the primary target; commercial buyers as the adjacent market
- **The 14+ modules at a glance** — bulleted list, each module a one-liner
- **What makes it different** — Ghana-specific design (staff_id login, RBAC built for ministries, planned Ghana Card/IPPD integrations) + commercial-grade engineering (973 tests, 121 services, V2 audit hardened)
- **How to read the rest of this dossier** — pointer to the reader's map (Ch 0)

Length: 3-5 pages.

- [ ] **Step 3: Build and commit**

Run:
```powershell
cd d:\CIHRMS\cihrms-mvp\docs\delivery_dossier
pwsh -File build.ps1 -Version v0.2
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/chapters/01_what_cihrms_is.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): Ch 1 What CIHRMS is and who it's for"
```

### Task W1.2: Write Ch 2 — "The Sovereign Precision design language"

**Files:** Create `docs/delivery_dossier/chapters/02_design_language.md`

- [ ] **Step 1: Read source material**

- Memory: `project_cihrms_design.md`
- `tailwind.config.js`
- `resources/css/app.css`
- `resources/js/Components/` (sample a handful: StatusBadge.vue, EmptyState.vue, KanbanBoard.vue)
- `docs/SYSTEM_ARCHITECTURE.md` (UI section if any)

- [ ] **Step 2: Draft the chapter**

Cover:
- **The name** — Sovereign Precision. Why it's named that (gov-grade gravitas + product polish)
- **The palette** — obsidian sidebar, cobalt accents (cite hex from palette.md)
- **Typography** — Plus Jakarta Sans (UI), Instrument Serif (display), Material Symbols Outlined (icons)
- **Animations** — the `animate-reveal-up` standard wrapper, stat-card RGB tinting
- **Component vocabulary** — StatusBadge, EmptyState, Pagination, SlidePanel, KanbanBoard
- **Accessibility hooks** — color contrast, focus states (forward ref to Ch 18 cross-cutting)

Length: 4-6 pages, includes a reproduced palette swatch table.

- [ ] **Step 3: Build and commit**

Run:
```powershell
pwsh -File build.ps1 -Version v0.2
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/chapters/02_design_language.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): Ch 2 Sovereign Precision design language"
```

### Tasks W1.3 — W1.16: Module chapters (Leave through Profile Portal)

Each task below follows the **same structure** — read sources, apply Chapter Template, build, commit. Sources differ per module.

For every module chapter task, the steps are:

1. **Inventory routes/pages** — Grep `routes/web.php` for the module name; list Vue pages under `resources/js/Pages/<Module>/`; list Controller/Service/FormRequests.
2. **Read source material** — see per-task list below.
3. **Draft chapter** — apply the **Chapter Template** at the top of this plan. Fill all sections; do not skip any.
4. **Build** — `pwsh -File build.ps1 -Version v0.2`.
5. **Commit** — `git add chapters/NN_slug.md; git commit -m "docs(dossier): Ch N <title>"`.

#### Task W1.3 — Ch 4 Leave

**Files:** Create `docs/delivery_dossier/chapters/04_leave.md`

- [ ] **Step 1: Inventory**

```powershell
Get-ChildItem d:\CIHRMS\cihrms-mvp\resources\js\Pages\Leave -Recurse -File | Select-Object FullName
Select-String -Path d:\CIHRMS\cihrms-mvp\routes\web.php -Pattern "leave" -CaseSensitive:$false
```

- [ ] **Step 2: Read**

- `app/Http/Controllers/LeaveRequestController.php`
- `app/Services/LeaveService.php`
- `app/Http/Requests/StoreLeaveRequestRequest.php` and any approve/reject FormRequests
- `app/Models/LeaveRequest.php`, `app/Enums/LeaveStatus.php`, `app/Enums/LeaveType.php`
- All `resources/js/Pages/Leave/*.vue`
- `docs/PRD.md` (Leave section)

- [ ] **Step 3: Draft `04_leave.md`** per Chapter Template

Required content checklist:
- Sidebar location and roles
- Screens: Leave list (employee view + manager approval view + HR view), Request form, Approval modal/page, Calendar view if exists
- Every button: Request, Approve, Reject, Cancel, filters (status, type, date range), bulk approve if any
- Data: LeaveRequest fields (type, start/end, days, reason, attachments), leave balances if implemented
- Cross-module: LeaveRequested + LeaveStatusUpdated events → Notifications + Analytics; consumed by Payroll for paid/unpaid days
- Standards: ISO 30414 §4.5 (absenteeism), Ghana Labour Act 651 (annual leave, sick leave, maternity)
- Next: per gap analysis, Phase 2 adds attendance integration and OT under Act 651

- [ ] **Step 4: Build and commit**

Run:
```powershell
pwsh -File build.ps1 -Version v0.2
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/chapters/04_leave.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): Ch 4 Leave"
```

#### Task W1.4 — Ch 5 Tickets

**Files:** Create `docs/delivery_dossier/chapters/05_tickets.md`

- [ ] **Step 1: Inventory** — apply Module Inventory pattern with `<ModuleName>=Tickets`, `<route-keyword>=ticket`

- [ ] **Step 2: Read**

- `app/Http/Controllers/TicketController.php`
- `app/Services/TicketService.php`
- `app/Http/Requests/StoreTicketRequest.php`, any state-change FormRequests
- `app/Models/Ticket.php`, `app/Enums/TicketStatus.php`, `app/Enums/TicketPriority.php`
- All `resources/js/Pages/Tickets/*.vue` (Index, Show, Create — likely uses KanbanBoard)
- `docs/PRD.md` (Service Desk section)

- [ ] **Step 3: Draft `05_tickets.md`** per Chapter Template

Required content checklist:
- Sidebar location and roles (it_support is the primary handler)
- Screens: Ticket list/kanban, Ticket detail (timeline + comments + status changes), Create form
- Every button: Create, Assign, Change status (open/in_progress/resolved/closed), Change priority, Comment, Attach file, Bulk assign on kanban drag
- Data: Ticket fields (title, description, priority, status, assignee, requester, category)
- Cross-module: TicketCreated event → Notifications + Analytics; references Employee (requester + assignee)
- Standards: ITIL-aligned status taxonomy (not certified, but mapped); ISO 27001 A.16 (information security incident management — relevant for security tickets)
- Next: integration with Notifications for SLA escalations (planned)

- [ ] **Step 4: Build and commit**

```powershell
pwsh -File build.ps1 -Version v0.2
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/chapters/05_tickets.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): Ch 5 Tickets"
```

#### Task W1.5 — Ch 6 Complaints

**Files:** Create `docs/delivery_dossier/chapters/06_complaints.md`

- [ ] **Step 1: Inventory** — apply Module Inventory pattern with `<ModuleName>=Complaints`, `<route-keyword>=complaint`

- [ ] **Step 2: Read**

- `app/Http/Controllers/ComplaintController.php`
- `app/Services/ComplaintService.php`
- `app/Http/Requests/StoreComplaintRequest.php`
- `app/Models/Complaint.php`, `app/Enums/ComplaintStatus.php`
- `resources/js/Pages/Complaints/*.vue`
- `docs/PRD.md` (Complaints / grievance section)

- [ ] **Step 3: Draft `06_complaints.md`** per Chapter Template

Required content checklist:
- Sidebar location and roles (employees raise, HR/CEO triage)
- Screens: Complaint list (anonymized view per role), Detail with comments/resolution
- Every button: Submit, Comment, Change status (open/under_review/resolved/dismissed), Assign reviewer, Mark confidential
- Data: Complaint fields, anonymity flag if present
- Cross-module: notifications on status change; analytics on volume
- Standards: **CHRAJ whistleblower channel** (Ghana Whistleblower Act 720) — note current gap vs full anonymous-channel requirement, forward to Phase 2
- Next: per gap analysis, Phase 2 adds a CHRAJ-aligned whistleblower channel + tamper-evident chain-of-custody on complaint events

- [ ] **Step 4: Build and commit**

```powershell
pwsh -File build.ps1 -Version v0.2
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/chapters/06_complaints.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): Ch 6 Complaints"
```

#### Task W1.6 — Ch 7 Recruitment + Public Careers

**Files:** Create `docs/delivery_dossier/chapters/07_recruitment.md`

- [ ] **Step 1: Inventory** — apply Module Inventory pattern twice: once with `<ModuleName>=Recruitment`, `<route-keyword>=recruitment|applicant|job-posting`, and again with `<ModuleName>=Careers`, `<route-keyword>=career`

- [ ] **Step 2: Read**

- `app/Http/Controllers/RecruitmentController.php`, `CareersController.php`
- `app/Services/RecruitmentService.php`
- All recruitment FormRequests
- `app/Models/JobPosting.php`, `app/Models/Applicant.php`, related Enums
- `resources/js/Pages/Recruitment/*.vue`, `resources/js/Pages/Careers/*.vue`
- Public landing/careers in `routes/web.php`

- [ ] **Step 3: Draft `07_recruitment.md`** per Chapter Template

Required content checklist:
- Two-audience module: HR admin view + public Careers view
- Sidebar location for HR; public URL for Careers
- Screens: Job postings list, Create/edit posting, Applicants pipeline (kanban), Applicant detail, Public job listing, Public application form
- Every button: HR side — Create posting, Publish/Unpublish, Move applicant through stages (applied/screening/interview/offer/hired/rejected), Send communication; Public side — Search, Filter, Apply, Upload CV
- Data: JobPosting (title, description, status, dept, deadline), Applicant (CV, contact, stage)
- Cross-module: ApplicantHired event triggers Employee creation flow; notifications on stage change
- Standards: ISO 30414 §4.2.3 (recruitment turnaround time), DPA Act 843 (consent for processing applicant data)
- Next: per gap analysis Phase 1+2 add pipeline depth and pre-boarding

- [ ] **Step 4: Build and commit**

```powershell
pwsh -File build.ps1 -Version v0.2
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/chapters/07_recruitment.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): Ch 7 Recruitment + Public Careers"
```

#### Task W1.7 — Ch 8 Payroll

**Files:** Create `docs/delivery_dossier/chapters/08_payroll.md`

- [ ] **Step 1: Inventory** — apply Module Inventory pattern with `<ModuleName>=Payments`, `<route-keyword>=payment|payroll|salary`

- [ ] **Step 2: Read**

- `app/Http/Controllers/PaymentController.php` and any PayrollController
- `app/Services/PaymentService.php`, any PayrollService
- `app/Models/Payment.php` and related
- `resources/js/Pages/Payments/*.vue`
- `docs/PRD.md` (Payroll section)
- Memory: `project_government_gap_analysis.md` (PAYE/SSNIT/Tier-2/Tier-3 facts — critical to flag the current gap)

- [ ] **Step 3: Draft `08_payroll.md`** per Chapter Template

Required content checklist:
- **Honesty up front**: current Payroll is payment-tracking; the **statutory engine (PAYE/SSNIT/Tier-2/NHIA) is Phase 1 of the roadmap** — say so plainly.
- Sidebar location; finance_officer + hr_admin + super_admin roles
- Screens: Payments list, Create payment, Payment detail with status (pending/paid/cancelled/failed)
- Every button: Create payment, Mark paid, Cancel, Filter by month/employee/status, Export
- Data: Payment fields (amount, currency GHS, period, employee, status, reference via SequenceService)
- Cross-module: PaymentCreated/Paid events → Analytics + Audit; references Employee
- Standards: relevant to **GRA PAYE (2026 brackets — cite memory)**, **SSNIT 18.5% (13/5.5)**, **NPRA 3-tier**, **GIFMIS export**, **E-Levy 1.5% on MoMo** — all currently in the "Not yet" or "Partial" status, forward to Ch 27 and Ch 29
- Next: Phase 1 ships full statutory engine with all four deductions, payslip PDF, GIFMIS export

- [ ] **Step 4: Build and commit**

```powershell
pwsh -File build.ps1 -Version v0.2
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/chapters/08_payroll.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): Ch 8 Payroll"
```

#### Task W1.8 — Ch 9 Finance (F1-F5)

**Files:** Create `docs/delivery_dossier/chapters/09_finance.md`

This is one of the longest module chapters — Finance is five sub-modules (F1 through F5).

- [ ] **Step 1: Inventory**

```powershell
Get-ChildItem d:\CIHRMS\cihrms-mvp\resources\js\Pages\Finance -Recurse -File | Select-Object FullName
Select-String -Path d:\CIHRMS\cihrms-mvp\routes\web.php -Pattern "finance|chart-of-account|payable|receivable|reconciliation|paystack" -CaseSensitive:$false
```

- [ ] **Step 2: Read**

- All Finance Controllers under `app/Http/Controllers/Finance/`
- All Finance Services under `app/Services/Finance/`
- All Finance Models and Enums
- All `resources/js/Pages/Finance/*.vue`
- Memory: `project_finance_f1.md`, `project_finance_f2.md`, `project_finance_f3.md`, `project_finance_f4.md`, `project_finance_f5.md`
- Memory: `project_finance_sequences.md` (SequenceService convention)

- [ ] **Step 3: Draft `09_finance.md`** per Chapter Template — **with five sub-sections inside "Every button"**

Required content checklist:
- **In one paragraph**: Finance is CIHRMS's accounting backbone — chart of accounts, payables, receivables, payment gateway, bank reconciliation. Five sub-modules, one coherent ledger. Differentiates CIHRMS from generic HRMS competitors.
- Sidebar: Finance Hub (entry point), then F1-F5 sub-areas
- Screens covered (one screenshot per sub-module):
  - F1 — Chart of Accounts, Org Bank Accounts, Finance Hub dashboard
  - F2 — Accounts Payable: bills list, bill create, journal preview
  - F3 — Accounts Receivable: invoices list, invoice create, customer statements
  - F4 — Paystack gateway: hosted checkout flow, payment confirmation
  - F5 — Bank Reconciliation: import (CSV/OFX/MT940), three-tier matching, bank-adjustment journal entries
- "Every button" subsections: one per sub-module, each with the full table
- Data: GL accounts, journal entries, bills, invoices, payments, bank statements, reconciliation sessions — plain-English
- Cross-module: Payment events → AR/AP entries; SequenceService for every reference (per `project_finance_sequences.md`)
- Standards: **IFRS general principles** (double-entry, journal integrity), **GIFMIS export** (planned), **CAGD chart-of-accounts mapping** (planned)
- Next: GIFMIS exporter scheduled in Phase 3

Expected length: 15-25 pages.

- [ ] **Step 4: Build and commit**

```powershell
pwsh -File build.ps1 -Version v0.2
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/chapters/09_finance.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): Ch 9 Finance F1-F5"
```

#### Task W1.9 — Ch 10 Documents

**Files:** Create `docs/delivery_dossier/chapters/10_documents.md`

- [ ] **Step 1: Inventory** — apply Module Inventory pattern with `<ModuleName>=Documents`, `<route-keyword>=document|annotation|stamp|letterhead|watermark`

- [ ] **Step 2: Read**

- All Documents controllers/services/models
- `resources/js/Pages/Documents/*.vue`
- Memory: `project_documents_v2.md`

- [ ] **Step 3: Draft `10_documents.md`** per Chapter Template

Required content checklist:
- Three-scope ownership model (per memory)
- Screens: Documents library, Upload, View/annotate, Stamp library, Letterhead library, Watermark library
- Every button: Upload, Open, Annotate (highlight/note/draw), Apply stamp, Apply letterhead, Apply watermark, Share, Download, Delete
- Data: documents, annotations (manipulable, not flattened), stamp/letterhead/watermark assets
- Cross-module: referenced by Tickets attachments, Recruitment CVs, Complaints evidence
- Standards: DPA Act 843 (document retention), ISO 27001 A.8 (asset management for document classifications)

- [ ] **Step 4: Build and commit**

```powershell
pwsh -File build.ps1 -Version v0.2
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/chapters/10_documents.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): Ch 10 Documents"
```

#### Task W1.10 — Ch 11 Chat

**Files:** Create `docs/delivery_dossier/chapters/11_chat.md`

- [ ] **Step 1: Inventory** — apply Module Inventory pattern with `<ModuleName>=Chat`, `<route-keyword>=chat|message|thread`

- [ ] **Step 2: Read**

- All Chat controllers/services/models
- `resources/js/Pages/Chat/*.vue` (or wherever it lives)
- Memory: `project_chat_redesign.md`

- [ ] **Step 3: Draft `11_chat.md`** per Chapter Template

Required content checklist:
- Single-column scrollable directory (per redesign memory)
- Screens: Chat directory, Thread view, Compose
- Every button: New chat, Send, Attach, Search, Filter (unread/all), Mark read, Delete thread
- Data: threads, messages, participants, read receipts
- Cross-module: Notifications fires on new message; references Employees (participants)
- Standards: DPA Act 843 (message retention + consent), ISO 27001 A.13 (communications security)

- [ ] **Step 4: Build and commit**

```powershell
pwsh -File build.ps1 -Version v0.2
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/chapters/11_chat.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): Ch 11 Chat"
```

#### Task W1.11 — Ch 12 Notifications

**Files:** Create `docs/delivery_dossier/chapters/12_notifications.md`

- [ ] **Step 1: Inventory** — apply Module Inventory pattern with `<ModuleName>=Notifications`, `<route-keyword>=notification`

- [ ] **Step 2: Read**

- `app/Http/Controllers/NotificationsController.php` (or equivalent)
- `resources/js/Pages/Notifications/*.vue`
- Laravel notification classes under `app/Notifications/`
- Memory: `project_sound_pack.md`

- [ ] **Step 3: Draft `12_notifications.md`** per Chapter Template

Required content checklist:
- Notification center (bell icon, panel, full page)
- Screens: Bell dropdown, Notifications index, Notification detail
- Every button: Mark read, Mark all read, Open linked entity, Delete, Filter by type
- Data: notification rows, read state, links
- Cross-module: fed by events from every module
- Sound packs: musical / cinematic / gamified — three packs, file-override architecture (per memory)
- Standards: WCAG 2.1 AA (accessible notification patterns), GDPR-parity (notification consent)

- [ ] **Step 4: Build and commit**

```powershell
pwsh -File build.ps1 -Version v0.2
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/chapters/12_notifications.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): Ch 12 Notifications"
```

#### Task W1.12 — Ch 13 Audit Logs

**Files:** Create `docs/delivery_dossier/chapters/13_audit_logs.md`

- [ ] **Step 1: Inventory** — apply Module Inventory pattern with `<ModuleName>=AuditLogs`, `<route-keyword>=audit`

- [ ] **Step 2: Read**

- `app/Http/Middleware/AuditTrail.php`
- `app/Jobs/WriteAuditLog.php`
- `app/Models/AuditLog.php`
- `resources/js/Pages/AuditLogs/*.vue`

- [ ] **Step 3: Draft `13_audit_logs.md`** per Chapter Template

Required content checklist:
- The audit trail is async (middleware dispatches Job on the `audit` queue) — non-blocking
- Roles: auditor + super_admin + ceo
- Screens: Audit log list (filters by user, action, date, model), Audit log detail
- Every button: Filter, Export, Search
- Data: actor, action, target model/id, payload diff, IP, user agent, timestamp
- Cross-module: every state-changing request flows through it
- Standards: DPA Act 843 §28 (record of processing activities), ISO 27001 A.12.4 (logging and monitoring), Cybersecurity Act 2020 (incident records)
- Next: per gap analysis, Phase 1 adds **tamper-evident** (hash-chain or append-only) audit — current implementation is mutable

- [ ] **Step 4: Build and commit**

```powershell
pwsh -File build.ps1 -Version v0.2
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/chapters/13_audit_logs.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): Ch 13 Audit Logs"
```

#### Task W1.13 — Ch 14 Reports

**Files:** Create `docs/delivery_dossier/chapters/14_reports.md`

- [ ] **Step 1: Inventory** — apply Module Inventory pattern with `<ModuleName>=Reports`, `<route-keyword>=report|analytics|dashboard`

- [ ] **Step 2: Read**

- `app/Http/Controllers/ReportsController.php`
- `app/Services/DashboardService.php` (per `project_cihrms.md`)
- `app/Listeners/RecordAnalyticsEvent.php`
- `resources/js/Pages/Reports/*.vue` and Dashboard.vue analytics sections

- [ ] **Step 3: Draft `14_reports.md`** per Chapter Template

Required content checklist:
- Dashboard widgets per role (DashboardService caches 60s per user)
- Screens: Reports landing, individual report views, dashboard tiles
- Every button: Filter, Date range, Export CSV/PDF, Refresh
- Data: aggregated analytics events (recorded async via `analytics` queue)
- Cross-module: receives events from every module
- Standards: ISO 30414 (the central reporting standard CIHRMS aspires to) — **call out which clauses are met today vs partial vs gap**
- Next: per gap analysis, Phase 3 adds Auditor-General report pack + establishment dashboard

- [ ] **Step 4: Build and commit**

```powershell
pwsh -File build.ps1 -Version v0.2
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/chapters/14_reports.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): Ch 14 Reports"
```

#### Task W1.14 — Ch 15 Departments

**Files:** Create `docs/delivery_dossier/chapters/15_departments.md`

- [ ] **Step 1: Inventory** — apply Module Inventory pattern with `<ModuleName>=Departments`, `<route-keyword>=department`

- [ ] **Step 2: Read**

- `app/Http/Controllers/DepartmentController.php`
- `app/Services/DepartmentService.php` (if exists)
- `app/Models/Department.php`
- `resources/js/Pages/Departments/*.vue`
- Memory: `project_rbac.md` (dept_head role)

- [ ] **Step 3: Draft `15_departments.md`** per Chapter Template

Required content checklist:
- Department tree (parent/child if implemented)
- dept_head role concept (per RBAC memory)
- Screens: Departments list/tree, Department detail, Create/edit
- Every button: Create, Edit, Reassign head, Move under parent, Deactivate
- Data: department fields, hierarchy if present, head FK to Employee
- Cross-module: every Employee belongs to one; Leave + Payroll + Reports filter by it
- Standards: foundation for the future **establishment control** (Phase 2 — positions/grades/steps/ceilings)
- Next: Phase 2 layers positions/grades/steps on top

- [ ] **Step 4: Build and commit**

```powershell
pwsh -File build.ps1 -Version v0.2
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/chapters/15_departments.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): Ch 15 Departments"
```

#### Task W1.15 — Ch 16 Profile / Employee Self-Service Portal

**Files:** Create `docs/delivery_dossier/chapters/16_profile_portal.md`

- [ ] **Step 1: Inventory** — apply Module Inventory pattern with `<ModuleName>=Profile`, `<route-keyword>=profile`

- [ ] **Step 2: Read**

- `app/Http/Controllers/ProfileController.php`
- `resources/js/Pages/Profile/*.vue`
- Memory: `project_employee_portal.md`

- [ ] **Step 3: Draft `16_profile_portal.md`** per Chapter Template

Required content checklist:
- Tabbed /profile self-service portal (per memory)
- Tabs: Personal, Emergency contact, Bank, Skills, Identity (Ghana Card # planned)
- Screens: each tab, password change flow, password-must-change first-login wall
- Every button: Edit tab, Save, Cancel, Upload photo, Change password
- Data: extended Employee fields per portal memory
- Cross-module: writes back to Employee; security flows from RBAC/password-must-change
- Standards: DPA Act 843 §38 (data subject rights — read your own data); WCAG (form accessibility)
- Next: full Phase 4 DPA data-subject-rights portal (export, erasure)

- [ ] **Step 4: Build and commit**

```powershell
pwsh -File build.ps1 -Version v0.2
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/chapters/16_profile_portal.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): Ch 16 Profile Portal"
```

#### Task W1.16 — Ch 17 Role-by-role tours

**Files:** Create `docs/delivery_dossier/chapters/17_role_tours.md`

Unlike module chapters, this chapter walks through a typical day for each of the 9 roles. **Does NOT use the module chapter template** — it's a narrative chapter.

- [ ] **Step 1: Read**

- Memory: `project_rbac.md`
- `app/Enums/UserRole.php`
- `app/Policies/*.php`
- Memory: `project_audit_v2_complete.md` (CEO mirrors super_admin)

- [ ] **Step 2: Draft `17_role_tours.md`**

For each role, write ~1 page covering: who they are, where they land after login, what they can see, the top 3-5 actions they do daily.

Roles to cover, in this order:
1. **super_admin** — wildcard `*`, full sidebar
2. **ceo** — mirrors super_admin (per audit memory)
3. **hr_admin** — workforce + leave + recruitment + payroll lite
4. **manager** — own team + leave approvals + reports for own dept
5. **employee** — Profile + Leave requests + Tickets + Chat + Notifications + Payslip (when Phase 1 ships)
6. **finance_officer** — Finance Hub + AP + AR + Payroll
7. **it_support** — Tickets owner, primary handler
8. **auditor** — Audit Logs + read-only across the app
9. **dept_head** — manager-equivalent for own department (per RBAC memory)

Length: 8-10 pages.

- [ ] **Step 3: Build and commit**

```powershell
pwsh -File build.ps1 -Version v0.2
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/chapters/17_role_tours.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): Ch 17 Role-by-role tours"
```

#### Task W1.17 — Ch 18 Cross-cutting features

**Files:** Create `docs/delivery_dossier/chapters/18_cross_cutting.md`

- [ ] **Step 1: Read**

- Memory: `project_rbac.md`, `project_sound_pack.md`, `project_cihrms_design.md`
- `docs/wcag_aa_checklist.md`

- [ ] **Step 2: Draft `18_cross_cutting.md`**

Cover (each ~1 page):
- **RBAC** in everyday use — sidebar visibility, per-user permissions overlay, what "permission" means in plain English
- **Sound packs** — three packs, switching them, accessibility (volume / mute respected)
- **Animations** — `animate-reveal-up`, the standard page wrapper, stat-card glow
- **Accessibility** — keyboard navigation, focus states, color contrast, screen reader hints, current WCAG status (from checklist)
- **Notifications + Chat unification** — how the realtime story works today
- **Search** — where it exists today, where it's planned

Length: 6-8 pages.

- [ ] **Step 3: Build and commit**

```powershell
pwsh -File build.ps1 -Version v0.2
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/chapters/18_cross_cutting.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): Ch 18 Cross-cutting features"
```

### Task W1.18: Build the screenshot capture script

**Files:**
- Create: `docs/delivery_dossier/scripts/routes.json`
- Create: `docs/delivery_dossier/scripts/capture_screenshots.mjs`

- [ ] **Step 1: Write `routes.json`** — chapter → routes mapping

Write to `docs/delivery_dossier/scripts/routes.json`:
```json
{
  "baseUrl": "http://127.0.0.1:8000",
  "viewport": { "width": 1920, "height": 1080 },
  "logins": {
    "super_admin": { "staff_id": "ADMIN001", "name": "Super Admin", "password": "password" },
    "hr_admin":    { "staff_id": "HR001",    "name": "HR Admin",    "password": "password" },
    "manager":     { "staff_id": "MGR001",   "name": "Manager",     "password": "password" },
    "employee":    { "staff_id": "EMP001",   "name": "Employee",    "password": "password" },
    "finance_officer": { "staff_id": "FIN001", "name": "Finance Officer", "password": "password" },
    "it_support":  { "staff_id": "IT001",    "name": "IT Support",  "password": "password" },
    "auditor":     { "staff_id": "AUD001",   "name": "Auditor",     "password": "password" }
  },
  "captures": [
    { "chapter": "03_employees", "as": "hr_admin", "screens": [
      { "name": "directory", "path": "/employees" },
      { "name": "detail",    "path": "/employees/1" },
      { "name": "create",    "path": "/employees/create" }
    ]},
    { "chapter": "04_leave", "as": "hr_admin", "screens": [
      { "name": "list",    "path": "/leave" },
      { "name": "request", "path": "/leave/create" }
    ]},
    { "chapter": "05_tickets", "as": "it_support", "screens": [
      { "name": "kanban", "path": "/tickets" },
      { "name": "detail", "path": "/tickets/1" },
      { "name": "create", "path": "/tickets/create" }
    ]},
    { "chapter": "06_complaints", "as": "hr_admin", "screens": [
      { "name": "list",   "path": "/complaints" },
      { "name": "detail", "path": "/complaints/1" }
    ]},
    { "chapter": "07_recruitment", "as": "hr_admin", "screens": [
      { "name": "postings",   "path": "/recruitment" },
      { "name": "pipeline",   "path": "/recruitment/applicants" },
      { "name": "public",     "path": "/careers" }
    ]},
    { "chapter": "08_payroll", "as": "finance_officer", "screens": [
      { "name": "list",   "path": "/payments" },
      { "name": "create", "path": "/payments/create" }
    ]},
    { "chapter": "09_finance", "as": "finance_officer", "screens": [
      { "name": "hub",        "path": "/finance" },
      { "name": "coa",        "path": "/finance/chart-of-accounts" },
      { "name": "payable",    "path": "/finance/payable" },
      { "name": "receivable", "path": "/finance/receivable" },
      { "name": "reconcile",  "path": "/finance/reconciliation" }
    ]},
    { "chapter": "10_documents",    "as": "hr_admin",     "screens": [{ "name": "library", "path": "/documents" }] },
    { "chapter": "11_chat",         "as": "employee",     "screens": [{ "name": "directory", "path": "/chat" }] },
    { "chapter": "12_notifications","as": "employee",     "screens": [{ "name": "index", "path": "/notifications" }] },
    { "chapter": "13_audit_logs",   "as": "super_admin",  "screens": [{ "name": "index", "path": "/audit-logs" }] },
    { "chapter": "14_reports",      "as": "super_admin",  "screens": [{ "name": "index", "path": "/reports" }] },
    { "chapter": "15_departments",  "as": "hr_admin",     "screens": [{ "name": "tree", "path": "/departments" }] },
    { "chapter": "16_profile_portal","as": "employee",    "screens": [
      { "name": "personal", "path": "/profile" },
      { "name": "bank",     "path": "/profile?tab=bank" },
      { "name": "skills",   "path": "/profile?tab=skills" }
    ]}
  ]
}
```

**Verify route paths first:** the actual paths may differ. Run:
```powershell
Select-String -Path d:\CIHRMS\cihrms-mvp\routes\web.php -Pattern "->name\("
```
Cross-reference and adjust the `path` values in `routes.json` to match real URIs.

- [ ] **Step 2: Write `capture_screenshots.mjs`**

Write to `docs/delivery_dossier/scripts/capture_screenshots.mjs`:
```javascript
// Captures CIHRMS screenshots per routes.json.
// Run from project root after `npm install --no-save playwright sharp`.
//
// Usage: node docs/delivery_dossier/scripts/capture_screenshots.mjs

import { chromium } from 'playwright';
import { readFile, mkdir } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const config = JSON.parse(await readFile(resolve(__dirname, 'routes.json'), 'utf8'));
const outputRoot = resolve(__dirname, '..', 'assets', 'screenshots');

const browser = await chromium.launch();
const context = await browser.newContext({ viewport: config.viewport });

async function login(page, role) {
  const creds = config.logins[role];
  if (!creds) throw new Error(`No login for role ${role}`);
  await page.goto(`${config.baseUrl}/login`);
  await page.fill('input[name=staff_id]', creds.staff_id);
  await page.fill('input[name=name]', creds.name);
  await page.fill('input[name=password]', creds.password);
  await page.click('button[type=submit]');
  await page.waitForURL(url => !url.toString().endsWith('/login'), { timeout: 10000 });
}

for (const group of config.captures) {
  const dir = resolve(outputRoot, group.chapter);
  await mkdir(dir, { recursive: true });
  const page = await context.newPage();
  try {
    await login(page, group.as);
  } catch (e) {
    console.warn(`Login as ${group.as} failed: ${e.message}`);
    await page.close();
    continue;
  }
  for (const screen of group.screens) {
    const target = `${config.baseUrl}${screen.path}`;
    try {
      await page.goto(target, { waitUntil: 'networkidle', timeout: 15000 });
      await page.waitForTimeout(800);  // let animations settle
      const out = resolve(dir, `${screen.name}.png`);
      await page.screenshot({ path: out, fullPage: true });
      console.log(`OK   ${group.chapter}/${screen.name}.png`);
    } catch (e) {
      console.warn(`FAIL ${group.chapter}/${screen.name} — ${e.message}`);
    }
  }
  await page.close();
}

await browser.close();
console.log('Done.');
```

- [ ] **Step 3: Commit**

```powershell
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/scripts/routes.json docs/delivery_dossier/scripts/capture_screenshots.mjs
git -C d:\CIHRMS\cihrms-mvp commit -m "feat(dossier): screenshot capture script"
```

### Task W1.19: Capture screenshots

**Files:** populates `docs/delivery_dossier/assets/screenshots/*/*.png`

- [ ] **Step 1: Seed demo data so screens are non-empty**

Run:
```powershell
cd d:\CIHRMS\cihrms-mvp
php artisan db:seed --class=DatabaseSeeder
```
Expected: seeder runs; fixed accounts (ADMIN001, HR001, …) created with `password=password`.

- [ ] **Step 2: Start the dev server in background**

Run:
```powershell
cd d:\CIHRMS\cihrms-mvp
npm run dev
```
(Run with `run_in_background: true` so the next steps can continue.) Wait ~10 seconds for Vite + artisan serve to be ready, then verify:
```powershell
Invoke-WebRequest -Uri http://127.0.0.1:8000/login -UseBasicParsing | Select-Object StatusCode
```
Expected: `200`.

- [ ] **Step 3: Install Playwright once**

Run:
```powershell
cd d:\CIHRMS\cihrms-mvp
npm install --no-save playwright sharp
npx playwright install chromium
```

- [ ] **Step 4: Run capture**

Run:
```powershell
cd d:\CIHRMS\cihrms-mvp
node docs/delivery_dossier/scripts/capture_screenshots.mjs
```
Expected: `OK <chapter>/<screen>.png` lines for each capture. Failures print a `FAIL` line — investigate per-route paths in `routes.json`.

- [ ] **Step 5: Verify screenshots landed**

Run:
```powershell
Get-ChildItem d:\CIHRMS\cihrms-mvp\docs\delivery_dossier\assets\screenshots -Recurse -Filter "*.png" |
  Select-Object Directory, Name |
  Group-Object Directory |
  ForEach-Object { "$($_.Name): $($_.Count) shots" }
```
Expected: at least 25 PNG files distributed across chapter folders.

- [ ] **Step 6: Stop the dev server**

Kill the background `npm run dev` process.

- [ ] **Step 7: Commit screenshots**

Run:
```powershell
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/assets/screenshots
git -C d:\CIHRMS\cihrms-mvp commit -m "chore(dossier): capture Part I screenshots"
```

### Task W1.20: Annotate screenshots (optional polish, can be deferred to Wave 4)

**Files:** Create `docs/delivery_dossier/scripts/annotate.mjs`

Numbered-callout overlay using Sharp. If time is tight, skip in Wave 1 and revisit in Wave 4.

- [ ] **Step 1: Write `annotate.mjs`** (minimal — overlays numbered circles per a JSON config)

Defer the full implementation to Wave 4 polish; for Wave 1 commit a stub:

Write to `docs/delivery_dossier/scripts/annotate.mjs`:
```javascript
// Annotation overlay — overlays numbered callouts on captured screenshots.
// Reads docs/delivery_dossier/scripts/annotations.json (created in Wave 4).
// Stub for now; implemented in Wave 4 polish.

console.log('annotate.mjs: stub — implemented in Wave 4 polish.');
process.exit(0);
```

- [ ] **Step 2: Commit**

```powershell
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/scripts/annotate.mjs
git -C d:\CIHRMS\cihrms-mvp commit -m "chore(dossier): annotation stub (full impl in Wave 4)"
```

### Task W1.21: Build v0.5 and bump CHANGELOG

- [ ] **Step 1: Build v0.5.docx**

```powershell
cd d:\CIHRMS\cihrms-mvp\docs\delivery_dossier
pwsh -File build.ps1 -Version v0.5
```
Expected: `build/CIHRMS_Delivery_Dossier_v0.5.docx` created; size now > 5 MB due to images.

- [ ] **Step 2: Append to CHANGELOG**

Edit `docs/delivery_dossier/CHANGELOG.md`, prepend:
```markdown
## [v0.5] — 2026-05-24 — Wave 1: Part I Product

- Wrote chapters 1, 2, 4-18 (Ch 3 done in Wave 0).
- Captured ~25 screenshots across module chapters.
- Added screenshot capture script (`scripts/capture_screenshots.mjs`).
```

- [ ] **Step 3: Commit CHANGELOG**

```powershell
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/CHANGELOG.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): bump CHANGELOG to v0.5"
```

### Task W1.22: Wave 1 review checkpoint — STOP

- [ ] **Step 1: Hand v0.5.docx to the user**

Tell the user: "Wave 1 is complete. Part I (Product) is fully written and screenshots are embedded. Open `docs/delivery_dossier/build/CIHRMS_Delivery_Dossier_v0.5.docx` in Word and tell me:
1. Are the module chapters at the right depth / right voice?
2. Are screenshots placed and sized correctly?
3. Any chapter need rework before we move to Part II (the engineering annex)?

I'll wait for approval before starting Wave 2."

**Do not proceed to Wave 2 until the user approves.**

---

## Wave 2 — Part II (Engineering) chapters

Writes chapters 19-26. Peer-engineer voice. Ends with v0.7.docx.

For every task in Wave 2, the structure is the same as Wave 1 module chapters:

1. Read source material
2. Draft markdown (engineering voice, third person, file paths cited, no hand-waving)
3. Build + commit

### Task W2.1 — Ch 19 Architecture & stack

**Files:** Create `docs/delivery_dossier/chapters/19_architecture.md`

- [ ] **Step 1: Read**

- `docs/SYSTEM_ARCHITECTURE.md`, `docs/SYSTEM_DESIGN_DIAGRAMS.md`, `docs/TRD.md`
- `composer.json`, `package.json`
- Memory: `project_stack.md`, `project_cihrms.md`

- [ ] **Step 2: Draft `19_architecture.md`**

Content checklist:
- High-level: Laravel 13.7 + Inertia v2 + Vue 3 + Tailwind 3 + SQLite dev / Postgres planned
- Inertia SSR/CSR model — pages live in `resources/js/Pages/`, layouts in `resources/js/Layouts/`
- Authentication: staff_id + name + password (custom — not Breeze default)
- Request flow diagram (textual): request → AuditTrail middleware → FormRequest → Controller → Service → Event → Listener → Resource → Inertia render
- Queues: `analytics` queue + `audit` queue + Horizon (planned)
- Caching: DashboardService 60s TTL per-user
- Stats footprint (cite memory): 432 routes, 128 Inertia pages, 116 migrations, 124 models, 121 services, 97 controllers, 973 tests
- Forward: Postgres migration is Phase 1

- [ ] **Step 3: Build and commit**

```powershell
pwsh -File build.ps1 -Version v0.6
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/chapters/19_architecture.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): Ch 19 Architecture and stack"
```

### Task W2.2 — Ch 20 Canonical pattern

**Files:** Create `docs/delivery_dossier/chapters/20_canonical_pattern.md`

- [ ] **Step 1: Read**

- Memory: `project_cihrms.md` (architecture established 2026-05-13)
- `app/Enums/`, sample `app/Http/Requests/StoreLeaveRequestRequest.php`, `app/Services/LeaveService.php`, `app/Events/LeaveRequested.php`, `app/Http/Resources/LeaveRequestResource.php`

- [ ] **Step 2: Draft `20_canonical_pattern.md`**

Content checklist:
- The pattern: **Enum → FormRequest → Service → Event → Listener → Resource**
- Why each layer exists (1 paragraph each)
- Worked example end-to-end: a Leave request creation. Show real code from `LeaveService::create()` etc.
- What NOT to put where (e.g., no business logic in Controllers, no validation in Services)
- How tests target each layer
- Convention enforcement: SequenceService for all refs (per `project_finance_sequences.md`); soft deletes on every core model

- [ ] **Step 3: Build and commit**

```powershell
pwsh -File build.ps1 -Version v0.6
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/chapters/20_canonical_pattern.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): Ch 20 Canonical pattern"
```

### Task W2.3 — Ch 21 Data model

**Files:** Create `docs/delivery_dossier/chapters/21_data_model.md`

- [ ] **Step 1: Read**

- `database/migrations/` (list all 116)
- Top-tier `app/Models/*.php` (User, Employee, Department, LeaveRequest, Ticket, Complaint, JobPosting, Applicant, Payment, AuditLog, Notification, Document, ChatThread, plus Finance models)

- [ ] **Step 2: Draft `21_data_model.md`**

Content checklist:
- Core entity map (text diagram or table): User ↔ Employee ↔ Department; Employee → LeaveRequest, Ticket, Complaint, Payment, etc.
- Soft deletes universal on core models
- Enum casts standard
- SoftDeletes + timestamps + audit on every state-changing row
- Finance schema overview (one paragraph per F1-F5 area)
- Migration discipline — 116 migrations, ordered, run cleanly with `php artisan migrate:fresh`
- Forward: Postgres migration is Phase 1; field-level encryption for sensitive columns is Phase 4 DPA work

- [ ] **Step 3: Build and commit**

```powershell
pwsh -File build.ps1 -Version v0.6
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/chapters/21_data_model.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): Ch 21 Data model"
```

### Task W2.4 — Ch 22 RBAC

**Files:** Create `docs/delivery_dossier/chapters/22_rbac.md`

- [ ] **Step 1: Read**

- Memory: `project_rbac.md`, `project_audit_v2_complete.md`
- `app/Enums/UserRole.php`
- `app/Policies/*.php`
- `database/migrations/*roles*.php`, `*permissions*.php`
- `app/Models/Role.php`, `app/Models/Permission.php` if they exist

- [ ] **Step 2: Draft `22_rbac.md`**

Content checklist:
- Two-layer model: legacy `User.role` enum + DB-backed roles/permissions tables + per-user JSON `permissions` column (per `project_test_patterns.md`)
- Roles enumerated (9 — same list as Ch 17)
- Policy mapping: which Policy guards which Model
- CEO mirrors super_admin (wildcard `*`) — important historical note per audit memory
- dept_head role and how it scopes data
- Per-user permission grants (used in tests, also runtime)
- Forward: Phase 4 SSO with NITA/M365

- [ ] **Step 3: Build and commit**

```powershell
pwsh -File build.ps1 -Version v0.6
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/chapters/22_rbac.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): Ch 22 RBAC"
```

### Task W2.5 — Ch 23 Security

**Files:** Create `docs/delivery_dossier/chapters/23_security.md`

- [ ] **Step 1: Read**

- `config/sanctum.php`, `config/auth.php`
- `app/Http/Middleware/`
- `app/Http/Requests/Auth/LoginRequest.php`
- Memory: `project_audit_v2_complete.md` (password-must-change enforcement)
- `docs/MARKET_READY_PUNCHLIST_V2.md` (security-relevant items)

- [ ] **Step 2: Draft `23_security.md`**

Content checklist:
- Authentication: Sanctum sessions + staff_id+name+password
- Password lifecycle: bcrypt; admin-created users get `password_must_change=true` (per PR #34 + audit memory)
- Audit trail: AuditTrail middleware → WriteAuditLog job on `audit` queue
- CSRF, XSS, SQL-injection postures (Laravel defaults — call out)
- Rate limiting and login throttling
- Secret handling: .env never committed; .env.example present
- Forward: 2FA (Phase 1), tamper-evident audit (Phase 1), field-level encryption (Phase 4), CSA registration (Phase 4)
- Honest disclosure: no penetration test yet — Phase 4

- [ ] **Step 3: Build and commit**

```powershell
pwsh -File build.ps1 -Version v0.6
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/chapters/23_security.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): Ch 23 Security"
```

### Task W2.6 — Ch 24 Performance, caching, queues

**Files:** Create `docs/delivery_dossier/chapters/24_performance.md`

- [ ] **Step 1: Read**

- `app/Services/DashboardService.php`
- `app/Jobs/WriteAuditLog.php`, `app/Listeners/RecordAnalyticsEvent.php`
- `config/queue.php`, `config/cache.php`

- [ ] **Step 2: Draft `24_performance.md`**

Content checklist:
- Two queues: `analytics`, `audit` — keep request path fast
- DashboardService caches per-user 60s
- Soft-delete scopes avoid table bloat at read time
- Eager loading patterns in services
- No N+1 enforcement story yet — call out as work item
- Forward: Horizon (Phase 4), Redis cache (Phase 1 alongside Postgres)

- [ ] **Step 3: Build and commit**

```powershell
pwsh -File build.ps1 -Version v0.6
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/chapters/24_performance.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): Ch 24 Performance and caching"
```

### Task W2.7 — Ch 25 Testing

**Files:** Create `docs/delivery_dossier/chapters/25_testing.md`

- [ ] **Step 1: Read**

- `phpunit.xml`, `tests/Pest.php`, `tests/TestCase.php`
- `tests/Feature/Audit/` (the V2 audit regression tests)
- Memory: `project_test_patterns.md`, `project_audit_v2_complete.md`

- [ ] **Step 2: Draft `25_testing.md`**

Content checklist:
- Stack: Pest, Feature + Unit
- Volume: 973 tests / 3,405 assertions / 182 feature files / 17,269 LOC (per audit memory — verify still current with `php artisan test` count)
- Base classes: per `project_test_patterns.md`
- Per-user JSON `permissions` granting pattern in tests
- Route binding caveats (per test patterns memory)
- Audit regression tests under `tests/Feature/Audit/` encode each V2 punch-list fix
- How to run: `php artisan test` and `php artisan test --filter=...`
- Forward: end-to-end Cypress/Playwright tests (Phase 4), load testing (Phase 4)

Verify the test count is still 973 by running:
```powershell
cd d:\CIHRMS\cihrms-mvp
php artisan test --without-coverage 2>&1 | Select-String -Pattern "Tests:|Assertions:" -SimpleMatch
```
Update numbers in the chapter if drift is detected.

- [ ] **Step 3: Build and commit**

```powershell
pwsh -File build.ps1 -Version v0.6
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/chapters/25_testing.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): Ch 25 Testing strategy"
```

### Task W2.8 — Ch 26 Deployment

**Files:** Create `docs/delivery_dossier/chapters/26_deployment.md`

- [ ] **Step 1: Read**

- `docs/deployment_production.md`
- `.env.example`, `composer.json` scripts, `package.json` scripts

- [ ] **Step 2: Draft `26_deployment.md`**

Content checklist:
- What currently works: `composer install`, `npm install`, `npm run build`, `php artisan migrate`, serve via PHP-FPM/Nginx or Forge
- Storage layout (storage/app/public, symlink)
- Background workers: artisan queue:work for `analytics` and `audit`
- Backups: not implemented yet, call out
- Hosting target: planned NITA per gap analysis
- Forward: Postgres migration, Horizon, Redis cache, S3-compatible storage (Phase 1 / Phase 4)
- CI: status today (call out honestly — if no CI, say so)

- [ ] **Step 3: Build and commit**

```powershell
pwsh -File build.ps1 -Version v0.6
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/chapters/26_deployment.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): Ch 26 Deployment and operations"
```

### Task W2.9 — Build v0.7 and bump CHANGELOG

- [ ] **Step 1: Build**

```powershell
cd d:\CIHRMS\cihrms-mvp\docs\delivery_dossier
pwsh -File build.ps1 -Version v0.7
```

- [ ] **Step 2: Append CHANGELOG entry**

Prepend to `CHANGELOG.md`:
```markdown
## [v0.7] — 2026-05-24 — Wave 2: Part II Engineering Annex

- Wrote chapters 19-26 covering architecture, canonical pattern, data model,
  RBAC, security, performance, testing, deployment.
- Verified test count against current `php artisan test` output.
```

- [ ] **Step 3: Commit**

```powershell
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/CHANGELOG.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): bump CHANGELOG to v0.7"
```

### Task W2.10: Wave 2 review checkpoint — STOP

- [ ] **Step 1: Hand v0.7.docx to the user**

Tell the user: "Wave 2 is complete. The engineering annex is in. Open `docs/delivery_dossier/build/CIHRMS_Delivery_Dossier_v0.7.docx` and let me know:
1. Is the engineering voice right (peer-engineer, file paths cited, no hand-waving)?
2. Anything missing from the technical story?

I'll wait for approval before starting Wave 3 (Standards & Market Readiness)."

**Do not proceed to Wave 3 until the user approves.**

---

## Wave 3 — Part III (Standards & Market Readiness) chapters

Writes chapters 27-30. The standards comparison spine plus the advocacy chapter and the roadmap.

### Task W3.1 — Ch 27 Standards benchmark

**Files:** Create `docs/delivery_dossier/chapters/27_standards_benchmark.md`

This is the longest single chapter in Part III — 13 standards × ~1 page each + intro. Uses the **Standards Page Template** at the top of this plan.

- [ ] **Step 1: Read**

- Memory: `project_government_gap_analysis.md` (the master gap analysis — the source of truth here)
- `docs/wcag_aa_checklist.md`
- `docs/MARKET_READY_PUNCHLIST_V2.md` (for evidence of "Met" claims)
- `docs/implementation_plan_2.md` (for phase mapping in gap rows)

- [ ] **Step 2: Draft `27_standards_benchmark.md`**

Structure:
- **Intro section** (~1 page): why benchmark, how to read the matrix, the legend (● Met, ◐ Partial, ○ Not yet)
- **Government — Ghana** section:
  1. IPPD2/IPPD3 (Integrated Personnel & Payroll Database)
  2. GIFMIS (Ghana Integrated Financial Management Info System)
  3. Ghana Card / NIA (National Identification Authority)
  4. NPRA 3-tier pensions
  5. GRA PAYE / SSNIT / NHIA (statutory deductions — cite 2026 PAYE brackets from memory)
  6. DPA 2012 Act 843 (Data Protection)
  7. Cybersecurity Act 2020 (CSA registration)
  8. CHRAJ whistleblower (Act 720)
- **International** section:
  9. ISO 30414 (Human Capital Reporting)
  10. ISO 27001 (Information Security)
  11. WCAG 2.1 AA (Accessibility)
  12. GDPR (Privacy parity)
  13. IFRS general principles (Finance)

For each: apply the **Standards Page Template** exactly. Pull "CIHRMS evidence" rows from real chapter references. Pull "Gap & path" rows from the 4-phase roadmap (Ch 29).

Status badges must be honest — most rows will be ◐ or ○; that's expected and exactly the disclosure the dossier is meant to make. Do not paper over gaps.

Expected length: 14-18 pages.

- [ ] **Step 3: Build and commit**

```powershell
pwsh -File build.ps1 -Version v0.8
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/chapters/27_standards_benchmark.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): Ch 27 Standards benchmark"
```

### Task W3.2 — Ch 28 Why we are ready for market

**Files:** Create `docs/delivery_dossier/chapters/28_why_ready.md`

The advocacy chapter — confident but evidence-led. Every claim cites a chapter or a memory/source.

- [ ] **Step 1: Read**

- Memory: `project_audit_v2_complete.md` (the readiness evidence base)
- `docs/MARKET_READY_PUNCHLIST_V2.md`
- All Wave 1 chapters (for cross-references)

- [ ] **Step 2: Draft `28_why_ready.md`**

Content checklist:
- **The case in three claims:**
  1. **Commercial-mid-market parity** — module list matches major competitors (SeamlessHR, Aruti)
  2. **Engineering rigor** — V2 audit hardened, 973 tests, canonical pattern enforced repo-wide, SequenceService convention, password-must-change wall
  3. **Finance is the moat** — F1-F5 shipped, very few HRMS competitors carry their own ledger
- **What "ready" means here**: ready to onboard the first paying customer, ready to enter a tender process, ready to be evaluated by a buyer's IT
- **What "ready" does NOT mean** — does not mean "feature-complete for a Ministry rollout" (Ch 27 shows the gaps); does not mean "production-hardened at scale" (Postgres, Horizon, pen-test in Phase 1+4)
- **Validation signals**: 65-item V2 punch list closed, 12 PRs landed in one wave, regression-tested
- **Recommended next step for a reader**: a 4-week pilot with a single MDA or commercial buyer, scoped to v1.0 modules, with Phase 1 work running in parallel

Length: 6-8 pages. Tone: confident PM, not hyperbolic marketing.

- [ ] **Step 3: Build and commit**

```powershell
pwsh -File build.ps1 -Version v0.8
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/chapters/28_why_ready.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): Ch 28 Why we are ready for market"
```

### Task W3.3 — Ch 29 What's left and how to get there

**Files:** Create `docs/delivery_dossier/chapters/29_roadmap.md`

The 4-phase roadmap, lifted from `implementation_plan_2.md` and the gap analysis memory, rendered as Gantt-style tables.

- [ ] **Step 1: Read**

- `docs/implementation_plan_2.md` (the canonical Phase 1 plan)
- Memory: `project_government_gap_analysis.md` (the 4-phase summary)
- All "Forward" / "Next" lines in Wave 1 + Wave 2 chapters

- [ ] **Step 2: Draft `29_roadmap.md`**

Structure:
- **Intro** (~1 page): how to read the roadmap, what "engineering-week" means, dependencies between phases
- **Phase 1 — The minimum for any government pitch (8-10 weeks)**
  - Table: workstream · effort (ew) · dependencies
  - Workstreams: statutory payroll engine, positions/grades/steps, Ghana Card adapter (mocked), tamper-evident audit, Postgres migration, 2FA
- **Phase 2 — Public sector depth (8-10 weeks)**
  - PSC-style performance management, biometric attendance ingestion, loans/advances, off-boarding clearance, CHRAJ whistleblower channel, statutory report pack
- **Phase 3 — Reach and integration (8 weeks)**
  - LMS, asset management, OpenAPI + GIFMIS export, MoMo disbursement, pulse/eNPS, WCAG AA full
- **Phase 4 — Sustained operations (ongoing)**
  - Workforce-planning analytics, AI Assistant wiring, SSO (NITA/M365), DPA data-subject rights portal, CSA pen-test, USSD/SMS gateway, Horizon, S3 storage, backups

For each phase: a tabular workstream list with effort in ew, dependencies, and a one-line "what this unlocks" outcome.

Expected length: 6-8 pages.

- [ ] **Step 3: Build and commit**

```powershell
pwsh -File build.ps1 -Version v0.8
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/chapters/29_roadmap.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): Ch 29 Roadmap"
```

### Task W3.4 — Ch 30 Funding and sequencing

**Files:** Create `docs/delivery_dossier/chapters/30_funding_sequencing.md`

Effort units only — **no GHS figures**, per spec §3.

- [ ] **Step 1: Draft `30_funding_sequencing.md`**

Content checklist:
- **Three sequencing paths**, each with effort range and dependency call-outs:
  1. **Pilot MDA → tender path**: Phase 1 + selected Phase 2 items (whistleblower, statutory pack) → buyer-acceptance demo → tender response
  2. **Donor-funded path** (World Bank / GIZ / DFID): roadmap mapped to standard donor delivery sprints; Phase 1 first
  3. **Commercial-SaaS path**: Phase 1 minus IPPD/GIFMIS plus Phase 3 reach items (MoMo, eNPS); shorter time-to-revenue, larger TAM
- **Team shape per phase** — engineering-weeks broken into roles: senior backend, senior frontend, QA, DevOps, product, design
- **Risk register** — top 5 risks (NITA hosting capacity, Ghana Card test environment access, third-party module IP if any, key-person dependency, scope creep). Each with mitigation.
- **Out of scope of this dossier**: pricing in GHS, contract terms, partner identification

Length: 4-6 pages.

- [ ] **Step 2: Build and commit**

```powershell
pwsh -File build.ps1 -Version v0.8
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/chapters/30_funding_sequencing.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): Ch 30 Funding and sequencing"
```

### Task W3.5 — Build v0.9 and bump CHANGELOG

- [ ] **Step 1: Build**

```powershell
cd d:\CIHRMS\cihrms-mvp\docs\delivery_dossier
pwsh -File build.ps1 -Version v0.9
```

- [ ] **Step 2: Append CHANGELOG**

Prepend:
```markdown
## [v0.9] — 2026-05-24 — Wave 3: Part III Standards & Market Readiness

- Wrote chapters 27-30 covering standards benchmark, why-ready advocacy,
  4-phase roadmap, and funding/sequencing.
- All body content complete; front and back matter still placeholders.
```

- [ ] **Step 3: Commit**

```powershell
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/CHANGELOG.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): bump CHANGELOG to v0.9"
```

### Task W3.6: Wave 3 review checkpoint — STOP

- [ ] **Step 1: Hand v0.9.docx to the user**

Tell the user: "Wave 3 is complete. All body chapters are in. Open `docs/delivery_dossier/build/CIHRMS_Delivery_Dossier_v0.9.docx` and review:
1. Are the standards benchmark statuses honest enough (no overstating)?
2. Does the advocacy chapter (Ch 28) hit the right confident-but-evidence-led tone?
3. Is the roadmap actionable enough?

Approve and I move to Wave 4 — front matter, back matter, glossaries, index, polish, and final v1.0."

**Do not proceed to Wave 4 until the user approves.**

---

## Wave 4 — Front matter, back matter, polish, v1.0

Writes the front-of-book and back-of-book material, runs annotation polish on screenshots, fixes any cross-references, builds v1.0.

### Task W4.1 — Replace front matter with the real version

**Files:** Modify `docs/delivery_dossier/chapters/00_front_matter.md`

- [ ] **Step 1: Read every chapter** (Ch 1-30) and **build the at-a-glance numbers**

Capture:
- Module count
- Roles count
- Test count (re-verify from `php artisan test`)
- Stats from `project_audit_v2_complete.md`

- [ ] **Step 2: Rewrite `00_front_matter.md`**

Replace the placeholder with full front matter:
```markdown
---
title: CIHRMS Delivery Dossier
subtitle: Features, Standards, and Market Readiness
author: CIHRMS Engineering Team
date: 2026-05-24
---

# CIHRMS {.unnumbered .cover}

*A Human Resource Management System*
*Built for Ghana, ready for the world.*

CIHRMS Engineering Team
2026-05-24
Version 1.0

\newpage

# Executive Summary {.unnumbered}

CIHRMS is a complete human resource management system designed first for
Ghanaian public-sector and commercial operations, and engineered to a
standard suitable for international evaluation.

This dossier explains:

- **What the product does today** — every module, every screen, every action
  (Part I, Chapters 1-18).
- **How it is engineered** — architecture, data model, security, testing,
  and operations (Part II, Chapters 19-26).
- **How it measures up** — benchmarked against 8 Ghana statutes/systems and
  5 international standards (Part III, Chapter 27).
- **Why it is ready for market** — the evidence base for shipping today
  (Part III, Chapter 28).
- **What is still to come** — a 4-phase roadmap to government-grade scale
  (Part III, Chapters 29-30).

[short three-paragraph executive summary covering: product positioning,
readiness claim, road ahead — ~250 words total]

\newpage

# How to read this dossier {.unnumbered}

| Reader | Where to start |
|---|---|
| Minister, board, procurement officer | Executive Summary → Ch 28 (Why ready) → Ch 27 (Standards) |
| HR director, line manager | Ch 1 (What CIHRMS is) → Ch 17 (Role tours) → relevant module chapters |
| IT director, integration architect | Ch 19 (Architecture) → Ch 22 (RBAC) → Ch 23 (Security) → Ch 27 |
| Auditor, security reviewer | Ch 23 (Security) → Ch 13 (Audit logs) → Ch 27 (Standards) |
| Engineering lead at a partner firm | Part II in order, then Ch 29 (Roadmap) |
| Reading cover to cover | Front matter → Part I → Part II → Part III → Back matter |

Every chapter opens with a three-line synopsis. Module chapters use a
consistent template so you can navigate by section, not by reading.
Tables capture what each button does and who can use it — they are the
scannable reference layer.

\newpage

# At a glance {.unnumbered}

| Indicator | Value |
|---|---|
| Modules covered in this dossier | 14+ (Employees through Profile portal) |
| Roles defined | 9 (super_admin, ceo, hr_admin, manager, employee, finance_officer, it_support, auditor, dept_head) |
| Web routes | 432 |
| Inertia pages | 128 |
| Eloquent models | 124 |
| Service classes | 121 |
| Migrations | 116 |
| Feature tests | 182 files, 17,269 LOC |
| Automated test suite | 973 tests / 3,405 assertions, all passing |
| V2 audit punch-list closed | 65/65 items shipped in 12 PRs (#44–#55) |
| Standards benchmarked in Ch 27 | 13 (8 Ghana + 5 international) |
| Roadmap phases to government-grade | 4 |

\newpage
```

(Replace the Executive Summary square brackets with the actual three-paragraph summary written by the executor.)

- [ ] **Step 3: Commit**

```powershell
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/chapters/00_front_matter.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): real front matter for v1.0"
```

### Task W4.2 — Write back matter

**Files:** Create `docs/delivery_dossier/chapters/99_back_matter.md`

- [ ] **Step 1: Draft `99_back_matter.md`** with four sections

Write the file with:
```markdown
# Glossary — Ghana-specific terms {.unnumbered}

| Term | Definition |
|---|---|
| **IPPD2 / IPPD3** | Integrated Personnel and Payroll Database — Government of Ghana's payroll system of record, operated by the Controller and Accountant-General's Department (CAGD). |
| **GIFMIS** | Ghana Integrated Financial Management Information System — central government finance system; expense, journal, and payroll commitments flow through it. |
| **Ghana Card / NIA** | National identity card issued by the National Identification Authority; the public-sector standard for personal identity verification. |
| **NPRA** | National Pensions Regulatory Authority — licenses the 3-tier pension scheme administrators. |
| **SSNIT** | Social Security and National Insurance Trust — administers Tier 1 (defined-benefit basic pension) and contributes 2.5% to NHIA. |
| **NHIA** | National Health Insurance Authority — receives 2.5% of the SSNIT contribution to fund national health insurance. |
| **GRA** | Ghana Revenue Authority — administers PAYE income tax. |
| **CHRAJ** | Commission on Human Rights and Administrative Justice — Ghana's ombudsman, with statutory whistleblower oversight under the Whistleblower Act 720. |
| **DPC** | Data Protection Commission — administers DPA 2012 (Act 843), including data-controller registration. |
| **CSA** | Cyber Security Authority — administers the Cybersecurity Act 2020. |
| **NITA** | National Information Technology Agency — sets standards for public-sector hosting and digital identity (SSO). |
| **PSC** | Public Services Commission — sets HR policy and performance frameworks for the public service. |
| **CAGD** | Controller and Accountant-General's Department — operates IPPD and GIFMIS. |
| **E-Levy** | Electronic Transfer Levy — 1.5% on mobile-money transfers; relevant for MoMo disbursement. |
| **MoMo** | Mobile money — primarily MTN, AirtelTigo, Vodafone Cash; the dominant digital payment channel in Ghana. |
| **Act 651** | Labour Act, 2003 (Act 651) — minimum standards for working hours, overtime, leave, termination. |
| **Act 720** | Whistleblower Act, 2006 (Act 720) — protection for whistleblowers; CHRAJ-administered. |
| **Act 843** | Data Protection Act, 2012 (Act 843) — Ghana's GDPR-equivalent. |

\newpage

# Glossary — Technical terms {.unnumbered}

| Term | Definition |
|---|---|
| **Inertia.js** | A library that lets server-side frameworks (Laravel) render single-page applications (Vue) without building a separate REST API. CIHRMS uses Inertia v2. |
| **Pest** | A PHP testing framework built on PHPUnit with cleaner syntax. CIHRMS has 973 Pest tests. |
| **FormRequest** | Laravel class that encapsulates HTTP request validation and authorization. Every state-changing endpoint in CIHRMS has its own FormRequest. |
| **Service class** | Plain PHP class that holds business logic, called from Controllers. CIHRMS has 121 of them. |
| **Event / Listener** | Laravel's pub/sub pattern. Domain events (`LeaveRequested`, `EmployeeCreated`) fire from Services; Listeners write analytics asynchronously. |
| **Resource** | Laravel `JsonResource` — transforms an Eloquent model into an API-ready payload. |
| **RBAC** | Role-Based Access Control — users hold roles, roles hold permissions, permissions guard actions. |
| **Soft delete** | A "deleted" record is hidden but retained in the database (`deleted_at` set). Reversible, audit-friendly. |
| **Sanctum** | Laravel's built-in authentication for SPAs (Inertia in our case) and API tokens. |
| **Queue** | A background job channel. CIHRMS uses `analytics` and `audit` queues to keep the request path fast. |
| **Horizon** | Laravel's queue-monitoring dashboard. Planned for Phase 4. |
| **SequenceService** | CIHRMS-internal service that produces gap-free, race-safe reference numbers (PAY-2026-0001 …). Mandatory for all new financial references — see PR #21. |
| **Audit trail** | Append-only record of every state-changing request. Currently mutable rows; Phase 1 makes it tamper-evident. |
| **Policy** | Laravel class that decides whether a user can perform an action on a model. |
| **Materialized callout** | A document annotation (highlight, note, draw) stored as data, not flattened into the image. CIHRMS Documents module keeps annotations manipulable per PR #15. |

\newpage

# Module index {.unnumbered}

| Module | Chapter | Page (in PDF) |
|---|---|---|
| Employees | Ch 3 | — |
| Leave | Ch 4 | — |
| Tickets (Service Desk) | Ch 5 | — |
| Complaints | Ch 6 | — |
| Recruitment + Public Careers | Ch 7 | — |
| Payroll | Ch 8 | — |
| Finance F1 Chart of Accounts | Ch 9 §1 | — |
| Finance F2 Accounts Payable | Ch 9 §2 | — |
| Finance F3 Accounts Receivable | Ch 9 §3 | — |
| Finance F4 Paystack gateway | Ch 9 §4 | — |
| Finance F5 Bank Reconciliation | Ch 9 §5 | — |
| Documents | Ch 10 | — |
| Chat | Ch 11 | — |
| Notifications | Ch 12 | — |
| Audit Logs | Ch 13 | — |
| Reports | Ch 14 | — |
| Departments | Ch 15 | — |
| Profile portal | Ch 16 | — |

(Word inserts page numbers in the rendered table of contents — leave the
"Page" column as `—` in markdown; the TOC at the front of the PDF is
authoritative.)

\newpage

# Standards cross-reference {.unnumbered}

| Framework | Status today | Phase that closes the gap | Chapter |
|---|---|---|---|
| IPPD2/IPPD3 | ○ Not yet | Phase 1 | Ch 27 |
| GIFMIS | ○ Not yet | Phase 3 | Ch 27 |
| Ghana Card / NIA | ○ Not yet | Phase 1 (mocked) → Phase 2 (live) | Ch 27 |
| NPRA 3-tier | ○ Not yet | Phase 1 (engine) | Ch 27 |
| GRA PAYE / SSNIT / NHIA | ○ Not yet | Phase 1 | Ch 27 |
| DPA 2012 Act 843 | ◐ Partial | Phase 1 (audit) → Phase 4 (subject-rights portal) | Ch 27 |
| Cybersecurity Act 2020 | ◐ Partial | Phase 4 (CSA registration + pen-test) | Ch 27 |
| CHRAJ Whistleblower Act 720 | ○ Not yet | Phase 2 | Ch 27 |
| ISO 30414 | ◐ Partial | Phase 3 (full reporting pack) | Ch 27 |
| ISO 27001 | ◐ Partial | Phase 4 | Ch 27 |
| WCAG 2.1 AA | ◐ Partial | Phase 3 | Ch 27 |
| GDPR (parity) | ◐ Partial | Phase 4 | Ch 27 |
| IFRS | ◐ Partial | shipped via F1-F5 (Ch 9) | Ch 27 |

\newpage

# Change log {.unnumbered}

See `docs/delivery_dossier/CHANGELOG.md` in the source repository.

# About this document {.unnumbered}

| Field | Value |
|---|---|
| Title | CIHRMS Delivery Dossier — Features, Standards, and Market Readiness |
| Version | 1.0 |
| Date | 2026-05-24 |
| Authored by | CIHRMS Engineering Team |
| Build pipeline | Markdown → Pandoc → Word .docx → manual PDF export |
| Source repository | `d:\CIHRMS\cihrms-mvp` (branch `dossier/v1.0`) |
| Source content | `docs/delivery_dossier/chapters/*.md` |
| Style template | `docs/delivery_dossier/reference.docx` |
| Re-build command | `pwsh -File docs/delivery_dossier/build.ps1 -Version v1.0` |
```

- [ ] **Step 2: Commit**

```powershell
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/chapters/99_back_matter.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): back matter (glossaries, indexes, cross-reference)"
```

### Task W4.3 — Implement screenshot annotation (the Wave 1 stub)

**Files:**
- Replace stub: `docs/delivery_dossier/scripts/annotate.mjs`
- Create: `docs/delivery_dossier/scripts/annotations.json`

Optional but high-impact polish. The callouts named ❶ ❷ ❸ in module chapters refer to numbered circles overlaid here.

- [ ] **Step 1: Write `annotations.json`** mapping screenshot → array of `{ x, y, n }` callout positions

Skeleton (one entry per screenshot that needs callouts):
```json
{
  "03_employees/directory.png": [
    { "x": 80,  "y": 120, "n": 1 },
    { "x": 1500, "y": 120, "n": 2 },
    { "x": 760, "y": 450, "n": 3 }
  ],
  "03_employees/detail.png": [
    { "x": 180, "y": 220, "n": 1 },
    { "x": 1200, "y": 320, "n": 2 }
  ]
  /* …add an entry per annotated image */
}
```

The executor adds entries as they decide which screens need callouts. Coordinates are pixels in the **already-captured** 1920×1080 image.

- [ ] **Step 2: Replace `annotate.mjs` with the real implementation**

Write to `docs/delivery_dossier/scripts/annotate.mjs`:
```javascript
// Overlays numbered circles on captured screenshots, per annotations.json.
//   Input:  docs/delivery_dossier/assets/screenshots/<chapter>/<file>.png
//   Output: same path, overwritten with annotated version
//
// Run from project root after `npm install --no-save sharp`.

import sharp from 'sharp';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const config = JSON.parse(await readFile(resolve(__dirname, 'annotations.json'), 'utf8'));
const root = resolve(__dirname, '..', 'assets', 'screenshots');

function circleSvg(n) {
  return Buffer.from(`
    <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64">
      <circle cx="32" cy="32" r="28" fill="#1F2BD8" stroke="white" stroke-width="3"/>
      <text x="32" y="42" font-family="Plus Jakarta Sans, sans-serif"
            font-size="28" font-weight="700" fill="white" text-anchor="middle">${n}</text>
    </svg>
  `);
}

for (const [key, callouts] of Object.entries(config)) {
  const file = resolve(root, key);
  try {
    const composites = callouts.map(c => ({
      input: circleSvg(c.n),
      top: c.y - 32,
      left: c.x - 32
    }));
    const out = await sharp(file).composite(composites).png().toBuffer();
    await sharp(out).toFile(file);
    console.log(`OK ${key} (${callouts.length} callouts)`);
  } catch (e) {
    console.warn(`FAIL ${key} — ${e.message}`);
  }
}
console.log('Done.');
```

- [ ] **Step 3: Run annotation**

Run:
```powershell
cd d:\CIHRMS\cihrms-mvp
node docs/delivery_dossier/scripts/annotate.mjs
```
Expected: `OK …` lines per file in `annotations.json`.

- [ ] **Step 4: Commit**

```powershell
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/scripts/annotate.mjs docs/delivery_dossier/scripts/annotations.json docs/delivery_dossier/assets/screenshots
git -C d:\CIHRMS\cihrms-mvp commit -m "feat(dossier): screenshot annotation overlay"
```

### Task W4.4 — Cross-reference sweep

**Files:** Modify any chapter where cross-references are stale.

- [ ] **Step 1: Find every `Ch N` reference in chapter files**

Run:
```powershell
Select-String -Path d:\CIHRMS\cihrms-mvp\docs\delivery_dossier\chapters\*.md -Pattern "Ch \d+|Chapter \d+|§\d+" |
  Select-Object Path, LineNumber, Line
```
Expected: a list of all chapter cross-refs.

- [ ] **Step 2: Verify each `Ch N` points to the right chapter**

For each result, confirm the cited chapter number matches the actual chapter filename (`NN_slug.md`). The chapter numbering after the spec is:
- Ch 1 = `01_what_cihrms_is.md`
- Ch 2 = `02_design_language.md`
- Ch 3 = `03_employees.md`
- Ch 4 = `04_leave.md`
- Ch 5 = `05_tickets.md`
- Ch 6 = `06_complaints.md`
- Ch 7 = `07_recruitment.md`
- Ch 8 = `08_payroll.md`
- Ch 9 = `09_finance.md`
- Ch 10 = `10_documents.md`
- Ch 11 = `11_chat.md`
- Ch 12 = `12_notifications.md`
- Ch 13 = `13_audit_logs.md`
- Ch 14 = `14_reports.md`
- Ch 15 = `15_departments.md`
- Ch 16 = `16_profile_portal.md`
- Ch 17 = `17_role_tours.md`
- Ch 18 = `18_cross_cutting.md`
- Ch 19 = `19_architecture.md`
- Ch 20 = `20_canonical_pattern.md`
- Ch 21 = `21_data_model.md`
- Ch 22 = `22_rbac.md`
- Ch 23 = `23_security.md`
- Ch 24 = `24_performance.md`
- Ch 25 = `25_testing.md`
- Ch 26 = `26_deployment.md`
- Ch 27 = `27_standards_benchmark.md`
- Ch 28 = `28_why_ready.md`
- Ch 29 = `29_roadmap.md`
- Ch 30 = `30_funding_sequencing.md`

Fix any mismatches inline with `Edit`.

- [ ] **Step 3: Commit any fixes**

```powershell
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/chapters
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): cross-reference sweep — fix chapter pointers"
```

### Task W4.5 — Final build and visual polish pass

- [ ] **Step 1: Build v1.0.docx**

```powershell
cd d:\CIHRMS\cihrms-mvp\docs\delivery_dossier
pwsh -File build.ps1 -Version v1.0
```
Expected: `build/CIHRMS_Delivery_Dossier_v1.0.docx` created.

- [ ] **Step 2: Page-count check**

Open in Word. Check the final page count is between 280 and 360. If under 280, investigate which chapters are too thin and expand. If over 360, look for repetition.

- [ ] **Step 3: Spot-check the TOC**

Confirm the auto-generated table of contents at the front includes every chapter and that page numbers populate (in Word: right-click TOC → Update Field → Update entire table).

- [ ] **Step 4: Spot-check cover and headers/footers**

- Cover: brand colors, Instrument Serif title, CIHRMS wordmark.
- Header on body pages: "CIHRMS Delivery Dossier" left, page number right.
- Footer: copyright line.

If any of these are off, return to `reference.docx`, fix the style, re-run build.

- [ ] **Step 5: Final CHANGELOG entry**

Prepend to `CHANGELOG.md`:
```markdown
## [v1.0] — 2026-05-24 — Wave 4: Front + Back matter, polish, release

- Replaced placeholder front matter with cover + executive summary +
  reader's map + at-a-glance.
- Wrote back matter: two glossaries, module index, standards cross-reference,
  change log pointer, about page.
- Implemented screenshot annotation overlay.
- Cross-reference sweep across all chapters.
- v1.0.docx built and validated; ready for Word PDF export.
```

- [ ] **Step 6: Commit**

```powershell
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier/CHANGELOG.md
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): release v1.0 (final build, polish, changelog)"
```

### Task W4.6 — Export to PDF (manual)

- [ ] **Step 1: Tell the user to do the PDF export themselves**

The build pipeline cannot produce a PDF — only a `.docx`. The PDF export is a manual step in Word that gives the user the chance to make last-mile edits and preserves visual fidelity.

Tell the user:

"Wave 4 complete. v1.0.docx is at `docs/delivery_dossier/build/CIHRMS_Delivery_Dossier_v1.0.docx`.

**To produce the PDF:**
1. Open `CIHRMS_Delivery_Dossier_v1.0.docx` in Microsoft Word.
2. (Optional) Make any last-mile edits.
3. Right-click the Table of Contents → Update Field → Update entire table (so page numbers reflect any edits).
4. File → Save As → PDF. Save next to the .docx as `CIHRMS_Delivery_Dossier_v1.0.pdf`.

The PDF is your deliverable."

### Task W4.7 — Final acceptance checklist

- [ ] **Step 1: Walk the acceptance criteria from the spec**

Verify each item in §13 of the spec (`docs/superpowers/specs/2026-05-24-cihrms-delivery-dossier-design.md`):

- [ ] All 30 chapters exist under `chapters/`.
- [ ] Every Part I module chapter contains at least one screenshot reference.
- [ ] Ch 27 includes all 13 frameworks.
- [ ] Ch 29 includes all 4 phases.
- [ ] `build.ps1` runs without Pandoc errors.
- [ ] v1.0.docx opens cleanly in Word with branded styles.
- [ ] PDF page count is between 280 and 360.
- [ ] Cover renders with brand colors and CIHRMS wordmark.
- [ ] TOC is clickable and page numbers correct.
- [ ] Module index and standards cross-reference resolve correctly.

Fix any items that fail; mark all green when complete.

- [ ] **Step 2: Final commit (if anything fixed in Step 1)**

```powershell
git -C d:\CIHRMS\cihrms-mvp add docs/delivery_dossier
git -C d:\CIHRMS\cihrms-mvp commit -m "docs(dossier): final acceptance fixes for v1.0"
```

- [ ] **Step 3: Show the user the final paths**

Tell the user:

"v1.0 ready. Final artifacts:

- **Word source** — `docs/delivery_dossier/build/CIHRMS_Delivery_Dossier_v1.0.docx`
- **PDF (after your Word export)** — `docs/delivery_dossier/build/CIHRMS_Delivery_Dossier_v1.0.pdf`
- **Markdown source** — `docs/delivery_dossier/chapters/`
- **Brand template** — `docs/delivery_dossier/reference.docx`
- **Build script** — `docs/delivery_dossier/build.ps1`
- **Branch** — `dossier/v1.0` on `cihrms-mvp` repository (ready to merge to main when you're satisfied)
- **CHANGELOG** — `docs/delivery_dossier/CHANGELOG.md`

Future re-runs only need: edit a chapter → `pwsh -File build.ps1 -Version v1.x` → re-export PDF from Word."

---

## Summary of waves

| Wave | Tasks | Output version | Review checkpoint |
|---|---|---|---|
| Wave 0 — Foundation | W0.1 – W0.11 | v0.1.docx | After W0.11 — proves format on Ch 3 |
| Wave 1 — Part I (Product) | W1.1 – W1.22 | v0.5.docx | After W1.22 |
| Wave 2 — Part II (Engineering) | W2.1 – W2.10 | v0.7.docx | After W2.10 |
| Wave 3 — Part III (Standards & Readiness) | W3.1 – W3.6 | v0.9.docx | After W3.6 |
| Wave 4 — Polish + release | W4.1 – W4.7 | v1.0.docx (+ user's exported PDF) | Acceptance per spec §13 |

**Total tasks:** 50 across 5 waves.
