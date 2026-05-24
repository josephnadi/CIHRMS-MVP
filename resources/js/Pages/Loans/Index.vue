<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import StatusPill from '@/Components/StatusPill.vue';
import { STATUS_PILL_REGISTRY } from '@/Components/statusPillRegistry.js';
import Pagination from '@/Components/Pagination.vue';
import EmptyState from '@/Components/EmptyState.vue';
import LiveBars from '@/Components/charts/LiveBars.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    loans:                Object,
    products:             Object,
    stats:                Object,
    statusBreakdown:      { type: Object, default: () => ({}) },
    monthlyDisbursements: { type: Array,  default: () => [] },
    filters:              Object,
    activeModule:         String,
});

// ── Helpers ──
const cedi = (v) => 'GHS ' + (Number(v) || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const cediShort = (v) => {
    const n = Number(v) || 0;
    if (n >= 1_000_000) return 'GHS ' + (n / 1_000_000).toFixed(1) + 'M';
    if (n >= 1_000)     return 'GHS ' + (n / 1_000).toFixed(1) + 'k';
    return 'GHS ' + n.toFixed(0);
};

const formatDate = (d) => {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

const initials = (name) => {
    if (!name) return '?';
    const parts = name.trim().split(' ');
    return parts.length >= 2
        ? (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
        : name.slice(0, 2).toUpperCase();
};

const productTypeIcon = (type) => ({
    salary_advance: 'payments',
    personal:       'person',
    mortgage:       'home',
    car:            'directions_car',
    emergency:      'emergency',
    education:      'school',
})[type] ?? 'account_balance';

// Resource collections in Inertia v2 deliver an outer { data: [...], meta, links }
// shape. Drill down so consumers can use a flat array.
const productList = computed(() => props.products?.data ?? props.products ?? []);
const loanRows    = computed(() => props.loans?.data ?? []);

// ── Filters ──
const localFilters = reactive({
    status:     props.filters?.status     ?? '',
    product_id: props.filters?.product_id ?? '',
    q:          props.filters?.q          ?? '',
});

const applyFilters = () => router.get(
    route('loans.index'),
    {
        status:     localFilters.status     || undefined,
        product_id: localFilters.product_id || undefined,
        q:          localFilters.q          || undefined,
    },
    { preserveState: true, replace: true },
);

let searchTimer = null;
watch(() => localFilters.q, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 380);
});

const hasFilters = computed(() => localFilters.status || localFilters.product_id || localFilters.q);
const clearFilters = () => {
    localFilters.status = '';
    localFilters.product_id = '';
    localFilters.q = '';
    applyFilters();
};

// ── Status dot helper (palette-aligned) ──
// Colours/labels live in the shared <StatusPill> registry. We only read the
// dot hex here to drive the card's left-border accent (the pill itself is
// rendered by <StatusPill>).
const statusDot = (s) => (STATUS_PILL_REGISTRY[s] ?? { dot: '#64748b' }).dot;

// ── Editorial-Sovereign masthead ──────────────────────────────────
// Volume = years since CIHRM-GH platform inception (2023). Issue = day-of-year.
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

// ── Composition donut data ──
const totalLoans = computed(() => Object.values(props.statusBreakdown ?? {}).reduce((s, v) => s + Number(v), 0));
const donutSegs  = computed(() => {
    const t = totalLoans.value || 1;
    const seg = (k) => ((props.statusBreakdown?.[k] ?? 0) / t) * 100;
    return {
        pending:  seg('pending_approval'),
        disbursed: seg('disbursed'),
        repaying:  seg('repaying'),
        paid:      seg('paid_off') + seg('fully_repaid'),
        rejected:  seg('rejected'),
    };
});
const activePortfolio = computed(() => donutSegs.value.disbursed + donutSegs.value.repaying);

// ── Apply panel ──
const showApply    = ref(false);

// ── Loan product management (separate slide-panel; gated by loans.product_manage) ──
const _page = usePage();
const canManageProducts = computed(() => (_page.props?.auth?.permissions ?? []).includes('loans.product_manage'));

const showProducts = ref(false);
const editingProduct = ref(null);
const productForm = useForm({
    code: '', name: '', type: 'personal', description: '',
    min_amount: 1000, max_amount: 50_000,
    min_term_months: 3, max_term_months: 36,
    annual_interest_rate: 0.12, amortization_method: 'reducing_balance',
    max_dti_ratio: 0.4, requires_guarantor: false, requires_collateral: false,
    approvals_required: 2, is_active: true,
    effective_from: new Date().toISOString().slice(0, 10), effective_to: null,
});

const openCreateProduct = () => {
    editingProduct.value = null;
    productForm.reset();
    productForm.clearErrors();
};

const openEditProduct = (p) => {
    editingProduct.value = p;
    Object.keys(productForm.data()).forEach(k => {
        if (p[k] !== undefined) productForm[k] = p[k];
    });
    productForm.is_active = p.is_active !== false;
    productForm.clearErrors();
};

const submitProduct = () => {
    if (editingProduct.value) {
        productForm.patch(route('loans.products.update', editingProduct.value.id), {
            preserveScroll: true,
            onSuccess: () => { editingProduct.value = null; productForm.reset(); },
        });
    } else {
        productForm.post(route('loans.products.store'), {
            preserveScroll: true,
            onSuccess: () => { productForm.reset(); },
        });
    }
};

const deleteProduct = (p) => {
    if (! window.confirm(`Delete loan product "${p.name}"?\n\nExisting loans referencing it will block deletion — deactivate it instead if so.`)) return;
    router.delete(route('loans.products.destroy', p.id), { preserveScroll: true });
};
const form = useForm({
    product_id:  '',
    principal:   '',
    term_months: '',
    purpose:     '',
});

onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    if (params.get('new') === '1') {
        showApply.value = true;
        // Strip ?new=1 so refresh + post-submit back() don't re-trigger the panel.
        params.delete('new');
        const qs = params.toString();
        window.history.replaceState(
            {},
            '',
            window.location.pathname + (qs ? `?${qs}` : '') + window.location.hash,
        );
    }
});

