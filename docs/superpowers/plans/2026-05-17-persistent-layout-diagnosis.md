# Persistent-Layout Diagnosis Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Diagnose why sidebar clicks change the URL but don't swap page content in the CIHRMS app's new persistent-layout setup, then apply a single targeted fix.

**Architecture:** Add `[NAVDIAG]`-prefixed `console.log` instrumentation at four sites that together reveal which of the five hypotheses (H1–H5 in the spec) matches the runtime behaviour. The user provides Console + Network evidence. Based on that evidence, apply *one* targeted fix from the spec's decision tree. Then remove all instrumentation in a single commit.

**Tech Stack:** Laravel 13, Inertia v2 (`@inertiajs/vue3` ^2.0.0), Vue 3.5, Vite, Tailwind v3. No tests are added in this plan — diagnostics are observational, the fix is verified by manual sidebar navigation per the spec's done criteria.

**Spec:** [docs/superpowers/specs/2026-05-17-persistent-layout-diagnosis-design.md](../specs/2026-05-17-persistent-layout-diagnosis-design.md)

---

## File Structure

| Path | Role in this plan |
|---|---|
| `resources/js/app.js` | Logs each `resolve(name)` plus Inertia router `start` / `success` events |
| `resources/js/Layouts/AuthenticatedLayout.vue` | Logs layout mount and `page.url` changes |
| `resources/js/Pages/Tickets/Index.vue` | Logs page mount + first prop count, acts as the example migrated page |
| (TBD by evidence) | The single file that the targeted fix touches in Phase B |

All instrumentation strings begin with `[NAVDIAG]` so the cleanup commit can strip them with one grep.

---

## Phase A — Instrumentation

### Task 1: Add navigation logs to app.js

**Files:**
- Modify: `resources/js/app.js`

- [ ] **Step 1: Add `router` to the Inertia import**

Open `resources/js/app.js`. Replace the import line:

```js
import { createInertiaApp } from '@inertiajs/vue3';
```

with:

```js
import { createInertiaApp, router } from '@inertiajs/vue3';
```

- [ ] **Step 2: Log every `resolve(name)` call**

Replace the `resolve` callback:

```js
    resolve: (name) => resolvePageComponent(
        `./Pages/${name}.vue`,
        import.meta.glob('./Pages/**/*.vue'),
    ),
```

with:

```js
    resolve: (name) => {
        console.log('[NAVDIAG] resolve:', name);
        return resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        );
    },
```

- [ ] **Step 3: Subscribe to router `start` and `success` events**

Add the following two lines immediately after `createInertiaApp({ … });` (i.e. at the bottom of `app.js`, outside the call):

```js
router.on('start',   (e) => console.log('[NAVDIAG] visit start  :', e.detail.visit.url.pathname));
router.on('success', (e) => console.log('[NAVDIAG] visit success:', e.detail.page.url, '· component:', e.detail.page.component));
```

- [ ] **Step 4: Build and verify**

Run: `npx vite build`
Expected: `built in <N>s` with no errors.

- [ ] **Step 5: Commit**

```bash
git add resources/js/app.js
git commit -m "chore(diag): instrument Inertia resolve and router events"
```

---

### Task 2: Add layout mount + page.url watcher to AuthenticatedLayout

**Files:**
- Modify: `resources/js/Layouts/AuthenticatedLayout.vue`

- [ ] **Step 1: Add a layout-mount log**

Locate the existing `onMounted(() => { … })` block at the top of `<script setup>` (around line 45 — the one that calls `init()` and registers `restoreSidebarScroll`). Add this as the **first** line inside it:

```js
    console.log('[NAVDIAG] Layout mounted at:', page.url);
```

This proves whether the layout itself remounts per navigation (it must not — persistent layouts stay mounted).

- [ ] **Step 2: Add a watcher on `page.url`**

`watch` is already imported in this file. Below the existing `onBeforeUnmount` block, add:

```js
watch(() => page.url, (now, prev) => {
    console.log('[NAVDIAG] page.url:', prev, '->', now);
});
```

- [ ] **Step 3: Add a click log to the Employees sidebar Link**

Per spec §3.3, log clicks on one representative sidebar Link to discriminate H5 (Inertia.Link click handler intercepted). Search the template for the sidebar's Employees Link — the regular nav `<Link>` near the line that reads `<!-- ── Regular nav link ── -->`. Add a non-capturing `@click` listener that runs alongside Inertia's handler. The simplest spot is the *generic* sidebar Link template (the one rendered for every regular nav item), which gives coverage for every sidebar click without picking a single item. Find the existing Link:

