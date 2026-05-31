<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import EmptyState from '@/Components/EmptyState.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    enrolments: { type: [Object, Array], default: () => ({ data: [] }) },
    dependants: { type: [Object, Array], default: () => ({ data: [] }) },
    claims:     { type: [Object, Array], default: () => ({ data: [] }) },
    plans:      { type: [Object, Array], default: () => ({ data: [] }) },
    provident:  { type: Array, default: () => [] },
});

const page = usePage();
const canEnrol  = computed(() => (page.props.auth?.permissions ?? []).includes('benefits.enrol'));
const canManage = computed(() => (page.props.auth?.permissions ?? []).includes('benefits.manage'));

// ── Normalised arrays (handle both Resource collections and bare arrays) ──
const enrolmentRows = computed(() => props.enrolments?.data ?? (Array.isArray(props.enrolments) ? props.enrolments : []));
const dependantRows = computed(() => props.dependants?.data ?? (Array.isArray(props.dependants) ? props.dependants : []));
const claimRows     = computed(() => props.claims?.data     ?? (Array.isArray(props.claims)     ? props.claims     : []));
const planRows      = computed(() => props.plans?.data      ?? (Array.isArray(props.plans)      ? props.plans      : []));

// ── Stats derived client-side ──
const stats = computed(() => {
    const enr   = enrolmentRows.value;
    const deps  = dependantRows.value;
    const clms  = claimRows.value;
    const prov  = props.provident ?? [];

    const active   = enr.filter(e => e.status === 'active');
    const pending  = clms.filter(c => c.status === 'submitted' || c.status === 'reviewing');
    const approved = clms.filter(c => c.status === 'approved' || c.status === 'paid');
    const monthly  = active.reduce((s, e) => s + Number(e.monthly_premium ?? 0), 0);
    const claimed  = clms.reduce((s, c) => s + Number(c.amount ?? 0), 0);
    const provided = prov.reduce((s, p) => s + Number(p.total_contributed ?? 0), 0);

    return {
        active:     active.length,
        total:      enr.length,
        dependants: deps.length,
        coveredDeps: deps.filter(d => d.is_covered).length,
        claimsTotal: clms.length,
        claimsPending: pending.length,
        claimsApproved: approved.length,
        monthly,
        claimed,
        provident: provided,
        plansAvailable: planRows.value.length,
    };
});

// ── Composition by plan type ──
const TYPE_META = {
    health_insurance: { label: 'Health',    color: '#1a237e', icon: 'health_and_safety' },
    provident_fund:   { label: 'Provident', color: '#ffd700', icon: 'savings' },
    life_insurance:   { label: 'Life',      color: '#7986cb', icon: 'shield' },
    dental:           { label: 'Dental',    color: '#12d9e3', icon: 'dentistry' },
    vision:           { label: 'Vision',    color: '#d912e3', icon: 'visibility' },
    wellness:         { label: 'Wellness',  color: '#16a34a', icon: 'self_improvement' },
    other:            { label: 'Other',     color: '#64748b', icon: 'category' },
};
const typeMeta = (t) => TYPE_META[t] ?? TYPE_META.other;

const composition = computed(() => {
    const counts = {};
    enrolmentRows.value.forEach(e => {
        const t = e.plan?.type ?? 'other';
        counts[t] = (counts[t] ?? 0) + 1;
    });
    const max = Math.max(1, ...Object.values(counts));
    return Object.entries(counts).map(([type, count]) => ({
        type, count, pct: Math.round((count / max) * 100), ...typeMeta(type),
    }));
});

// ── Claims status breakdown ──
const claimsBreakdown = computed(() => {
    const buckets = [
        { key: 'submitted', label: 'Submitted', color: '#1a237e' },
        { key: 'reviewing', label: 'Reviewing', color: '#d97706' },
        { key: 'approved',  label: 'Approved',  color: '#16a34a' },
        { key: 'paid',      label: 'Paid',      color: '#12d9e3' },
        { key: 'rejected',  label: 'Rejected',  color: '#dc2626' },
    ];
    const total = claimRows.value.length || 1;
    return buckets.map(b => {
        const count = claimRows.value.filter(c => c.status === b.key).length;
        return { ...b, count, pct: Math.round((count / total) * 100) };
    });
});

