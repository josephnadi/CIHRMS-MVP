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

const show   = ref(false);   // currently visible (hover/focus)
const pinned = ref(false);   // tapped open (touch) — stays until dismissed
const uid    = `term-${Math.random().toString(36).slice(2, 9)}`;

const open  = () => { show.value = true; };
const close = () => { if (! pinned.value) show.value = false; };

const toggle = () => {
    pinned.value = ! pinned.value;
    show.value = pinned.value;
    if (pinned.value) document.addEventListener('click', onDocClick, true);
    else document.removeEventListener('click', onDocClick, true);
};

const root = ref(null);
const onDocClick = (e) => {
    if (root.value && ! root.value.contains(e.target)) { pinned.value = false; show.value = false; document.removeEventListener('click', onDocClick, true); }
};
const onEsc = () => { pinned.value = false; show.value = false; };

onBeforeUnmount(() => document.removeEventListener('click', onDocClick, true));
</script>

<template>
    <!-- Unknown term: render plain text, no affordance. -->
    <span v-if="! entry">{{ text }}</span>

    <span v-else ref="root" class="tw-term relative inline-block" @mouseenter="open" @mouseleave="close">
        <abbr
            :title="entry.term"
            :aria-describedby="show ? uid : undefined"
            tabindex="0"
            class="cursor-help no-underline decoration-dotted underline-offset-2 [text-decoration-line:underline] decoration-1 decoration-on-surface-variant/50 outline-none focus-visible:ring-2 focus-visible:ring-primary/40 rounded-sm"
            @focus="open"
            @blur="close"
            @click.stop="toggle"
            @keydown.enter.prevent="toggle"
            @keydown.esc="onEsc"
        >{{ text }}</abbr>

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
                class="absolute z-50 left-0 top-full mt-1.5 w-64 max-w-[16rem] rounded-xl border border-outline-variant/70 bg-surface-container-lowest p-3 text-left shadow-lg ring-1 ring-black/5"
            >
                <span class="block text-[12px] font-black text-primary leading-snug">{{ entry.term }}</span>
                <span class="mt-1 block text-[12px] leading-snug text-on-surface-variant">{{ entry.definition }}</span>
            </span>
        </transition>
    </span>
</template>
