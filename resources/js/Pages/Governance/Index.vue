<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';

const props = defineProps({
    policies:             Object,
    pending_ack_ids:      Array,
    categoryDistribution: { type: Object, default: () => ({}) },
    stats:                { type: Object, default: () => ({}) },
    cert_stats:           { type: Object, default: () => ({}) },
});

// ── Categories with palette-keyed icons + tones ──
const CATEGORIES = {
    hr:         { label: 'HR',         icon: 'people',           tile: 'icon-magenta', accent: '#d912e3' },
    finance:    { label: 'Finance',    icon: 'account_balance',  tile: 'icon-brand',   accent: '#1a237e' },
    it:         { label: 'IT',         icon: 'computer',         tile: 'icon-cyan',    accent: '#12d9e3' },
    compliance: { label: 'Compliance', icon: 'gavel',            tile: 'icon-gold',    accent: '#ffd700' },
    safety:     { label: 'Safety',     icon: 'health_and_safety',tile: 'icon-success', accent: '#059669' },
    conduct:    { label: 'Conduct',    icon: 'verified_user',    tile: 'icon-navy',    accent: '#0d1452' },
    other:      { label: 'Other',      icon: 'description',      tile: 'icon-sky',     accent: '#7986cb' },
};
const categoryMeta = (k) => CATEGORIES[k] ?? CATEGORIES.other;

// Acknowledgement state pill
const ackMeta = (s) => ({
    pending:      { label: 'Ack required', cls: 'bg-amber-50 text-amber-700 border-amber-200', dot: '#d97706' },
    acknowledged: { label: "Acknowledged",  cls: 'bg-green-50 text-green-700 border-green-200', dot: '#059669' },
    overdue:      { label: 'Overdue',       cls: 'bg-red-50 text-red-700 border-red-200',       dot: '#dc2626' },
}[s] ?? null);

// ── Filter state ──
const search          = ref('');
const categoryFilter  = ref('');
const onlyPendingMine = ref(false);

const policyRows = computed(() => props.policies?.data ?? props.policies ?? []);

const filteredPolicies = computed(() => {
    let rows = policyRows.value;
    if (categoryFilter.value) {
        rows = rows.filter(p => p.category === categoryFilter.value);
    }
    if (onlyPendingMine.value) {
        rows = rows.filter(p => p.my_ack_status === 'pending');
    }
    if (search.value.trim()) {
        const q = search.value.trim().toLowerCase();
        rows = rows.filter(p =>
            (p.title ?? '').toLowerCase().includes(q) ||
            (p.summary ?? '').toLowerCase().includes(q),
        );
    }
    return rows;
});

// ── Category bar data — sorted descending by count ──
const categoryBars = computed(() => {
    const entries = Object.entries(props.categoryDistribution ?? {});
    if (!entries.length) return [];
    const max = Math.max(...entries.map(([, v]) => v), 1);
    return entries
        .sort((a, b) => b[1] - a[1])
        .map(([key, count]) => ({
            key,
            count,
            pct: Math.round((count / max) * 100),
            meta: categoryMeta(key),
        }));
});

// ── Visual helpers ──
const compliancePct = computed(() => props.stats?.my_ack_rate ?? 0);
const circumference = 2 * Math.PI * 42;
const complianceDash = computed(() => (compliancePct.value / 100) * circumference);

