<script setup>
import { computed } from 'vue';

const props = defineProps({ category: { type: String, required: true } });

const meta = computed(() => ({
    grievance:    { label: 'Grievance',              cls: 'bg-fuchsia-50 text-fuchsia-700 border-fuchsia-200' },
    improvement:  { label: 'Improvement Suggestion', cls: 'bg-blue-50    text-blue-700    border-blue-200'    },
    safety:       { label: 'Workplace Safety',       cls: 'bg-red-50     text-red-700     border-red-200'     },
    other:        { label: 'Other',                  cls: 'bg-slate-50   text-slate-700   border-slate-200'   },
}[props.category] ?? { label: props.category, cls: 'bg-slate-50 text-slate-700 border-slate-200' }));
</script>

<template>
    <span :class="['inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.14em]', meta.cls]">
        {{ meta.label }}
    </span>
</template>
