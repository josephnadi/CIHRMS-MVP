<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';

const props = defineProps({
    columns: {
        type: Array,
        default: () => [],
        // Each: { id: String, label: String, color: 'blue'|'green'|'amber'|'red'|'violet'|'gray', items: Array }
    },
    loading: {
        type: Boolean,
        default: false,
    },
    // When false, hides the 3-dot menu and disables drag.
    interactive: {
        type: Boolean,
        default: true,
    },
});

const emit = defineEmits(['move']);

// ── Drag state ───────────────────────────────────────────────────────────────
const draggingItemId   = ref(null);
const draggingFromCol  = ref(null);
const hoveredColumnId  = ref(null);

function onDragStart(ev, item, columnId) {
    if (!props.interactive) return;
    draggingItemId.value  = item.id ?? item;
    draggingFromCol.value = columnId;
    ev.dataTransfer.effectAllowed = 'move';
    // Required for Firefox so dragstart fires reliably
    try { ev.dataTransfer.setData('text/plain', String(draggingItemId.value)); } catch (e) { /* noop */ }
}

function onDragEnd() {
    draggingItemId.value  = null;
    draggingFromCol.value = null;
    hoveredColumnId.value = null;
}

function onDragEnter(columnId) {
    if (!props.interactive || draggingItemId.value === null) return;
    hoveredColumnId.value = columnId;
}

function onDragOver(ev) {
    if (!props.interactive || draggingItemId.value === null) return;
    ev.preventDefault();
    ev.dataTransfer.dropEffect = 'move';
}

function onDragLeave(columnId, ev) {
    // Only clear if we're leaving the column entirely, not entering a child element.
    if (hoveredColumnId.value === columnId && ev.target === ev.currentTarget) {
        hoveredColumnId.value = null;
    }
}

function onDrop(toColumnId) {
    if (!props.interactive) return;
    const itemId         = draggingItemId.value;
    const fromColumnId   = draggingFromCol.value;
    onDragEnd();
    if (itemId === null || fromColumnId === null || fromColumnId === toColumnId) return;
    emit('move', { itemId, fromColumnId, toColumnId });
}

// ── Kebab menu state ─────────────────────────────────────────────────────────
const openKebabId = ref(null);

function toggleKebab(itemId, ev) {
    ev?.stopPropagation();
    openKebabId.value = openKebabId.value === itemId ? null : itemId;
}

function pickKebab(item, fromColumnId, toColumnId, ev) {
    ev?.stopPropagation();
    openKebabId.value = null;
    if (fromColumnId === toColumnId) return;
    emit('move', { itemId: item.id ?? item, fromColumnId, toColumnId });
}

function onDocClick() { openKebabId.value = null; }
function onEsc(e) { if (e.key === 'Escape') openKebabId.value = null; }

onMounted(() => {
    document.addEventListener('click', onDocClick);
    document.addEventListener('keydown', onEsc);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', onDocClick);
    document.removeEventListener('keydown', onEsc);
});

