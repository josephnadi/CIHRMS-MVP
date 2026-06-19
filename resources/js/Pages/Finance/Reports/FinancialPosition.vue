<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({ asOf: String, report: { type: Object, required: true } });
const asOf = ref(props.asOf);
const apply = () => router.get(route('finance.reports.financial-position'), { as_of: asOf.value }, { preserveState: false });
const money = (n) => Number(n).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const sections = [['assets','Assets'],['liabilities','Liabilities'],['equity','Equity / Funds']];
</script>
<template>
    <Head title="Statement of Financial Position" />
    <div class="p-6 max-w-4xl mx-auto">
        <header class="mb-6 flex flex-wrap items-end justify-between gap-3">
            <div><h1 class="text-2xl font-black text-primary">Statement of Financial Position</h1>
                <p class="text-on-surface-variant text-sm mt-1">As of {{ report.as_of }} (prior {{ report.comparative_as_of }})</p></div>
            <div class="flex items-end gap-2 text-xs font-bold text-on-surface-variant">
                <label>As of <input type="date" v-model="asOf" aria-label="As-of date" class="mt-1 block rounded-lg bg-surface-container-lowest border-outline-variant/60 text-sm text-primary" /></label>
                <button @click="apply" class="rounded-lg bg-secondary/20 px-3 py-2 text-sm text-secondary">Apply</button>
            </div>
        </header>
        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5 space-y-5">
            <section v-for="[key,label] in sections" :key="key">
                <h2 class="text-sm font-black uppercase tracking-wide text-secondary/80 mb-2">{{ label }}</h2>
                <table class="w-full text-sm"><tbody class="divide-y divide-outline-variant/30">
                    <tr v-for="r in report[key].rows" :key="r.code"><td class="p-2 text-primary">{{ r.code }} {{ r.name }}</td><td class="p-2 text-right text-primary">{{ money(r.current) }}</td><td class="p-2 text-right text-on-surface-variant">{{ money(r.prior) }}</td></tr>
                    <tr v-if="key === 'equity'"><td class="p-2 text-primary">Surplus / (Deficit) to date</td><td class="p-2 text-right text-primary">{{ money(report.surplus_current) }}</td><td class="p-2 text-right text-on-surface-variant">{{ money(report.surplus_prior) }}</td></tr>
                </tbody>
                <tfoot class="font-black border-t border-outline-variant/50"><tr><td class="p-2">Total {{ label }}</td>
                    <td class="p-2 text-right">{{ money(key === 'equity' ? report.total_funds_current : report[key].total_current) }}</td>
                    <td class="p-2 text-right">{{ money(key === 'equity' ? report.total_funds_prior : report[key].total_prior) }}</td></tr></tfoot></table>
            </section>
            <p :class="report.balanced_current ? 'text-emerald-300' : 'text-amber-300 font-bold'" class="text-sm">
                {{ report.balanced_current ? '✓ Balanced — Assets = Liabilities + Funds' : '⚠ Out of balance — investigate' }}
            </p>
        </div>
    </div>
</template>
