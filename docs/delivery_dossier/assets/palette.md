# CIHRMS Sovereign Precision — Color & Typography Tokens

> **Source:** `tailwind.config.js` (live, 2026-05-24). Updates to the live config
> should be mirrored here so the dossier stays brand-consistent.

## Identity colors

| Token | Hex | Use in dossier |
|---|---|---|
| `brand-navy` | `#0d1452` | Cover background, H1 headings, header bar, callout box left borders |
| `brand-navy-deep` | `#070b3a` | Cover gradient anchor (deeper end) |
| `brand-blue` (`secondary`) | `#1a237e` | Links, H2/H3 accents, "Met" badges, table header rows |
| `brand-blue-bright` (`secondary-container`) | `#3949ab` | Hover state (not used in static document) |
| `brand-sky` | `#a7d3f0` | Info callout backgrounds (very pale) |
| `brand-gold` | `#ffd700` | ≤5% accent — single CTA highlights, the right-edge hairline on cover |
| `brand-gold-deep` | `#b88a08` | Gold-tone text (use sparingly, never on large surfaces) |
| `brand-cyan` | `#12d9e3` | Chart/spark only (avoid in body of dossier) |
| `brand-magenta` | `#d912e3` | Reserved for `.field-error` parity — error/warning fill |

## Surface & ink

| Token | Hex | Use |
|---|---|---|
| Paper background | `#FFFFFF` | Body pages |
| Page ink (primary text) | `#0F172A` | Body text — near-black slate |
| Secondary ink | `#475569` | Captions, footnotes |
| Subtle rule | `#E2E8F0` | Horizontal rules, table borders |

## Status palette (used in Standards Benchmark, Ch 27)

| Status | Color | Use |
|---|---|---|
| ● Met | `#16A34A` (green-600) | Filled circle |
| ◐ Partial | `#CA8A04` (yellow-600) | Half-filled circle |
| ○ Not yet | `#DC2626` (red-600) | Open circle |

## Typography

The live project uses a single sans stack for body and headings, with a mono stack for tabular data — Open Sans throughout, JetBrains Mono for code.

| Stack | Family (first preference) | Fallback chain |
|---|---|---|
| Sans (body, headings) | **Open Sans** | system-ui, sans-serif |
| Mono (code, tabular) | **JetBrains Mono** | Consolas, monospace |

**Reference.docx style heights** (matched to Tailwind type scale in `tailwind.config.js`):

| Document style | Family | Size | Weight |
|---|---|---|---|
| Title (cover) | Open Sans | 44 pt | 900 (Black) |
| Subtitle | Open Sans | 18 pt | 400 (Regular) |
| Heading 1 (chapter) | Open Sans | 28 pt | 900 (Black), navy |
| Heading 2 | Open Sans | 22 pt | 800 (ExtraBold), navy |
| Heading 3 | Open Sans | 18 pt | 700 (Bold), action blue |
| Heading 4 | Open Sans | 14 pt | 700 (Bold), ink |
| Body (Normal) | Open Sans | 11 pt | 400 (Regular), ink |
| Block Text (callout) | Open Sans | 10.5 pt | 400, ink, navy 3pt left border |
| Source Code | JetBrains Mono | 10 pt | 400, ink on #F1F5F9 |
| Caption | Open Sans | 9.5 pt | 400 (Italic), secondary ink |
| Header / footer | Open Sans | 9 pt | 400, secondary ink |

## Notes

- **The spec referenced Plus Jakarta Sans + Instrument Serif** — these are NOT in use in the live project as of 2026-05-24. The live brand has consolidated to Open Sans. The dossier follows the live brand.
- **Gold rationing rule (≤5%)** — preserve in the dossier. Use brand-gold only for: the cover hairline accent, and individual "★ shipped" markers in Ch 28. Never as table fills, never as body text.
- **Page setup** — A4 portrait, margins 2.0 cm top/bottom × 2.2 cm left/right.
