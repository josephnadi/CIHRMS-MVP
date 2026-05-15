<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';

const props = defineProps({
    payment:      Object,
    activeModule: String,
});

const p = computed(() => props.payment?.data ?? props.payment);

const items = computed(() => p.value?.items ?? []);
const earnings = computed(() => items.value.filter(i => i.type === 'earning'));
const deductions = computed(() => items.value.filter(i => i.type === 'deduction'));

const isStatutory = (label) => /^(SSNIT\s+Tier\s+1|PAYE)/i.test(label ?? '');

const statutoryDeductions = computed(() => deductions.value.filter(d => isStatutory(d.label)));
const voluntaryDeductions = computed(() => deductions.value.filter(d => !isStatutory(d.label)));

const basicItem = computed(() => earnings.value.find(e => /basic/i.test(e.label)));
const basicAmount = computed(() => parseFloat(basicItem.value?.amount ?? 0));

const totalEarnings = computed(() => earnings.value.reduce((s, i) => s + parseFloat(i.amount ?? 0), 0));
const totalStatutory = computed(() => Math.abs(statutoryDeductions.value.reduce((s, i) => s + parseFloat(i.amount ?? 0), 0)));
const totalVoluntary = computed(() => Math.abs(voluntaryDeductions.value.reduce((s, i) => s + parseFloat(i.amount ?? 0), 0)));
const totalDeductions = computed(() => totalStatutory.value + totalVoluntary.value);
const net = computed(() => totalEarnings.value - totalDeductions.value);

// Employer cost: gross + Tier 1 (13%) + Tier 2 (5%) of basic, capped at SSNIT MIE
const SSNIT_MAX_INS = 61000;
const employerCost = computed(() => {
    const ssnitBase = Math.min(basicAmount.value, SSNIT_MAX_INS);
    const tier1Er = ssnitBase * 0.13;
    const tier2Er = ssnitBase * 0.05;
    return {
        gross:   totalEarnings.value,
        tier1Er: Math.round(tier1Er * 100) / 100,
        tier2Er: Math.round(tier2Er * 100) / 100,
        total:   Math.round((totalEarnings.value + tier1Er + tier2Er) * 100) / 100,
    };
});

const markPaid = () => {
    router.patch(route('payments.paid', p.value.id), {}, { preserveScroll: true });
};

