<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    directory:   { type: Object, required: true },     // paginator
    recent:      { type: Array,  default: () => [] },
    unreadTotal: { type: Number, default: 0 },
    filters:     { type: Object, default: () => ({ q: '' }) },
});

const q = ref(props.filters?.q ?? '');

function search() {
    router.get(route('chat.index'), { q: q.value }, { preserveState: true, replace: true });
}

function clearSearch() {
    q.value = '';
    router.get(route('chat.index'), {}, { preserveState: true, replace: true });
}

const items = computed(() => props.directory?.data ?? []);
const meta  = computed(() => props.directory ?? {});

function initials(name) {
    return (name ?? '?').split(' ').filter(Boolean).slice(0, 2).map(s => s[0]).join('').toUpperCase();
}

// A monochrome but distinguishable initial-card colour from the staff_id hash —
// keeps the page calm while still making cards individually recognisable.
function initialBg(id) {
    const hues = ['#0a1138', '#1a237e', '#0c4a6e', '#164e63', '#3f1c5c', '#3b1f0a', '#0f3d2e', '#3a0d2c'];
    return hues[(id ?? 0) % hues.length];
}

// L12 audit fix: decode Laravel paginator HTML entities so the pagination
// labels can render with {{ }} instead of v-html. `&amp;` is replaced LAST
// to avoid double-unescape (a literal `&amp;lt;` must decode to `&lt;`,
// not `<`). CodeQL js/double-escaping confirmed this on PR #60.
function decodePaginatorLabel(label) {
    if (typeof label !== 'string') return '';
    return label
        .replace(/&laquo;/g, '«').replace(/&raquo;/g, '»')
        .replace(/&lt;/g, '<').replace(/&gt;/g, '>')
        .replace(/&quot;/g, '"').replace(/&#039;/g, "'").replace(/&nbsp;/g, ' ')
        .replace(/&amp;/g, '&');
}
</script>

<template>
    <Head title="Messages" />

    <div data-page-root="true">
        <Teleport to="#page-header-mount" defer>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">forum</span>
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">INTERNAL CHAT</p>
                    </div>
                    <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Messages</h1>
                    <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                        Talk to anyone in the institute. Tap a row to open a chat.
                    </p>
                </div>
                <div v-if="unreadTotal > 0"
                     class="inline-flex items-center gap-2 rounded-full bg-rose-50 px-3 py-1.5 border border-rose-200">
                    <span class="h-2 w-2 rounded-full bg-rose-500 animate-pulse"></span>
                    <span class="text-[12px] font-black text-rose-700">{{ unreadTotal }} unread</span>
                </div>
            </div>
        </Teleport>

        <div class="space-y-8 max-w-3xl">

            <!-- Search bar -->
            <div class="flex items-center gap-3">
                <div class="flex-1 max-w-md relative">
                    <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[18px] text-on-surface-variant">search</span>
                    <input aria-label="Q" v-model="q" @keyup.enter="search"
                           placeholder="Search name, staff ID, or email…"
                           class="w-full rounded-xl border border-outline-variant bg-surface-container-lowest text-[13px] pl-10 pr-3 py-2.5 font-semibold focus:border-secondary focus:outline-none focus:ring-2 focus:ring-secondary/20" />
                </div>
                <button @click="search" class="rounded-xl border border-outline-variant bg-surface-container-lowest px-4 py-2.5 text-[12px] font-black hover:bg-surface-container-low">Search</button>
                <button v-if="filters.q" @click="clearSearch" class="text-[12px] font-black text-on-surface-variant hover:text-rose-600">Clear</button>
            </div>

            <!-- ╔═══════════════════════════════════════════════╗ -->
            <!-- ║ SECTION 1 — Recent conversations              ║ -->
            <!-- ╚═══════════════════════════════════════════════╝ -->
            <section v-if="recent.length">
                <div class="flex items-baseline justify-between mb-3 px-1">
                    <h2 class="text-[10px] font-black uppercase tracking-[0.22em] text-on-surface-variant">Continue chatting</h2>
                    <span class="text-[10px] font-bold text-on-surface-variant">{{ recent.length }} recent</span>
                </div>

                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden divide-y divide-outline-variant/30">
                    <Link v-for="t in recent" :key="t.id"
                          :href="route('chat.show', t.id)"
                          class="chat-row group">

                        <!-- Cobalt unread indicator bar -->
                        <span class="chat-row-bar" :class="t.unread_count > 0 ? 'is-unread' : ''"></span>

                        <!-- Avatar -->
                        <div class="chat-row-avatar">
                            <img v-if="t.other?.avatar_url" :src="t.other.avatar_url" :alt="t.other?.name ?? ''"
                                 class="h-full w-full rounded-[10px] object-cover" />
                            <div v-else class="h-full w-full rounded-[10px] flex items-center justify-center text-[13px] font-black tracking-tight"
                                 :style="`background:${initialBg(t.other?.id)};color:#fbc02d`">
                                {{ t.other?.initials ?? initials(t.other?.name) }}
                            </div>
                            <span v-if="t.unread_count > 0"
                                  class="absolute -top-1 -right-1 inline-flex h-4 min-w-[16px] items-center justify-center rounded-full bg-rose-600 px-1 text-[9px] font-black text-white">
                                {{ t.unread_count }}
                            </span>
                        </div>

                        <!-- Name + last message -->
                        <div class="chat-row-body">
                            <p class="chat-row-name" :class="t.unread_count > 0 ? 'is-unread' : ''">
                                {{ t.other?.name ?? 'Unknown' }}
                            </p>
                            <p class="chat-row-preview">
                                <span v-if="t.last_message?.is_mine" class="chat-row-prefix">You:</span>
                                <em>{{ t.last_message?.body ?? 'Say hello —' }}</em>
                            </p>
                        </div>

                        <!-- Time + chevron -->
                        <div class="chat-row-meta">
                            <span class="chat-row-time">{{ t.last_message?.time ?? '' }}</span>
                            <span class="material-symbols-outlined chat-row-arrow">arrow_forward</span>
                        </div>
                    </Link>
                </div>
            </section>

            <!-- ╔═══════════════════════════════════════════════╗ -->
            <!-- ║ SECTION 2 — Directory of all people           ║ -->
            <!-- ╚═══════════════════════════════════════════════╝ -->
            <section>
                <div class="flex items-baseline justify-between mb-3 px-1">
                    <h2 class="text-[10px] font-black uppercase tracking-[0.22em] text-on-surface-variant">All people</h2>
                    <span class="text-[10px] font-bold text-on-surface-variant">
                        {{ meta.total ?? items.length }} {{ (meta.total ?? items.length) === 1 ? 'person' : 'people' }}
                    </span>
                </div>

                <div v-if="items.length"
                     class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden divide-y divide-outline-variant/30">

                    <Link v-for="person in items" :key="person.id"
                          :href="route('chat.openWith', person.id)"
                          class="chat-row group">

                        <span class="chat-row-bar"></span>

                        <div class="chat-row-avatar">
                            <img v-if="person.employee?.avatar_path"
                                 :src="`/storage/${person.employee.avatar_path}`"
                                 :alt="person.name"
                                 class="h-full w-full rounded-[10px] object-cover" />
                            <div v-else class="h-full w-full rounded-[10px] flex items-center justify-center text-[13px] font-black tracking-tight"
                                 :style="`background:${initialBg(person.id)};color:#fbc02d`">
                                {{ initials(person.name) }}
                            </div>
                        </div>

                        <div class="chat-row-body">
                            <p class="chat-row-name">{{ person.name }}</p>
                            <p class="chat-row-preview">
                                <em>{{ person.employee?.position ?? 'Employee' }}<span v-if="person.employee?.department?.name"> · {{ person.employee.department.name }}</span></em>
                            </p>
                        </div>

                        <div class="chat-row-meta">
                            <span class="chat-row-cta">Start chat</span>
                            <span class="material-symbols-outlined chat-row-arrow">arrow_forward</span>
                        </div>
                    </Link>
                </div>

                <!-- Empty state -->
                <div v-else class="rounded-2xl border border-dashed border-outline-variant bg-surface-container-lowest p-12 text-center">
                    <span class="material-symbols-outlined text-3xl text-on-surface-variant" style="font-variation-settings:'FILL' 1">person_search</span>
                    <p class="mt-2 text-[14px] font-black text-primary">No people match "{{ filters.q }}"</p>
                    <p class="mt-1 text-[12px] font-medium text-on-surface-variant">Try a different name or staff ID.</p>
                </div>

                <!-- Pagination -->
                <div v-if="meta.last_page > 1" class="mt-6 flex items-center justify-center gap-2 flex-wrap">
                    <Link v-for="link in meta.links" :key="link.label"
                          :href="link.url ?? '#'"
                          :class="[
                              'px-3 py-1.5 rounded-xl text-[12px] font-black border',
                              link.active ? 'bg-primary text-white border-primary'
                                          : link.url ? 'bg-surface-container-lowest border-outline-variant text-primary hover:border-secondary'
                                                     : 'opacity-40 cursor-not-allowed border-outline-variant text-on-surface-variant'
                          ]">{{ decodePaginatorLabel(link.label) }}</Link>
                </div>
            </section>
        </div>
    </div>