```vue
                            <Link
                                v-else
                                :href="resolveHref(item)"
                                class="group flex items-center gap-3 …"
                                :class="navItemClass(item)"
                                :style="navItemStyle(item)"
                            >
```

Add a `@click` listener immediately above `class="…"`:

```vue
                            <Link
                                v-else
                                :href="resolveHref(item)"
                                @click="() => console.log('[NAVDIAG] sidebar click:', item.label, '->', resolveHref(item))"
                                class="group flex items-center gap-3 …"
                                :class="navItemClass(item)"
                                :style="navItemStyle(item)"
                            >
```

Vue propagates `@click` on Inertia.Link without preventing Inertia's own internal handler from running, so this is purely additive.

- [ ] **Step 4: Build and verify**

Run: `npx vite build`
Expected: `built in <N>s` with no errors.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Layouts/AuthenticatedLayout.vue
git commit -m "chore(diag): instrument layout mount, page.url watcher, sidebar click"
```

---

### Task 3: Add a mount log to the example page

**Files:**
- Modify: `resources/js/Pages/Tickets/Index.vue`

- [ ] **Step 1: Confirm `onMounted` is already imported**

Run: `grep -n "import.*onMounted" resources/js/Pages/Tickets/Index.vue`
Expected: a hit on the first `import { … } from 'vue';` line (it's imported because the page already uses the `?new=1` URL-strip pattern).
If no hit, add `onMounted` to the existing `vue` import on that file.

- [ ] **Step 2: Add the mount log**

Open `resources/js/Pages/Tickets/Index.vue`. Find the FIRST `onMounted(` call (the URL-strip pattern around line 82, which contains `params.get('new')`). Add this line **before** that `onMounted` call:

```js
onMounted(() => console.log('[NAVDIAG] Tickets/Index mounted · tickets:', props.tickets?.data?.length));
```

This proves whether the page component mounts per navigation. If it never mounts, the slot isn't re-rendering.

- [ ] **Step 3: Build and verify**

Run: `npx vite build`
Expected: clean build.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Tickets/Index.vue
git commit -m "chore(diag): instrument Tickets/Index page mount"
```

---

## Phase B — Evidence + Targeted Fix

### Task 4: User collects browser evidence

**This task is performed by the user, not the implementer.** Do NOT proceed to Task 5 until evidence has been pasted into the conversation.

- [ ] **Step 1: Start the dev server**

Run: `npm run dev`

- [ ] **Step 2: User hard-refreshes the app**

User opens the app in their browser and presses Ctrl+Shift+R.

- [ ] **Step 3: User opens DevTools → Console**

Console should already show `[NAVDIAG]` lines from the initial load.

- [ ] **Step 4: User clicks a sidebar item that currently fails to swap content**

Recommended: navigate to Dashboard, then click **Tickets** in the sidebar.

- [ ] **Step 5: User copies the full Console output**

Includes: every `[NAVDIAG]` line from initial load through the click, plus any red Vue/Inertia warnings.

- [ ] **Step 6: User copies the Network tab summary for the click**

Includes: the request URL, status code, `X-Inertia` and `X-Inertia-Version` response headers. If the click triggers a full document reload (visible as a `text/html` request rather than a JSON Inertia response), record that.

- [ ] **Step 7: User pastes both into the conversation**

The implementer will then map the evidence to one of H1–H5 from the spec's decision tree (§5).

---

### Task 5: Apply the targeted fix derived from the evidence

This task **branches** based on which row of the spec's decision tree matches the evidence. Only one branch executes.

**Files:** TBD by evidence. Likely candidates: `resources/js/app.js`, `resources/js/Layouts/AuthenticatedLayout.vue`, a single page file, or `node_modules/@inertiajs/vue3` (for understanding only — never edited).

- [ ] **Step 1: Map evidence to hypothesis**

Compare the pasted Console + Network output against the table in [spec §5](../specs/2026-05-17-persistent-layout-diagnosis-design.md#5-decision-tree). Identify the matching row. State the matched hypothesis explicitly in the response (e.g. "Evidence matches H3 — keyed wrapper remounts but children are empty").

- [ ] **Step 2: Apply the single fix from the matched row**

| If matched | Then apply |
|---|---|
| **H5** (no `visit start` after click) | Inspect the sidebar `<Link>` in `AuthenticatedLayout.vue` for any wrapping element that swallows clicks (e.g. an outer `@click.stop` or `pointer-events: none`). Restore plain Link semantics. |
| **Network/server error** | Show the response body — fix in the relevant controller / middleware, **not** in the frontend. |
| **H1/H2** (`visit success` and `resolve` logged, no `page.url` change) | Switch the layout assignment from `defineOptions({ layout: AuthenticatedLayout })` to the Inertia render-function form on the example page (`Tickets/Index.vue`), then expand to others if it fixes the issue. The function form is: `defineOptions({ layout: (h, page) => h(AuthenticatedLayout, {}, () => page) })`. |
| **H3** (`page.url` changes, no page-mount log) | Move the `:key` from the layout's slot wrapper into each page's root: replace `<div data-page-root="true">` with `<div data-page-root="true" :key="$page.url">` in the affected page. Then remove the keyed wrapper from `AuthenticatedLayout.vue`. |
| **H4** (Tickets mounts but DOM doesn't update OR symptom is page-specific) | Open the page named in the URL of the failing click. Look at its template root structure (single root vs multi-root, Teleport placement). Fix the template inline. |

- [ ] **Step 3: Build**

Run: `npx vite build`
Expected: clean build.

- [ ] **Step 4: User re-verifies**

Hard refresh. Click the same sidebar item that previously failed. Content swaps. Then click 4 more distinct sidebar items in sequence — each must swap content within roughly 200 ms.

- [ ] **Step 5: Commit the fix**

```bash
git add <files-touched-by-fix>
git commit -m "fix(layout): <one-line description of the matched hypothesis fix>"
```

---

## Phase C — Cleanup

### Task 6: Remove all `[NAVDIAG]` instrumentation

**Files:**
- Modify: `resources/js/app.js`
- Modify: `resources/js/Layouts/AuthenticatedLayout.vue`
- Modify: `resources/js/Pages/Tickets/Index.vue`

- [ ] **Step 1: Grep for all instrumentation**

Run: `grep -rln "NAVDIAG" resources/`
Expected: exactly the three files listed above.

- [ ] **Step 2: Remove the `resolve` instrumentation in app.js**

Revert the `resolve` callback in `resources/js/app.js` to its single-expression form:

```js
    resolve: (name) => resolvePageComponent(
        `./Pages/${name}.vue`,
        import.meta.glob('./Pages/**/*.vue'),
    ),
```

- [ ] **Step 3: Remove the router event subscriptions**

Delete these two lines added at the bottom of `app.js`:

```js
router.on('start',   (e) => console.log('[NAVDIAG] visit start  :', e.detail.visit.url.pathname));
router.on('success', (e) => console.log('[NAVDIAG] visit success:', e.detail.page.url, '· component:', e.detail.page.component));
```

- [ ] **Step 4: Remove `router` from the Inertia import if nothing else uses it**

Run: `grep -n "\\brouter\\b" resources/js/app.js`
If the only remaining hit is the `import` line, change:

```js
import { createInertiaApp, router } from '@inertiajs/vue3';
```

back to:

```js
import { createInertiaApp } from '@inertiajs/vue3';
```

If there are other hits (unlikely in this file), leave the import alone.

- [ ] **Step 5: Remove the layout mount + watcher + click logs**

In `resources/js/Layouts/AuthenticatedLayout.vue`:
- Delete the line `console.log('[NAVDIAG] Layout mounted at:', page.url);` from the `onMounted` block.
- Delete the `watch(() => page.url, …)` block added after `onBeforeUnmount`.
- Delete the `@click="() => console.log('[NAVDIAG] sidebar click:', …)"` attribute from the regular nav `<Link>` (restoring the original four-attribute Link).

- [ ] **Step 6: Remove the Tickets page mount log**

In `resources/js/Pages/Tickets/Index.vue`, delete the line:

```js
onMounted(() => console.log('[NAVDIAG] Tickets/Index mounted · tickets:', props.tickets?.data?.length));
```

- [ ] **Step 7: Verify cleanup completeness**

Run: `grep -rln "NAVDIAG" resources/`
Expected: **zero hits** anywhere in `resources/`.

- [ ] **Step 8: Build**

Run: `npx vite build`
Expected: clean build.

- [ ] **Step 9: Commit cleanup**

```bash
git add resources/js/app.js resources/js/Layouts/AuthenticatedLayout.vue resources/js/Pages/Tickets/Index.vue
git commit -m "chore: remove navigation diagnostic logs"
```

---

## Done Criteria (re-verify before declaring complete)

- [ ] Clicking 5+ distinct sidebar items each swaps content within ~200 ms.
- [ ] Sidebar / top bar / AnnouncementTicker / NotificationBell do not flash on navigation (verified visually — they remain in place).
- [ ] `grep -rln "NAVDIAG" resources/` returns nothing.
- [ ] `npx vite build` completes without errors.
- [ ] The targeted fix from Task 5 is a single commit; the instrumentation and cleanup are each their own commits.
