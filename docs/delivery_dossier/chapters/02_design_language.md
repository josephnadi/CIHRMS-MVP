# Chapter 2 — The Sovereign Precision design language

> *In one paragraph.* Sovereign Precision is the name we gave to the way CIHRMS looks, reads, and moves. It is the brand promise made visible on every screen — that the institute is dealing with a system built for a government workplace, not a Silicon Valley demo. This chapter is the brand book in plain English: what the name means, what colours and type carry it, how it animates, which components speak it, and — just as importantly — what it deliberately is not.

## The name — why "Sovereign Precision"

The brand is called Sovereign Precision because those are the two words a stakeholder ought to feel inside three seconds of opening the application.

- **Sovereign** is for the gravitas. CIHRMS is the workforce record of a government institute. Pay runs, leave entitlements, identity numbers, and disciplinary cases all live here. The interface has to look like it belongs to an organisation that takes that responsibility seriously — closer in feeling to a passport or a tax return than to a chat app.
- **Precision** is for the polish. Gravitas alone gets you something stiff and bureaucratic that nobody enjoys using. Precision is the product-grade craft on top: aligned columns, predictable animations, calm typography, consistent verbs. The result has to feel modern and finished, the way a well-engineered Swiss watch feels — quiet, exact, trustworthy.

Together the two words tell every designer and every developer what to optimise for. When a screen feels too playful or too consumer-y, it has drifted away from "sovereign". When it feels heavy, ornamental, or hard to scan, it has drifted away from "precision". Both halves have to be present at once.

## The palette — navy first, gold rationed

The palette is built around a single primary identity colour and a single action colour, with everything else playing a strict supporting role. The full live token list is reproduced in the swatch table below; here is the working summary in narrative form.

- **Brand navy `#0d1452`** is the institute's identity. It is the sidebar background, the cover of every document we export, the colour of every H1, and the left-hand border on every callout. When somebody glances at CIHRMS from across the room and sees a single colour, that colour is brand navy.
- **Action blue `#1a237e`** is the verb. Every primary button, every active navigation item, every link, every "Save", every focus ring — they are all action blue. It is intentionally a mid-blue rather than a bright accent, because action blue earns its emphasis by being the *only* mid-blue on the screen, not by being the loudest.
- **Brand gold `#ffd700`** is rationed at five percent or less of any single screen. It appears as the right-edge hairline accent on the dossier cover, on the rare "★ shipped" marker in the governance chapter, and as a cautious highlight on a single hero CTA. Gold is never used as a table fill, never as body text, and never as a large surface. The five-percent ceiling is what keeps gold feeling premium instead of decorative.
- **Brand cyan `#12d9e3`** and **brand magenta `#d912e3`** are reserved for charts and small status sparks. They are the two accents that exist for one job only — to differentiate a slice of a donut, a series on a line chart, or a coloured icon well inside an otherwise navy/white card. They never appear on a button, never on a sidebar item, never as a heading.
- **Brand sky `#a7d3f0`** is a pale wash for informational callouts and tinted panels. Where a section needs to read as "context" rather than "input", it gets a sky tint at low opacity.

Surface and ink are deliberately understated. Body text sits at `#0F172A` — a near-black slate that is calmer on the eye than pure black. Secondary text drops to `#475569`. The horizontal rule and table border colour is `#E2E8F0`. The paper itself is plain white. This restraint is the reason brand navy and action blue land with weight when they appear: the rest of the page has been kept quiet so that the brand colours can speak.

### Live swatch table

