<script setup>
/**
 * Skip-to-main-content link — WCAG 2.4.1 (Bypass Blocks).
 *
 * Renders an `sr-only` anchor that becomes visible the moment a keyboard
 * user tabs to it. Targets `#main-content` by default; pass a different
 * `target` prop for layouts that put the primary landmark elsewhere.
 *
 * Drop into the first child of every layout (Authenticated + Guest):
 *
 *   <SkipLink />
 *   <main id="main-content" tabindex="-1"> … </main>
 *
 * The `tabindex="-1"` on <main> lets the anchor move focus there even
 * though <main> isn't normally focusable.
 */
defineProps({
    target: { type: String, default: '#main-content' },
    label:  { type: String, default: 'Skip to main content' },
});
</script>

<template>
    <a :href="target" class="sr-only-focusable skip-link">
        {{ label }}
    </a>
</template>

<style scoped>
/* The .sr-only / .sr-only-focusable / focus-visible styles in app.css do
   the heavy lifting; the only thing this scoped block adds is a visible
   skip-link refinement on focus that matches the brand. */
.skip-link {
    position: absolute;
    top: -100px; left: 0.5rem;
    background: #0d1452;
    color: #ffffff;
    font: 700 13px/1.2 'Open Sans', system-ui, sans-serif;
    padding: 0.6rem 1rem;
    border-radius: 8px;
    z-index: 99999;
    transition: top 0.12s ease;
}
.skip-link:focus,
.skip-link:focus-visible {
    top: 0.5rem;
    outline: 2px solid #1a237e;
    outline-offset: 2px;
}
</style>
