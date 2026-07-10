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


defineOptions({ layout: AuthenticatedLayout });
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
    <div data-page-root="true">

            <Teleport to="#page-header-mount" defer>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">payments</span>
                            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">STATUTORY PAYROLL</p>
                        </div>
                        <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Payroll Runs</h1>
                        <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                            Act 896 PAYE · SSNIT · Tier-2 · NHIA — dual-control approved, auditor-ready Ghana Audit Service packs.
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <Link :href="route('salary-revisions.index')"
                              class="flex items-center gap-2 rounded-xl border border-outline-variant px-4 py-2.5 text-[13px] font-bold text-primary hover:bg-surface-container-low transition-colors">
                            <span class="material-symbols-outlined text-[17px]">trending_up</span>
                            Salary Revisions
                        </Link>
                        <button @click="showPanel = true"
                                class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px"
                                style="background:linear-gradient(135deg,#0d1452,#1a237e);">
                            <span class="material-symbols-outlined text-[17px]">edit_document</span>
                            Create Run
                        </button>
                    </div>
                </div>
            </Teleport>

            <div class="space-y-6 py-6">

                <div class="bg-surface-container-lowest rounded-2xl shadow-sm border border-outline-variant/60">
                    <div class="px-5 py-4 border-b border-outline-variant/60 flex flex-wrap gap-3 items-center">
                        <select aria-label="Status" v-model="localFilters.status" @change="applyFilters"
                                class="rounded-lg border-outline-variant text-sm">
                            <option value="">All statuses</option>
                            <option value="draft">Draft</option>
                            <option value="calculated">Calculated</option>
                            <option value="approved">Approved</option>
                            <option value="paid">Paid</option>
                            <option value="reversed">Reversed</option>
                        </select>
                        <input aria-label="Year" v-model="localFilters.year" @change="applyFilters" type="number"
                               placeholder="Year" class="rounded-lg border-outline-variant text-sm w-24">
                    </div>

                    <div v-if="runs?.data?.length === 0">
                        <EmptyState title="No payroll runs yet"
                                    description="Create the first run to begin the statutory cycle." />
                    </div>

                    <table v-else class="w-full text-sm">
                        <thead class="bg-surface-container-lowest text-on-surface-variant text-xs uppercase">
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
                        <tbody class="divide-y divide-outline-variant/40">
                            <tr v-for="r in runs.data" :key="r.id" class="hover:bg-surface-container-low">
                                <td class="px-5 py-3 font-mono text-xs">{{ r.reference }}</td>
                                <td class="px-5 py-3">{{ r.period_label }}</td>
                                <td class="px-5 py-3">{{ r.department?.name ?? 'Whole organization' }}</td>
                                <td class="px-5 py-3">
                                    <StatusBadge :status="r.status" :label="r.status_label" />
                                </td>
                                <td class="px-5 py-3 text-right">{{ r.lines_count }} <span class="text-on-surface-variant/60 text-xs" v-if="r.skipped_count">+{{ r.skipped_count }} skipped</span></td>
                                <td class="px-5 py-3 text-right">{{ cedi(r.totals.gross) }}</td>
                                <td class="px-5 py-3 text-right">{{ cedi(r.totals.net) }}</td>
                                <td class="px-5 py-3 text-right">
                                    <Link :href="route('payroll-runs.show', r.id)"
                                          class="text-blue-600 hover:underline">Open</Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="px-5 py-3 border-t border-outline-variant/60">
                        <Pagination :links="runs?.meta?.links ?? []" />
                    </div>
                </div>
            </div>

            <SlidePanel :open="showPanel" @close="showPanel = false" title="Create payroll run">
                <form @submit.prevent="submit" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-on-surface-variant mb-1">Year</label>
                        <input v-model="form.period_year" aria-label="Payroll period year" type="number" class="w-full rounded-lg border-outline-variant" required>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-on-surface-variant mb-1">Month</label>
                        <input aria-label="Month" v-model="form.period_month" type="number" min="1" max="12"
                               class="w-full rounded-lg border-outline-variant" required>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-on-surface-variant mb-1">Reason (optional)</label>
                        <textarea aria-label="Reason (optional)" v-model="form.reason" rows="3"
                                  class="w-full rounded-lg border-outline-variant"></textarea>
                    </div>
                    <PrimaryButton type="submit" :disabled="form.processing">Create draft</PrimaryButton>
                </form>
            </SlidePanel>
    </div>
</template>