| Token | Hex | Role |
|---|---|---|
| `brand-navy` | `#0d1452` | Cover background, H1 headings, header bar, sidebar, callout left borders |
| `brand-navy-deep` | `#070b3a` | Cover gradient anchor (deeper end), ambient glows |
| `brand-blue` / `secondary` | `#1a237e` | Primary buttons, links, H2/H3 accents, "Met" badges, active nav, focus ring |
| `brand-blue-bright` / `secondary-container` | `#3949ab` | Hover state on action surfaces |
| `brand-sky` | `#a7d3f0` | Info callout backgrounds (very pale) |
| `brand-gold` | `#ffd700` | ≤5% accent — single CTA highlights, cover hairline, ★ shipped markers |
| `brand-gold-deep` | `#b88a08` | Gold-tone text (use sparingly, never on large surfaces) |
| `brand-cyan` | `#12d9e3` | Chart/spark only — never on buttons or text |
| `brand-magenta` | `#d912e3` | Chart/spark only — also drives `.field-error` parity |
| Paper background | `#FFFFFF` | Body of every page |
| Page ink (primary text) | `#0F172A` | Body text — near-black slate |
| Secondary ink | `#475569` | Captions, footnotes, "reports to" lines |
| Subtle rule | `#E2E8F0` | Horizontal rules, table borders |
| Status — Met | `#16A34A` | ● filled circle (Standards Benchmark, Ch 27) |
| Status — Partial | `#CA8A04` | ◐ half-filled circle |
| Status — Not yet | `#DC2626` | ○ open circle |

> *Notes:* Every one of these tokens lives in `tailwind.config.js` as a named colour, and many also have a CSS-variable backing in `resources/css/app.css` so Tailwind opacity modifiers (e.g. `bg-background/80`, `border-outline-variant/50`) work without losing the brand mapping. Dark-mode token overrides keep the same semantic names; the brand colours themselves do not change between light and dark — only the surfaces and inks underneath them do.

## Typography — one sans, one mono, one icon set

The live brand has consolidated to a single typeface for everything readable on screen.

- **Open Sans** carries body, headings, captions, buttons, table cells, form labels, and the document export. The weights are used as a hierarchy: 400 for body, 700 for small headings (H4–H6), 800 for the chapter and section headings (H1–H3), and 900 (Black) for the dossier cover title. There is no second display face. There is no editorial serif. There is no decorative weight. The whole hierarchy is built from one family, one stack, one mental model.
- **JetBrains Mono** is the only place a second face is allowed. It carries staff numbers, employee numbers, invoice references, Ghana Card / SSNIT / TIN digits, currency totals in tables, and inline code in the dossier. Anywhere a column of numbers needs to line up, JetBrains Mono shows up. Anywhere prose is being read, it does not.
- **Material Symbols Outlined** is the icon set. Outlined (not filled, not rounded) gives the same calm-and-exact feeling as the type — a single, consistent stroke weight, no decorative flourishes, no two-tone fills. Every button icon, every sidebar item, every status badge, every chart legend uses the same symbol library, so the user only ever has to learn one visual vocabulary.

> A note for anyone reading older notes — the original spec mentioned Plus Jakarta Sans alongside an Instrument Serif for editorial moments. Those choices were tried and dropped. The live brand consolidated to Open Sans because one well-tuned family across body and headings reads as calmer and more institutional than two faces in conversation. The dossier follows what is actually live.

The pixel scale (from `tailwind.config.js`) is consistent and named: `micro` 10 px, `tiny` 11 px, `caption` 12 px, `body` 14 px, `body-lg` 15 px, `lead` 16 px, then `h6` 13 px, `h5` 15 px, `h4` 18 px, `h3` 22 px, `h2` 28 px, `h1` 34 px, `display` 44 px. Letter-spacing tightens as the size grows — the display size has `-0.028em` tracking; body sits at 0. Line-heights loosen as the size shrinks. The result is that long-form body copy breathes while large headings hold their shape.

## Animations — calm, short, purposeful

Sovereign Precision moves, but it never fidgets. Three patterns do almost all of the work, and every one of them is short enough to feel like a hint rather than a performance.

