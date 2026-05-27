<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    links: { type: Array,  required: true },
    meta:  { type: Object, default: null },
});

function navigate(url) {
    if (!url) return;
    router.visit(url, { preserveState: true });
}

// Derive prev / next from the standard Inertia links array
const prevLink = computed(() => props.links.find(l => l.label === '&laquo; Previous' || l.label === '‹' || l.label.includes('Previous') || l.label.includes('prev')));
const nextLink = computed(() => props.links.find(l => l.label === 'Next &raquo;' || l.label === '›' || l.label.includes('Next') || l.label.includes('next')));

// Page number links (everything except first and last which are prev/next)
const pageLinks = computed(() => {
    if (!props.links.length) return [];
    // Standard Inertia pagination: first = prev, last = next, middle = pages
    const mid = props.links.slice(1, -1);
    return mid;
});

const metaText = computed(() => {
    if (!props.meta) return null;
    const { from, to, total } = props.meta;
    if (!from && !to) return null;
    return `Showing ${from ?? 0}–${to ?? 0} of ${total ?? 0} results`;
});

// L12 audit fix: Laravel paginator labels arrive as HTML-entity strings
// (e.g. `&laquo; Previous`, `Next &raquo;`). Decode them in JS so the
// template can render with {{ }} instead of v-html. Avoids the entire
// `v-html` attack surface for what is framework-trusted text.
function decodeHtml(label) {
    if (typeof label !== 'string') return '';
    // Tiny replacement table — sufficient for the paginator's known set.
    return label
        .replace(/&laquo;/g, '«')
        .replace(/&raquo;/g, '»')
        .replace(/&amp;/g,  '&')
        .replace(/&lt;/g,   '<')
        .replace(/&gt;/g,   '>')
        .replace(/&quot;/g, '"')
        .replace(/&#039;/g, "'")
        .replace(/&nbsp;/g, ' ');
}
</script>

<template>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between px-1 py-2">
        <!-- Meta text -->
        <p v-if="metaText" class="text-[12px] text-on-surface-variant/70 hidden sm:block">
            {{ metaText }}
        </p>

        <!-- Navigation -->
        <nav class="flex items-center gap-1" aria-label="Pagination">
            <!-- Previous -->
            <button
                :disabled="!prevLink?.url"
                :class="[
                    'flex items-center gap-1 rounded-lg px-3 py-1.5 text-[12px] font-semibold transition-colors',
                    prevLink?.url
                        ? 'text-on-surface-variant hover:bg-surface-container hover:text-on-surface'
                        : 'text-on-surface-variant/30 cursor-not-allowed',
                ]"
                @click="navigate(prevLink?.url)"
            >
                <span class="material-symbols-outlined text-[16px]">chevron_left</span>
                <span class="hidden sm:inline">Prev</span>
            </button>

            <!-- Page numbers -->
            <template v-for="link in pageLinks" :key="link.label">
                <span
                    v-if="link.label === '...'"
                    class="px-2 py-1.5 text-[12px] text-on-surface-variant/40 select-none"
                >…</span>
                <button
                    v-else
                    :class="[
                        'min-w-[32px] rounded-lg px-2.5 py-1.5 text-[12px] font-semibold transition-colors',
                        link.active
                            ? 'bg-secondary text-white shadow-glow-sm'
                            : 'text-on-surface-variant hover:bg-surface-container hover:text-on-surface',
                    ]"
                    @click="navigate(link.url)"
                >{{ decodeHtml(link.label) }}</button>
            </template>

            <!-- Next -->
            <button
                :disabled="!nextLink?.url"
                :class="[
                    'flex items-center gap-1 rounded-lg px-3 py-1.5 text-[12px] font-semibold transition-colors',
                    nextLink?.url
                        ? 'text-on-surface-variant hover:bg-surface-container hover:text-on-surface'
                        : 'text-on-surface-variant/30 cursor-not-allowed',
                ]"
                @click="navigate(nextLink?.url)"
            >
                <span class="hidden sm:inline">Next</span>
                <span class="material-symbols-outlined text-[16px]">chevron_right</span>
            </button>
        </nav>
    </div>
</template>
