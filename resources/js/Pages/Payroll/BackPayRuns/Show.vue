<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    run:          { type: Object, required: true },
    lines:        { type: Array, default: () => [] },
    canApprove:   { type: Boolean, default: false },
    activeModule: String,
});

const ghs = (v) => 'GHS ' + (Number(v) || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const badge = {
    draft:    'bg-amber-100 text-amber-800',
    approved: 'bg-blue-100 text-blue-800',
    paid:     'bg-emerald-100 text-emerald-800',
    reversed: 'bg-rose-100 text-rose-700',
};

const approveForm = useForm({});
const payForm = useForm({});
const approve = () => approveForm.post(route('back-pay-runs.approve', props.run.id), { preserveScroll: true });
const pay = () => payForm.post(route('back-pay-runs.pay', props.run.id), { preserveScroll: true });

const expanded = ref(null);
const toggle = (id) => expanded.value = expanded.value === id ? null : id;
</script>

<template>
    <Head :title="`Back-pay ${run.reference}`" />
    <div class="p-6 max-w-5xl mx-auto space-y-6">
        <header class="flex items-start justify-between gap-4">
            <div>
                <Link :href="route('salary-revisions.index')" class="text-xs font-bold text-secondary hover:underline">← Salary revisions</Link>
                <h1 class="text-2xl font-black text-primary mt-1 flex items-center gap-3">
                    {{ run.reference }}
                    <span class="text-[10px] font-black uppercase tracking-widest px-2 py-1 rounded-full" :class="badge[run.status]">{{ run.status }}</span>
                </h1>
                <p class="text-sm text-on-surface-variant mt-1">
                    Arrears from revision
                    <Link v-if="run.revision" :href="route('salary-revisions.back-pay', run.revision.id)" class="font-bold text-blue-600 hover:underline">{{ run.revision.reference }}</Link>
                    ({{ run.revision?.percentage }}%) · effective {{ run.effective_from }} · {{ run.employees_count }} employees
                </p>
            </div>
            <div class="flex flex-col items-end gap-2">
                <PrimaryButton v-if="canApprove" :disabled="approveForm.processing" @click="approve">Approve &amp; post</PrimaryButton>
                <PrimaryButton v-else-if="run.status === 'approved'" :disabled="payForm.processing" @click="pay">Mark paid</PrimaryButton>
                <p v-if="run.status === 'draft' && !canApprove" class="text-[11px] text-on-surface-variant max-w-[12rem] text-right">
                    Approval needs a second officer (dual control) — the creator cannot approve.
                </p>
            </div>
        </header>

        <p v-if="approveForm.errors.approve" class="text-[12px] text-rose-600">{{ approveForm.errors.approve }}</p>
        <p v-if="payForm.errors.pay" class="text-[12px] text-rose-600">{{ payForm.errors.pay }}</p>

        <!-- GL summary -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4">
                <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">Arrears (net)</p>
                <p class="text-lg font-black text-primary mt-1 tabular-nums">{{ ghs(run.arrears_net_total) }}</p>
            </div>
            <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4">
                <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">Back-PAYE</p>
                <p class="text-lg font-black text-primary mt-1 tabular-nums">{{ ghs(run.back_paye_total) }}</p>
            </div>
            <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4">
                <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">SSNIT (ee + er)</p>
                <p class="text-lg font-black text-primary mt-1 tabular-nums">{{ ghs(Number(run.ssnit_employee_total) + Number(run.ssnit_employer_total)) }}</p>
            </div>
            <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4">
                <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">Staff cost (DR)</p>
                <p class="text-lg font-black text-primary mt-1 tabular-nums">{{ ghs(run.gross_total) }}</p>
            </div>
        </div>

        <!-- Lines -->
        <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
            <table v-if="lines.length" class="w-full text-sm">
                <thead class="text-on-surface-variant text-[10px] uppercase bg-surface-container-low/20"><tr>
                    <th class="text-left p-3">Employee</th><th class="text-right p-3">Arrears (net)</th>
                    <th class="text-right p-3">Back-PAYE</th><th class="text-right p-3">Staff cost</th><th class="p-3"></th>
                </tr></thead>
                <tbody class="divide-y divide-outline-variant/30">
                    <template v-for="l in lines" :key="l.employee_id">
                        <tr class="hover:bg-surface-container-low/20 cursor-pointer" @click="toggle(l.employee_id)">
                            <td class="p-3"><span class="font-bold text-primary">{{ l.employee_name ?? '—' }}</span>
                                <span class="text-xs text-on-surface-variant ml-1">{{ l.employee_no }}</span></td>
                            <td class="p-3 text-right font-bold text-primary tabular-nums">{{ ghs(l.arrears_net) }}</td>
                            <td class="p-3 text-right tabular-nums">{{ ghs(l.back_paye) }}</td>
                            <td class="p-3 text-right text-on-surface-variant tabular-nums">{{ ghs(l.gross) }}</td>
                            <td class="p-3 text-right"><span class="text-[12px] font-bold text-blue-600">{{ expanded === l.employee_id ? 'Hide' : 'Months' }}</span></td>
                        </tr>
                        <tr v-if="expanded === l.employee_id" class="bg-surface-container-low/30">
                            <td colspan="5" class="px-5 py-3">
                                <table class="w-full text-[12px]">
                                    <thead class="text-on-surface-variant text-[10px] uppercase"><tr>
                                        <th class="text-left p-1.5">Period</th><th class="text-right p-1.5">Old basic</th>
                                        <th class="text-right p-1.5">New basic</th><th class="text-right p-1.5">Arrears</th><th class="text-right p-1.5">Back-PAYE</th>
                                    </tr></thead>
                                    <tbody>
                                        <tr v-for="(m, i) in l.months" :key="i">
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
                No back-pay lines on this run.
            </p>
        </div>

        <p class="text-[11px] text-on-surface-variant">
            On approval this run posts a catch-up accrual to the ledger — DR staff cost + employer contributions,
            CR net-pay / PAYE / SSNIT / Tier-2 / Tier-3 payable — recognised in the current open period.
        </p>
    </div>
</template>