- **`animate-reveal-up` — the standard wrapper.** Cards, panels, page sections, and tables enter with a 50-pixel upward translation and a fade, on a 0.9 s `cubic-bezier(0.22, 1, 0.36, 1)` curve. The curve is "spring out" — fast at the start, settling at the end — so the screen feels confident, not slow. This is the default for any block of new content arriving on the page.
- **Stat-card RGB-triplet tint.** The dashboard stat tiles take a brand colour as an RGB triplet (e.g. `13 20 82` for navy) and blend it into the card at 8% on the background and 14% on a thin top border. Because the value is a triplet rather than a hex, the same single token drives both the background tint and the matching glow shadow without anyone having to author a second colour. The tile reads as "this metric is in the navy family" without shouting about it.
- **`animate-slide-up-fade` stagger.** When a list animates in — a row of stat tiles, a column of contact cards, a stream of notifications — each item gets `animation-delay: ${idx * 0.06}s`. Six-hundredths of a second between items is just enough to read as a wave, not a march. The first item lands in 0.5 s; the eighth lands by 0.92 s. Anything longer and the user starts to wait for it; anything shorter and the effect is lost.

Hover states are equally restrained — buttons lift one or two pixels with a subtle `card-hover` shadow, never balloon. Spinners use `slow-spin` (20 s per rotation) so background activity never competes with foreground content. Pulses and glows exist only as `glow-pulse` on status indicators that genuinely need a heartbeat, like an unread chat dot.

The rule on all of these is the same: animation is a *hint*. If a user with motion sensitivity turns it off (see the accessibility section below), the application still works perfectly. Nothing depends on motion to communicate state.

## Component vocabulary — five building blocks

Almost every screen in CIHRMS is built from the same small set of named components. They are the brand's nouns — every page recombines them rather than inventing new ones.

- **StatusBadge.** The pill that says "Active", "On Leave", "Approved", "Pending", "Terminated". One width formula, one set of colour mappings (status → fill), one tiny dot, one consistent label weight. Wherever you see status in CIHRMS, it looks the same.
- **EmptyState.** When a list has no rows yet — an empty leave history, an unstarted ticket queue, a department with no employees — the page does not collapse into white space. EmptyState renders a centred icon, a one-line headline ("No leave requests yet"), one supporting sentence, and at most one CTA ("Request leave"). The user always knows whether they are looking at "nothing here" or "still loading".
- **Pagination.** Twenty rows per page is the institute default. The pagination bar sits bottom-right, with the range and total at the bottom-left ("Showing 1–20 of 412"). The arrows are keyboard-navigable, the active page is action blue, the rest are subtle ink. There is exactly one pagination component; every table uses it.
- **SlidePanel.** Create and edit forms do not navigate to a new page; they slide in from the right over a dimmed backdrop. The same panel chrome (header with title and close-X, scrollable body, sticky footer with Cancel + primary CTA) wraps every "Add Employee", "Edit Department", "Add Document", and "Compose Announcement" form in the application. One pattern, learned once, used everywhere.
- **KanbanBoard.** Where workflow has stages — tickets, complaints, recruitment pipelines, performance cycles — the same Kanban component renders the columns, the cards, the drag handles, and the empty-column states. The columns are coloured by status (sky, gold, green, magenta) but the structure is identical.

There are other components — DataTable, TabBar, Toast, MetricCard, ChartCard — but those five are the brand vocabulary. If a screen needs something none of them cover, the design rule is to find the closest match and extend it, not to invent a sixth.

## Voice in copy — institutional, plain, action-led

Sovereign Precision applies to writing as much as it does to colour and type. The copy rules are short and strict.

- **Plain English.** No jargon, no acronyms that haven't been spelled out, no metaphors. "Submit your leave request" is the right voice. "Kick off your time-off journey" is not.
- **Institutional, not marketing.** The application talks to the user the way a clear-headed civil-service circular would — calm, neutral, factual. There are no exclamation marks. There are no superlatives ("amazing", "awesome", "powerful"). There are no winking emoji and no jokes.
- **Action verbs first.** Buttons read "Save", "Approve", "Cancel", "Submit Request", "Download Statement". They never read "Click here to save" or "OK". The verb tells the user what will happen; the noun, where needed, tells them what it will happen to.
- **Numbers and dates the way Ghana writes them.** Currency is `GHS 12,450.00` (always with the prefix and the two decimals). Dates are `25 May 2026` or `25/05/2026` — never the American month-first form. Phone numbers carry the `+233` international prefix in stored data; the UI may display the local `0…` form for familiarity.
- **Error messages are diagnostic, not apologetic.** "You do not have permission to edit salary." is the voice. "Oops! Something went wrong." is not. Every error says what failed and, where possible, what to do about it.

