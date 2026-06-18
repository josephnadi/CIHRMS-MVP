<script setup>
import { computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    year:           { type: Number, required: true },
    years:          { type: Array,  default: () => [] },
    periods:        { type: Object, required: true },
    reconciliation: { type: Array,  default: () => [] },
});

const page = usePage();
const can = (perm) => {
    const perms = page.props?.auth?.permissions ?? [];
    const list = Array.isArray(perms) ? perms : (typeof perms === 'function' ? perms() : []);
    return list.includes(perm);
};

const rows = computed(() => props.periods.data ?? props.periods ?? []);

const gotoYear = (y) => router.get(route('finance.periods.index'), { year: y }, { preserveState: false });

const act = (period, action) => {
    if (! confirm(`${action} ${period.name}?`)) return;
    router.post(route(`finance.periods.${action}`, period.id), {}, { preserveScroll: true });
};

const statusChip = (status) => ({
    open:   'bg-emerald-500/15 text-emerald-300',
    closed: 'bg-amber-500/15 text-amber-300',
    locked: 'bg-slate-500/20 text-slate-300',
}[status.value] ?? 'bg-slate-500/20 text-slate-300');
</script>

<template>
    <Head title="Fiscal Calendar" />

    <div class="p-6 max-w-5xl mx-auto">
        <header class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-black text-primary">Fiscal Calendar</h1>
                <p class="text-on-surface-variant mt-1 text-sm">
                    Close, reopen, and lock fiscal periods. Closed and locked periods reject new postings.
                </p>
            </div>
            <select :value="year" @change="gotoYear($event.target.value)" aria-label="Fiscal year"
                    class="rounded-lg bg-surface-container-lowest border-outline-variant/60 text-sm text-primary">
                <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
            </select>
        </header>

        <section class="mb-6 rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4">
            <h2 class="text-sm font-black uppercase tracking-wide text-secondary/80 mb-3">Subledger ↔ GL reconciliation</h2>
            <div class="grid gap-2 sm:grid-cols-3">
                <div v-for="r in reconciliation" :key="r.gl_code"
                     class="rounded-xl border border-outline-variant/40 p-3"
                     :class="r.in_balance ? '' : 'border-amber-500/50'">
                    <p class="text-[11px] font-bold text-on-surface-variant">{{ r.subledger }} <span class="opacity-60">({{ r.gl_code }})</span></p>
                    <p class="mt-1 text-sm text-primary">Subledger {{ Number(r.subledger_total).toFixed(2) }} · GL {{ Number(r.gl_balance).toFixed(2) }}</p>
                    <p class="mt-0.5 text-[11px]" :class="r.in_balance ? 'text-emerald-300' : 'text-amber-300 font-bold'">
                        {{ r.in_balance ? 'In balance' : 'Variance ' + Number(r.variance).toFixed(2) }}
                    </p>
                </div>
            </div>
        </section>

        <EmptyState v-if="rows.length === 0" title="No periods" description="This year has no fiscal periods." />

        <div v-else class="rounded-2xl border border-outline-variant/60 divide-y divide-outline-variant/40 bg-surface-container-lowest">
            <div v-for="period in rows" :key="period.id" class="p-4 flex items-center justify-between gap-4">
                <div class="min-w-0">
                    <p class="font-semibold text-primary">{{ period.name }}</p>
                    <p class="text-xs text-on-surface-variant">{{ period.starts_on }} → {{ period.ends_on }}</p>
                </div>

                <div class="flex items-center gap-3">
                    <span class="text-xs px-2 py-0.5 rounded-full font-bold" :class="statusChip(period.status)">
                        {{ period.status.label }}
                    </span>

                    <button v-if="period.status.value === 'open' && can('finance.period.close')"
                            class="text-amber-300 text-sm hover:underline" @click="act(period, 'close')">Close</button>
                    <button v-if="period.status.value === 'closed' && can('finance.period.reopen')"
                            class="text-emerald-300 text-sm hover:underline" @click="act(period, 'reopen')">Reopen</button>
                    <button v-if="period.status.value === 'closed' && can('finance.period.lock')"
                            class="text-slate-300 text-sm hover:underline" @click="act(period, 'lock')">Lock</button>
                </div>
            </div>
        </div>
    </div>
</template>
