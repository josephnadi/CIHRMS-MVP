<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchInput from '@/Components/SearchInput.vue';
import StatCard from '@/Components/StatCard.vue';
import EmptyState from '@/Components/EmptyState.vue';

const props = defineProps({
    loans:        Object, // paginated: { data: [], links: [], meta: {} }
    products:     Array,  // flat array of LoanProductResource
    stats:        Object, // { active_count, total_outstanding, pending_approval, disbursed_this_year }
    filters:      Object, // { status, product_id, q }
    activeModule: String,
});

// ── Helpers ────────────────────────────────────────────────────────────────────
const cedi = (v) => 'GHS ' + (Number(v) || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const formatDate = (d) => {
    if (!d) return '–';
    return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

const relativeDate = (d) => {
    if (!d) return '–';
    const diff = Math.floor((Date.now() - new Date(d).getTime()) / 86400000);
    if (diff === 0) return 'today';
    if (diff === 1) return '1d ago';
    if (diff < 0)   return `in ${Math.abs(diff)}d`;
    return `${diff}d ago`;
};

const gradients = [
    'linear-gradient(135deg,#205295,#2c74b3)',
    'linear-gradient(135deg,#059669,#34d399)',
    'linear-gradient(135deg,#d97706,#fbbf24)',
    'linear-gradient(135deg,#205295,#7cb6e8)',
    'linear-gradient(135deg,#dc2626,#f87171)',
    'linear-gradient(135deg,#0891b2,#22d3ee)',
];
const avatarGradient = (id) => gradients[(id ?? 0) % gradients.length];
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

const statusColorMap = {
    pending_approval: { bg: 'rgba(217,119,6,0.12)',   text: '#b45309' },
    approved:         { bg: 'rgba(5,150,105,0.12)',   text: '#065f46' },
    disbursed:        { bg: 'rgba(0,81,213,0.12)',    text: '#1e40af' },
    repaying:         { bg: 'rgba(124,92,255,0.12)',  text: '#5b21b6' },
    paid_off:         { bg: 'rgba(5,150,105,0.12)',   text: '#065f46' },
    fully_repaid:     { bg: 'rgba(5,150,105,0.12)',   text: '#065f46' },
    rejected:         { bg: 'rgba(220,38,38,0.12)',   text: '#991b1b' },
};

// ── Filters ────────────────────────────────────────────────────────────────────
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

// ── Apply panel ────────────────────────────────────────────────────────────────
const showApply = ref(false);
const form = useForm({
    product_id:  '',
    principal:   '',
    term_months: '',
    purpose:     '',
});

onMounted(() => {
    if (new URLSearchParams(window.location.search).get('new') === '1') {
        showApply.value = true;
    }
});

const productList = computed(() => props.products ?? []);
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

// ── Repayment progress ─────────────────────────────────────────────────────────
const repayPct = (loan) => {
    if (!loan.total_repayable || loan.total_repayable === 0) return 0;
    const paid = loan.total_repayable - loan.outstanding_balance;
    return Math.min(100, Math.round((paid / loan.total_repayable) * 100));
};
</script>

<template>
    <Head title="Loans & Advances" />
    <AuthenticatedLayout :activeModule="activeModule">

        <!-- ── Header ──────────────────────────────────────────────────────────── -->
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-[1.6rem] font-black tracking-tight text-on-surface leading-tight">Loans &amp; Advances</h2>
                    <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                        Manage staff loan applications, disbursements and repayment schedules.
                        <span class="ml-2 inline-flex items-center rounded-full bg-secondary/10 px-2.5 py-0.5 text-[11px] font-bold text-secondary">
                            {{ loans?.meta?.total ?? 0 }} total
                        </span>
                    </p>
                </div>
                <button
                    @click="showApply = true"
                    class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-bold text-white shadow-glow-sm transition-all hover:-translate-y-px hover:shadow-glow active:scale-[0.97]"
                    style="background:linear-gradient(135deg,#205295,#2c74b3)"
                >
                    <span class="material-symbols-outlined text-[18px]">add</span>
                    Apply for Loan
                </button>
            </div>
        </template>

        <div class="space-y-6">

            <!-- ── Stat cards ──────────────────────────────────────────────────── -->
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard
                    :value="stats?.active_count ?? 0"
                    label="Active Loans"
                    icon="account_balance"
                    color="#205295"
                />
                <StatCard
                    :value="stats?.pending_approval ?? 0"
                    label="Pending Approval"
                    icon="pending_actions"
                    color="#d97706"
                />
                <StatCard
                    :value="cedi(stats?.disbursed_this_year ?? 0)"
                    label="Disbursed This Year"
                    icon="payments"
                    color="#059669"
                />
                <StatCard
                    :value="cedi(stats?.total_outstanding ?? 0)"
                    label="Total Outstanding"
                    icon="trending_up"
                    color="#7c5cfc"
                />
            </div>

            <!-- ── Filter strip ─────────────────────────────────────────────────── -->
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex-1 min-w-[200px] max-w-xs">
                    <SearchInput
                        v-model="localFilters.q"
                        placeholder="Search reference or employee…"
                    />
                </div>

                <select
                    v-model="localFilters.status"
                    @change="applyFilters"
                    class="rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                >
                    <option value="">All Statuses</option>
                    <option value="pending_approval">Pending Approval</option>
                    <option value="approved">Approved</option>
                    <option value="disbursed">Disbursed</option>
                    <option value="repaying">Repaying</option>
                    <option value="paid_off">Paid Off</option>
                    <option value="rejected">Rejected</option>
                </select>

                <select
                    v-model="localFilters.product_id"
                    @change="applyFilters"
                    class="rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                >
                    <option value="">All Products</option>
                    <option v-for="p in productList" :key="p.id" :value="p.id">{{ p.name }}</option>
                </select>

                <button
                    v-if="hasFilters"
                    @click="clearFilters"
                    class="rounded-xl border border-outline-variant/60 px-3 py-2.5 text-[12px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors flex items-center gap-1.5"
                >
                    <span class="material-symbols-outlined text-[16px]">close</span>
                    Clear
                </button>
            </div>

            <!-- ── Loan card grid ───────────────────────────────────────────────── -->
            <div v-if="(loans?.data?.length ?? 0) === 0" class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-12">
                <EmptyState
                    title="No loan applications found"
                    description="Submit the first application or adjust your filters."
                    icon="account_balance"
                >
                    <template #action>
                        <button
                            @click="showApply = true"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2 text-[13px] font-bold text-white"
                            style="background:linear-gradient(135deg,#205295,#2c74b3)"
                        >
                            <span class="material-symbols-outlined text-[18px]">add</span>
                            Apply for Loan
                        </button>
                    </template>
                </EmptyState>
            </div>

            <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div
                    v-for="(loan, i) in loans.data"
                    :key="loan.id"
                    class="card-lift rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card p-5 flex flex-col gap-4 cursor-pointer animate-slide-up-fade"
                    :style="`animation-delay:${i * 0.06}s`"
                    @click="router.get(route('loans.show', loan.id))"
                >
                    <!-- Employee row -->
                    <div class="flex items-center gap-3">
                        <div
                            class="h-10 w-10 flex-shrink-0 rounded-full flex items-center justify-center text-[13px] font-black text-white overflow-hidden"
                            :style="`background:${avatarGradient(loan.employee?.id ?? loan.id)}`"
                        >
                            {{ initials(loan.employee?.name) }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-[14px] font-bold text-on-surface leading-tight truncate">
                                {{ loan.employee?.name ?? '–' }}
                            </p>
                            <p class="text-[11px] font-mono text-on-surface-variant/60 leading-tight">
                                {{ loan.employee?.employee_no ?? '–' }}
                            </p>
                        </div>
                        <!-- Status pill -->
                        <StatusBadge :status="loan.status" :label="loan.status_label" />
                    </div>

                    <!-- Product row -->
                    <div class="flex items-center gap-2">
                        <div class="h-7 w-7 rounded-lg bg-secondary/10 flex items-center justify-center flex-shrink-0">
                            <span class="material-symbols-outlined text-[16px] text-secondary">
                                {{ productTypeIcon(loan.product?.data?.type ?? loan.product?.type) }}
                            </span>
                        </div>
                        <span class="text-[13px] font-semibold text-on-surface">
                            {{ loan.product?.data?.name ?? loan.product?.name ?? '–' }}
                        </span>
                        <span
                            v-if="loan.booked_interest_rate"
                            class="ml-auto inline-flex items-center rounded-full bg-cobalt/10 px-2 py-0.5 text-[10px] font-black text-cobalt tracking-wider"
                            style="background:rgba(0,81,213,0.1);color:#205295"
                        >
                            {{ (loan.booked_interest_rate * 100).toFixed(1) }}% p.a.
                        </span>
                    </div>

                    <!-- Amounts row -->
                    <div class="grid grid-cols-3 gap-2 rounded-xl bg-surface-container-low p-3">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 mb-0.5">Principal</p>
                            <p class="font-mono text-[14px] font-black text-on-surface tabular-nums">{{ cedi(loan.principal) }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 mb-0.5">Monthly</p>
                            <p class="font-mono text-[14px] font-black text-on-surface tabular-nums">{{ cedi(loan.monthly_installment) }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 mb-0.5">Term</p>
                            <p class="text-[14px] font-bold text-on-surface">{{ loan.term_months }}<span class="text-[11px] font-normal text-on-surface-variant"> mo</span></p>
                        </div>
                    </div>

                    <!-- Repayment progress bar (only when active) -->
                    <div v-if="['disbursed','repaying'].includes(loan.status)" class="space-y-1.5">
                        <div class="flex items-center justify-between text-[11px]">
                            <span class="font-semibold text-on-surface-variant">Repayment progress</span>
                            <span class="font-black text-on-surface">{{ repayPct(loan) }}%</span>
                        </div>
                        <div class="h-2 w-full rounded-full bg-surface-container overflow-hidden">
                            <div
                                class="h-full rounded-full transition-all"
                                style="background:linear-gradient(90deg,#205295,#2c74b3)"
                                :style="`width:${repayPct(loan)}%`"
                            ></div>
                        </div>
                        <p class="text-[11px] text-on-surface-variant">
                            Outstanding: <span class="font-bold text-on-surface">{{ cedi(loan.outstanding_balance) }}</span>
                        </p>
                    </div>

                    <!-- Footer -->
                    <div class="flex items-center justify-between pt-1 border-t border-outline-variant/40">
                        <span class="text-[11px] text-on-surface-variant/60">
                            <span class="material-symbols-outlined text-[13px] align-text-bottom">calendar_today</span>
                            {{ formatDate(loan.applied_at) }}
                        </span>
                        <Link
                            :href="route('loans.show', loan.id)"
                            class="flex items-center gap-1 text-[12px] font-bold text-secondary hover:underline"
                            @click.stop
                        >
                            Open
                            <span class="material-symbols-outlined text-[15px]">arrow_forward</span>
                        </Link>
                    </div>
                </div>
            </div>

            <!-- Pagination -->
            <div v-if="loans?.links?.length > 3" class="flex items-center justify-between">
                <p class="text-[12px] text-on-surface-variant">
                    Showing
                    <span class="font-semibold text-on-surface">{{ loans.meta?.from }}</span>
                    –
                    <span class="font-semibold text-on-surface">{{ loans.meta?.to }}</span>
                    of
                    <span class="font-semibold text-on-surface">{{ loans.meta?.total }}</span>
                </p>
                <Pagination :links="loans.links" />
            </div>
        </div>

        <!-- ── Apply for Loan SlidePanel ─────────────────────────────────────── -->
        <SlidePanel
            :open="showApply"
            title="Apply for a Loan"
            size="lg"
            @close="showApply = false"
        >
            <form @submit.prevent="submitLoan" class="space-y-5 p-6">

                <!-- Product -->
                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">
                        Loan Product <span class="text-red-500">*</span>
                    </label>
                    <select
                        v-model="form.product_id"
                        @change="previewQuote"
                        required
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                        :class="{ 'border-red-400': form.errors.product_id }"
                    >
                        <option value="">Choose a product…</option>
                        <option v-for="p in productList" :key="p.id" :value="p.id">
                            {{ p.name }} — {{ (p.annual_interest_rate * 100).toFixed(2) }}% p.a.
                        </option>
                    </select>
                    <p v-if="form.errors.product_id" class="mt-1 text-[11px] text-red-500">{{ form.errors.product_id }}</p>
                    <div v-if="selectedProduct" class="mt-2 rounded-xl bg-secondary/5 border border-secondary/15 px-4 py-2.5 text-[12px] text-on-surface-variant">
                        Range: <strong>{{ cedi(selectedProduct.min_amount) }}</strong> – <strong>{{ cedi(selectedProduct.max_amount) }}</strong>
                        &nbsp;·&nbsp; {{ selectedProduct.min_term_months }}–{{ selectedProduct.max_term_months }} months
                        &nbsp;·&nbsp; {{ selectedProduct.amortization_label ?? selectedProduct.amortization_method }}
                    </div>
                </div>

                <!-- Principal + Term -->
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Principal (GHS) <span class="text-red-500">*</span></label>
                        <input
                            v-model="form.principal"
                            @blur="previewQuote"
                            type="number"
                            min="1"
                            step="0.01"
                            placeholder="5000.00"
                            required
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                            :class="{ 'border-red-400': form.errors.principal }"
                        />
                        <p v-if="form.errors.principal" class="mt-1 text-[11px] text-red-500">{{ form.errors.principal }}</p>
                    </div>
                    <div>
                        <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Term (months) <span class="text-red-500">*</span></label>
                        <input
                            v-model="form.term_months"
                            @blur="previewQuote"
                            type="number"
                            min="1"
                            max="240"
                            placeholder="12"
                            required
                            class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all"
                            :class="{ 'border-red-400': form.errors.term_months }"
                        />
                        <p v-if="form.errors.term_months" class="mt-1 text-[11px] text-red-500">{{ form.errors.term_months }}</p>
                    </div>
                </div>

                <!-- Purpose -->
                <div>
                    <label class="text-[12px] font-semibold text-on-surface-variant mb-1.5 block">Purpose</label>
                    <textarea
                        v-model="form.purpose"
                        rows="3"
                        placeholder="Briefly describe the loan purpose…"
                        class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all resize-none"
                    ></textarea>
                </div>

                <!-- Quote preview -->
                <div v-if="previewing" class="rounded-xl bg-surface-container-low border border-outline-variant/50 p-4 text-[13px] text-on-surface-variant animate-pulse">
                    Calculating quote…
                </div>
                <div v-else-if="preview" class="rounded-xl bg-secondary/5 border border-secondary/15 p-4 space-y-3">
                    <p class="text-[10px] font-black uppercase tracking-[0.1em] text-secondary">Estimated Quote</p>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="rounded-xl bg-surface-container-lowest p-3 text-center">
                            <p class="text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/60 mb-1">Monthly Installment</p>
                            <p class="font-mono text-[16px] font-black text-on-surface">{{ cedi(preview.monthly_installment) }}</p>
                        </div>
                        <div class="rounded-xl bg-surface-container-lowest p-3 text-center">
                            <p class="text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/60 mb-1">Total Interest</p>
                            <p class="font-mono text-[16px] font-black text-on-surface">{{ cedi(preview.total_interest) }}</p>
                        </div>
                        <div class="rounded-xl bg-surface-container-lowest p-3 text-center">
                            <p class="text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/60 mb-1">Total Repayable</p>
                            <p class="font-mono text-[16px] font-black text-on-surface">{{ cedi(preview.total_repayable) }}</p>
                        </div>
                        <div class="rounded-xl bg-surface-container-lowest p-3 text-center">
                            <p class="text-[10px] font-bold uppercase tracking-wider text-on-surface-variant/60 mb-1">Installments</p>
                            <p class="text-[16px] font-black text-on-surface">{{ preview.schedule?.length ?? form.term_months }}</p>
                        </div>
                    </div>
                </div>
            </form>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button
                        type="button"
                        @click="showApply = false"
                        class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors"
                    >
                        Cancel
                    </button>
                    <button
                        @click="submitLoan"
                        :disabled="form.processing"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-bold text-white disabled:opacity-60"
                        style="background:linear-gradient(135deg,#205295,#2c74b3)"
                    >
                        <span v-if="form.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                        Submit Application
                    </button>
                </div>
            </template>
        </SlidePanel>

    </AuthenticatedLayout>
</template>