const formatDate = (d) => {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

const hasActiveFilters = computed(() => search.value || categoryFilter.value || onlyPendingMine.value);
const clearFilters = () => { search.value = ''; categoryFilter.value = ''; onlyPendingMine.value = false; };
</script>

<template>
    <Head title="Governance" />
    <AuthenticatedLayout active-module="governance">

        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Governance</h1>
                    <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                        Policies you must read and acknowledge · compliance certifications under tracking
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <Link :href="route('governance.certifications.index')"
                          class="inline-flex items-center gap-2 rounded-xl border border-outline-variant px-4 py-2.5 text-[13px] font-bold text-on-surface hover:bg-surface-container-low transition-colors">
                        <span class="material-symbols-outlined text-[17px] text-cyan-600">workspace_premium</span>
                        Certifications
                    </Link>
                    <Link v-if="$page.props.auth.permissions?.includes('governance.manage')"
                          :href="route('governance.manage')"
                          class="btn-shimmer inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px active:scale-[0.97]"
                          style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                        <span class="material-symbols-outlined text-[18px]">edit_note</span>
                        Manage policies
                    </Link>
                </div>
            </div>
        </template>

        <div class="space-y-8">

            <!-- ── Hero banner ── -->
            <div class="relative overflow-hidden rounded-3xl px-8 py-7 text-white animate-reveal-up"
                 style="background:linear-gradient(135deg,#1a237e 0%, #283593 55%, #3949ab 100%);border:1px solid rgba(255,255,255,0.06);">
                <div class="pointer-events-none absolute -right-16 -top-16 h-72 w-72 rounded-full blur-3xl" style="background:radial-gradient(circle,rgba(18,217,227,0.18),transparent 70%)"></div>
                <div class="pointer-events-none absolute -left-8 bottom-0 h-48 w-48 rounded-full blur-2xl" style="background:rgba(255,215,0,0.06)"></div>

                <div class="relative flex flex-wrap items-center justify-between gap-8">
                    <div>
                        <p class="text-[9px] font-black uppercase tracking-[0.25em] mb-2" style="color:rgba(18,217,227,0.7)">Policy register</p>
                        <h2 class="text-3xl font-black leading-tight">
                            <template v-if="stats?.pending_for_me">
                                <em class="not-italic" style="color:#ffd700">{{ stats.pending_for_me }}</em> policy<span v-if="stats.pending_for_me !== 1">s</span> awaiting your acknowledgement
                            </template>
                            <template v-else>
                                <em class="not-italic" style="color:#12d9e3">All caught up</em><span class="text-base font-bold opacity-50"> — {{ stats?.published_count ?? 0 }} published policies on file</span>
                            </template>
                        </h2>
                        <p class="mt-2 text-sm font-medium" style="color:rgba(255,255,255,0.5)">
                            <span style="color:#12d9e3">{{ stats?.my_ack_rate ?? 100 }}%</span> of published policies acknowledged ·
                            <span style="color:#ffd700">{{ cert_stats?.expiring_30d ?? 0 }}</span> certifications expiring in 30 days
                        </p>
                    </div>
                    <div class="flex items-center gap-8 flex-shrink-0">
                        <div v-for="kpi in [
                            { label: 'Total',       val: stats?.total_policies ?? 0,  color: '#12d9e3' },
                            { label: 'Pending you', val: stats?.pending_for_me ?? 0,  color: '#ffd700' },
                            { label: 'Certs',       val: cert_stats?.total ?? 0,       color: '#7986cb' },
                        ]" :key="kpi.label" class="text-center">
                            <p class="text-3xl font-black leading-none tabular-nums" :style="`color:${kpi.color}`">{{ kpi.val }}</p>
                            <p class="mt-1 text-[9px] font-black uppercase tracking-[0.18em]" style="color:rgba(255,255,255,0.35)">{{ kpi.label }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Action-required banner ── -->
            <div v-if="stats?.pending_for_me"
                 class="rounded-2xl border-2 border-amber-300 bg-gradient-to-r from-amber-50 to-amber-50/60 dark:from-amber-900/15 dark:to-amber-900/5 dark:border-amber-800/40 p-5 flex items-center justify-between gap-4 animate-reveal-up">
                <div class="flex items-center gap-3">
                    <div class="icon-tile icon-gold flex-shrink-0">
                        <span class="material-symbols-outlined">pending_actions</span>
                    </div>
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-widest text-amber-700 dark:text-amber-300">Action required</p>
                        <p class="text-[14px] font-black text-amber-900 dark:text-amber-200 mt-0.5">
                            You have {{ stats.pending_for_me }} {{ stats.pending_for_me === 1 ? 'policy' : 'policies' }} pending acknowledgement.
                        </p>
                    </div>
                </div>
                <button @click="onlyPendingMine = true"
                        class="rounded-xl bg-white dark:bg-amber-900/30 border border-amber-300 dark:border-amber-700/50 px-4 py-2 text-[12px] font-black uppercase tracking-widest text-amber-800 dark:text-amber-200 hover:bg-amber-100 dark:hover:bg-amber-900/50 transition-colors flex-shrink-0">
                    Show me
                </button>
            </div>

            <!-- ── KPI tiles ── -->
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div v-for="(card, i) in [
                    { label: 'Total policies',    val: stats?.total_policies ?? 0,    sub: 'In register',                 cls: 'icon-brand',  icon: 'menu_book' },
                    { label: 'Published',         val: stats?.published_count ?? 0,   sub: 'Currently active',            cls: 'icon-cyan',   icon: 'verified' },
                    { label: 'Pending you',       val: stats?.pending_for_me ?? 0,    sub: 'Awaiting your ack',           cls: 'icon-gold',   icon: 'pending_actions' },
                    { label: 'Certs expiring',    val: cert_stats?.expiring_30d ?? 0, sub: 'Within 30 days',              cls: 'icon-magenta', icon: 'workspace_premium' },
                ]" :key="card.label"
                     class="group relative overflow-hidden rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5 transition-all hover:shadow-md hover:-translate-y-0.5"
                     :style="`animation:slideUpFade 0.4s ease both;animation-delay:${i*0.06}s`">
                    <div class="icon-tile" :class="card.cls">
                        <span class="material-symbols-outlined">{{ card.icon }}</span>
                    </div>
                    <p class="mt-3 text-[10px] font-black uppercase tracking-[0.12em] text-on-surface-variant/70">{{ card.label }}</p>
                    <p class="mt-1 text-[28px] font-black tabular-nums text-primary leading-none">{{ card.val }}</p>
                    <p class="mt-1 text-[10px] font-semibold text-on-surface-variant">{{ card.sub }}</p>
                </div>
            </div>

            <!-- ── Visual band: compliance ring + categories ── -->
            <div class="grid gap-6 lg:grid-cols-3 animate-reveal-up">

                <!-- Personal compliance ring -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-6 flex flex-col">
                    <div class="flex items-center justify-between mb-1">
                        <h3 class="text-[15px] font-black text-primary">Your compliance</h3>
                        <span class="text-[9.5px] font-black uppercase tracking-widest text-on-surface-variant/60">Personal</span>
                    </div>
                    <p class="text-[11px] text-on-surface-variant mb-4">Acknowledged vs published policies for your role.</p>

                    <div class="flex items-center justify-center relative my-2 flex-1">
                        <svg viewBox="0 0 100 100" width="180" height="180" class="-rotate-90">
                            <circle cx="50" cy="50" r="42" fill="none" stroke="rgb(var(--ct-surface-low))" stroke-width="9"/>
                            <circle cx="50" cy="50" r="42" fill="none"
                                    :stroke="compliancePct >= 100 ? '#12d9e3' : compliancePct >= 80 ? '#1a237e' : '#ffd700'"
                                    stroke-width="9" stroke-linecap="round"
                                    :stroke-dasharray="`${complianceDash} ${circumference}`"
                                    style="transition: stroke-dasharray 0.7s cubic-bezier(0.22,1,0.36,1);"/>
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center">
                            <p class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">Acknowledged</p>
                            <p class="text-3xl font-black tabular-nums text-primary leading-none">{{ compliancePct }}%</p>
                            <p class="mt-0.5 text-[9.5px] font-bold text-on-surface-variant/70">
                                {{ stats?.my_acked_count ?? 0 }} / {{ stats?.published_count ?? 0 }}
                            </p>
                        </div>
                    </div>

                    <div v-if="stats?.pending_for_me" class="mt-3 pt-3 border-t border-outline-variant/40 text-center">
                        <button @click="onlyPendingMine = true"
                                class="text-[11.5px] font-black text-amber-700 dark:text-amber-300 hover:underline">
                            {{ stats.pending_for_me }} pending — review now →
                        </button>
                    </div>
                    <p v-else class="mt-3 pt-3 border-t border-outline-variant/40 text-center text-[11.5px] font-bold text-green-700 dark:text-green-300">
                        <span class="material-symbols-outlined text-[14px] align-middle">check_circle</span>
                        Fully compliant
                    </p>
                </div>

                <!-- Category distribution bars (spans 2/3) -->
                <div class="lg:col-span-2 rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-6">
                    <div class="flex items-center justify-between mb-1">
                        <h3 class="text-[15px] font-black text-primary">Policies by category</h3>
                        <span class="text-[9.5px] font-black uppercase tracking-widest text-on-surface-variant/60">{{ Object.values(categoryDistribution).reduce((a, b) => a + b, 0) }} total</span>
                    </div>
                    <p class="text-[11px] text-on-surface-variant mb-5">Click a category to filter the register below.</p>

                    <div v-if="categoryBars.length" class="space-y-3">
                        <button v-for="(row, i) in categoryBars" :key="row.key"
                                @click="categoryFilter = categoryFilter === row.key ? '' : row.key"
                                class="w-full group flex items-center gap-3 transition-all"
                                :class="categoryFilter && categoryFilter !== row.key ? 'opacity-40' : ''"
                                :style="`animation:slideUpFade 0.4s ease both;animation-delay:${i*0.05}s`">
                            <span class="w-32 flex-shrink-0 flex items-center gap-2">
                                <span class="material-symbols-outlined text-[15px]" :style="`color:${row.meta.accent}`">{{ row.meta.icon }}</span>
                                <span class="text-[12px] font-bold text-on-surface">{{ row.meta.label }}</span>
                            </span>
                            <div class="flex-1 h-6 rounded-lg bg-surface-container-low border border-outline-variant/30 relative overflow-hidden">
                                <div class="absolute inset-y-0 left-0 rounded-lg transition-all duration-700 flex items-center justify-end pr-2.5"
                                     :style="`width:${row.pct}%;background:linear-gradient(90deg,${row.meta.accent}cc,${row.meta.accent})`">
                                    <span class="text-[10px] font-black text-white tabular-nums">{{ row.count }}</span>
                                </div>
                            </div>
                            <span class="w-10 text-right text-[10px] font-bold text-on-surface-variant/70 tabular-nums">{{ row.pct }}%</span>
                        </button>
                    </div>
                    <div v-else class="py-12 text-center text-[12px] font-medium text-on-surface-variant italic">No policies registered yet.</div>

                    <!-- Cert teaser footer -->
                    <div class="mt-5 pt-4 border-t border-outline-variant/40 grid grid-cols-3 gap-2">
                        <Link :href="route('governance.certifications.index')"
                              class="rounded-xl bg-surface-container-low/60 hover:bg-cyan-50 dark:hover:bg-cyan-900/15 border border-outline-variant/30 hover:border-cyan-200/60 px-3 py-2.5 transition-colors group">
                            <p class="text-[9px] font-black uppercase tracking-widest text-on-surface-variant/60">Total certs</p>
                            <p class="text-[18px] font-black text-primary tabular-nums">{{ cert_stats?.total ?? 0 }}</p>
                        </Link>
                        <Link :href="route('governance.certifications.index')"
                              class="rounded-xl bg-amber-50/40 hover:bg-amber-50 border border-amber-200/40 px-3 py-2.5 transition-colors">
                            <p class="text-[9px] font-black uppercase tracking-widest text-amber-700">Expiring 30d</p>
                            <p class="text-[18px] font-black text-amber-800 tabular-nums">{{ cert_stats?.expiring_30d ?? 0 }}</p>
                        </Link>
                        <Link :href="route('governance.certifications.index')"
                              class="rounded-xl bg-rose-50/40 hover:bg-rose-50 border border-rose-200/40 px-3 py-2.5 transition-colors">
                            <p class="text-[9px] font-black uppercase tracking-widest text-rose-700">Expired</p>
                            <p class="text-[18px] font-black text-rose-800 tabular-nums">{{ cert_stats?.expired ?? 0 }}</p>
                        </Link>
                    </div>
                </div>
            </div>

            <!-- ── Policy register ── -->
            <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">

                <!-- Filter row -->
                <div class="flex flex-wrap items-center gap-3 px-6 py-4 border-b border-outline-variant/50 bg-surface-container-low/30">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-[16px] text-secondary">filter_list</span>
                        <span class="text-[11px] font-black uppercase tracking-widest text-on-surface-variant">Filter</span>
                    </div>
                    <div class="relative flex-1 min-w-[220px] max-w-xs">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[16px] text-on-surface-variant/50">search</span>
                        <input v-model="search" placeholder="Search title or summary…"
                               class="w-full rounded-xl border-outline-variant pl-9 text-[12.5px] focus:border-secondary focus:ring-secondary/20"/>
                    </div>
                    <select v-model="categoryFilter"
                            class="rounded-xl border-outline-variant text-[12.5px] font-semibold focus:border-secondary focus:ring-secondary/20">
                        <option value="">All categories</option>
                        <option v-for="(meta, key) in CATEGORIES" :key="key" :value="key">{{ meta.label }}</option>
                    </select>
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input v-model="onlyPendingMine" aria-label="Show only items pending my acknowledgement" type="checkbox" class="rounded border-outline-variant text-secondary focus:ring-secondary/20"/>
                        <span class="text-[12px] font-semibold text-on-surface">Pending mine only</span>
                    </label>
                    <button v-if="hasActiveFilters" @click="clearFilters"
                            class="ml-auto rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-bold text-on-surface-variant hover:bg-surface-container transition-colors flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-[15px]">close</span>
                        Clear
                    </button>
                </div>

                <!-- Empty state -->
                <div v-if="!policyRows.length" class="px-6 py-16">
                    <EmptyState title="No policies published yet"
                                description="HR-managed policies will appear here once published. Until then there's nothing for staff to acknowledge."
                                icon="menu_book" />
                </div>

                <div v-else-if="!filteredPolicies.length" class="px-6 py-16">
                    <EmptyState title="No policies match your filters"
                                description="Try clearing filters or broadening your search." />
                </div>

                <!-- Cards grid -->
                <div v-else class="grid grid-cols-1 lg:grid-cols-2 gap-4 p-5">
                    <Link v-for="(p, i) in filteredPolicies" :key="p.id"
                          :href="route('governance.policies.show', p.id)"
                          class="group rounded-2xl border border-outline-variant/60 bg-surface-container-low/30 p-5 transition-all hover:-translate-y-0.5 hover:shadow-md hover:border-secondary/30"
                          :style="`animation:slideUpFade 0.4s ease both;animation-delay:${i*0.04}s;border-left:3px solid ${categoryMeta(p.category).accent};`">

                        <div class="flex items-start gap-4">
                            <div class="icon-tile flex-shrink-0" :class="categoryMeta(p.category).tile">
                                <span class="material-symbols-outlined">{{ categoryMeta(p.category).icon }}</span>
                            </div>

                            <div class="flex-1 min-w-0">
                                <!-- Pills -->
                                <div class="flex items-center gap-2 flex-wrap mb-1.5">
                                    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-black uppercase tracking-wider"
                                          :style="`background:${categoryMeta(p.category).accent}1a;color:${categoryMeta(p.category).accent}`">
                                        {{ categoryMeta(p.category).label }}
                                    </span>
                                    <span v-if="ackMeta(p.my_ack_status)"
                                          class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[10px] font-black uppercase tracking-wider"
                                          :class="ackMeta(p.my_ack_status).cls">
                                        <span class="h-1.5 w-1.5 rounded-full" :style="`background:${ackMeta(p.my_ack_status).dot}`"></span>
                                        {{ ackMeta(p.my_ack_status).label }}
                                    </span>
                                </div>

                                <!-- Title -->
                                <h3 class="text-[15px] font-black text-primary leading-tight mb-1 truncate group-hover:text-secondary transition-colors">{{ p.title }}</h3>

                                <!-- Summary -->
                                <p v-if="p.summary" class="text-[12px] text-on-surface-variant leading-relaxed line-clamp-2 mb-3">{{ p.summary }}</p>
                                <p v-else class="text-[12px] text-on-surface-variant/50 italic mb-3">No summary provided.</p>

                                <!-- Footer meta -->
                                <div class="flex items-center justify-between text-[10.5px] text-on-surface-variant/70 pt-2 border-t border-outline-variant/30">
                                    <span v-if="p.current_version" class="font-mono inline-flex items-center gap-1">
                                        <span class="material-symbols-outlined text-[13px] text-secondary">commit</span>
                                        v{{ p.current_version.version_number }}
                                        <span class="opacity-50">·</span>
                                        eff. {{ formatDate(p.current_version.effective_from) }}
                                    </span>
                                    <span v-else class="italic text-on-surface-variant/50">No published version</span>
                                    <span class="inline-flex items-center gap-1 font-black text-secondary group-hover:translate-x-0.5 transition-transform">
                                        Open
                                        <span class="material-symbols-outlined text-[14px]">arrow_forward</span>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </Link>
                </div>
            </div>
        </div>

    </AuthenticatedLayout>
</template>