const formatCurrency = (amount, currency = 'GHS') => {
    if (amount == null) return `${currency} 0.00`;
    return `${currency} ${Number(amount).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
};

const formatDate = (d) => {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};
</script>

<template>
    <Head :title="`Payment #${p.id}`" />
    <AuthenticatedLayout :activeModule="activeModule">

        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2 text-[12px] font-semibold text-on-surface-variant/70">
                        <Link :href="route('payments.index')" class="hover:text-secondary">Payroll</Link>
                        <span class="material-symbols-outlined text-[14px]">chevron_right</span>
                        <span>Payslip #{{ p.id }}</span>
                    </div>
                    <h2 class="mt-1 text-[1.6rem] font-black tracking-tight text-on-surface leading-tight">{{ p.description }}</h2>
                </div>
                <div class="flex items-center gap-2">
                    <Link
                        :href="route('payments.index')"
                        class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors flex items-center gap-2"
                    >
                        <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                        Back
                    </Link>
                    <button
                        v-if="p.status === 'pending'"
                        @click="markPaid"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2 text-[13px] font-bold text-white shadow-glow-sm hover:-translate-y-px transition-all"
                        style="background:linear-gradient(135deg,#059669,#34d399)"
                    >
                        <span class="material-symbols-outlined text-[18px]">check_circle</span>
                        Mark as Paid
                    </button>
                </div>
            </div>
        </template>

        <div class="grid gap-6 lg:grid-cols-3">

            <!-- Payslip body -->
            <div class="lg:col-span-2 space-y-6">

                <!-- Header card -->
                <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card overflow-hidden">
                    <div class="h-1.5 w-full" style="background:linear-gradient(90deg,#0051d5,#316bf3,#7c5cff)"></div>
                    <div class="p-6">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70 mb-2">Payslip</p>
                                <h3 class="text-[18px] font-bold text-on-surface">{{ p.employee?.name ?? '—' }}</h3>
                                <p class="mt-0.5 text-[12px] font-mono text-on-surface-variant">{{ p.employee?.employee_no }}</p>
                            </div>
                            <StatusBadge :status="p.status" type="payment" />
                        </div>
                    </div>
                </div>

                <!-- Line items -->
                <div v-if="items.length > 0" class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6">
                    <h3 class="text-[12px] font-bold uppercase tracking-wider text-on-surface-variant/70 mb-4">Line Items</h3>

                    <div v-if="earnings.length > 0" class="mb-5">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-green-700 dark:text-green-400 mb-2">Earnings</p>
                        <div class="space-y-1">
                            <div v-for="item in earnings" :key="item.id" class="flex items-center justify-between rounded-lg bg-green-50 dark:bg-green-950/20 px-3 py-2">
                                <span class="text-[13px] text-on-surface">{{ item.label }}</span>
                                <span class="text-[13px] font-mono font-bold text-green-700 dark:text-green-400">{{ formatCurrency(item.amount, p.currency) }}</span>
                            </div>
                        </div>
                        <div class="mt-2 flex items-center justify-between border-t border-green-200 dark:border-green-900/40 pt-2 px-3">
                            <span class="text-[12px] font-bold text-on-surface-variant">Total Earnings</span>
                            <span class="text-[14px] font-mono font-bold text-green-700 dark:text-green-400">{{ formatCurrency(totalEarnings, p.currency) }}</span>
                        </div>
                    </div>

                    <!-- Statutory deductions (PAYE + SSNIT) -->
                    <div v-if="statutoryDeductions.length > 0" class="mb-5">
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-red-700 dark:text-red-400">Statutory Deductions</p>
                            <span class="inline-flex items-center gap-1 text-[9px] font-black uppercase tracking-wider text-red-600 bg-red-100 dark:bg-red-950/40 dark:text-red-300 px-1.5 py-0.5 rounded-md">
                                <span class="material-symbols-outlined text-[11px]">verified</span>
                                GRA / SSNIT
                            </span>
                        </div>
                        <div class="space-y-1">
                            <div v-for="item in statutoryDeductions" :key="item.id" class="flex items-center justify-between rounded-lg bg-red-50 dark:bg-red-950/20 px-3 py-2">
                                <span class="text-[13px] text-on-surface">{{ item.label }}</span>
                                <span class="text-[13px] font-mono font-bold text-red-700 dark:text-red-400">−{{ formatCurrency(Math.abs(item.amount), p.currency) }}</span>
                            </div>
                        </div>
                        <div class="mt-2 flex items-center justify-between border-t border-red-200 dark:border-red-900/40 pt-2 px-3">
                            <span class="text-[12px] font-bold text-on-surface-variant">Subtotal</span>
                            <span class="text-[14px] font-mono font-bold text-red-700 dark:text-red-400">−{{ formatCurrency(totalStatutory, p.currency) }}</span>
                        </div>
                    </div>

                    <!-- Voluntary deductions (Tier 3, loans, etc.) -->
                    <div v-if="voluntaryDeductions.length > 0" class="mb-5">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-amber-700 dark:text-amber-400 mb-2">Voluntary Deductions</p>
                        <div class="space-y-1">
                            <div v-for="item in voluntaryDeductions" :key="item.id" class="flex items-center justify-between rounded-lg bg-amber-50 dark:bg-amber-950/20 px-3 py-2">
                                <span class="text-[13px] text-on-surface">{{ item.label }}</span>
                                <span class="text-[13px] font-mono font-bold text-amber-700 dark:text-amber-400">−{{ formatCurrency(Math.abs(item.amount), p.currency) }}</span>
                            </div>
                        </div>
                        <div class="mt-2 flex items-center justify-between border-t border-amber-200 dark:border-amber-900/40 pt-2 px-3">
                            <span class="text-[12px] font-bold text-on-surface-variant">Subtotal</span>
                            <span class="text-[14px] font-mono font-bold text-amber-700 dark:text-amber-400">−{{ formatCurrency(totalVoluntary, p.currency) }}</span>
                        </div>
                    </div>

                    <!-- Net pay -->
                    <div class="mt-6 rounded-xl p-4" style="background:linear-gradient(135deg,#0051d5,#316bf3);color:#fff">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-[11px] font-bold uppercase tracking-wider opacity-80">Net Pay</p>
                                <p class="mt-1 text-[10px] opacity-70">Earnings − Deductions</p>
                            </div>
                            <p class="text-[22px] font-black font-mono">{{ formatCurrency(net, p.currency) }}</p>
                        </div>
                    </div>
                </div>

                <!-- No line items fallback -->
                <div v-else class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6 text-center">
                    <span class="material-symbols-outlined text-[32px] text-on-surface-variant/30 mb-2">receipt_long</span>
                    <p class="text-[13px] text-on-surface-variant">No detailed line items recorded for this payment.</p>
                    <p class="mt-1 text-[22px] font-black font-mono text-on-surface">{{ formatCurrency(p.amount, p.currency) }}</p>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="space-y-4">
                <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6 space-y-4">
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70 mb-1.5">Status</p>
                        <StatusBadge :status="p.status" type="payment" />
                    </div>
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70 mb-1.5">Amount</p>
                        <p class="text-[16px] font-bold font-mono text-on-surface">{{ formatCurrency(p.amount, p.currency) }}</p>
                    </div>
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70 mb-1.5">Created</p>
                        <p class="text-[13px] text-on-surface">{{ formatDate(p.created_at) }}</p>
                    </div>
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70 mb-1.5">Paid On</p>
                        <p :class="['text-[13px]', p.paid_at ? 'text-on-surface font-semibold' : 'text-on-surface-variant italic']">
                            {{ formatDate(p.paid_at) }}
                        </p>
                    </div>
                    <div v-if="p.processed_by">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70 mb-1.5">Processed By</p>
                        <p class="text-[13px] text-on-surface">{{ p.processed_by.name }}</p>
                    </div>
                </div>

                <!-- Employer cost panel -->
                <div v-if="basicAmount > 0" class="rounded-2xl border border-outline-variant/50 shadow-card p-6"
                     style="background:linear-gradient(180deg,rgba(124,58,237,0.06),transparent)">
                    <div class="flex items-center justify-between mb-3">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70">Employer Cost</p>
                        <span class="material-symbols-outlined text-[16px] text-on-surface-variant/50">business</span>
                    </div>
                    <div class="space-y-1.5 text-[12px]">
                        <div class="flex justify-between">
                            <span class="text-on-surface-variant">Gross paid</span>
                            <span class="font-mono text-on-surface">{{ formatCurrency(employerCost.gross, p.currency) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-on-surface-variant">SSNIT Tier 1 (13%)</span>
                            <span class="font-mono text-on-surface">{{ formatCurrency(employerCost.tier1Er, p.currency) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-on-surface-variant">SSNIT Tier 2 (5%)</span>
                            <span class="font-mono text-on-surface">{{ formatCurrency(employerCost.tier2Er, p.currency) }}</span>
                        </div>
                        <div class="flex justify-between border-t border-outline-variant/40 pt-2 mt-2 font-bold">
                            <span class="text-on-surface">Total cost</span>
                            <span class="font-mono text-secondary">{{ formatCurrency(employerCost.total, p.currency) }}</span>
                        </div>
                    </div>
                    <p class="mt-3 text-[10px] text-on-surface-variant/60 italic">
                        Employer-side SSNIT contributions are paid on top of the employee's net pay.
                    </p>
                </div>

                <!-- Remittance deadlines -->
                <div v-if="totalStatutory > 0" class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-6">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-on-surface-variant/70 mb-3">Remittance Schedule</p>
                    <div class="space-y-2 text-[11px]">
                        <div class="flex items-start gap-2">
                            <span class="material-symbols-outlined text-[16px] text-amber-600 mt-0.5">event_upcoming</span>
                            <div>
                                <p class="font-bold text-on-surface">SSNIT — by 14th</p>
                                <p class="text-on-surface-variant">Tier 1 employee + employer share, plus Tier 2, due to SSNIT/NPRA by the 14th of the following month.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-2">
                            <span class="material-symbols-outlined text-[16px] text-red-600 mt-0.5">event_upcoming</span>
                            <div>
                                <p class="font-bold text-on-surface">PAYE — by 15th</p>
                                <p class="text-on-surface-variant">File monthly PAYE return and remit via GRA taxpayers portal by the 15th of the following month.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </AuthenticatedLayout>
</template>
