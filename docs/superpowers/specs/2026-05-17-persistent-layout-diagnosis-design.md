# Persistent-Layout Migration — Diagnosis & Targeted Fix

**Date:** 2026-05-17
**Status:** Draft, pending evidence

## 1. Context

The CIHRMS frontend (Laravel 13 + Inertia v2 + Vue 3) was migrated from a per-page inline-layout pattern to Inertia's persistent-layout pattern across multiple codemod passes:

- **79 pages** received `defineOptions({ layout: AuthenticatedLayout })` plus an `import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'`.
- **77 pages** have their executive header projected via `<Teleport to="#page-header-mount">`, replacing the previous `<template #header>` slot.
- **77 pages** had their template body wrapped in `<div data-page-root="true">` to give Vue a single-root vnode per page.
- [`resources/js/Layouts/AuthenticatedLayout.vue`](../../resources/js/Layouts/AuthenticatedLayout.vue): the old `<slot name="header" />` was replaced with `<div id="page-header-mount" class="page-header-strip …"></div>` (CSS `:empty { display: none }`). The default `<slot />` was wrapped in `<div :key="page.url"><slot /></div>` as a defensive force-remount.
- [`resources/js/app.js`](../../resources/js/app.js): standard `resolvePageComponent` form. No auto-attach. Pages declare their layout themselves.
- Sidebar Inertia.Link components had `prefetch cache-for="30s"` added then removed during diagnosis.

**Current runtime symptom:** Sidebar/header/notification bell/etc. render correctly. Clicking a sidebar `Link` changes the browser URL but the main content area still shows the *previous* page. `npx vite build` is clean.

## 2. Hypothesis Tree

Each hypothesis maps to a specific cause and produces a distinctive evidence signature.

| # | Hypothesis | Distinctive evidence |
|---|---|---|
| **H1** | Inertia's `swapComponent` never fires for sidebar clicks | Network tab shows Inertia XHR but `page.value.url` doesn't change in Vue |
| **H2** | `swapComponent` fires but Vue's reactivity doesn't propagate through the `page` ref | `page.value.url` updates in console but DOM doesn't change |
| **H3** | Vue remounts the keyed wrapper but the new page renders empty inside | Keyed `<div>` remounts but contains no children |
| **H4** | The codemod broke specific pages' templates | Symptom is page-specific |
| **H5** | Inertia.Link's click handler is being intercepted | Network tab shows a full page reload, not an Inertia XHR |

## 3. Instrumentation

Temporary console logs at four sites. All prefixed `[NAVDIAG]` for easy grep/strip.

### 3.1 `resources/js/app.js`
- Inside the `resolve(name)` callback: log `[NAVDIAG] resolve: <name>`.
- After `createInertiaApp` set-up: register `router.on('start', e => console.log('[NAVDIAG] visit start:', e.detail.visit.url.pathname))` and `router.on('success', e => console.log('[NAVDIAG] visit success:', e.detail.page.url, 'component:', e.detail.page.component))`.

### 3.2 `resources/js/Layouts/AuthenticatedLayout.vue`
- `onMounted(() => console.log('[NAVDIAG] Layout mounted at:', page.url))`.
- `watch(() => page.url, (now, prev) => console.log('[NAVDIAG] page.url:', prev, '->', now))`.

### 3.3 One example sidebar `<Link>` (the Employees nav item)
- `@click="(e) => console.log('[NAVDIAG] sidebar click:', e.target.href)"` — non-capturing, runs alongside Inertia's handler.

### 3.4 One migrated page (`resources/js/Pages/Tickets/Index.vue`)
- `onMounted(() => console.log('[NAVDIAG] Tickets/Index mounted, props.tickets length:', props.tickets?.data?.length))`.

## 4. Evidence Collection

The user:
1. Runs the dev server (`npm run dev`).
2. Hard-refreshes the app (Ctrl+Shift+R).
3. Opens DevTools → Console.
4. Clicks **Tickets** in the sidebar from a different starting page (e.g. Dashboard or Employees).
5. Copies the complete Console output, plus the **Network** request that fires on click (status code + response headers, especially `X-Inertia` and `X-Inertia-Version`).

## 5. Decision Tree

| Console pattern | Conclusion | Fix |
|---|---|---|
| Click logged → no `visit start` | H5 | Inspect Link wiring; restore plain `<Link>` semantics; check for click-trap handlers in the sidebar template |
| `visit start` → no `visit success` → no `resolve` | Network/server error | Read response body and fix server-side controller |
| `visit success` → `resolve` logged → no `page.url` change | H1/H2 (Inertia's ref isn't updating Vue) | Verify `App` component in `node_modules/@inertiajs/vue3` — check whether `swapComponent` is being awaited; consider switching to render-function layout form |
| `page.url` change logged → no page-mounted log | H3 (Vue not remounting) | Move `:key` from layout wrapper into the page itself (`<div data-page-root :key="$page.url">`), or upgrade to Inertia render-function layout |
| Page-mounted log fires but DOM doesn't update | H4 (specific page broken) | Inspect that page's template structure; fix codemod artifact (likely indentation/wrapper malformation) |

## 6. Targeted Fix Strategy

Apply *one* fix derived from the decision tree above. No broad codemods. Verify with a fresh click before declaring done.

After the fix is verified:
- Build runs clean (`npx vite build`).
- A click from Dashboard → Tickets → Employees → Dashboard swaps content each time, with the layout staying mounted (sidebar/header don't flash).

## 7. Cleanup

Remove every `[NAVDIAG]` log in a single commit titled `chore: remove navigation diagnostic logs`. The grep `grep -rln "NAVDIAG" resources/` must return zero matches.

## 8. Done Criteria

- Sidebar clicks consistently swap content for at least 5 distinct pages.
- Layout components (sidebar, top bar, AnnouncementTicker, NotificationBell) survive navigations without remounting (verified via mount-counter log if needed).
- No `[NAVDIAG]` strings remain in the repo.
- `npx vite build` clean.

## 9. Scope, Explicitly

**In scope:** the 5 hypotheses above and the targeted fix derived from one of them.

**Out of scope:**
- Re-evaluating the persistent-layout decision itself.
- Re-running broad codemods.
- Touching pages that the symptom does not implicate.
- Re-introducing `prefetch`/`cache-for` (deferred until the basic navigation works).
