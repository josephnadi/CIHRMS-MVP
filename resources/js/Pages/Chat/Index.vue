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

/**
 * The card style alternates between two palettes (yellow & teal) based on
 * the card's index — that gives the page the zig-zag rhythm of the
 * reference. The page-level zig-zag (left/right column offset) is done
 * via a CSS grid + an alternating column-start class.
 */
const PALETTES = [
    { bg: '#fbc02d', name: '#0a1138', sub: '#1a237e', initialBg: '#0a1138', initialFg: '#fbc02d' },
    { bg: '#0c8b86', name: '#ffffff', sub: '#d6f4f2', initialBg: '#ffffff', initialFg: '#0c8b86' },
];
const paletteFor = (i) => PALETTES[i % 2];
const colStartFor = (i) => (i % 2 === 0 ? 'lg:col-start-1' : 'lg:col-start-7');

const items = computed(() => props.directory?.data ?? []);
const meta  = computed(() => props.directory ?? {});
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
                        Talk to anyone in the institute. Tap a card to open a chat.
                    </p>
                </div>
                <div v-if="unreadTotal > 0"
                     class="inline-flex items-center gap-2 rounded-full bg-rose-50 px-3 py-1.5 border border-rose-200">
                    <span class="h-2 w-2 rounded-full bg-rose-500 animate-pulse"></span>
                    <span class="text-[12px] font-black text-rose-700">{{ unreadTotal }} unread</span>
                </div>
            </div>
        </Teleport>

        <div class="space-y-10">

            <!-- ╔══════════════════════════════════════════════════════════════╗ -->
            <!-- ║ Search bar                                                    ║ -->
            <!-- ╚══════════════════════════════════════════════════════════════╝ -->
            <div class="flex items-center gap-3">
                <div class="flex-1 max-w-md relative">
                    <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[18px] text-on-surface-variant">search</span>
                    <input v-model="q" @keyup.enter="search"
                           placeholder="Search name, staff ID, or email…"
                           class="w-full rounded-xl border border-outline-variant bg-surface-container-lowest text-[13px] pl-10 pr-3 py-2.5 font-semibold focus:border-secondary focus:outline-none focus:ring-2 focus:ring-secondary/20" />
                </div>
                <button @click="search" class="rounded-xl border border-outline-variant bg-surface-container-lowest px-4 py-2.5 text-[12px] font-black hover:bg-surface-container-low">Search</button>
                <button v-if="filters.q" @click="clearSearch" class="text-[12px] font-black text-on-surface-variant hover:text-rose-600">Clear</button>
            </div>

            <!-- ╔══════════════════════════════════════════════════════════════╗ -->
            <!-- ║ Recent threads (only shown if you have any)                  ║ -->
            <!-- ╚══════════════════════════════════════════════════════════════╝ -->
            <section v-if="recent.length" class="space-y-4">
                <div class="flex items-baseline justify-between">
                    <h2 class="text-[11px] font-black uppercase tracking-[0.22em] text-on-surface-variant">Continue chatting</h2>
                    <span class="text-[10px] font-bold text-on-surface-variant">{{ recent.length }} recent</span>
                </div>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <Link v-for="t in recent" :key="t.id"
                          :href="route('chat.show', t.id)"
                          class="group flex items-center gap-3 rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4 hover:border-secondary/40 hover:shadow-md transition-all">
                        <div class="relative h-12 w-12 flex-shrink-0">
                            <img v-if="t.other?.avatar_url" :src="t.other.avatar_url" :alt="t.other?.name ?? ''"
                                 class="h-12 w-12 rounded-2xl object-cover" />
                            <div v-else
                                 class="h-12 w-12 rounded-2xl flex items-center justify-center text-[12px] font-black"
                                 style="background:#0a1138;color:#fbc02d">
                                {{ t.other?.initials ?? '?' }}
                            </div>
                            <span v-if="t.unread_count > 0"
                                  class="absolute -top-1 -right-1 inline-flex h-5 min-w-[20px] items-center justify-center rounded-full bg-rose-600 px-1 text-[10px] font-black text-white">
                                {{ t.unread_count }}
                            </span>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-[13px] font-black text-primary truncate">{{ t.other?.name ?? 'Unknown' }}</p>
                            <p class="text-[11px] font-medium text-on-surface-variant truncate">
                                <span v-if="t.last_message?.is_mine" class="text-on-surface-variant/70">You: </span>{{ t.last_message?.body ?? 'Say hello —' }}
                            </p>
                        </div>
                        <span class="text-[10px] font-bold text-on-surface-variant whitespace-nowrap">{{ t.last_message?.time ?? '' }}</span>
                    </Link>
                </div>
            </section>

            <!-- ╔══════════════════════════════════════════════════════════════╗ -->
            <!-- ║ DIRECTORY — alternating yellow/teal zig-zag card grid         ║ -->
            <!-- ╚══════════════════════════════════════════════════════════════╝ -->
            <section class="relative">
                <div class="flex items-baseline justify-between mb-6">
                    <h2 class="text-[11px] font-black uppercase tracking-[0.22em] text-on-surface-variant">All people</h2>
                    <span class="text-[10px] font-bold text-on-surface-variant">
                        {{ meta.total ?? items.length }} {{ (meta.total ?? items.length) === 1 ? 'person' : 'people' }}
                    </span>
                </div>

                <!-- Decorative backdrop: faded institute aerial -->
                <div class="absolute inset-0 -z-10 pointer-events-none opacity-[0.025]"
                     style="background:radial-gradient(ellipse at top, rgba(10,17,56,0.4) 0%, transparent 60%);"></div>

                <div v-if="items.length" class="grid grid-cols-1 lg:grid-cols-12 gap-x-6 gap-y-5">
                    <Link v-for="(person, i) in items" :key="person.id"
                          :href="route('chat.openWith', person.id)"
                          :class="['lg:col-span-6 group block', colStartFor(i)]">
                        <div class="relative flex items-stretch gap-0 rounded-[28px] overflow-hidden shadow-[0_10px_24px_-12px_rgba(0,0,0,0.35)] transition-transform duration-300 group-hover:-translate-y-1 group-hover:shadow-[0_18px_30px_-14px_rgba(0,0,0,0.4)]"
                             :style="`background:${paletteFor(i).bg}`">

                            <!-- Avatar pane — square card on the left -->
                            <div class="relative w-[110px] flex-shrink-0">
                                <img v-if="person.employee?.avatar_path"
                                     :src="`/storage/${person.employee.avatar_path}`"
                                     :alt="person.name"
                                     class="absolute inset-0 h-full w-full object-cover" />
                                <div v-else
                                     class="absolute inset-0 flex items-center justify-center text-[28px] font-black"
                                     :style="`background:${paletteFor(i).initialBg};color:${paletteFor(i).initialFg}`">
                                    {{ (person.name ?? '?').split(' ').filter(Boolean).slice(0,2).map(s => s[0]).join('').toUpperCase() }}
                                </div>
                                <!-- soft fade on the inner edge so the photo blends into the card -->
                                <div class="absolute inset-y-0 right-0 w-6 pointer-events-none"
                                     :style="`background:linear-gradient(90deg, transparent, ${paletteFor(i).bg})`"></div>
                            </div>

                            <!-- Text pane -->
                            <div class="flex-1 flex flex-col justify-center px-6 py-5 min-w-0">
                                <p class="text-[18px] sm:text-[20px] font-black leading-tight tracking-tight truncate"
                                   :style="`color:${paletteFor(i).name}`">
                                    {{ person.name }}
                                </p>
                                <p class="text-[12px] sm:text-[13px] italic mt-1 truncate"
                                   :style="`color:${paletteFor(i).sub}`">
                                    {{ person.employee?.position ?? 'Employee' }}<span v-if="person.employee?.department?.name"> · {{ person.employee.department.name }}</span>
                                </p>
                            </div>

                            <!-- Chat-arrow affordance, only on hover -->
                            <div class="hidden sm:flex absolute right-4 top-1/2 -translate-y-1/2 items-center justify-center h-9 w-9 rounded-full opacity-0 translate-x-2 group-hover:opacity-100 group-hover:translate-x-0 transition-all"
                                 :style="`background:${paletteFor(i).initialBg};color:${paletteFor(i).initialFg}`">
                                <span class="material-symbols-outlined text-[18px]">chat</span>
                            </div>
                        </div>
                    </Link>
                </div>

                <!-- Empty state -->
                <div v-else class="rounded-3xl border border-dashed border-outline-variant bg-surface-container-lowest p-12 text-center">
                    <span class="material-symbols-outlined text-3xl text-on-surface-variant" style="font-variation-settings:'FILL' 1">person_search</span>
                    <p class="mt-2 text-[14px] font-black text-primary">No people match "{{ filters.q }}"</p>
                    <p class="mt-1 text-[12px] font-medium text-on-surface-variant">Try a different name or staff ID.</p>
                </div>

                <!-- Pagination -->
                <div v-if="meta.last_page > 1" class="mt-8 flex items-center justify-center gap-2 flex-wrap">
                    <Link v-for="link in meta.links" :key="link.label"
                          :href="link.url ?? '#'"
                          v-html="link.label"
                          :class="[
                              'px-3 py-1.5 rounded-xl text-[12px] font-black border',
                              link.active ? 'bg-primary text-white border-primary'
                                          : link.url ? 'bg-surface-container-lowest border-outline-variant text-primary hover:border-secondary'
                                                     : 'opacity-40 cursor-not-allowed border-outline-variant text-on-surface-variant'
                          ]" />
                </div>
            </section>
        </div>
    </div>
</template>
