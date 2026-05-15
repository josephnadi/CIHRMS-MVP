<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
    columns:    { type: Array,   required: true },
    rows:       { type: Array,   required: true },
    loading:    { type: Boolean, default: false },
    pagination: { type: Object,  default: null },
});

const emit = defineEmits(['sort', 'row-click']);

const sortKey = ref('');
const sortDir = ref('asc');

function handleSort(col) {
    if (!col.sortable) return;
    if (sortKey.value === col.key) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortKey.value = col.key;
        sortDir.value = 'asc';
    }
    emit('sort', sortKey.value, sortDir.value);
}

const skeletonRows = Array.from({ length: 6 });
</script>

<template>
    <div class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest shadow-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full min-w-full">
                <!-- Sticky Header -->
                <thead class="sticky top-0 z-10">
                    <tr class="bg-surface-container-low border-b border-outline-variant/50">
                        <th
                            v-for="col in columns"
                            :key="col.key"
                            :style="col.width ? { width: col.width } : {}"
                            :class="[
                                'px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider select-none',
                                col.sortable ? 'cursor-pointer hover:text-on-surface transition-colors' : '',
                                sortKey === col.key ? 'text-secondary' : 'text-on-surface-variant/70',
                            ]"
                            @click="handleSort(col)"
                        >
                            <span class="flex items-center gap-1.5">
                                {{ col.label }}
                                <span v-if="col.sortable" class="flex flex-col gap-px">
                                    <span
                                        class="material-symbols-outlined text-[12px] leading-none"
                                        :class="sortKey === col.key && sortDir === 'asc' ? 'text-secondary' : 'text-on-surface-variant/30'"
                                        style="font-size:12px"
                                    >arrow_drop_up</span>
                                    <span
                                        class="material-symbols-outlined leading-none"
                                        :class="sortKey === col.key && sortDir === 'desc' ? 'text-secondary' : 'text-on-surface-variant/30'"
                                        style="font-size:12px"
                                    >arrow_drop_down</span>
                                </span>
                            </span>
                        </th>
                    </tr>
                </thead>

                <tbody>
                    <!-- Skeleton loading rows -->
                    <template v-if="loading">
                        <tr v-for="(_, i) in skeletonRows" :key="`skel-${i}`" class="border-b border-outline-variant/50">
                            <td
                                v-for="col in columns"
                                :key="col.key"
                                class="px-4 py-3"
                            >
                                <div
                                    class="h-4 rounded-md animate-pulse bg-surface-container-low/50"
                                    :style="{ width: `${60 + Math.floor((col.key.length * 7 + i * 13) % 40)}%` }"
                                ></div>
                            </td>
                        </tr>
                    </template>

                    <!-- Empty state -->
                    <template v-else-if="rows.length === 0">
                        <tr>
                            <td :colspan="columns.length">
                                <div class="flex flex-col items-center justify-center py-16 gap-3">
                                    <span class="material-symbols-outlined text-[40px] text-on-surface-variant/30">search_off</span>
                                    <p class="text-[14px] font-semibold text-on-surface-variant/60">No records found</p>
                                </div>
                            </td>
                        </tr>
                    </template>

                    <!-- Data rows -->
                    <template v-else>
                        <tr
                            v-for="(row, rowIdx) in rows"
                            :key="rowIdx"
                            class="border-b border-outline-variant/50 last:border-b-0 hover:bg-surface-container/40 cursor-pointer transition-colors"
                            @click="emit('row-click', row)"
                        >
                            <td
                                v-for="col in columns"
                                :key="col.key"
                                class="px-4 py-3 text-[13px] text-on-surface"
                            >
                                <slot :name="`cell-${col.key}`" :row="row" :value="row[col.key]">
                                    {{ row[col.key] }}
                                </slot>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</template>