## Accessibility hooks — built into the brand

The brand has accessibility wired into its first principles. The full cross-cutting story is in Chapter 35; this is the brand-level surface of it.

- **Colour contrast.** Body ink against paper is `#0F172A` on `#FFFFFF` — a contrast ratio above 16:1, well past WCAG AAA. Action blue against white sits above 8:1. Sidebar copy is white on `#0d1452` — also AAA. The status palette (green/yellow/red) is paired with a shape (● ◐ ○) so colour is never the only signal.
- **Focus states.** Every interactive element shows a 2-pixel action-blue outline at 2-pixel offset on `:focus-visible`. Inside the obsidian sidebar the same ring is rendered in white so it keeps 3:1 contrast against the dark surface. Keyboard users always know where they are.
- **Keyboard navigation.** Every flow that can be done with a mouse can be done from the keyboard alone — including the slide panels, the kanban boards, the tab bars, and the row-action popovers. Tab order follows reading order; `Escape` closes panels and popovers; `Enter` confirms the primary CTA.
- **Reduced motion respect.** Every animation defined above lives inside a `@media (prefers-reduced-motion: reduce)` envelope. When the user (or their operating system) signals that motion should be reduced, durations collapse to 0.01 ms, iteration counts drop to 1, and scroll-behaviour goes back to auto. The application loses its hints and keeps its function.
- **High-contrast mode.** An opt-in `data-theme="high-contrast"` flag on `<html>` swaps the token palette for AAA-7:1 equivalents — black ink on white surface, white ink on black surface, darker rules — without changing layout or component structure. The brand stays recognisable; it just turns the contrast up to eleven.

The accessibility commitment is not a bolted-on layer. It is part of what "precision" means in Sovereign Precision — a precise tool is one that everyone in the institute can pick up and use, not just the median sighted, mouse-using staff member.

## What the brand promises stakeholders

When the Director-General opens CIHRMS, when the Auditor-General opens a generated report, when a new hire opens their portal on day one, the brand is making three promises to all of them.

- **Gravitas.** This is a serious tool for serious work. The navy, the calm type, the restrained motion, and the absence of marketing language together tell the reader that the institute has built (or commissioned) something that respects the weight of what it does.
- **Clarity.** The reader can find what they need quickly. The sidebar groupings, the consistent components, the plain-English copy, and the accessibility floor all reduce the mental cost of using the system. Stakeholders can act on what they see without reading a manual.
- **Speed.** The application feels responsive — short animations, debounced filters, lazy-loaded tabs, optimistic UI on row-action popovers. Speed is not a separate feature; it is what precision feels like when the user is sitting in front of it.

## What it deliberately is not

Sovereign Precision is just as clear about what it refuses to be.

- **Not playful.** No cartoon illustrations, no animated mascots, no rounded "friendly" fonts, no emoji in product copy. The audience is a payroll officer running a national-service payment, not a teenager picking a streaming plan.
- **Not ornamental.** No drop shadows for the sake of drama, no gradients on body surfaces, no decorative dividers, no second display face for "premium" headings. Every visual choice has to earn its place by carrying information.
- **Not consumer-y.** No social-app patterns ("Like", "Share to feed", "Streaks"), no game-style progress, no notifications that nudge for engagement. CIHRMS notifies when something needs the user's attention and is quiet the rest of the time.
- **Not loud.** No bright accent fills behind whole sections, no full-bleed hero photographs, no marketing colour gradients. The page is calm so the data on it can be heard.

This is the brand. The rest of this dossier is the same set of values, applied module by module — Employees in Chapter 3, Leave in Chapter 4, and so on through every screen the institute will live in. Wherever the brand is followed, the application will feel sovereign and precise; wherever it slips, that is where to come back to.
