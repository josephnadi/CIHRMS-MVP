<script setup>
import { ref, reactive, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Pagination from '@/Components/Pagination.vue';
import StatCard from '@/Components/StatCard.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SlidePanel from '@/Components/SlidePanel.vue';

const props = defineProps({
    runs:         Object,
    filters:      Object,
    activeModule: String,
});

const localFilters = reactive({
    status: props.filters?.status ?? '',
    year:   props.filters?.year   ?? '',
});

const applyFilters = () => router.get(route('payroll-runs.index'), {
    status: localFilters.status || undefined,
    year:   localFilters.year   || undefined,
}, { preserveState: true, replace: true });

const totals = computed(() => {
    const r = props.runs?.data ?? [];
    return {
        runs:   props.runs?.meta?.total ?? r.length,
        gross:  r.reduce((s, x) => s + (x.totals?.gross ?? 0), 0),
        net:    r.reduce((s, x) => s + (x.totals?.net   ?? 0), 0),
        paye:   r.reduce((s, x) => s + (x.totals?.paye  ?? 0), 0),
    };
});

const cedi = (v) => 'GHS ' + (Number(v) || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

// ── Editorial-Sovereign masthead ──────────────────────────────────
// Volume = year offset from CIHRM-GH platform inception. Issue = day-of-year.
const editionLabel = computed(() => {
    const d   = new Date();
    const day = Math.floor((d - new Date(d.getFullYear(), 0, 0)) / 86_400_000);
    const vol = d.getFullYear() - 2023;
    const roman = (n) => {
        const map = [['M',1000],['CM',900],['D',500],['CD',400],['C',100],['XC',90],['L',50],['XL',40],['X',10],['IX',9],['V',5],['IV',4],['I',1]];
        let s = '';
        for (const [r, v] of map) while (n >= v) { s += r; n -= v; }
        return s;
    };
    return {
        date: d.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }),
        edition: `Vol. ${roman(vol)} · No. ${day}`,
    };
});

const showPanel = ref(false);
const form = useForm({
    period_year:  new Date().getFullYear(),
    period_month: new Date().getMonth() + 1,
    department_id: '',
    reason: '',
});

const submit = () => form.post(route('payroll-runs.store'), {
    preserveScroll: true,
    onSuccess: () => { showPanel.value = false; form.reset('reason'); },
});
</script>