// ── Theming ──────────────────────────────────────────────────────────────────
const colorMap = {
    blue:   { dot: 'bg-blue-500',   badge: 'bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300',     header: 'text-blue-700 dark:text-blue-300',     ring: 'ring-blue-400/40   bg-blue-50/40   dark:bg-blue-950/30' },
    green:  { dot: 'bg-green-500',  badge: 'bg-green-50 text-green-700 dark:bg-green-950/40 dark:text-green-300', header: 'text-green-700 dark:text-green-300',   ring: 'ring-green-400/40  bg-green-50/40  dark:bg-green-950/30' },
    amber:  { dot: 'bg-amber-500',  badge: 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300', header: 'text-amber-700 dark:text-amber-300',   ring: 'ring-amber-400/40  bg-amber-50/40  dark:bg-amber-950/30' },
    red:    { dot: 'bg-red-500',    badge: 'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-300',         header: 'text-red-700 dark:text-red-300',       ring: 'ring-red-400/40    bg-red-50/40    dark:bg-red-950/30' },
    violet: { dot: 'bg-violet-500', badge: 'bg-violet-50 text-violet-700 dark:bg-violet-950/40 dark:text-violet-300', header: 'text-violet-700 dark:text-violet-300', ring: 'ring-violet-400/40 bg-violet-50/40 dark:bg-violet-950/30' },
    gray:   { dot: 'bg-slate-400',  badge: 'bg-slate-100 text-slate-600 dark:bg-slate-800/60 dark:text-slate-300', header: 'text-slate-600 dark:text-slate-300',   ring: 'ring-slate-400/40  bg-slate-50/40  dark:bg-slate-800/30' },
};

function getColor(colorKey) {
    return colorMap[colorKey] ?? colorMap.gray;
}

function otherColumns(currentColumnId) {
    return props.columns.filter(c => c.id !== currentColumnId);
}

const skeletonCounts = [3, 2, 4, 1];
</script>

<template>
    <div class="w-full overflow-x-auto pb-4 -mb-4">

        <!-- ── Loading skeleton ────────────────────────────────────────────── -->
        <div v-if="loading" class="flex gap-4" style="min-width: max-content;">
            <div v-for="(n, ci) in 4" :key="ci" class="min-w-[280px] flex-shrink-0">
                <div class="flex items-center gap-2 mb-3 px-1">
                    <div class="w-2 h-2 rounded-full bg-outline-variant animate-pulse"></div>
                    <div class="h-3.5 w-24 rounded-full bg-outline-variant/60 animate-pulse"></div>
                    <div class="h-4 w-6 rounded-full bg-outline-variant/40 animate-pulse ml-auto"></div>
                </div>
                <div class="space-y-2">
                    <div
                        v-for="k in (skeletonCounts[ci] ?? 2)" :key="k"
                        class="rounded-xl bg-surface-container-lowest border border-outline-variant/40 p-3 shadow-card"
                    >
                        <div class="h-3 w-3/4 rounded-full bg-outline-variant/50 animate-pulse mb-2"></div>
                        <div class="h-3 w-1/2 rounded-full bg-outline-variant/30 animate-pulse mb-3"></div>
                        <div class="h-5 w-16 rounded-full bg-outline-variant/20 animate-pulse"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Board ──────────────────────────────────────────────────────── -->
        <div v-else class="flex gap-4" style="min-width: max-content;">
            <div
                v-for="column in columns"
                :key="column.id"
                class="min-w-[300px] flex-shrink-0 flex flex-col"
            >
                <!-- Column header -->
                <div class="flex items-center gap-2 mb-3 px-1">
                    <span class="w-2 h-2 rounded-full shrink-0" :class="getColor(column.color).dot"></span>
                    <span class="text-[13px] font-bold tracking-tight" :class="getColor(column.color).header">{{ column.label }}</span>
                    <span
                        class="ml-auto inline-flex items-center justify-center min-w-[22px] h-5 px-1.5 rounded-full text-[10px] font-black"
                        :class="getColor(column.color).badge"
                    >{{ column.items.length }}</span>
                </div>

                <!-- Drop zone -->
                <div
                    class="flex-1 space-y-2 min-h-[200px] rounded-xl p-2 transition-all duration-150 ring-1 ring-transparent"
                    :class="[
                        column.items.length === 0 && hoveredColumnId !== column.id ? 'border-2 border-dashed border-outline-variant/30' : '',
                        hoveredColumnId === column.id && draggingFromCol !== column.id ? `ring-2 ${getColor(column.color).ring}` : '',
                    ]"
                    @dragenter.prevent="onDragEnter(column.id)"
                    @dragover.prevent="onDragOver($event)"
                    @dragleave="onDragLeave(column.id, $event)"
                    @drop.prevent="onDrop(column.id)"
                >
                    <!-- Empty state -->
                    <div
                        v-if="column.items.length === 0"
                        class="flex flex-col items-center justify-center py-8 gap-2 pointer-events-none"
                    >
                        <span class="material-symbols-outlined text-[28px] text-on-surface-variant/20">inbox</span>
                        <p class="text-[12px] text-on-surface-variant/30 font-medium text-center">
                            <template v-if="hoveredColumnId === column.id && draggingFromCol !== column.id">
                                Drop to move here
                            </template>
                            <template v-else>
                                No items in<br/>{{ column.label }}
                            </template>
                        </p>
                    </div>

                    <!-- Cards -->
                    <div
                        v-for="item in column.items"
                        :key="item.id ?? item"
                        :draggable="interactive"
                        @dragstart="onDragStart($event, item, column.id)"
                        @dragend="onDragEnd"
                        class="relative rounded-xl bg-surface-container-lowest border border-outline-variant/50 p-3 pr-9 shadow-card hover:shadow-lifted hover:-translate-y-0.5 transition-all duration-200 group"
                        :class="[
                            interactive ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer',
                            draggingItemId === (item.id ?? item) ? 'opacity-40 ring-2 ring-secondary/40' : '',
                        ]"
                    >
                        <!-- 3-dot kebab menu (top-right) -->
                        <div v-if="interactive && otherColumns(column.id).length > 0" class="absolute top-2 right-2">
                            <button
                                type="button"
                                @click="toggleKebab(item.id ?? item, $event)"
                                @mousedown.stop
                                class="flex h-6 w-6 items-center justify-center rounded-md text-on-surface-variant/50 hover:bg-surface-container hover:text-on-surface transition-colors opacity-0 group-hover:opacity-100 focus:opacity-100"
                                :class="openKebabId === (item.id ?? item) ? 'opacity-100 bg-surface-container text-on-surface' : ''"
                                title="Change status"
                                aria-label="Change status"
                            >
                                <span class="material-symbols-outlined text-[18px]">more_vert</span>
                            </button>

                            <!-- Kebab menu popover -->
                            <Transition
                                enter-active-class="transition duration-100 ease-out"
                                enter-from-class="opacity-0 scale-95 -translate-y-1"
                                enter-to-class="opacity-100 scale-100 translate-y-0"
                                leave-active-class="transition duration-75 ease-in"
                                leave-from-class="opacity-100 scale-100"
                                leave-to-class="opacity-0 scale-95"
                            >
                                <div
                                    v-if="openKebabId === (item.id ?? item)"
                                    @click.stop
                                    class="absolute right-0 top-7 z-30 w-48 rounded-xl border border-outline-variant/60 bg-surface-container-lowest shadow-lifted overflow-hidden"
                                >
                                    <p class="px-3 pt-2 pb-1 text-[9.5px] font-black uppercase tracking-[0.18em] text-on-surface-variant/60">
                                        Move to
                                    </p>
                                    <button
                                        v-for="col in otherColumns(column.id)" :key="col.id"
                                        type="button"
                                        @click="pickKebab(item, column.id, col.id, $event)"
                                        class="w-full flex items-center gap-2 px-3 py-2 text-[12.5px] font-semibold text-on-surface hover:bg-secondary/[0.06] transition-colors text-left"
                                    >
                                        <span class="w-2 h-2 rounded-full shrink-0" :class="getColor(col.color).dot"></span>
                                        <span>{{ col.label }}</span>
                                    </button>
                                </div>
                            </Transition>
                        </div>

                        <!-- Custom card slot -->
                        <slot name="card" :item="item" :column="column">
                            <p class="text-[13px] font-semibold text-on-surface leading-snug">
                                {{ item.title ?? item.label ?? item.name ?? String(item.id ?? '') }}
                            </p>
                        </slot>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
