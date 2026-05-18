<script setup>
import { computed } from 'vue';

const props = defineProps({ status: { type: String, required: true } });

const meta = computed(() => ({
    open:      { label: 'Open',      cls: 'bg-cyan-50    text-cyan-700    border-cyan-200'   },
    in_review: { label: 'In Review', cls: 'bg-indigo-50  text-indigo-700  border-indigo-200' },
    closed:    { label: 'Closed',    cls: 'bg-green-50   text-green-700   border-green-200'  },
}[props.status] ?? { label: props.status, cls: 'bg-slate-50 text-slate-700 border-slate-200' }));
</script>

<template>
    <span :class="['inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-[11px] font-bold', meta.cls]">
        <span class="h-1.5 w-1.5 rounded-full bg-current opacity-70"></span>
        {{ meta.label }}
    </span>
</template>