<template>
    <Head title="Payroll Runs" />

    <AuthenticatedLayout :active-module="activeModule">
        <template #header>
            <div class="space-y-8">

                <!-- ─── Masthead strip ────────────────────────────────────── -->
                <div class="es-masthead">
                    <span>CIHRM&nbsp;Ghana &nbsp;·&nbsp; <span class="es-masthead-edition">STATUTORY PAYROLL</span></span>
                    <span class="es-masthead-spacer"></span>
                    <span>{{ editionLabel.date }}</span>
                    <span class="es-masthead-spacer"></span>
                    <span>{{ editionLabel.edition }}</span>
                    <span class="es-masthead-spacer"></span>
                    <span class="es-masthead-live">
                        <span class="es-dot" aria-hidden="true"></span>
                        Live · Treasury desk
                    </span>
                </div>

                <!-- ─── Broadsheet hero ───────────────────────────────────── -->
                <div class="es-broadsheet rounded-none">
                    <!-- LEAD column -->
                    <div class="es-broadsheet-lead">
                        <p class="es-eyebrow mb-6">Phase 1 · PAYE · SSNIT · Tier-2 · NHIA</p>
                        <h1 class="es-display text-[clamp(2.4rem,5.5vw,4.6rem)]">
                            Statutory
                            <span class="es-display-italic block">runs.</span>
                        </h1>
                        <p class="es-display-sub">
                            Calculated under Act 896, dual-control approved, and remitted to SSNIT, NPRA Tier-2 and NHIA —
                            every cycle ships with an auditor-ready Ghana Audit Service pack.
                        </p>

                        <div class="mt-9 flex flex-wrap items-center gap-x-7 gap-y-3">
                            <button @click="showPanel = true" class="es-chip">
                                <span class="material-symbols-outlined text-[15px]">edit_document</span>
                                Create run
                            </button>
                            <span class="text-on-surface-variant/30">·</span>
                            <button @click="showPanel = true" class="es-chip">
                                <span class="material-symbols-outlined text-[15px]">verified</span>
                                Export AG pack
                            </button>
                        </div>
                    </div>

                    <!-- SIDEBAR column: headline gross as drop-cap stat -->
                    <div class="es-broadsheet-sidebar">
                        <div class="es-stat-hero">
                            <p class="es-stat-hero-label">Gross on the table</p>
                            <p class="es-stat-hero-value">{{ cedi(totals.gross) }}</p>
                            <p class="es-stat-hero-caption">
                                Last {{ totals.runs }} run{{ totals.runs === 1 ? '' : 's' }} · cedi-denominated
                            </p>
                            <span class="es-stat-hero-delta">
                                <span class="material-symbols-outlined text-[13px]">shield_lock</span>
                                Dual-control · Act 896 compliant
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <div class="space-y-6 py-6">
            <!-- ─── Supporting metrics strip (broadsheet sub-numbers) ── -->
            <div class="es-stat-strip rounded-none">
                <div class="es-stat-cell">
                    <p class="es-stat-cell-label">Total runs</p>
                    <p class="es-stat-cell-value">{{ (totals.runs ?? 0).toLocaleString() }}</p>
                    <p class="es-stat-cell-caption">Cycles on the ledger</p>
                </div>
                <div class="es-stat-cell">
                    <p class="es-stat-cell-label">Gross · all runs</p>
                    <p class="es-stat-cell-value">{{ cedi(totals.gross) }}</p>
                    <p class="es-stat-cell-caption">Before statutory deductions</p>
                </div>
                <div class="es-stat-cell">
                    <p class="es-stat-cell-label">Net · all runs</p>
                    <p class="es-stat-cell-value">{{ cedi(totals.net) }}</p>
                    <p class="es-stat-cell-caption">Take-home disbursed</p>
                </div>
                <div class="es-stat-cell">
                    <p class="es-stat-cell-label">PAYE remitted</p>
                    <p class="es-stat-cell-value">{{ cedi(totals.paye) }}</p>
                    <p class="es-stat-cell-caption">GRA · Act 896 schedules</p>
                </div>
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-slate-100">
                <div class="px-5 py-4 border-b border-slate-100 flex flex-wrap gap-3 items-center">
                    <select v-model="localFilters.status" @change="applyFilters"
                            class="rounded-lg border-slate-200 text-sm">
                        <option value="">All statuses</option>
                        <option value="draft">Draft</option>
                        <option value="calculated">Calculated</option>
                        <option value="approved">Approved</option>
                        <option value="paid">Paid</option>
                        <option value="reversed">Reversed</option>
                    </select>
                    <input v-model="localFilters.year" @change="applyFilters" type="number"
                           placeholder="Year" class="rounded-lg border-slate-200 text-sm w-24">
                </div>

                <div v-if="runs?.data?.length === 0">
                    <EmptyState title="No payroll runs yet"
                                description="Create the first run to begin the statutory cycle." />
                </div>

                <table v-else class="w-full text-sm">
                    <thead class="bg-slate-50 text-slate-600 text-xs uppercase">
                        <tr>
                            <th class="px-5 py-3 text-left">Reference</th>
                            <th class="px-5 py-3 text-left">Period</th>
                            <th class="px-5 py-3 text-left">Scope</th>
                            <th class="px-5 py-3 text-left">Status</th>
                            <th class="px-5 py-3 text-right">Lines</th>
                            <th class="px-5 py-3 text-right">Gross</th>
                            <th class="px-5 py-3 text-right">Net</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="r in runs.data" :key="r.id" class="hover:bg-slate-50">
                            <td class="px-5 py-3 font-mono text-xs">{{ r.reference }}</td>
                            <td class="px-5 py-3">{{ r.period_label }}</td>
                            <td class="px-5 py-3">{{ r.department?.name ?? 'Whole organization' }}</td>
                            <td class="px-5 py-3">
                                <StatusBadge :status="r.status" :label="r.status_label" />
                            </td>
                            <td class="px-5 py-3 text-right">{{ r.lines_count }} <span class="text-slate-400 text-xs" v-if="r.skipped_count">+{{ r.skipped_count }} skipped</span></td>
                            <td class="px-5 py-3 text-right">{{ cedi(r.totals.gross) }}</td>
                            <td class="px-5 py-3 text-right">{{ cedi(r.totals.net) }}</td>
                            <td class="px-5 py-3 text-right">
                                <Link :href="route('payroll-runs.show', r.id)"
                                      class="text-blue-600 hover:underline">Open</Link>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="px-5 py-3 border-t border-slate-100">
                    <Pagination :links="runs?.meta?.links ?? []" />
                </div>
            </div>
        </div>

        <SlidePanel v-model="showPanel" title="Create payroll run">
            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Year</label>
                    <input v-model="form.period_year" aria-label="Payroll period year" type="number" class="w-full rounded-lg border-slate-200" required>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Month</label>
                    <input v-model="form.period_month" type="number" min="1" max="12"
                           class="w-full rounded-lg border-slate-200" required>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Reason (optional)</label>
                    <textarea v-model="form.reason" rows="3"
                              class="w-full rounded-lg border-slate-200"></textarea>
                </div>
                <PrimaryButton type="submit" :disabled="form.processing">Create draft</PrimaryButton>
            </form>
        </SlidePanel>
    </AuthenticatedLayout>
</template>