</template>

<style scoped>
/* ────────────────────────────────────────────────────────────────
   Chat row — editorial single-column list. Each row is a precise
   3-column grid: avatar | flexible body | meta. Cobalt unread bar
   slides in from the left edge on unread items.
   ──────────────────────────────────────────────────────────────── */
.chat-row {
    position: relative;
    display: grid;
    grid-template-columns: 56px 1fr auto;
    align-items: center;
    column-gap: 16px;
    padding: 14px 20px 14px 24px;
    background: transparent;
    transition: background-color 0.15s ease;
    text-decoration: none;
}
.chat-row:hover {
    background: rgba(26, 35, 126, 0.04);
}

/* Left cobalt accent bar — present but transparent by default.
   Slides in on hover; persistent + bright when the thread is unread. */
.chat-row-bar {
    position: absolute;
    left: 0; top: 8px; bottom: 8px;
    width: 3px;
    border-radius: 0 3px 3px 0;
    background: transparent;
    transition: background-color 0.2s ease, transform 0.2s ease;
    transform: scaleY(0.4);
    transform-origin: center;
}
.chat-row:hover .chat-row-bar {
    background: rgba(26, 35, 126, 0.35);
    transform: scaleY(0.7);
}
.chat-row-bar.is-unread {
    background: linear-gradient(180deg, #1a237e, #3949ab);
    transform: scaleY(1);
    box-shadow: 0 0 12px rgba(26, 35, 126, 0.35);
}

/* Avatar — 52px square with 10px corner radius; slight relief shadow */
.chat-row-avatar {
    position: relative;
    height: 52px;
    width: 52px;
    border-radius: 10px;
    overflow: visible;
    box-shadow: 0 2px 6px -2px rgba(10, 17, 56, 0.35),
                0 0 0 1px rgba(0, 0, 0, 0.04);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.chat-row:hover .chat-row-avatar {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px -4px rgba(10, 17, 56, 0.42),
                0 0 0 1px rgba(0, 0, 0, 0.05);
}

/* Body — name (display-black) + last message (italic muted) */
.chat-row-body {
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.chat-row-name {
    font-size: 14px;
    font-weight: 700;
    color: rgb(var(--ct-primary));
    line-height: 1.2;
    letter-spacing: -0.01em;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.chat-row-name.is-unread {
    font-weight: 900;
    color: #0a1138;
}
.chat-row-preview {
    font-size: 12px;
    color: rgb(var(--ct-on-surface-variant));
    font-weight: 500;
    line-height: 1.35;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.chat-row-preview em {
    font-style: italic;
    font-weight: 400;
}
.chat-row-prefix {
    color: rgb(var(--ct-on-surface-variant) / 0.65);
    font-style: normal;
    font-weight: 700;
    margin-right: 4px;
}

/* Meta — time + arrow indicator. Time uses a tabular-figures font
   so timestamps align vertically in the list. */
.chat-row-meta {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}
.chat-row-time {
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    font-size: 10px;
    font-weight: 700;
    color: rgb(var(--ct-on-surface-variant));
    font-feature-settings: 'tnum' 1;
    letter-spacing: 0.02em;
}
.chat-row-cta {
    font-size: 9.5px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 0.14em;
    color: rgb(var(--ct-on-surface-variant) / 0.5);
    transition: color 0.15s ease;
}
.chat-row:hover .chat-row-cta {
    color: #1a237e;
}
.chat-row-arrow {
    font-size: 16px;
    color: rgb(var(--ct-on-surface-variant) / 0.3);
    transform: translateX(-4px);
    opacity: 0;
    transition: opacity 0.18s ease, transform 0.18s cubic-bezier(0.22, 1, 0.36, 1), color 0.18s ease;
}
.chat-row:hover .chat-row-arrow {
    opacity: 1;
    transform: translateX(0);
    color: #1a237e;
}

@media (max-width: 640px) {
    .chat-row {
        padding: 12px 14px 12px 18px;
        column-gap: 12px;
    }
    .chat-row-time, .chat-row-cta {
        display: none;
    }
}
</style>
