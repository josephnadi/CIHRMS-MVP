<script setup>
import { computed } from 'vue';

const props = defineProps({
    status: { type: String, required: true },
    type:   {
        type:    String,
        default: 'generic',
        validator: v => ['leave','ticket','payment','complaint','recruitment','employee','generic'].includes(v),
    },
});

const statusMaps = {
    leave: {
        pending:     'amber',
        approved:    'green',
        rejected:    'red',
    },
    ticket: {
        open:        'blue',
        in_progress: 'violet',
        resolved:    'green',
        closed:      'gray',
    },
    payment: {
        pending:     'amber',
        paid:        'green',
        failed:      'red',
        cancelled:   'gray',
    },
    complaint: {
        open:        'red',
        under_review:'amber',
        resolved:    'green',
        closed:      'gray',
    },
    recruitment: {
        applied:     'blue',
        shortlisted: 'violet',
        interviewed: 'amber',
        offered:     'cyan',
        hired:       'green',
        rejected:    'red',
        draft:       'gray',
        open:        'green',
        filled:      'blue',
    },
    employee: {
        active:      'green',
        inactive:    'gray',
        terminated:  'red',
        on_leave:    'amber',
    },
    generic: {
        active:      'green',
        inactive:    'gray',
        pending:     'amber',
        approved:    'green',
        rejected:    'red',
        open:        'blue',
        closed:      'gray',
        draft:       'gray',
        filled:      'blue',
    },
};

const colorClasses = {
    amber:  'bg-amber-100  text-amber-700  dark:bg-amber-900/30  dark:text-amber-400',
    green:  'bg-green-100  text-green-700  dark:bg-green-900/30  dark:text-green-400',
    red:    'bg-red-100    text-red-700    dark:bg-red-900/30    dark:text-red-400',
    blue:   'bg-blue-100   text-blue-700   dark:bg-blue-900/30   dark:text-blue-400',
    violet: 'bg-violet-100 text-violet-700 dark:bg-violet-900/30 dark:text-violet-400',
    gray:   'bg-slate-100  text-slate-600  dark:bg-slate-800/50  dark:text-slate-400',
    cyan:   'bg-cyan-100   text-cyan-700   dark:bg-cyan-900/30   dark:text-cyan-400',
};

const badgeClasses = computed(() => {
    const normalized = props.status?.toLowerCase().replace(/\s+/g, '_') ?? '';
    const map = statusMaps[props.type] ?? statusMaps.generic;
    const color = map[normalized] ?? statusMaps.generic[normalized] ?? 'gray';
    return colorClasses[color] ?? colorClasses.gray;
});

const label = computed(() => {
    return (props.status ?? '')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, c => c.toUpperCase());
});
</script>

<template>
    <span
        :class="[
            'inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase tracking-wider',
            badgeClasses,
        ]"
    >
        <span class="h-1.5 w-1.5 rounded-full bg-current flex-shrink-0"></span>
        {{ label }}
    </span>
</template>