const selectedProduct = computed(() => productList.value.find(p => String(p.id) === String(form.product_id)));

const preview    = ref(null);
const previewing = ref(false);

const previewQuote = async () => {
    if (!form.product_id || !form.principal || !form.term_months) {
        preview.value = null;
        return;
    }
    previewing.value = true;
    try {
        const r = await fetch(route('loans.preview'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept':       'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({
                product_id:  form.product_id,
                principal:   form.principal,
                term_months: form.term_months,
            }),
        });
        preview.value = r.ok ? await r.json() : null;
    } finally {
        previewing.value = false;
    }
};

const submitLoan = () => form.post(route('loans.store'), {
    preserveScroll: true,
    onSuccess: () => { showApply.value = false; form.reset(); preview.value = null; },
});

// ── Repayment progress ──
const repayPct = (loan) => {
    if (!loan.total_repayable || loan.total_repayable === 0) return 0;
    const paid = loan.total_repayable - loan.outstanding_balance;
    return Math.min(100, Math.round((paid / loan.total_repayable) * 100));
};
</script>

<template>
    <Head title="Loans & Advances" />
    <div data-page-root="true">
            <Teleport to="#page-header-mount" defer>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">account_balance</span>
                            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">STAFF LOANS &amp; ADVANCES</p>
                        </div>
                        <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Loans &amp; Advances</h1>
                        <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                            Staff lending under dual-control — HR review, Finance countersignature, reducing-balance or flat amortisation.
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button v-if="canManageProducts" @click="showProducts = true"
                                class="flex items-center gap-2 rounded-xl border border-outline-variant/50 bg-surface-container-lowest px-4 py-2.5 text-[13px] font-black text-primary shadow-card transition-all hover:-translate-y-px hover:shadow-card-hover">
                            <span class="material-symbols-outlined text-[17px]">tune</span>
                            Manage Products
                        </button>
                        <button @click="showApply = true"
                                class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px"
                                style="background:linear-gradient(135deg,#0d1452,#1a237e);">
                            <span class="material-symbols-outlined text-[17px]">add</span>
                            Apply for Loan
                        </button>
                    </div>
                </div>
            </Teleport>

            <div class="space-y-8">

                <!-- ── KPI tiles ── -->
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <div v-for="(card, i) in [
                        { label: 'Active loans',       val: stats?.active_count ?? 0,                   sub: 'Repaying / disbursed', cls: 'icon-cyan',    icon: 'account_balance' },
                        { label: 'Pending approval',   val: stats?.pending_approval ?? 0,               sub: 'Awaiting decision',     cls: 'icon-gold',    icon: 'pending_actions' },
                        { label: 'Disbursed (YTD)',    val: cediShort(stats?.disbursed_this_year ?? 0), sub: new Date().getFullYear(), cls: 'icon-brand',   icon: 'payments' },
                        { label: 'Outstanding',        val: cediShort(stats?.total_outstanding ?? 0),   sub: 'Total exposure',         cls: 'icon-magenta', icon: 'trending_up' },
                    ]" :key="card.label"
                         class="group relative overflow-hidden rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5 transition-all hover:shadow-md hover:-translate-y-0.5"
                         :style="`animation:slideUpFade 0.4s ease both;animation-delay:${i*0.06}s`">
                        <div class="icon-tile" :class="card.cls">
                            <span class="material-symbols-outlined">{{ card.icon }}</span>
                        </div>
                        <p class="mt-3 text-[10px] font-black uppercase tracking-[0.12em] text-on-surface-variant/70">{{ card.label }}</p>
                        <p class="mt-1 text-[26px] font-black tabular-nums text-primary leading-none">{{ card.val }}</p>
                        <p class="mt-1 text-[10px] font-semibold text-on-surface-variant">{{ card.sub }}</p>
                    </div>
                </div>

                <!-- ── Visual band: monthly disbursements + composition donut ── -->
                <div class="grid gap-6 lg:grid-cols-3 animate-reveal-up">

                    <!-- Disbursement trend (spans 2/3) -->
                    <div class="lg:col-span-2 rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <h3 class="text-[15px] font-black text-primary">Monthly disbursements · last 12 months</h3>
                                <p class="mt-0.5 text-[11px] text-on-surface-variant">Cumulative principal disbursed. Peak month highlighted in gold.</p>
                            </div>
                            <div class="flex items-center gap-3">
                                <div class="flex items-center gap-1.5"><span class="h-2 w-3 rounded bg-secondary"></span><span class="text-[9.5px] font-bold text-on-surface-variant">Disbursed</span></div>
                                <div class="flex items-center gap-1.5"><span class="h-2 w-3 rounded" style="background:#ffd700"></span><span class="text-[9.5px] font-bold text-on-surface-variant">Peak</span></div>
                            </div>
                        </div>

                        <div v-if="monthlyDisbursements.length" class="mt-2">
                            <LiveBars :data="monthlyDisbursements"
                                      :height="200"
                                      color="#1a237e"
                                      accent-color="#ffd700"
                                      second-color="#12d9e3"
                                      :show-median="true"
                                      :rounded="5"
                                      :format-value="v => cediShort(v)" />
                        </div>
                        <div v-else class="py-16 text-center text-[12px] font-medium text-on-surface-variant italic">No disbursements in the past year.</div>
                    </div>

                    <!-- Portfolio composition donut -->
                    <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-6 flex flex-col">
                        <div class="flex items-center justify-between mb-1">
                            <h3 class="text-[15px] font-black text-primary">Portfolio mix</h3>
                            <span class="text-[9.5px] font-black uppercase tracking-widest text-on-surface-variant/60">All-time</span>
                        </div>
                        <p class="text-[11px] text-on-surface-variant mb-4">{{ totalLoans }} loans total.</p>

                        <div class="flex items-center justify-center relative my-2">
                            <svg viewBox="0 0 100 100" width="180" height="180" class="-rotate-90">
                                <circle cx="50" cy="50" r="42" fill="none" stroke="rgb(var(--ct-surface-low))" stroke-width="10"/>
                                <!-- pending first (gold) -->
                                <circle v-if="donutSegs.pending > 0" cx="50" cy="50" r="42" fill="none" stroke="#ffd700" stroke-width="10"
                                        :stroke-dasharray="`${donutSegs.pending * 2.6389} ${263.89}`" stroke-dashoffset="0"/>
                                <!-- disbursed (brand blue) -->
                                <circle v-if="donutSegs.disbursed > 0" cx="50" cy="50" r="42" fill="none" stroke="#1a237e" stroke-width="10"
                                        :stroke-dasharray="`${donutSegs.disbursed * 2.6389} ${263.89}`"
                                        :stroke-dashoffset="`${-donutSegs.pending * 2.6389}`"/>
                                <!-- repaying (cyan) -->
                                <circle v-if="donutSegs.repaying > 0" cx="50" cy="50" r="42" fill="none" stroke="#12d9e3" stroke-width="10"
                                        :stroke-dasharray="`${donutSegs.repaying * 2.6389} ${263.89}`"
                                        :stroke-dashoffset="`${-(donutSegs.pending + donutSegs.disbursed) * 2.6389}`"/>
                                <!-- paid off (green) -->
                                <circle v-if="donutSegs.paid > 0" cx="50" cy="50" r="42" fill="none" stroke="#059669" stroke-width="10"
                                        :stroke-dasharray="`${donutSegs.paid * 2.6389} ${263.89}`"
                                        :stroke-dashoffset="`${-(donutSegs.pending + donutSegs.disbursed + donutSegs.repaying) * 2.6389}`"/>
                                <!-- rejected (rose) -->
                                <circle v-if="donutSegs.rejected > 0" cx="50" cy="50" r="42" fill="none" stroke="#dc2626" stroke-width="10"
                                        :stroke-dasharray="`${donutSegs.rejected * 2.6389} ${263.89}`"
                                        :stroke-dashoffset="`${-(donutSegs.pending + donutSegs.disbursed + donutSegs.repaying + donutSegs.paid) * 2.6389}`"/>
                            </svg>
                            <div class="absolute inset-0 flex flex-col items-center justify-center">
                                <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">Active</p>
                                <p class="text-3xl font-black tabular-nums text-primary leading-none">{{ Math.round(activePortfolio) }}%</p>
                                <p class="mt-0.5 text-[9.5px] font-bold text-on-surface-variant/70">disbursed + repaying</p>
                            </div>
                        </div>

                        <!-- Legend -->
                        <div class="mt-4 space-y-1.5">
                            <div v-for="row in [
                                { key: 'pending_approval',  label: 'Pending',   color: '#ffd700' },
                                { key: 'disbursed',         label: 'Disbursed', color: '#1a237e' },
                                { key: 'repaying',          label: 'Repaying',  color: '#12d9e3' },
                                { key: 'paid_off',          label: 'Paid off',  color: '#059669' },
                                { key: 'rejected',          label: 'Rejected',  color: '#dc2626' },
                            ]" :key="row.key"
                                 class="flex items-center justify-between text-[11.5px]">
                                <div class="flex items-center gap-2">
                                    <span class="h-2 w-2 rounded-full" :style="`background:${row.color}`"></span>
                                    <span class="font-semibold text-on-surface-variant">{{ row.label }}</span>
                                </div>
                                <span class="font-black tabular-nums text-primary">{{ statusBreakdown[row.key] ?? 0 }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Loan list ── -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">

                    <!-- Filter row -->
                    <div class="flex flex-wrap items-center gap-3 px-6 py-4 border-b border-outline-variant/50 bg-surface-container-low/30">
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-[16px] text-secondary">filter_list</span>
                            <span class="text-[11px] font-black uppercase tracking-widest text-on-surface-variant">Filter</span>
                        </div>
                        <div class="relative flex-1 min-w-[220px] max-w-xs">
                            <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[16px] text-on-surface-variant/50">search</span>
                            <input v-model="localFilters.q" placeholder="Search reference or employee…"
                                   class="w-full rounded-xl border-outline-variant pl-9 text-[12.5px] focus:border-secondary focus:ring-secondary/20"/>
                        </div>
                        <select v-model="localFilters.status" @change="applyFilters"
                                class="rounded-xl border-outline-variant text-[12.5px] font-semibold focus:border-secondary focus:ring-secondary/20">
                            <option value="">All statuses</option>
                            <option value="pending_approval">Pending approval</option>
                            <option value="approved">Approved</option>
                            <option value="disbursed">Disbursed</option>
                            <option value="repaying">Repaying</option>
                            <option value="paid_off">Paid off</option>
                            <option value="rejected">Rejected</option>
                        </select>
                        <select v-model="localFilters.product_id" @change="applyFilters"
                                class="rounded-xl border-outline-variant text-[12.5px] font-semibold focus:border-secondary focus:ring-secondary/20">
                            <option value="">All products</option>
                            <option v-for="p in productList" :key="p.id" :value="p.id">{{ p.name }}</option>
                        </select>
                        <button v-if="hasFilters" @click="clearFilters"
                                class="rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-bold text-on-surface-variant hover:bg-surface-container transition-colors flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-[15px]">close</span>
                            Clear
                        </button>
                    </div>

                    <!-- Empty state -->
                    <div v-if="!loanRows.length" class="px-6 py-16">
                        <EmptyState title="No loan applications found"
                                    description="Submit the first application or adjust your filters above."
                                    icon="account_balance">
                            <template #action>
                                <button @click="showApply = true"
                                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2.5 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px"
                                        style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                                    <span class="material-symbols-outlined text-[18px]">add</span>
                                    Apply for loan
                                </button>
                            </template>
                        </EmptyState>
                    </div>

                    <!-- Cards grid -->
                    <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-2 p-5">
                        <div v-for="(loan, i) in loanRows" :key="loan.id"
                             class="group rounded-2xl border border-outline-variant/60 bg-surface-container-low/30 p-5 flex flex-col gap-4 cursor-pointer transition-all hover:-translate-y-0.5 hover:shadow-md hover:border-secondary/30"
                             :style="`animation:slideUpFade 0.4s ease both;animation-delay:${i*0.04}s;border-left:3px solid ${statusDot(loan.status)};`"
                             @click="router.get(route('loans.show', loan.id))">

                            <!-- Employee + status -->
                            <div class="flex items-center gap-3">
                                <div class="h-9 w-9 rounded-full bg-secondary/10 flex items-center justify-center text-[11.5px] font-black text-secondary flex-shrink-0">
                                    {{ initials(loan.employee?.name) }}
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-[13.5px] font-bold text-primary leading-tight truncate">{{ loan.employee?.name ?? '—' }}</p>
                                    <p class="text-[10.5px] font-mono text-on-surface-variant/70 leading-tight">{{ loan.employee?.employee_no ?? loan.reference ?? '—' }}</p>
                                </div>
                                <StatusPill :status="loan.status" />
                            </div>

                            <!-- Product row -->
                            <div class="flex items-center gap-2">
                                <div class="h-7 w-7 rounded-lg bg-secondary/10 flex items-center justify-center flex-shrink-0">
                                    <span class="material-symbols-outlined text-[16px] text-secondary">
                                        {{ productTypeIcon(loan.product?.data?.type ?? loan.product?.type) }}
                                    </span>
                                </div>
                                <span class="text-[12.5px] font-bold text-primary truncate">
                                    {{ loan.product?.data?.name ?? loan.product?.name ?? '—' }}
                                </span>
                                <span v-if="loan.booked_interest_rate"
                                      class="ml-auto inline-flex items-center rounded-full bg-cyan-50 border border-cyan-200 px-2 py-0.5 text-[10px] font-black text-cyan-700 tracking-wider">
                                    {{ (loan.booked_interest_rate * 100).toFixed(1) }}% p.a.
                                </span>
                            </div>

                            <!-- Amounts -->
                            <div class="grid grid-cols-3 gap-2 rounded-xl bg-surface-container-low/60 border border-outline-variant/30 p-3">
                                <div>
                                    <p class="text-[9.5px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 mb-0.5">Principal</p>
                                    <p class="font-mono text-[13px] font-black text-primary tabular-nums">{{ cediShort(loan.principal) }}</p>
                                </div>
                                <div>
                                    <p class="text-[9.5px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 mb-0.5">Monthly</p>
                                    <p class="font-mono text-[13px] font-black text-primary tabular-nums">{{ cediShort(loan.monthly_installment) }}</p>
                                </div>
                                <div>
                                    <p class="text-[9.5px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 mb-0.5">Term</p>
                                    <p class="text-[13px] font-black text-primary">{{ loan.term_months }}<span class="text-[10.5px] font-medium text-on-surface-variant"> mo</span></p>
                                </div>
                            </div>

                            <!-- Repayment progress -->
                            <div v-if="['disbursed','repaying'].includes(loan.status)" class="space-y-1.5">
                                <div class="flex items-center justify-between text-[11px]">
                                    <span class="font-bold text-on-surface-variant">Repayment progress</span>
                                    <span class="font-black text-primary tabular-nums">{{ repayPct(loan) }}%</span>
                                </div>
                                <div class="h-2 w-full rounded-full bg-surface-container overflow-hidden">
                                    <div class="h-full rounded-full transition-all duration-700"
                                         :style="`width:${repayPct(loan)}%;background:linear-gradient(90deg,#1a237e,#12d9e3);`"></div>
                                </div>
                                <p class="text-[10.5px] text-on-surface-variant">
                                    Outstanding: <span class="font-black text-primary tabular-nums">{{ cedi(loan.outstanding_balance) }}</span>
                                </p>
                            </div>

                            <!-- Footer -->
                            <div class="flex items-center justify-between pt-1 border-t border-outline-variant/40">
                                <span class="text-[10.5px] text-on-surface-variant/70 flex items-center gap-1">
                                    <span class="material-symbols-outlined text-[13px]">calendar_today</span>
                                    {{ formatDate(loan.applied_at) }}
                                </span>
                                <Link :href="route('loans.show', loan.id)"
                                      class="flex items-center gap-1 text-[11.5px] font-black text-secondary group-hover:text-secondary-container transition-colors"
                                      @click.stop>
                                    Open
                                    <span class="material-symbols-outlined text-[15px] transition-transform group-hover:translate-x-0.5">arrow_forward</span>
                                </Link>
                            </div>
                        </div>
                    </div>

                    <!-- Pagination -->
                    <div v-if="loans?.meta?.links?.length > 3 || loans?.links?.length > 3" class="px-6 py-3 border-t border-outline-variant/40 flex items-center justify-between flex-wrap gap-2">
                        <p class="text-[11.5px] text-on-surface-variant">
                            Showing <span class="font-bold text-primary">{{ loans?.meta?.from ?? 0 }}</span>
                            – <span class="font-bold text-primary">{{ loans?.meta?.to ?? 0 }}</span>
                            of <span class="font-bold text-primary">{{ loans?.meta?.total ?? 0 }}</span>
                        </p>
                        <Pagination :links="loans?.meta?.links ?? loans?.links ?? []" />
                    </div>
                </div>
            </div>

            <!-- ── Apply for Loan slide-panel ── -->
            <!-- ── Loan product management ───────────────────────────── -->
            <SlidePanel :open="showProducts" title="Loan products" size="lg"
                        @close="showProducts = false; editingProduct = null; productForm.reset();">
                <div class="p-4 space-y-4">
                    <!-- Existing products list -->
                    <div class="rounded-xl border border-outline-variant/50 overflow-hidden">
                        <table v-if="productList.length" class="w-full text-[12.5px]">
                            <thead class="bg-surface-container-low text-on-surface-variant"><tr class="text-left text-[10px] font-black uppercase tracking-widest">
                                <th class="px-3 py-2">Code</th><th>Name</th><th>Rate</th><th>Range</th><th>Status</th><th></th>
                            </tr></thead>
                            <tbody class="divide-y divide-outline-variant/40">
                                <tr v-for="p in productList" :key="p.id" class="hover:bg-surface-container-low/40 transition-colors">
                                    <td class="px-3 py-2 font-mono">{{ p.code }}</td>
                                    <td>{{ p.name }}</td>
                                    <td>{{ (Number(p.annual_interest_rate) * 100).toFixed(2) }}%</td>
                                    <td class="text-[11px] text-on-surface-variant">{{ cediShort(p.min_amount) }} – {{ cediShort(p.max_amount) }}</td>
                                    <td><span class="text-[10px] font-bold" :class="p.is_active ? 'text-emerald-700' : 'text-on-surface-variant'">{{ p.is_active ? 'ACTIVE' : 'inactive' }}</span></td>
                                    <td class="text-right whitespace-nowrap pr-3">
                                        <button type="button" @click="openEditProduct(p)"
                                                class="inline-flex h-7 w-7 items-center justify-center rounded-lg text-on-surface-variant/70 hover:bg-secondary/10 hover:text-secondary transition-colors"
                                                title="Edit product">
                                            <span class="material-symbols-outlined text-[15px]">edit</span>
                                        </button>
                                        <button type="button" @click="deleteProduct(p)"
                                                class="inline-flex h-7 w-7 items-center justify-center rounded-lg text-on-surface-variant/70 hover:bg-rose-50 hover:text-rose-600 transition-colors"
                                                title="Delete product">
                                            <span class="material-symbols-outlined text-[15px]">delete</span>
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-else class="p-4 text-center text-[12px] text-on-surface-variant">No products defined yet.</p>
                    </div>

                    <!-- Create / edit form -->
                    <form @submit.prevent="submitProduct" class="rounded-xl border border-outline-variant/50 p-4 space-y-3 bg-surface-container-low/30">
                        <div class="flex items-center justify-between">
                            <h3 class="text-[13px] font-bold text-on-surface">{{ editingProduct ? `Edit ${editingProduct.name}` : 'New product' }}</h3>
                            <button v-if="editingProduct" type="button" @click="openCreateProduct"
                                    class="text-[11px] font-bold text-on-surface-variant hover:text-secondary">+ Create new instead</button>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div><label class="text-[10.5px] font-bold text-on-surface-variant uppercase tracking-wider">Code</label><input v-model="productForm.code" aria-label="Product code" maxlength="30" required class="w-full mt-1 rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 font-mono uppercase text-[12px]" /></div>
                            <div><label class="text-[10.5px] font-bold text-on-surface-variant uppercase tracking-wider">Name</label><input v-model="productForm.name" aria-label="Product name" maxlength="120" required class="w-full mt-1 rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[12px]" /></div>
                            <div><label class="text-[10.5px] font-bold text-on-surface-variant uppercase tracking-wider">Type</label><select v-model="productForm.type" aria-label="Product type" required class="w-full mt-1 rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[12px]">
                                <option value="personal">Personal</option><option value="vehicle">Vehicle</option><option value="housing">Housing</option><option value="education">Education</option><option value="emergency">Emergency</option><option value="other">Other</option>
                            </select></div>
                            <div><label class="text-[10.5px] font-bold text-on-surface-variant uppercase tracking-wider">Amortization</label><select v-model="productForm.amortization_method" aria-label="Amortization method" required class="w-full mt-1 rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[12px]">
                                <option value="reducing_balance">Reducing balance</option><option value="flat">Flat</option>
                            </select></div>
                            <div><label class="text-[10.5px] font-bold text-on-surface-variant uppercase tracking-wider">Min amount (GHS)</label><input v-model.number="productForm.min_amount" aria-label="Minimum loan amount (GHS)" type="number" step="0.01" required class="w-full mt-1 rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[12px]" /></div>
                            <div><label class="text-[10.5px] font-bold text-on-surface-variant uppercase tracking-wider">Max amount (GHS)</label><input v-model.number="productForm.max_amount" aria-label="Maximum loan amount (GHS)" type="number" step="0.01" required class="w-full mt-1 rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[12px]" /></div>
                            <div><label class="text-[10.5px] font-bold text-on-surface-variant uppercase tracking-wider">Min term (months)</label><input v-model.number="productForm.min_term_months" aria-label="Minimum term in months" type="number" min="1" max="360" required class="w-full mt-1 rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[12px]" /></div>
                            <div><label class="text-[10.5px] font-bold text-on-surface-variant uppercase tracking-wider">Max term (months)</label><input v-model.number="productForm.max_term_months" aria-label="Maximum term in months" type="number" min="1" max="360" required class="w-full mt-1 rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[12px]" /></div>
                            <div><label class="text-[10.5px] font-bold text-on-surface-variant uppercase tracking-wider">Annual rate (0–1)</label><input v-model.number="productForm.annual_interest_rate" aria-label="Annual interest rate (decimal between 0 and 1)" type="number" step="0.0001" min="0" max="1" required class="w-full mt-1 rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[12px]" /></div>
                            <div><label class="text-[10.5px] font-bold text-on-surface-variant uppercase tracking-wider">Max DTI ratio</label><input v-model.number="productForm.max_dti_ratio" aria-label="Maximum debt-to-income ratio" type="number" step="0.01" min="0" max="1" class="w-full mt-1 rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[12px]" /></div>
                            <div><label class="text-[10.5px] font-bold text-on-surface-variant uppercase tracking-wider">Approvals required</label><input v-model.number="productForm.approvals_required" aria-label="Number of approvals required" type="number" min="1" max="5" required class="w-full mt-1 rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[12px]" /></div>
                            <div><label class="text-[10.5px] font-bold text-on-surface-variant uppercase tracking-wider">Effective from</label><input v-model="productForm.effective_from" aria-label="Effective-from date" type="date" required class="w-full mt-1 rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[12px]" /></div>
                            <div><label class="text-[10.5px] font-bold text-on-surface-variant uppercase tracking-wider">Effective to</label><input v-model="productForm.effective_to" aria-label="Effective-to date" type="date" class="w-full mt-1 rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[12px]" /></div>
                        </div>
                        <div class="flex items-center gap-4">
                            <label class="flex items-center gap-2 text-[12px] font-semibold"><input type="checkbox" v-model="productForm.requires_guarantor" aria-label="Requires guarantor" class="rounded border-outline-variant" /> Requires guarantor</label>
                            <label class="flex items-center gap-2 text-[12px] font-semibold"><input type="checkbox" v-model="productForm.requires_collateral" aria-label="Requires collateral" class="rounded border-outline-variant" /> Requires collateral</label>
                            <label class="flex items-center gap-2 text-[12px] font-semibold"><input type="checkbox" v-model="productForm.is_active" aria-label="Product is active" class="rounded border-outline-variant" /> Active</label>
                        </div>
                        <div><label class="text-[10.5px] font-bold text-on-surface-variant uppercase tracking-wider">Description</label><textarea v-model="productForm.description" aria-label="Product description" rows="2" class="w-full mt-1 rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-[12px]" /></div>
                        <button type="submit" :disabled="productForm.processing"
                                class="w-full rounded-xl bg-gradient-to-br from-primary to-secondary px-4 py-2 text-[13px] font-bold text-white disabled:opacity-60">
                            {{ editingProduct ? 'Update Product' : 'Create Product' }}
                        </button>
                    </form>
                </div>
            </SlidePanel>

            <SlidePanel :open="showApply" title="Apply for a loan" size="lg" @close="showApply = false">
                <form @submit.prevent="submitLoan" class="space-y-5 p-6">

                    <div class="rounded-xl bg-cyan-50/60 border border-cyan-200/60 dark:bg-cyan-900/15 dark:border-cyan-800/40 px-4 py-3 flex items-start gap-3">
                        <span class="material-symbols-outlined text-cyan-600 text-[20px] mt-0.5">info</span>
                        <p class="text-[12px] text-cyan-900 dark:text-cyan-200 leading-relaxed">
                            Applications are reviewed by HR and approved by Finance. Disbursed loans deduct automatically from monthly payroll under Labour Act §70 limits.
                        </p>
                    </div>

                    <!-- Product -->
                    <div>
                        <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">
                            Loan product <span class="text-rose-500">*</span>
                        </label>
                        <select v-model="form.product_id" @change="previewQuote" required
                                class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20"
                                :class="{ 'border-rose-400': form.errors.product_id }">
                            <option value="">Choose a product…</option>
                            <option v-for="p in productList" :key="p.id" :value="p.id">
                                {{ p.name }} — {{ (p.annual_interest_rate * 100).toFixed(2) }}% p.a.
                            </option>
                        </select>
                        <p v-if="form.errors.product_id" class="mt-1 text-[11px] text-rose-500">{{ form.errors.product_id }}</p>
                        <div v-if="selectedProduct" class="mt-2 rounded-xl bg-secondary/8 border border-secondary/20 px-4 py-2.5 text-[12px] text-on-surface-variant">
                            Range: <strong class="text-primary">{{ cedi(selectedProduct.min_amount) }}</strong> – <strong class="text-primary">{{ cedi(selectedProduct.max_amount) }}</strong>
                            · {{ selectedProduct.min_term_months }}–{{ selectedProduct.max_term_months }} months
                            · {{ selectedProduct.amortization_label ?? selectedProduct.amortization_method }}
                        </div>
                    </div>

                    <!-- Principal + Term -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Principal (GHS) <span class="text-rose-500">*</span></label>
                            <input v-model="form.principal" @blur="previewQuote" type="number" min="1" step="0.01" placeholder="5,000.00" required
                                   class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] font-mono tabular-nums focus:border-secondary focus:ring-secondary/20"
                                   :class="{ 'border-rose-400': form.errors.principal }"/>
                            <p v-if="form.errors.principal" class="mt-1 text-[11px] text-rose-500">{{ form.errors.principal }}</p>
                        </div>
                        <div>
                            <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Term (months) <span class="text-rose-500">*</span></label>
                            <input v-model="form.term_months" @blur="previewQuote" type="number" min="1" max="240" placeholder="12" required
                                   class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] font-mono tabular-nums focus:border-secondary focus:ring-secondary/20"
                                   :class="{ 'border-rose-400': form.errors.term_months }"/>
                            <p v-if="form.errors.term_months" class="mt-1 text-[11px] text-rose-500">{{ form.errors.term_months }}</p>
                        </div>
                    </div>

                    <!-- Purpose -->
                    <div>
                        <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Purpose</label>
                        <textarea v-model="form.purpose" rows="3" placeholder="Briefly describe the loan purpose…"
                                  class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20 resize-none"></textarea>
                    </div>

                    <!-- Quote preview -->
                    <div v-if="previewing" class="rounded-xl bg-surface-container-low/60 border border-outline-variant/50 p-4 text-[12.5px] font-medium text-on-surface-variant animate-pulse flex items-center gap-2">
                        <span class="material-symbols-outlined text-secondary animate-spin">progress_activity</span>
                        Calculating quote…
                    </div>

                    <Transition
                        enter-active-class="transition-all duration-300 ease-out"
                        enter-from-class="opacity-0 translate-y-2"
                        enter-to-class="opacity-100 translate-y-0"
                    >
                        <div v-if="!previewing && preview" class="rounded-xl border border-cyan-200/60 bg-gradient-to-br from-cyan-50/60 to-secondary/5 dark:from-cyan-900/15 dark:to-secondary/10 p-4 space-y-3">
                            <div class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-cyan-600 text-[18px]" style="font-variation-settings:'FILL' 1">calculate</span>
                                <p class="text-[10px] font-black uppercase tracking-[0.12em] text-cyan-700 dark:text-cyan-300">Estimated quote</p>
                            </div>
                            <div class="grid grid-cols-2 gap-2.5">
                                <div v-for="q in [
                                    { label: 'Monthly installment', val: cedi(preview.monthly_installment), accent: '#1a237e' },
                                    { label: 'Total interest',       val: cedi(preview.total_interest),     accent: '#d912e3' },
                                    { label: 'Total repayable',      val: cedi(preview.total_repayable),    accent: '#ffd700' },
                                    { label: 'Installments',         val: preview.schedule?.length ?? form.term_months, accent: '#12d9e3' },
                                ]" :key="q.label"
                                     class="rounded-xl bg-surface-container-lowest border border-outline-variant/40 p-3"
                                     :style="`border-top:2px solid ${q.accent};`">
                                    <p class="text-[9.5px] font-black uppercase tracking-wider text-on-surface-variant/70 mb-0.5">{{ q.label }}</p>
                                    <p class="font-mono text-[15px] font-black text-primary tabular-nums">{{ q.val }}</p>
                                </div>
                            </div>
                        </div>
                    </Transition>
                </form>

                <template #footer>
                    <div class="flex items-center justify-end gap-3">
                        <button type="button" @click="showApply = false"
                                class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors">
                            Cancel
                        </button>
                        <button @click="submitLoan" :disabled="form.processing"
                                class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-black text-white disabled:opacity-60 shadow-glow-sm"
                                style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                            <span v-if="form.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                            <span v-else class="material-symbols-outlined text-[16px]">send</span>
                            Submit application
                        </button>
                    </div>
                </template>
            </SlidePanel>

    </div>
</template>
