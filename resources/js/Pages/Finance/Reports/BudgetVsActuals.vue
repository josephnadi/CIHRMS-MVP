<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    year:   { type: Number, required: true },
    period: { type: Number, required: true },
    report: { type: Object, required: true },
});

const year = ref(props.year);
const period = ref(props.period);

const apply = () => router.get(route('finance.reports.budget-vs-actuals'),
    { year: year.value, period: period.value }, { preserveState: false });

const money = (n) => Number(n).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const unfavourable = (row) => row.favourable === false;
const typeLabel = (t) => t.charAt(0).toUpperCase() + t.slice(1);
</script>

<template>
    <Head title="Budget vs Actuals" />

    <div class="p-6 max-w-5xl mx-auto">
        <header class="mb-6 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-black text-primary">Budget vs Actuals</h1>
                <p class="text-on-surface-variant text-sm mt-1">Fiscal year {{ year }} · through period {{ report.as_of_period }} ({{ report.as_of }})</p>
            </div>
            <div class="flex items-end gap-2 text-xs font-bold text-on-surface-variant">
                <label>Year
                    <input type="number" v-model.number="year" aria-label="Fiscal year"
                           class="mt-1 block w-24 rounded-lg bg-surface-container-lowest border-outline-variant/60 text-sm text-primary" />
                </label>
                <label>Through period
                    <input type="number" min="1" max="12" v-model.number="period" aria-label="As-of period number"
                           class="mt-1 block w-20 rounded-lg bg-surface-container-lowest border-outline-variant/60 text-sm text-primary" />
                </label>
                <button @click="apply" class="rounded-lg bg-secondary/20 px-3 py-2 text-sm text-secondary">Apply</button>
                <a :href="route('finance.reports.budget-vs-actuals.csv', { year, period })" class="rounded-lg border border-outline-variant/60 px-3 py-2 text-sm text-primary">CSV</a>
                <a :href="route('finance.reports.budget-vs-actuals.pdf', { year, period })" class="rounded-lg border border-outline-variant/60 px-3 py-2 text-sm text-primary">PDF</a>
            </div>
        </header>

        <p v-if="!report.has_budget" class="mb-4 rounded-lg border border-amber-400/40 bg-amber-500/10 px-4 py-3 text-sm text-amber-200">
            No budget exists for {{ year }} — actuals are shown against a zero budget.
        </p>

        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5 space-y-6">
            <section v-for="group in report.groups" :key="group.type">
                <h2 class="text-sm font-black uppercase tracking-wide text-secondary/80 mb-2">{{ typeLabel(group.type) }}</h2>
                <table class="w-full text-sm">
                    <thead class="text-on-surface-variant text-[11px] uppercase">
                        <tr>
                            <th class="text-left p-2">Account</th>
                            <th class="text-right p-2">Annual budget</th>
                            <th class="text-right p-2">YTD budget</th>
                            <th class="text-right p-2">YTD actual</th>
                            <th class="text-right p-2">Variance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/30">
                        <tr v-for="r in group.rows" :key="r.code" :class="unfavourable(r) ? 'bg-rose-500/5' : ''">
                            <td class="p-2 text-primary">{{ r.code }} {{ r.name }}</td>
                            <td class="p-2 text-right text-on-surface-variant">{{ money(r.annual_budget) }}</td>
                            <td class="p-2 text-right text-on-surface-variant">{{ money(r.ytd_budget) }}</td>
                            <td class="p-2 text-right text-primary">{{ money(r.ytd_actual) }}</td>
                            <td class="p-2 text-right font-bold" :class="unfavourable(r) ? 'text-rose-300' : 'text-emerald-300'">{{ money(r.variance) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="font-black border-t border-outline-variant/50">
                        <tr>
                            <td class="p-2">Total {{ typeLabel(group.type) }}</td>
                            <td class="p-2 text-right">{{ money(group.annual_budget) }}</td>
                            <td class="p-2 text-right">{{ money(group.ytd_budget) }}</td>
                            <td class="p-2 text-right">{{ money(group.ytd_actual) }}</td>
                            <td class="p-2 text-right">{{ money(group.variance) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </section>

            <div class="flex justify-between border-t border-outline-variant/60 pt-3 font-black text-primary">
                <span>Grand total variance</span>
                <span>{{ money(report.totals.variance) }}</span>
            </div>
        </div>
    </div>
</template>
