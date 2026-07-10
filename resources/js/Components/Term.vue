<script setup>
import { ref, computed, onBeforeUnmount } from 'vue';
import { lookupTerm } from '@/glossary';

const props = defineProps({
    // Glossary key, e.g. "AR". Case-insensitive.
    code:  { type: String, required: true },
    // Optional visible text if it differs from the code (defaults to the code).
    label: { type: String, default: null },
});

const entry = computed(() => lookupTerm(props.code));
const text  = computed(() => props.label ?? props.code);

const show   = ref(false);   // currently visible (hover/focus/tap)
const pinned = ref(false);   // tapped open — stays until dismissed
const above  = ref(false);   // flip above the trigger when there's no room below
const uid    = `term-${Math.random().toString(36).slice(2, 9)}`;

const trigger = ref(null);
const coords  = ref({ top: 0, left: 0 });

// The card is teleported to <body> and fixed-positioned so it is never clipped
// by an ancestor's overflow (stat cards, table scroll areas, truncated titles).
const CARD_W = 256; // matches w-64
function place() {
    const el = trigger.value;
    if (! el) return;
    const r = el.getBoundingClientRect();
    let left = r.left;
    if (left + CARD_W > window.innerWidth - 8) left = Math.max(8, window.innerWidth - CARD_W - 8);
    // Flip above if the trigger sits near the bottom of the viewport.
    above.value = (window.innerHeight - r.bottom) < 120;
    coords.value = {
        top:  Math.round(above.value ? r.top - 6 : r.bottom + 6),
        left: Math.round(left),
    };
}

function open() {
    place();
    show.value = true;
    window.addEventListener('scroll', onScroll, true);
    window.addEventListener('resize', close, true);
}
function close() {
    if (pinned.value) return;
    show.value = false;
    detachWindow();
}
function onScroll() { dismiss(); }

// Tap/click and Enter pin the card open (touch + keyboard); a second toggle,
// Esc, an outside click, or scrolling dismisses it.
function toggle() {
    if (pinned.value) { dismiss(); return; }
    pinned.value = true;
    open();
    document.addEventListener('click', onDocClick, true);
    document.addEventListener('keydown', onDocKey, true);
}
function onDocClick(e) {
    if (trigger.value && ! trigger.value.contains(e.target)) dismiss();
}
function onDocKey(e) {
    if (e.key === 'Escape') dismiss();
}
function dismiss() {
    pinned.value = false;
    show.value = false;
    cleanup();
}

function detachWindow() {
    window.removeEventListener('scroll', onScroll, true);
    window.removeEventListener('resize', close, true);
}
function cleanup() {
    detachWindow();
    document.removeEventListener('click', onDocClick, true);
    document.removeEventListener('keydown', onDocKey, true);
}
onBeforeUnmount(cleanup);
</script>

<template>
    <!-- Unknown term: render plain text, no affordance. -->
    <span v-if="! entry">{{ text }}</span>

    <span v-else class="inline">
        <abbr
            ref="trigger"
            :aria-label="`${text}: ${entry.term} — ${entry.definition}`"
            tabindex="0"
            class="cursor-help [text-decoration-line:underline] decoration-dotted decoration-1 underline-offset-2 decoration-on-surface-variant/50 outline-none focus-visible:ring-2 focus-visible:ring-primary/40 rounded-sm"
            @mouseenter="open"
            @mouseleave="close"
            @focus="open"
            @blur="close"
            @click="toggle"
            @keydown.enter.prevent="toggle"
            @keydown.esc="dismiss"
        >{{ text }}</abbr>

        <Teleport to="body">
            <transition
                enter-active-class="transition ease-out duration-100"
                enter-from-class="opacity-0 translate-y-0.5"
                leave-active-class="transition ease-in duration-75"
                leave-to-class="opacity-0"
            >
                <span
                    v-if="show"
                    :id="uid"
                    role="tooltip"
                    :style="{ top: coords.top + 'px', left: coords.left + 'px' }"
                    :class="above ? '-translate-y-full' : ''"
                    class="fixed z-[100] w-64 max-w-[16rem] rounded-xl border border-outline-variant/70 bg-surface-container-lowest p-3 text-left shadow-xl ring-1 ring-black/5"
                >
                    <span class="block text-[12px] font-black text-primary leading-snug">{{ entry.term }}</span>
                    <span class="mt-1 block text-[12px] leading-snug text-on-surface-variant">{{ entry.definition }}</span>
                </span>
            </transition>
        </Teleport>
    </span>
</template>
