<script setup>
import { computed } from 'vue';

const props = defineProps({
    filters: {
        type: Array,
        default: () => [],
        // Each item: { key: String, label: String, options: [{ value, label }], type?: 'select'|'date' }
    },
    modelValue: {
        type: Object,
        default: () => ({}),
    },
});

const emit = defineEmits(['update:modelValue', 'change']);

// Count how many filters are currently active
const activeCount = computed(() =>
    props.filters.filter(f => {
        const v = props.modelValue[f.key];
        return v !== undefined && v !== null && v !== '';
    }).length
);

const hasActiveFilters = computed(() => activeCount.value > 0);

function onFilterChange(key, value) {
    const updated = { ...props.modelValue, [key]: value };
    emit('update:modelValue', updated);
    emit('change', updated);
}

function clearFilter(key) {
    const updated = { ...props.modelValue, [key]: '' };
    emit('update:modelValue', updated);
    emit('change', updated);
}

function clearAll() {
    const cleared = {};
    props.filters.forEach(f => { cleared[f.key] = ''; });
    emit('update:modelValue', cleared);
    emit('change', cleared);
}

function isActive(key) {
    const v = props.modelValue[key];
    return v !== undefined && v !== null && v !== '';
}

function getLabelForValue(filter, value) {
    if (!value) return '';
    const opt = (filter.options || []).find(o => String(o.value) === String(value));
    return opt ? opt.label : value;
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <!-- "Filters" label with active badge -->
        <div class="flex items-center gap-1.5 shrink-0">
            <span class="material-symbols-outlined text-[16px] text-on-surface-variant/60">filter_list</span>
            <span class="text-[12px] font-semibold text-on-surface-variant/70 tracking-wide uppercase">Filters</span>
            <span
                v-if="hasActiveFilters"
                class="inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full bg-secondary text-on-secondary text-[10px] font-black"
            >{{ activeCount }}</span>
        </div>

        <div class="w-px h-4 bg-outline-variant/50 shrink-0"></div>

        <!-- Filter selects -->
        <template v-for="filter in filters" :key="filter.key">
            <div class="relative">
                <select
                    :value="modelValue[filter.key] ?? ''"
                    @change="onFilterChange(filter.key, ($event.target as HTMLSelectElement).value)"
                    class="appearance-none rounded-full border border-outline-variant bg-surface-container-low px-3 py-1.5 pr-7 text-[12px] font-semibold text-on-surface-variant cursor-pointer transition-colors hover:border-secondary/40 hover:bg-surface-container focus:outline-none focus:ring-2 focus:ring-secondary/30 focus:border-secondary dark:bg-surface-container-low dark:border-outline-variant dark:text-on-surface-variant"
                >
                    <option value="">{{ filter.label }}</option>
                    <option
                        v-for="opt in filter.options"
                        :key="opt.value"
                        :value="opt.value"
                    >{{ opt.label }}</option>
                </select>
                <!-- Chevron icon -->
                <span class="material-symbols-outlined pointer-events-none absolute right-1.5 top-1/2 -translate-y-1/2 text-[14px] text-on-surface-variant/50">
                    expand_more
                </span>
            </div>
        </template>

        <!-- Active filter chips -->
        <div class="flex flex-wrap items-center gap-1.5">
            <template v-for="filter in filters" :key="'chip-' + filter.key">
                <span
                    v-if="isActive(filter.key)"
                    class="inline-flex items-center gap-1 bg-secondary/10 text-secondary border border-secondary/20 rounded-full px-3 py-1 text-[11px] font-bold transition-all"
                >
                    <span class="opacity-70 font-medium">{{ filter.label }}:</span>
                    <span>{{ getLabelForValue(filter, modelValue[filter.key]) }}</span>
                    <button
                        type="button"
                        @click="clearFilter(filter.key)"
                        class="ml-0.5 flex items-center justify-center w-3.5 h-3.5 rounded-full hover:bg-secondary/20 transition-colors"
                        :aria-label="'Clear ' + filter.label"
                    >
                        <span class="material-symbols-outlined text-[11px] leading-none">close</span>
                    </button>
                </span>
            </template>
        </div>

        <!-- Clear all -->
        <button
            v-if="hasActiveFilters"
            type="button"
            @click="clearAll"
            class="ml-1 text-[12px] text-on-surface-variant/60 hover:text-secondary transition-colors font-medium underline-offset-2 hover:underline"
        >
            Clear all
        </button>
    </div>
</template>