// ── Status pill tones ──
const statusTone = {
    active:     'bg-emerald-50 text-emerald-700 border-emerald-200',
    suspended:  'bg-amber-50 text-amber-700 border-amber-200',
    terminated: 'bg-rose-50 text-rose-700 border-rose-200',
    submitted:  'bg-blue-50 text-blue-700 border-blue-200',
    reviewing:  'bg-amber-50 text-amber-700 border-amber-200',
    approved:   'bg-emerald-50 text-emerald-700 border-emerald-200',
    rejected:   'bg-rose-50 text-rose-700 border-rose-200',
    paid:       'bg-sky-50 text-sky-700 border-sky-200',
};
const statusDot = {
    active: '#16a34a', suspended: '#d97706', terminated: '#dc2626',
    submitted: '#1a237e', reviewing: '#d97706', approved: '#16a34a',
    rejected: '#dc2626', paid: '#12d9e3',
};

// ── Filters ──
const enrolFilter = ref('all');
const filteredEnrolments = computed(() => {
    if (enrolFilter.value === 'all') return enrolmentRows.value;
    return enrolmentRows.value.filter(e => e.status === enrolFilter.value);
});

// ── Forms ──
const showEnrol = ref(false);
const showDependant = ref(false);
const showClaim = ref(false);
const claimEnrolment = ref(null);

const enrolForm = useForm({
    plan_id: '',
    effective_from: new Date().toISOString().slice(0, 10),
    premium: null,
});
const dependantForm = useForm({
    full_name: '', relationship: 'spouse', date_of_birth: '',
    national_id: '', gender: '', is_covered: true,
});
const claimForm = useForm({
    enrolment_id: '', amount: null, currency: 'GHS',
    claim_date: new Date().toISOString().slice(0, 10), description: '',
});

const submitEnrol = () => enrolForm.post(route('benefits.enrol'), {
    preserveScroll: true,
    onSuccess: () => { showEnrol.value = false; enrolForm.reset(); },
});
const submitDependant = () => dependantForm.post(route('benefits.dependants.store'), {
    preserveScroll: true,
    onSuccess: () => { showDependant.value = false; dependantForm.reset(); },
});
const openClaim = (enrolment) => {
    claimEnrolment.value = enrolment;
    claimForm.enrolment_id = enrolment.id;
    showClaim.value = true;
};
const submitClaim = () => claimForm.post(route('benefits.claims.store'), {
    preserveScroll: true,
    onSuccess: () => { showClaim.value = false; claimEnrolment.value = null; claimForm.reset(); },
});

