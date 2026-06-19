<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({ from: String, to: String, report: { type: Object, required: true } });
const from = ref(props.from); const to = ref(props.to);
const apply = () => router.get(route('finance.reports.financial-activities'), { from: from.value, to: to.value }, { preserveState: false });
const money = (n) => Number(n).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
</script>
<template>
    <Head title="Statement of Financial Activities" />
    <div class="p-6 max-w-4xl mx-auto">
        <header class="mb-6 flex flex-wrap items-end justify-between gap-3">
            <div><h1 class="text-2xl font-black text-primary">Statement of Financial Activities</h1>
                <p class="text-on-surface-variant text-sm mt-1">{{ from }} → {{ to }}</p></div>
            <div class="flex items-end gap-2 text-xs font-bold text-on-surface-variant">
                <label>From <input type="date" v-model="from" aria-label="From date" class="mt-1 block rounded-lg bg-surface-container-lowest border-outline-variant/60 text-sm text-primary" /></label>
                <label>To <input type="date" v-model="to" aria-label="To date" class="mt-1 block rounded-lg bg-surface-container-lowest border-outline-variant/60 text-sm text-primary" /></label>
                <button @click="apply" class="rounded-lg bg-secondary/20 px-3 py-2 text-sm text-secondary">Apply</button>
                <a :href="route('finance.reports.financial-activities.csv', { from, to })" class="rounded-lg border border-outline-variant/60 px-3 py-2 text-sm text-primary">CSV</a>
            </div>
        </header>
        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5 space-y-5">
            <section v-for="key in ['income','expenditure']" :key="key">
                <h2 class="text-sm font-black uppercase tracking-wide text-secondary/80 mb-2">{{ key }}</h2>
                <table class="w-full text-sm">
                    <thead class="text-on-surface-variant text-[11px] uppercase"><tr><th class="text-left p-2">Account</th><th class="text-right p-2">Current</th><th class="text-right p-2">Prior</th></tr></thead>
                    <tbody class="divide-y divide-outline-variant/30">
                        <tr v-for="r in report[key].rows" :key="r.code"><td class="p-2 text-primary">{{ r.code }} {{ r.name }}</td><td class="p-2 text-right text-primary">{{ money(r.current) }}</td><td class="p-2 text-right text-on-surface-variant">{{ money(r.prior) }}</td></tr>
                    </tbody>
                    <tfoot class="font-black border-t border-outline-variant/50"><tr><td class="p-2">Total {{ key }}</td><td class="p-2 text-right">{{ money(report[key].total_current) }}</td><td class="p-2 text-right">{{ money(report[key].total_prior) }}</td></tr></tfoot>
                </table>
            </section>
            <div class="flex justify-between border-t border-outline-variant/60 pt-3 font-black text-primary">
                <span>Surplus / (Deficit)</span>
                <span>{{ money(report.surplus_current) }} <span class="text-on-surface-variant font-medium">(prior {{ money(report.surplus_prior) }})</span></span>
            </div>
        </div>
    </div>
</template>
