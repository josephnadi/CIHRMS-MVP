<script setup>
import { computed } from 'vue';

const props = defineProps({
    routes: { type: Array, default: () => [] },
});

const sorted = computed(() => [...props.routes].sort((a, b) => a.sequence - b.sequence));

const stepCls = (s) => ({
    completed:  'bg-emerald-50 text-emerald-800 border-emerald-300',
    in_progress:'bg-amber-50 text-amber-900 border-amber-400',
    pending:    'bg-surface-container-low text-on-surface-variant border-outline-variant',
    rejected:   'bg-rose-50 text-rose-800 border-rose-300',
    cancelled:  'bg-slate-100 text-slate-500 border-slate-300',
}[s] ?? 'bg-surface-container-low text-on-surface-variant border-outline-variant');
</script>

<template>
    <div class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-4 shadow-card">
        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary mb-3">Routing slip</p>
        <div v-if="!sorted.length" class="text-[12px] font-semibold text-on-surface-variant">
            Not routed yet.
        </div>
        <ol v-else class="space-y-2">
            <li v-for="r in sorted" :key="r.id"
                class="rounded-xl border px-3 py-2 text-[12px] font-semibold flex items-center gap-3"
                :class="stepCls(r.status)">
                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-white border border-current font-black text-[11px]">
                    {{ r.sequence }}
                </span>
                <div class="flex-1 min-w-0">
                    <div class="truncate font-black">{{ r.to_user?.name ?? '—' }}</div>
                    <div class="text-[11px] opacity-75">{{ r.action_label }} · {{ r.status_label }}</div>
                </div>
                <div v-if="r.acted_at" class="text-[10px] opacity-60 whitespace-nowrap">
                    {{ new Date(r.acted_at).toLocaleDateString('en-GB') }}
                </div>
            </li>
        </ol>
    </div>
</template>
