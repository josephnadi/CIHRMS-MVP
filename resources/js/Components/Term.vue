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
    coords.value = { top: Math.round(r.bottom + 6), left: Math.round(left) };
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
    cleanup();
}
function onScroll() { pinned.value = false; show.value = false; cleanup(); }

function toggle() {
    if (pinned.value) { pinned.value = false; show.value = false; cleanup(); return; }
    pinned.value = true;
    open();
    document.addEventListener('click', onDocClick, true);
}
function onDocClick(e) {
    if (trigger.value && ! trigger.value.contains(e.target)) { pinned.value = false; show.value = false; cleanup(); }
}
function onEsc() { pinned.value = false; show.value = false; cleanup(); }

function cleanup() {
    window.removeEventListener('scroll', onScroll, true);
    window.removeEventListener('resize', close, true);
    document.removeEventListener('click', onDocClick, true);
}
onBeforeUnmount(cleanup);
</script>

<template>
    <!-- Unknown term: render plain text, no affordance. -->
    <span v-if="! entry">{{ text }}</span>

    <span v-else class="inline">
        <abbr
            ref="trigger"
            :aria-label="`${text}: ${entry.term}`"
            tabindex="0"
            class="cursor-help [text-decoration-line:underline] decoration-dotted decoration-1 underline-offset-2 decoration-on-surface-variant/50 outline-none focus-visible:ring-2 focus-visible:ring-primary/40 rounded-sm"
            @mouseenter="open"
            @mouseleave="close"
            @focus="open"
            @blur="close"
            @click.stop="toggle"
            @keydown.enter.prevent="toggle"
            @keydown.esc="onEsc"
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
                    class="fixed z-[100] w-64 max-w-[16rem] rounded-xl border border-outline-variant/70 bg-surface-container-lowest p-3 text-left shadow-xl ring-1 ring-black/5"
                >
                    <span class="block text-[12px] font-black text-primary leading-snug">{{ entry.term }}</span>
                    <span class="mt-1 block text-[12px] leading-snug text-on-surface-variant">{{ entry.definition }}</span>
                </span>
            </transition>
        </Teleport>
    </span>
</template>
