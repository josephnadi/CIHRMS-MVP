<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    revision:     { type: Object, required: true },
    arrears:      { type: Array, default: () => [] },
    activeModule: String,
});

const ghs = (v) => 'GHS ' + (Number(v) || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const totals = computed(() => ({
    net:  props.arrears.reduce((a, e) => a + Number(e.arrears_net), 0),
    paye: props.arrears.reduce((a, e) => a + Number(e.back_paye), 0),
}));
const expanded = ref(null);
const toggle = (id) => expanded.value = expanded.value === id ? null : id;
</script>

<template>
    <Head :title="`Back-pay — ${revision.reference}`" />
    <div class="p-6 max-w-5xl mx-auto space-y-6">
        <header>
            <Link :href="route('salary-revisions.index')" class="text-xs font-bold text-secondary hover:underline">← Salary revisions</Link>
            <h1 class="text-2xl font-black text-primary mt-1">Back-pay · {{ revision.reference }}</h1>
            <p class="text-sm text-on-surface-variant mt-1">
                Arrears from the {{ revision.percentage }}% revision effective {{ revision.effective_from }} —
                the difference between what was paid and the revised rate for each approved month, per employee.
            </p>
        </header>

        <div class="grid grid-cols-2 gap-4 max-w-md">
            <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4">
                <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">Total arrears (net)</p>
                <p class="text-xl font-black text-primary mt-1 tabular-nums">{{ ghs(totals.net) }}</p>
            </div>
            <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4">
                <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">Total back-PAYE</p>
                <p class="text-xl font-black text-primary mt-1 tabular-nums">{{ ghs(totals.paye) }}</p>
            </div>
        </div>

        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
            <table v-if="arrears.length" class="w-full text-sm">
                <thead class="text-on-surface-variant text-[10px] uppercase bg-surface-container-low/20"><tr>
                    <th class="text-left p-3">Employee</th><th class="text-right p-3">Months</th>
                    <th class="text-right p-3">Arrears (net)</th><th class="text-right p-3">Back-PAYE</th><th class="p-3"></th>
                </tr></thead>
                <tbody class="divide-y divide-outline-variant/30">
                    <template v-for="e in arrears" :key="e.employee_id">
                        <tr class="hover:bg-surface-container-low/20 cursor-pointer" @click="toggle(e.employee_id)">
                            <td class="p-3"><span class="font-bold text-primary">{{ e.employee_name ?? '—' }}</span>
                                <span class="text-xs text-on-surface-variant ml-1">{{ e.employee_no }}</span></td>
                            <td class="p-3 text-right">{{ e.months.length }}</td>
                            <td class="p-3 text-right font-bold text-primary tabular-nums">{{ ghs(e.arrears_net) }}</td>
                            <td class="p-3 text-right tabular-nums">{{ ghs(e.back_paye) }}</td>
                            <td class="p-3 text-right"><span class="text-[12px] font-bold text-blue-600">{{ expanded === e.employee_id ? 'Hide' : 'Months' }}</span></td>
                        </tr>
                        <tr v-if="expanded === e.employee_id" class="bg-surface-container-low/30">
                            <td colspan="5" class="px-5 py-3">
                                <table class="w-full text-[12px]">
                                    <thead class="text-on-surface-variant text-[10px] uppercase"><tr>
                                        <th class="text-left p-1.5">Period</th><th class="text-right p-1.5">Old basic</th>
                                        <th class="text-right p-1.5">New basic</th><th class="text-right p-1.5">Arrears</th><th class="text-right p-1.5">Back-PAYE</th>
                                    </tr></thead>
                                    <tbody>
                                        <tr v-for="(m, i) in e.months" :key="i">
                                            <td class="p-1.5">{{ m.period }}</td>
                                            <td class="p-1.5 text-right text-on-surface-variant">{{ ghs(m.old_basic) }}</td>
                                            <td class="p-1.5 text-right">{{ ghs(m.new_basic) }}</td>
                                            <td class="p-1.5 text-right font-bold text-primary">{{ ghs(m.arrears) }}</td>
                                            <td class="p-1.5 text-right">{{ ghs(m.back_paye) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
            <p v-else class="p-8 text-center text-sm text-on-surface-variant">
                No arrears — there are no approved payroll months on or after this revision's effective date.
            </p>
        </div>

        <p class="text-[12px] text-on-surface-variant">
            This is a preview of what's owed. Paying the arrears (a back-pay run that posts the net + back-PAYE to the GL)
            is the next step in this feature.
        </p>
    </div>
</template>