// ── Helpers ──
const fmtGhs = (n) => `GHS ${Number(n ?? 0).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const fmtDate = (d) => d ? new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';
</script>

<template>
<Head title="My Benefits" />
    <div data-page-root="true">
        <Teleport to="#page-header-mount" defer>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">card_giftcard</span>
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">WELFARE &amp; BENEFITS</p>
                    </div>
                    <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">My Benefits</h1>
                    <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                        Institutional cover — health schemes, Tier-2 provident accruals, life assurance, SSNIT Tier-1.
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button v-if="canEnrol" @click="showEnrol = true" type="button"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px"
                            style="background:linear-gradient(135deg,#0d1452,#1a237e);">
                        <span class="material-symbols-outlined text-[17px]">add_card</span>
                        Enrol in Plan
                    </button>
                </div>
            </div>
        </Teleport>

        <div class="space-y-8">

            <!-- ── Hero banner ── -->
            <div class="relative overflow-hidden rounded-3xl px-8 py-7 text-white animate-reveal-up"
                 style="background:linear-gradient(135deg,#1a237e 0%, #283593 55%, #3949ab 100%);border:1px solid rgba(255,255,255,0.06);">
                <div class="pointer-events-none absolute -right-16 -top-16 h-72 w-72 rounded-full blur-3xl" style="background:radial-gradient(circle,rgba(22,163,74,0.18),transparent 70%)"></div>
                <div class="pointer-events-none absolute -left-8 bottom-0 h-48 w-48 rounded-full blur-2xl" style="background:rgba(255,215,0,0.10)"></div>

                <div class="relative flex flex-wrap items-center justify-between gap-8">
                    <div>
                        <p class="text-[9px] font-black uppercase tracking-[0.25em] mb-2" style="color:rgba(22,163,74,0.85)">Coverage snapshot · live</p>
                        <h2 class="text-3xl font-black leading-tight">
                            <em class="not-italic" style="color:#16a34a">{{ stats.active }}</em> active enrolment<span v-if="stats.active !== 1">s</span> · <em class="not-italic" style="color:#ffd700">{{ fmtGhs(stats.monthly) }}</em>/mo
                        </h2>
                        <p class="mt-2 text-sm font-medium" style="color:rgba(255,255,255,0.5)">
                            <span style="color:#12d9e3">{{ stats.dependants }}</span> dependant<span v-if="stats.dependants !== 1">s</span> registered ·
                            <template v-if="stats.claimsPending">
                                <span style="color:#fbbf24">{{ stats.claimsPending }}</span> claim<span v-if="stats.claimsPending !== 1">s</span> pending ·
                            </template>
                            <span style="color:#d912e3">{{ fmtGhs(stats.claimed) }}</span> claimed lifetime
                        </p>
                    </div>
                    <div class="flex items-center gap-8 flex-shrink-0">
                        <div v-for="kpi in [
                            { label: 'Active',     val: stats.active,                    color: '#16a34a' },
                            { label: 'Monthly',    val: fmtGhs(stats.monthly),           color: '#ffd700', compact: true },
                            { label: 'Provident',  val: fmtGhs(stats.provident),         color: '#7986cb', compact: true },
                        ]" :key="kpi.label" class="text-center">
                            <p :class="kpi.compact ? 'text-xl' : 'text-3xl'" class="font-black leading-none tabular-nums" :style="`color:${kpi.color}`">{{ kpi.val }}</p>
                            <p class="mt-1 text-[9px] font-black uppercase tracking-[0.18em]" style="color:rgba(255,255,255,0.35)">{{ kpi.label }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── KPI tiles ── -->
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div v-for="(card, i) in [
                    { label: 'Active enrolments', val: stats.active,         sub: `${stats.total} lifetime`,                    cls: 'icon-brand',   icon: 'verified_user' },
                    { label: 'Dependants',        val: stats.dependants,     sub: `${stats.coveredDeps} covered`,               cls: 'icon-cyan',    icon: 'family_restroom' },
                    { label: 'Monthly premium',   val: fmtGhs(stats.monthly), sub: 'Across active plans',                       cls: 'icon-gold',    icon: 'payments', large: true },
                    { label: 'Claims pending',    val: stats.claimsPending,  sub: `${stats.claimsApproved} approved overall`,   cls: 'icon-magenta', icon: 'pending_actions' },
                ]" :key="card.label"
                     class="group relative overflow-hidden rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5 transition-all hover:shadow-md hover:-translate-y-0.5"
                     :style="`animation:slideUpFade 0.4s ease both;animation-delay:${i*0.06}s`">
                    <div class="icon-tile" :class="card.cls">
                        <span class="material-symbols-outlined">{{ card.icon }}</span>
                    </div>
                    <p class="mt-3 text-[10px] font-black uppercase tracking-[0.12em] text-on-surface-variant/70">{{ card.label }}</p>
                    <p :class="card.large ? 'text-[20px]' : 'text-[28px]'" class="mt-1 font-black tabular-nums text-primary leading-none">{{ card.val }}</p>
                    <p class="mt-1 text-[10px] font-semibold text-on-surface-variant">{{ card.sub }}</p>
                </div>
            </div>

            <!-- ── Composition + Claims breakdown band ── -->
            <div v-if="enrolmentRows.length || claimRows.length" class="grid grid-cols-1 lg:grid-cols-2 gap-4">

                <!-- Plan composition -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-6">
                    <div class="flex items-center justify-between mb-1">
                        <h3 class="text-[15px] font-black text-primary">Plan composition</h3>
                        <span class="text-[9.5px] font-black uppercase tracking-widest text-on-surface-variant/60">By type</span>
                    </div>
                    <p class="text-[11px] text-on-surface-variant mb-4">How your enrolments split across benefit categories.</p>
                    <div v-if="composition.length" class="space-y-2.5">
                        <div v-for="(seg, i) in composition" :key="seg.type"
                             class="flex items-center gap-3"
                             :style="`animation:slideUpFade 0.35s ease both;animation-delay:${i*0.05}s`">
                            <span class="flex h-7 w-7 items-center justify-center rounded-lg flex-shrink-0" :style="`background:${seg.color}15`">
                                <span class="material-symbols-outlined text-[15px]" :style="`color:${seg.color};font-variation-settings:'FILL' 1`">{{ seg.icon }}</span>
                            </span>
                            <span class="w-24 flex-shrink-0 text-[12px] font-bold text-on-surface">{{ seg.label }}</span>
                            <div class="flex-1 h-5 rounded-lg bg-surface-container-low border border-outline-variant/30 relative overflow-hidden">
                                <div class="absolute inset-y-0 left-0 rounded-lg transition-all duration-700"
                                     :style="`width:${Math.max(4, seg.pct)}%;background:linear-gradient(90deg,${seg.color}cc,${seg.color})`"></div>
                            </div>
                            <span class="w-8 text-right text-[12px] font-black text-primary tabular-nums">{{ seg.count }}</span>
                        </div>
                    </div>
                    <p v-else class="text-center text-[12px] text-on-surface-variant py-8">No enrolment data yet.</p>
                </div>

                <!-- Claims status breakdown -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-6">
                    <div class="flex items-center justify-between mb-1">
                        <h3 class="text-[15px] font-black text-primary">Claims pipeline</h3>
                        <span class="text-[9.5px] font-black uppercase tracking-widest text-on-surface-variant/60">{{ stats.claimsTotal }} total</span>
                    </div>
                    <p class="text-[11px] text-on-surface-variant mb-4">Status distribution across your submitted claims.</p>
                    <div v-if="stats.claimsTotal" class="space-y-2.5">
                        <div v-for="(bucket, i) in claimsBreakdown" :key="bucket.key"
                             class="flex items-center gap-3"
                             :style="`animation:slideUpFade 0.35s ease both;animation-delay:${i*0.05}s`">
                            <span class="w-24 flex-shrink-0 text-[12px] font-bold text-on-surface">{{ bucket.label }}</span>
                            <div class="flex-1 h-5 rounded-lg bg-surface-container-low border border-outline-variant/30 relative overflow-hidden">
                                <div class="absolute inset-y-0 left-0 rounded-lg transition-all duration-700 flex items-center justify-end pr-2"
                                     :style="`width:${Math.max(2, bucket.pct)}%;background:linear-gradient(90deg,${bucket.color}cc,${bucket.color})`">
                                    <span v-if="bucket.pct >= 12" class="text-[10px] font-black text-white tabular-nums">{{ bucket.count }}</span>
                                </div>
                            </div>
                            <span class="w-10 text-right text-[10px] font-bold text-on-surface-variant/70 tabular-nums">{{ bucket.pct }}%</span>
                        </div>
                    </div>
                    <p v-else class="text-center text-[12px] text-on-surface-variant py-8">No claims submitted yet.</p>
                </div>
            </div>

            <!-- ── Provident fund cards ── -->
            <section v-if="provident?.length" class="space-y-3">
                <div class="flex items-end justify-between">
                    <div>
                        <h2 class="text-[11px] font-black uppercase tracking-[0.18em] text-on-surface-variant/70">Provident fund</h2>
                        <p class="text-[12px] text-on-surface-variant mt-0.5">Long-term savings accumulated through payroll deductions.</p>
                    </div>
                    <span class="text-[10px] font-black uppercase tracking-widest" style="color:#ffd700">Tax-advantaged</span>
                </div>
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div v-for="(p, i) in provident" :key="p.plan_id"
                         class="relative overflow-hidden rounded-2xl border border-outline-variant/60 p-5 card-lift"
                         :style="`background:linear-gradient(135deg,rgba(255,215,0,0.04) 0%,rgba(121, 134, 203,0.04) 100%);animation:slideUpFade 0.4s ease both;animation-delay:${i*0.06}s;border-left:3px solid #ffd700;`">
                        <div class="absolute -right-8 -top-8 h-32 w-32 rounded-full blur-2xl" style="background:rgba(255,215,0,0.10)"></div>
                        <div class="relative">
                            <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">Provident</p>
                            <p class="text-[14px] font-black text-primary mt-1">{{ p.plan_name }}</p>
                            <p class="mt-3 text-[26px] font-black tabular-nums" style="color:#0d1452">
                                {{ fmtGhs(p.total_contributed) }}
                            </p>
                            <p class="text-[11px] text-on-surface-variant mt-1">
                                Contributed over <span class="font-bold text-primary">{{ p.months_active }}</span> month<span v-if="p.months_active !== 1">s</span> ·
                                <span class="font-bold" style="color:#1a237e">{{ fmtGhs(p.monthly_premium) }}</span>/mo
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ── Enrolments ── -->
            <section>
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
                    <!-- Filter row -->
                    <div class="flex flex-wrap items-center gap-3 px-6 py-4 border-b border-outline-variant/50 bg-surface-container-low/30">
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-[16px] text-secondary">verified_user</span>
                            <span class="text-[11px] font-black uppercase tracking-widest text-on-surface-variant">My enrolments</span>
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <button v-for="opt in [
                                { id: 'all',        label: 'All',        icon: 'list_alt' },
                                { id: 'active',     label: 'Active',     icon: 'check_circle' },
                                { id: 'suspended',  label: 'Suspended',  icon: 'pause_circle' },
                                { id: 'terminated', label: 'Terminated', icon: 'cancel' },
                            ]" :key="opt.id" @click="enrolFilter = opt.id"
                                    :class="['inline-flex items-center gap-1.5 rounded-xl border px-3 py-1.5 text-[11.5px] font-black uppercase tracking-wide transition-all',
                                              enrolFilter === opt.id
                                                ? 'border-secondary bg-secondary text-white shadow-glow-sm'
                                                : 'border-outline-variant text-on-surface-variant hover:border-secondary/40']">
                                <span class="material-symbols-outlined text-[13px]">{{ opt.icon }}</span>
                                {{ opt.label }}
                            </button>
                        </div>
                    </div>

                    <div v-if="!filteredEnrolments.length" class="px-6 py-16">
                        <EmptyState title="No enrolments yet"
                                    description="Pick a plan to start enjoying coverage — health, life, dental, vision and more."
                                    icon="card_membership">
                            <template v-if="canEnrol" #action>
                                <button @click="showEnrol = true"
                                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2.5 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px"
                                        style="background:linear-gradient(135deg,#1a237e,#3949ab)">
                                    <span class="material-symbols-outlined text-[18px]">add_card</span>
                                    Enrol in plan
                                </button>
                            </template>
                        </EmptyState>
                    </div>

                    <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-4 p-5">
                        <div v-for="(e, i) in filteredEnrolments" :key="e.id"
                             class="group rounded-2xl border bg-surface-container-low/30 p-5 transition-all hover:-translate-y-0.5 hover:shadow-md hover:border-secondary/30 flex flex-col"
                             :style="`animation:slideUpFade 0.4s ease both;animation-delay:${i*0.04}s;border-left:3px solid ${statusDot[e.status] ?? '#64748b'};`">

                            <div class="flex items-start justify-between gap-2 mb-3">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="flex h-6 w-6 items-center justify-center rounded-lg flex-shrink-0" :style="`background:${typeMeta(e.plan?.type).color}15`">
                                            <span class="material-symbols-outlined text-[14px]" :style="`color:${typeMeta(e.plan?.type).color};font-variation-settings:'FILL' 1`">{{ typeMeta(e.plan?.type).icon }}</span>
                                        </span>
                                        <p class="text-[10px] font-black uppercase tracking-widest" :style="`color:${typeMeta(e.plan?.type).color}`">{{ typeMeta(e.plan?.type).label }}</p>
                                    </div>
                                    <h3 class="mt-1.5 text-[14px] font-black text-primary leading-tight group-hover:text-secondary transition-colors">{{ e.plan?.name }}</h3>
                                    <p class="text-[10.5px] font-mono text-on-surface-variant/70 mt-0.5">{{ e.plan?.code }}</p>
                                </div>
                                <span class="inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[10px] font-black uppercase tracking-wider flex-shrink-0"
                                      :class="statusTone[e.status]">
                                    <span class="h-1.5 w-1.5 rounded-full" :style="`background:${statusDot[e.status]}`"></span>
                                    {{ e.status }}
                                </span>
                            </div>

                            <dl class="space-y-1.5 text-[12px] flex-1">
                                <div class="flex justify-between">
                                    <dt class="text-on-surface-variant">Effective</dt>
                                    <dd class="font-bold text-primary">{{ fmtDate(e.effective_from) }} → {{ e.effective_to ? fmtDate(e.effective_to) : 'ongoing' }}</dd>
                                </div>
                                <div class="flex justify-between">
                                    <dt class="text-on-surface-variant">Premium / mo</dt>
                                    <dd class="font-mono font-black" style="color:#ffd700">{{ fmtGhs(e.monthly_premium) }}</dd>
                                </div>
                            </dl>

                            <div class="mt-4 flex flex-wrap gap-2 pt-3 border-t border-outline-variant/40">
                                <a :href="route('benefits.e-card', e.id)"
                                   class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-800 px-3 py-1.5 text-[11px] font-black uppercase tracking-wide hover:bg-emerald-100 transition-colors">
                                    <span class="material-symbols-outlined text-[13px]">download</span>
                                    E-card
                                </a>
                                <button v-if="e.status === 'active'" @click="openClaim(e)" type="button"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-cyan-200 bg-cyan-50 text-cyan-800 px-3 py-1.5 text-[11px] font-black uppercase tracking-wide hover:bg-cyan-100 transition-colors">
                                    <span class="material-symbols-outlined text-[13px]">receipt_long</span>
                                    Submit claim
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ── Dependants ── -->
            <section>
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
                    <div class="flex items-center justify-between px-6 py-4 border-b border-outline-variant/50 bg-surface-container-low/30">
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-[16px] text-secondary">family_restroom</span>
                            <span class="text-[11px] font-black uppercase tracking-widest text-on-surface-variant">My dependants</span>
                            <span class="rounded-full bg-cyan-50 border border-cyan-200 px-2 py-0.5 text-[10px] font-black text-cyan-700">{{ stats.dependants }}</span>
                        </div>
                        <button v-if="canEnrol" @click="showDependant = true" type="button"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-outline-variant px-3 py-1.5 text-[11px] font-black uppercase tracking-wide text-primary hover:bg-surface-container-low transition-colors">
                            <span class="material-symbols-outlined text-[13px]">person_add</span>
                            Add dependant
                        </button>
                    </div>

                    <div v-if="!dependantRows.length" class="px-6 py-12">
                        <EmptyState title="No dependants registered"
                                    description="Add family members to extend coverage where the plan allows."
                                    icon="family_restroom" />
                    </div>

                    <table v-else class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[10px] font-black uppercase text-on-surface-variant tracking-widest bg-surface-container-low/20">
                                <th class="p-3 pl-6">Name</th>
                                <th class="p-3">Relationship</th>
                                <th class="p-3">DOB</th>
                                <th class="p-3">National ID</th>
                                <th class="p-3 text-center">Covered</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="d in dependantRows" :key="d.id" class="border-t border-outline-variant/40 hover:bg-surface-container-low/20 transition-colors">
                                <td class="p-3 pl-6 font-bold text-primary">{{ d.full_name }}</td>
                                <td class="p-3 text-[12px] capitalize">{{ d.relationship }}</td>
                                <td class="p-3 text-[12px]">{{ fmtDate(d.date_of_birth) }}</td>
                                <td class="p-3 text-[12px] font-mono text-on-surface-variant">{{ d.national_id ?? '—' }}</td>
                                <td class="p-3 text-center">
                                    <span v-if="d.is_covered" class="inline-flex items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[10px] font-black uppercase text-emerald-700">
                                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                        Covered
                                    </span>
                                    <span v-else class="text-[10px] font-bold uppercase text-on-surface-variant">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- ── Claims ── -->
            <section>
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
                    <div class="flex items-center justify-between px-6 py-4 border-b border-outline-variant/50 bg-surface-container-low/30">
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-[16px] text-secondary">receipt_long</span>
                            <span class="text-[11px] font-black uppercase tracking-widest text-on-surface-variant">My claims</span>
                            <span class="rounded-full bg-magenta-50 border border-pink-200 px-2 py-0.5 text-[10px] font-black" style="background:rgba(217,18,227,0.08);color:#d912e3;border-color:rgba(217,18,227,0.25)">{{ stats.claimsTotal }}</span>
                        </div>
                    </div>

                    <div v-if="!claimRows.length" class="px-6 py-12">
                        <EmptyState title="No claims yet"
                                    description="Submit a claim against any active enrolment to track reimbursement."
                                    icon="receipt_long" />
                    </div>

                    <table v-else class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[10px] font-black uppercase text-on-surface-variant tracking-widest bg-surface-container-low/20">
                                <th class="p-3 pl-6">Reference</th>
                                <th class="p-3">Plan</th>
                                <th class="p-3 text-right">Amount</th>
                                <th class="p-3">Submitted</th>
                                <th class="p-3 text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in claimRows" :key="c.id" class="border-t border-outline-variant/40 hover:bg-surface-container-low/20 transition-colors">
                                <td class="p-3 pl-6 font-mono text-[11.5px] font-bold text-primary">{{ c.claim_reference }}</td>
                                <td class="p-3 text-[12px]">{{ c.enrolment?.plan_name ?? '—' }}</td>
                                <td class="p-3 text-right font-mono font-black text-primary">{{ c.currency }} {{ Number(c.amount).toFixed(2) }}</td>
                                <td class="p-3 text-[12px]">{{ fmtDate(c.claim_date) }}</td>
                                <td class="p-3 text-center">
                                    <span :class="['inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[10px] font-black uppercase tracking-wider', statusTone[c.status]]">
                                        <span class="h-1.5 w-1.5 rounded-full" :style="`background:${statusDot[c.status]}`"></span>
                                        {{ c.status }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <!-- ── Enrol slide-panel ── -->
        <SlidePanel :open="showEnrol" title="Enrol in plan" size="lg" @close="showEnrol = false">
            <form @submit.prevent="submitEnrol" class="space-y-5 p-6">
                <div class="rounded-xl bg-cyan-50/60 border border-cyan-200/60 dark:bg-cyan-900/15 dark:border-cyan-800/40 px-4 py-3 flex items-start gap-3">
                    <span class="material-symbols-outlined text-cyan-600 text-[20px] mt-0.5">info</span>
                    <p class="text-[12px] text-cyan-900 dark:text-cyan-200 leading-relaxed">
                        Premium amounts default to the plan's standard rate. Override only if your offer letter specifies a different contribution.
                    </p>
                </div>
                <div>
                    <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Plan <span class="text-rose-500">*</span></label>
                    <select aria-label="Plan" v-model="enrolForm.plan_id" required
                            class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20"
                            :class="{ 'border-rose-400': enrolForm.errors.plan_id }">
                        <option value="" disabled>Select a plan…</option>
                        <option v-for="p in planRows" :key="p.id" :value="p.id">{{ p.name }} ({{ typeMeta(p.type).label }})</option>
                    </select>
                    <p v-if="enrolForm.errors.plan_id" class="mt-1 text-[11px] text-rose-500">{{ enrolForm.errors.plan_id }}</p>
                </div>
                <div>
                    <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Effective from <span class="text-rose-500">*</span></label>
                    <input aria-label="Effective from" v-model="enrolForm.effective_from" type="date" required
                           class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20"/>
                </div>
                <div>
                    <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">
                        Premium override <span class="ml-1 font-normal normal-case text-on-surface-variant/60">(optional)</span>
                    </label>
                    <input aria-label="Premium override (optional)" v-model.number="enrolForm.premium" type="number" step="0.01" min="0"
                           class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20"/>
                </div>
            </form>
            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button type="button" @click="showEnrol = false"
                            class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors">
                        Cancel
                    </button>
                    <button @click="submitEnrol" :disabled="enrolForm.processing"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-black text-white disabled:opacity-60 shadow-glow-sm"
                            style="background:linear-gradient(135deg,#1a237e,#3949ab)">
                        <span v-if="enrolForm.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                        <span v-else class="material-symbols-outlined text-[16px]">add_card</span>
                        Enrol
                    </button>
                </div>
            </template>
        </SlidePanel>

        <!-- ── Dependant slide-panel ── -->
        <SlidePanel :open="showDependant" title="Add dependant" size="lg" @close="showDependant = false">
            <form @submit.prevent="submitDependant" class="space-y-5 p-6">
                <div>
                    <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Full name <span class="text-rose-500">*</span></label>
                    <input aria-label="Full name" v-model="dependantForm.full_name" maxlength="120" required
                           class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20"/>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Relationship <span class="text-rose-500">*</span></label>
                        <select aria-label="Relationship" v-model="dependantForm.relationship" required
                                class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20">
                            <option value="spouse">Spouse</option>
                            <option value="child">Child</option>
                            <option value="parent">Parent</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Gender</label>
                        <select aria-label="Gender" v-model="dependantForm.gender"
                                class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20">
                            <option value="">—</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Date of birth <span class="text-rose-500">*</span></label>
                    <input aria-label="Date of birth" v-model="dependantForm.date_of_birth" type="date" required
                           class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20"/>
                </div>
                <div>
                    <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">National ID <span class="ml-1 font-normal normal-case text-on-surface-variant/60">(optional)</span></label>
                    <input aria-label="National ID (optional)" v-model="dependantForm.national_id" maxlength="32"
                           class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20"/>
                </div>
            </form>
            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button type="button" @click="showDependant = false"
                            class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors">
                        Cancel
                    </button>
                    <button @click="submitDependant" :disabled="dependantForm.processing"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-black text-white disabled:opacity-60 shadow-glow-sm"
                            style="background:linear-gradient(135deg,#1a237e,#3949ab)">
                        <span v-if="dependantForm.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                        <span v-else class="material-symbols-outlined text-[16px]">person_add</span>
                        Add dependant
                    </button>
                </div>
            </template>
        </SlidePanel>

        <!-- ── Claim slide-panel ── -->
        <SlidePanel :open="showClaim" :title="`Claim against ${claimEnrolment?.plan?.name ?? ''}`" size="lg" @close="showClaim = false">
            <form @submit.prevent="submitClaim" class="space-y-5 p-6">
                <div class="rounded-xl bg-amber-50/60 border border-amber-200/60 dark:bg-amber-900/15 dark:border-amber-800/40 px-4 py-3 flex items-start gap-3">
                    <span class="material-symbols-outlined text-amber-700 text-[20px] mt-0.5">payments</span>
                    <p class="text-[12px] text-amber-900 dark:text-amber-200 leading-relaxed">
                        Provide a detailed description (minimum 10 characters). Claims are typically reviewed within 5 business days.
                    </p>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Amount (GHS) <span class="text-rose-500">*</span></label>
                        <input aria-label="Amount (GHS)" v-model.number="claimForm.amount" type="number" step="0.01" min="0.01" required
                               class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20"/>
                    </div>
                    <div>
                        <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Claim date <span class="text-rose-500">*</span></label>
                        <input aria-label="Claim date" v-model="claimForm.claim_date" type="date" required
                               class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20"/>
                    </div>
                </div>
                <div>
                    <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Description <span class="text-rose-500">*</span></label>
                    <textarea aria-label="Description" v-model="claimForm.description" required minlength="10" maxlength="1000" rows="5"
                              placeholder="Describe the expense, including provider name and date of service…"
                              class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20 resize-none"></textarea>
                </div>
            </form>
            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button type="button" @click="showClaim = false"
                            class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors">
                        Cancel
                    </button>
                    <button @click="submitClaim" :disabled="claimForm.processing"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-black text-white disabled:opacity-60 shadow-glow-sm"
                            style="background:linear-gradient(135deg,#1a237e,#3949ab)">
                        <span v-if="claimForm.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                        <span v-else class="material-symbols-outlined text-[16px]">receipt_long</span>
                        Submit claim
                    </button>
                </div>
            </template>
        </SlidePanel>

    </div>
</template>
