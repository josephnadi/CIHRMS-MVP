<script setup>
import { ref, computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import EmptyState from '@/Components/EmptyState.vue';
import LiveBars from '@/Components/charts/LiveBars.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    providers:        Array,
    recent_attempts:  Array,
    stats:            { type: Object, default: () => ({}) },
    outcomeBreakdown: { type: Object, default: () => ({}) },
    attemptsTrend:    { type: Array,  default: () => [] },
    activeModule:     String,
});

// ── Outcome metadata (palette-keyed) ──
const OUTCOME_META = {
    success:           { label: 'Success',             cls: 'bg-green-50 text-green-700 border-green-200',  dot: '#059669', icon: 'check_circle' },
    invalid_state:     { label: 'Invalid state',       cls: 'bg-amber-50 text-amber-700 border-amber-200',  dot: '#d97706', icon: 'shield_question' },
    claim_missing:     { label: 'Claim missing',       cls: 'bg-amber-50 text-amber-700 border-amber-200',  dot: '#ffd700', icon: 'fact_check' },
    user_disabled:     { label: 'User disabled',       cls: 'bg-slate-100 text-slate-600 border-slate-200', dot: '#64748b', icon: 'person_off' },
    provider_error:    { label: 'Provider error',      cls: 'bg-rose-50 text-rose-700 border-rose-200',     dot: '#dc2626', icon: 'cloud_off' },
    provision_failed:  { label: 'Provision failed',    cls: 'bg-rose-50 text-rose-700 border-rose-200',     dot: '#d912e3', icon: 'person_remove' },
    domain_blocked:    { label: 'Domain blocked',      cls: 'bg-rose-50 text-rose-700 border-rose-200',     dot: '#dc2626', icon: 'block' },
};
const outcomeMeta = (o) => OUTCOME_META[o] ?? { label: o ?? '—', cls: 'bg-slate-100 text-slate-600 border-slate-200', dot: '#64748b', icon: 'help' };

// ── Provider-type metadata ──
const TYPE_META = {
    oidc: { label: 'OIDC',     accent: '#12d9e3', icon: 'lock', tile: 'icon-cyan' },
    saml: { label: 'SAML 2.0', accent: '#1a237e', icon: 'security', tile: 'icon-brand' },
};
const typeMeta = (t) => TYPE_META[t] ?? { label: (t ?? '—').toUpperCase(), accent: '#7986cb', icon: 'vpn_key', tile: 'icon-sky' };

// ── Outcome bars (sorted desc) ──
const totalOutcomes = computed(() => Object.values(props.outcomeBreakdown ?? {}).reduce((s, v) => s + Number(v), 0));
const outcomeBars   = computed(() => {
    const entries = Object.entries(props.outcomeBreakdown ?? {});
    if (!entries.length) return [];
    const max = Math.max(...entries.map(([, v]) => v), 1);
    return entries
        .sort((a, b) => b[1] - a[1])
        .map(([key, count]) => ({
            key, count,
            pct: Math.round((count / max) * 100),
            sharePct: totalOutcomes.value > 0 ? Math.round((count / totalOutcomes.value) * 100) : 0,
            meta: outcomeMeta(key),
        }));
});

// ── Trend (LiveBars compatible) ──
const trendBars = computed(() => (props.attemptsTrend ?? []).map(d => ({
    label: d.label,
    value: d.value,
})));

// ── Compose form ──
const showAdd = ref(false);
const form = useForm({
    slug:            '',
    name:            '',
    type:            'oidc',
    auto_provision:  false,
    default_role:    'employee',
    config_json:     '{\n  "issuer": "",\n  "client_id": "",\n  "client_secret": "",\n  "scopes": ["openid","profile","email"]\n}',
    claim_mapping_json: '{\n  "email": "preferred_username",\n  "name": "name"\n}',
    domains:         '',
    button_label:    '',
    button_icon:     '',
});

const submit = () => {
    let cfg = {}, mapping = {};
    try { cfg = form.config_json ? JSON.parse(form.config_json) : {}; } catch { cfg = {}; }
    try { mapping = form.claim_mapping_json ? JSON.parse(form.claim_mapping_json) : null; } catch { mapping = null; }

    form.transform(() => ({
        slug:             form.slug,
        name:             form.name,
        type:             form.type,
        auto_provision:   form.auto_provision,
        default_role:     form.default_role,
        config:           cfg,
        claim_mapping:    mapping,
        allowed_email_domains: form.domains ? form.domains.split(',').map(s => s.trim()).filter(Boolean) : null,
        button_label:     form.button_label || null,
        button_icon:      form.button_icon  || null,
    })).post(route('sso-admin.store'), {
        preserveScroll: true,
        onSuccess: () => { showAdd.value = false; form.reset(); },
    });
};

// ── Helpers ──
const initials = (name) => {
    if (!name) return '?';
    const p = name.trim().split(' ');
    return p.length >= 2 ? (p[0][0] + p[p.length-1][0]).toUpperCase() : name.slice(0, 2).toUpperCase();
};

const formatRelTime = (iso) => {
    if (!iso) return '—';
    const diff = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);
    if (diff < 60)       return `${diff}s ago`;
    if (diff < 3600)     return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400)    return `${Math.floor(diff / 3600)}h ago`;
    return new Date(iso).toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
};

// JSON validation badge for the form
const cfgValid = computed(() => {
    try { JSON.parse(form.config_json); return true; } catch { return false; }
});
const mapValid = computed(() => {
    try { if (!form.claim_mapping_json.trim()) return true; JSON.parse(form.claim_mapping_json); return true; } catch { return false; }
});
</script>

<template>
    <Head title="Single Sign-On Providers" />
    <div data-page-root="true">
            <Teleport to="#page-header-mount" defer>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="material-symbols-outlined text-[16px] text-cyan-600" style="font-variation-settings:'FILL' 1">vpn_key</span>
                            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-cyan-700 dark:text-cyan-300">Phase 4 · NITA / ghana.gov federation</p>
                        </div>
                        <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Single Sign-On Providers</h1>
                        <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                            Federated identity providers, JIT provisioning, and live sign-in telemetry
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="flex items-center gap-1.5 rounded-full bg-cyan-50 border border-cyan-200 px-3 py-1.5 dark:bg-cyan-900/20 dark:border-cyan-800/40">
                            <span class="h-1.5 w-1.5 rounded-full bg-cyan-500 live-dot"></span>
                            <span class="text-[10px] font-black uppercase tracking-widest text-cyan-700 dark:text-cyan-300">{{ stats?.attempts_today ?? 0 }} attempts today</span>
                        </div>
                        <button @click="showAdd = true"
                                class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px hover:shadow-glow active:scale-[0.97]"
                                style="background:linear-gradient(135deg,#1a237e,#3949ab)">
                            <span class="material-symbols-outlined text-[18px]">add</span>
                            Register provider
                            <span class="text-[9px] font-black uppercase tracking-widest rounded bg-white/20 px-1.5 py-0.5">2FA</span>
                        </button>
                    </div>
                </div>
            </Teleport>

            <div class="space-y-8">

                <!-- ── Hero banner ── -->
                <div class="relative overflow-hidden rounded-3xl px-8 py-7 text-white animate-reveal-up"
                     style="background:linear-gradient(135deg,#1a237e 0%, #283593 55%, #3949ab 100%);border:1px solid rgba(255,255,255,0.06);">
                    <div class="pointer-events-none absolute -right-16 -top-16 h-72 w-72 rounded-full blur-3xl" style="background:radial-gradient(circle,rgba(18,217,227,0.18),transparent 70%)"></div>
                    <div class="pointer-events-none absolute -left-8 bottom-0 h-48 w-48 rounded-full blur-2xl" style="background:rgba(255,215,0,0.06)"></div>

                    <div class="relative flex flex-wrap items-center justify-between gap-8">
                        <div>
                            <p class="text-[9px] font-black uppercase tracking-[0.25em] mb-2" style="color:rgba(18,217,227,0.7)">Identity federation · live</p>
                            <h2 class="text-3xl font-black leading-tight">
                                <em class="not-italic" style="color:#12d9e3">{{ stats?.providers_active ?? 0 }}</em> active provider<span v-if="(stats?.providers_active ?? 0) !== 1">s</span>
                                <span class="text-base font-bold opacity-50">· <span style="color:#ffd700">{{ stats?.success_rate_7d ?? 100 }}%</span> success over 7 days</span>
                            </h2>
                            <p class="mt-2 text-sm font-medium" style="color:rgba(255,255,255,0.5)">
                                <span style="color:#12d9e3">{{ stats?.success_today ?? 0 }}</span> successful sign-ins today ·
                                <template v-if="stats?.failed_today > 0">
                                    <span style="color:#f87171">{{ stats.failed_today }}</span> failed ·
                                </template>
                                <span style="color:#7986cb">{{ stats?.links_total ?? 0 }}</span> identities linked org-wide
                            </p>
                        </div>
                        <div class="flex items-center gap-8 flex-shrink-0">
                            <div v-for="kpi in [
                                { label: 'Active',  val: stats?.providers_active ?? 0, color: '#12d9e3' },
                                { label: 'Success', val: (stats?.success_rate_7d ?? 100) + '%', color: '#ffd700' },
                                { label: 'Linked',  val: stats?.links_total ?? 0, color: '#7986cb' },
                            ]" :key="kpi.label" class="text-center">
                                <p class="text-3xl font-black leading-none tabular-nums" :style="`color:${kpi.color}`">{{ kpi.val }}</p>
                                <p class="mt-1 text-[9px] font-black uppercase tracking-[0.18em]" style="color:rgba(255,255,255,0.35)">{{ kpi.label }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── KPI tiles ── -->
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <div v-for="(card, i) in [
                        { label: 'Active providers', val: stats?.providers_active ?? 0,            sub: (stats?.providers_total ?? 0) + ' total registered', cls: 'icon-cyan',    icon: 'verified_user' },
                        { label: 'Attempts today',   val: stats?.attempts_today ?? 0,              sub: (stats?.success_today ?? 0) + ' successful',          cls: 'icon-brand',   icon: 'login' },
                        { label: 'Success rate · 7d',val: (stats?.success_rate_7d ?? 100) + '%',  sub: (stats?.attempts_7d ?? 0) + ' attempts',              cls: 'icon-gold',    icon: 'trending_up' },
                        { label: 'Linked identities',val: stats?.links_total ?? 0,                sub: 'Federated org-wide',                                  cls: 'icon-magenta', icon: 'link' },
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

                <!-- ── Visual band: outcome bars + 7-day attempts trend ── -->
                <div class="grid gap-6 lg:grid-cols-3 animate-reveal-up">

                    <!-- Outcome composition (1/3) -->
                    <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-6 flex flex-col">
                        <div class="flex items-center justify-between mb-1">
                            <h3 class="text-[15px] font-black text-primary">Outcome composition</h3>
                            <span class="text-[9.5px] font-black uppercase tracking-widest text-on-surface-variant/60">Last 30 days</span>
                        </div>
                        <p class="text-[11px] text-on-surface-variant mb-4">Where sign-in attempts land — a high "claim missing" share signals a misconfigured provider.</p>

                        <div v-if="outcomeBars.length" class="space-y-2.5 flex-1">
                            <div v-for="(row, i) in outcomeBars" :key="row.key"
                                 class="w-full flex items-center gap-3"
                                 :style="`animation:slideUpFade 0.35s ease both;animation-delay:${i*0.05}s`">
                                <span class="w-24 flex-shrink-0 flex items-center gap-1.5">
                                    <span class="material-symbols-outlined text-[13px]" :style="`color:${row.meta.dot}`">{{ row.meta.icon }}</span>
                                    <span class="text-[11px] font-black text-on-surface truncate">{{ row.meta.label }}</span>
                                </span>
                                <div class="flex-1 h-5 rounded-lg bg-surface-container-low border border-outline-variant/30 relative overflow-hidden">
                                    <div class="absolute inset-y-0 left-0 rounded-lg transition-all duration-700 flex items-center justify-end pr-2"
                                         :style="`width:${row.pct}%;background:linear-gradient(90deg,${row.meta.dot}cc,${row.meta.dot})`">
                                        <span class="text-[10px] font-black text-white tabular-nums">{{ row.count }}</span>
                                    </div>
                                </div>
                                <span class="w-9 text-right text-[10px] font-bold text-on-surface-variant/70 tabular-nums">{{ row.sharePct }}%</span>
                            </div>
                        </div>
                        <div v-else class="py-10 text-center text-[12px] font-medium text-on-surface-variant italic">No attempts in the last 30 days.</div>

                        <!-- Health footer -->
                        <div class="mt-5 pt-4 border-t border-outline-variant/40 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-[16px]"
                                      :style="`color:${(stats?.success_rate_7d ?? 100) >= 95 ? '#059669' : (stats?.success_rate_7d ?? 100) >= 80 ? '#ffd700' : '#dc2626'}`">
                                    {{ (stats?.success_rate_7d ?? 100) >= 95 ? 'shield_with_heart' : 'health_and_safety' }}
                                </span>
                                <span class="text-[11px] font-black uppercase tracking-wider text-on-surface-variant">Auth health</span>
                            </div>
                            <span class="text-[14px] font-black tabular-nums"
                                  :style="`color:${(stats?.success_rate_7d ?? 100) >= 95 ? '#059669' : (stats?.success_rate_7d ?? 100) >= 80 ? '#b88a08' : '#dc2626'}`">
                                {{ stats?.success_rate_7d ?? 100 }}%
                            </span>
                        </div>
                    </div>

                    <!-- 7-day attempts trend (2/3) -->
                    <div class="lg:col-span-2 rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <h3 class="text-[15px] font-black text-primary">Sign-in volume · last 7 days</h3>
                                <p class="mt-0.5 text-[11px] text-on-surface-variant">All federated attempts per day. Peak day in gold.</p>
                            </div>
                            <div class="flex items-center gap-3">
                                <div class="flex items-center gap-1.5"><span class="h-2 w-3 rounded bg-secondary"></span><span class="text-[9.5px] font-bold text-on-surface-variant">Attempts</span></div>
                                <div class="flex items-center gap-1.5"><span class="h-2 w-3 rounded" style="background:#ffd700"></span><span class="text-[9.5px] font-bold text-on-surface-variant">Peak</span></div>
                                <div class="flex items-center gap-1.5"><span class="h-2 w-3 rounded" style="background:#12d9e3"></span><span class="text-[9.5px] font-bold text-on-surface-variant">Live</span></div>
                            </div>
                        </div>

                        <div v-if="trendBars.length && trendBars.some(b => b.value > 0)" class="mt-2">
                            <LiveBars :data="trendBars"
                                      :height="180"
                                      color="#1a237e"
                                      accent-color="#ffd700"
                                      second-color="#12d9e3"
                                      :show-median="true"
                                      :rounded="5"
                                      :format-value="v => `${v} attempt${v === 1 ? '' : 's'}`" />
                        </div>
                        <div v-else class="py-14 text-center text-[12px] font-medium text-on-surface-variant italic">No sign-in attempts in the last 7 days.</div>
                    </div>
                </div>

                <!-- ── Providers grid ── -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
                    <div class="flex items-center justify-between px-6 py-4 border-b border-outline-variant/50">
                        <div>
                            <h3 class="text-[15px] font-black text-primary">Registered providers</h3>
                            <p class="mt-0.5 text-[11px] text-on-surface-variant">{{ stats?.providers_active ?? 0 }} active · {{ stats?.providers_total ?? 0 }} total</p>
                        </div>
                    </div>

                    <div v-if="!(providers ?? []).length" class="px-6 py-16">
                        <EmptyState icon="vpn_key" title="No providers registered yet"
                                    description="Add a provider (NITA OIDC, ghana.gov SAML, Google Workspace, Microsoft Entra) to enable federated sign-in.">
                            <template #action>
                                <button @click="showAdd = true"
                                        class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2.5 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px"
                                        style="background:linear-gradient(135deg,#1a237e,#3949ab)">
                                    <span class="material-symbols-outlined text-[18px]">add</span>
                                    Register provider
                                </button>
                            </template>
                        </EmptyState>
                    </div>

                    <div v-else class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-4 p-5">
                        <div v-for="(p, i) in providers" :key="p.id"
                             class="rounded-2xl border bg-surface-container-low/30 p-5 transition-all hover:-translate-y-0.5 hover:shadow-md hover:border-secondary/30"
                             :class="p.is_active ? 'border-outline-variant/60' : 'border-outline-variant/30 opacity-70'"
                             :style="`animation:slideUpFade 0.4s ease both;animation-delay:${i*0.04}s;border-left:3px solid ${typeMeta(p.type).accent};`">

                            <div class="flex items-start gap-3 mb-3">
                                <div class="icon-tile flex-shrink-0" :class="typeMeta(p.type).tile">
                                    <span class="material-symbols-outlined">{{ p.button_icon || typeMeta(p.type).icon }}</span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 mb-0.5">
                                        <p class="text-[14px] font-black text-primary truncate">{{ p.name }}</p>
                                        <span class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[9.5px] font-black uppercase tracking-wider"
                                              :class="p.is_active
                                                ? 'bg-green-50 text-green-700 border-green-200'
                                                : 'bg-slate-100 text-slate-600 border-slate-200'">
                                            <span class="h-1.5 w-1.5 rounded-full" :class="p.is_active ? 'bg-green-500 live-dot' : 'bg-slate-400'"></span>
                                            {{ p.is_active ? 'Active' : 'Disabled' }}
                                        </span>
                                    </div>
                                    <p class="font-mono text-[10.5px] text-on-surface-variant/70 uppercase tracking-wider">{{ p.slug }}</p>
                                </div>
                            </div>

                            <!-- Meta pills -->
                            <div class="flex flex-wrap gap-1.5 mb-3">
                                <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-black uppercase tracking-wider"
                                      :style="`background:${typeMeta(p.type).accent}1a;color:${typeMeta(p.type).accent}`">
                                    <span class="material-symbols-outlined text-[11px]">{{ typeMeta(p.type).icon }}</span>
                                    {{ p.type_label }}
                                </span>
                                <span v-if="p.auto_provision"
                                      class="inline-flex items-center gap-1 rounded-full bg-cyan-50 text-cyan-700 border border-cyan-200 px-2 py-0.5 text-[10px] font-black uppercase tracking-wider">
                                    <span class="material-symbols-outlined text-[11px]">person_add</span>
                                    JIT
                                </span>
                                <span v-else
                                      class="inline-flex items-center gap-1 rounded-full bg-slate-100 text-slate-600 border border-slate-200 px-2 py-0.5 text-[10px] font-black uppercase tracking-wider">
                                    <span class="material-symbols-outlined text-[11px]">link_off</span>
                                    Manual link
                                </span>
                            </div>

                            <!-- Callback URL -->
                            <div class="rounded-xl bg-surface-container-low/60 border border-outline-variant/40 px-3 py-2">
                                <p class="text-[9.5px] font-black uppercase tracking-widest text-on-surface-variant/60 mb-0.5">Callback URL</p>
                                <p class="font-mono text-[10.5px] text-secondary truncate" :title="p.callback_url">{{ p.callback_url }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Recent attempts ── -->
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
                    <div class="flex items-center justify-between px-6 py-4 border-b border-outline-variant/50">
                        <div>
                            <h3 class="text-[15px] font-black text-primary">Recent SSO attempts</h3>
                            <p class="mt-0.5 text-[11px] text-on-surface-variant">Last 50 entries — every attempt is audit-logged</p>
                        </div>
                        <div class="flex items-center gap-1.5 rounded-full bg-cyan-50 border border-cyan-200 px-3 py-1 dark:bg-cyan-900/20 dark:border-cyan-800/40">
                            <span class="h-1.5 w-1.5 rounded-full bg-cyan-500 live-dot"></span>
                            <span class="text-[9.5px] font-black uppercase tracking-widest text-cyan-700 dark:text-cyan-300">Live</span>
                        </div>
                    </div>

                    <div v-if="!(recent_attempts ?? []).length" class="px-6 py-16">
                        <EmptyState icon="history" title="No sign-in attempts yet" description="When users hit the SSO callback, attempts appear here in real time." />
                    </div>

                    <div v-else class="canvas-scroll overflow-x-auto max-h-[480px]">
                        <table class="w-full text-left">
                            <thead class="sticky top-0 z-10 bg-surface-container-low text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 border-b border-outline-variant/50">
                                <tr>
                                    <th class="px-6 py-3">When</th>
                                    <th class="px-6 py-3">Provider</th>
                                    <th class="px-6 py-3">User / email</th>
                                    <th class="px-6 py-3">IP</th>
                                    <th class="px-6 py-3">Outcome</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-outline-variant/30">
                                <tr v-for="(a, idx) in recent_attempts" :key="a.id"
                                    class="hover:bg-surface-container-low/40 transition-colors"
                                    :style="`animation:slideUpFade 0.3s ease both;animation-delay:${idx*0.01}s;border-left:3px solid ${outcomeMeta(a.outcome).dot};`">
                                    <td class="px-6 py-2.5 text-[11.5px] text-on-surface-variant whitespace-nowrap">
                                        <span class="font-mono tabular-nums">{{ formatRelTime(a.created_at) }}</span>
                                    </td>
                                    <td class="px-6 py-2.5">
                                        <span v-if="a.provider" class="text-[12px] font-bold text-primary">{{ a.provider }}</span>
                                        <span v-else class="text-[11px] text-on-surface-variant/40 italic">—</span>
                                    </td>
                                    <td class="px-6 py-2.5">
                                        <div class="flex items-center gap-2.5 min-w-0">
                                            <div class="h-7 w-7 rounded-full bg-secondary/10 flex items-center justify-center text-[10px] font-black text-secondary flex-shrink-0">
                                                {{ initials(a.user ?? a.email) }}
                                            </div>
                                            <div class="min-w-0">
                                                <p class="text-[12px] font-bold text-primary leading-tight truncate">{{ a.user ?? 'Unknown' }}</p>
                                                <p class="font-mono text-[10.5px] text-on-surface-variant/70 leading-tight truncate">{{ a.email ?? '—' }}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-2.5 font-mono text-[11px] text-on-surface-variant tabular-nums">{{ a.ip ?? '—' }}</td>
                                    <td class="px-6 py-2.5">
                                        <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-[10px] font-black uppercase tracking-wider"
                                              :class="outcomeMeta(a.outcome).cls">
                                            <span class="material-symbols-outlined text-[11px]">{{ outcomeMeta(a.outcome).icon }}</span>
                                            {{ outcomeMeta(a.outcome).label }}
                                        </span>
                                        <p v-if="a.error" class="mt-1 text-[10.5px] font-mono text-rose-600 dark:text-rose-400 line-clamp-1" :title="a.error">{{ a.error }}</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- ── Audit footer ── -->
                <div class="rounded-xl border border-outline-variant/40 bg-surface-container-low/40 px-5 py-3 flex items-center gap-3 text-[11.5px] text-on-surface-variant">
                    <span class="material-symbols-outlined text-[18px] text-cyan-600 flex-shrink-0">shield_lock</span>
                    <p class="leading-relaxed">
                        <span class="font-bold text-on-surface">Privileged action.</span>
                        Registering, editing, or disabling a provider requires fresh 2FA confirmation. Every change is recorded in the
                        <span class="font-bold">SSO audit log</span> with the actor, before/after diff, and IP for compliance review.
                    </p>
                </div>
            </div>

            <!-- ── Register provider slide-panel ── -->
            <SlidePanel v-model="showAdd" title="Register identity provider">
                <form @submit.prevent="submit" class="space-y-5 p-6">

                    <div class="rounded-xl bg-cyan-50/60 border border-cyan-200/60 dark:bg-cyan-900/15 dark:border-cyan-800/40 px-4 py-3 flex items-start gap-3">
                        <span class="material-symbols-outlined text-cyan-600 text-[20px] mt-0.5">info</span>
                        <p class="text-[12px] text-cyan-900 dark:text-cyan-200 leading-relaxed">
                            After saving, copy the generated <span class="font-bold">Callback URL</span> from the providers grid into your IdP's allowed-redirect-URIs list. Mismatch is the most common reason for "invalid_state" failures.
                        </p>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Slug <span class="text-rose-500">*</span></label>
                            <input aria-label="Slug" v-model="form.slug" required placeholder="nita" pattern="[a-z0-9-]+"
                                   class="w-full rounded-xl border-outline-variant bg-surface-container-low font-mono text-[13px] focus:border-secondary focus:ring-secondary/20"/>
                        </div>
                        <div>
                            <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Type <span class="text-rose-500">*</span></label>
                            <div class="grid grid-cols-2 gap-1.5">
                                <button v-for="(meta, k) in TYPE_META" :key="k" type="button"
                                        @click="form.type = k"
                                        :class="['rounded-xl border px-3 py-2 text-[11.5px] font-black uppercase tracking-wide transition-all flex items-center justify-center gap-1.5',
                                                  form.type === k
                                                    ? 'border-2 text-white shadow-glow-sm'
                                                    : 'border-outline-variant text-on-surface-variant hover:border-secondary/40']"
                                        :style="form.type === k ? `background:${meta.accent};border-color:${meta.accent}` : ''">
                                    <span class="material-symbols-outlined text-[14px]">{{ meta.icon }}</span>
                                    {{ meta.label }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Display name <span class="text-rose-500">*</span></label>
                        <input aria-label="Display name" v-model="form.name" required placeholder="NITA IDM"
                               class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20"/>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Button label</label>
                            <input aria-label="Button label" v-model="form.button_label" placeholder="Sign in with NITA"
                                   class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20"/>
                        </div>
                        <div>
                            <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Icon (material name)</label>
                            <input aria-label="Icon (material name)" v-model="form.button_icon" placeholder="vpn_key"
                                   class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20"/>
                        </div>
                    </div>

                    <!-- JIT toggle card -->
                    <label class="block cursor-pointer rounded-xl border border-outline-variant/60 bg-surface-container-low p-4 transition-all hover:border-secondary/30"
                           :class="form.auto_provision ? 'border-cyan-300 bg-cyan-50/40 dark:bg-cyan-900/15' : ''">
                        <div class="flex items-start gap-3">
                            <input v-model="form.auto_provision" aria-label="Auto-provision new users on first sign-in" type="checkbox" class="mt-0.5 rounded border-outline-variant text-secondary focus:ring-secondary/20"/>
                            <div class="flex-1">
                                <p class="text-[13px] font-black text-primary">Auto-provision new users on first sign-in (JIT)</p>
                                <p class="text-[11px] text-on-surface-variant mt-0.5">Required for NITA / ghana.gov federation. Users land with the default role below.</p>
                            </div>
                        </div>
                        <div v-if="form.auto_provision" class="mt-3 pt-3 border-t border-cyan-200/60 dark:border-cyan-800/40">
                            <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Default role for new users</label>
                            <input aria-label="Default role" v-model="form.default_role"
                                   class="w-full rounded-xl border-outline-variant bg-surface-container-lowest text-[13px] font-mono focus:border-secondary focus:ring-secondary/20"/>
                        </div>
                    </label>

                    <div>
                        <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Allowed email domains
                            <span class="ml-1 font-normal normal-case text-on-surface-variant/60">(comma-separated, blank = any)</span>
                        </label>
                        <input aria-label="Allowed email domains (comma-separated, blank = any)" v-model="form.domains" placeholder="mofep.gov.gh, cihrm.gov.gh"
                               class="w-full rounded-xl border-outline-variant bg-surface-container-low font-mono text-[12.5px] focus:border-secondary focus:ring-secondary/20"/>
                    </div>

                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant">Provider configuration (JSON) <span class="text-rose-500">*</span></label>
                            <span class="inline-flex items-center gap-1 text-[10px] font-black uppercase tracking-wider rounded-full px-2 py-0.5"
                                  :class="cfgValid ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-rose-50 text-rose-700 border border-rose-200'">
                                <span class="material-symbols-outlined text-[11px]">{{ cfgValid ? 'check_circle' : 'error' }}</span>
                                {{ cfgValid ? 'Valid JSON' : 'Invalid JSON' }}
                            </span>
                        </div>
                        <textarea aria-label="Config json" v-model="form.config_json" rows="6"
                                  class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[11.5px] font-mono focus:border-secondary focus:ring-secondary/20 resize-none"
                                  :class="{ 'border-rose-300': !cfgValid }"></textarea>
                    </div>

                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant">Claim mapping (JSON) <span class="ml-1 font-normal normal-case text-on-surface-variant/60">(optional)</span></label>
                            <span class="inline-flex items-center gap-1 text-[10px] font-black uppercase tracking-wider rounded-full px-2 py-0.5"
                                  :class="mapValid ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-rose-50 text-rose-700 border border-rose-200'">
                                <span class="material-symbols-outlined text-[11px]">{{ mapValid ? 'check_circle' : 'error' }}</span>
                                {{ mapValid ? 'Valid' : 'Invalid' }}
                            </span>
                        </div>
                        <textarea aria-label="Claim mapping json" v-model="form.claim_mapping_json" rows="3"
                                  class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[11.5px] font-mono focus:border-secondary focus:ring-secondary/20 resize-none"
                                  :class="{ 'border-rose-300': !mapValid }"></textarea>
                    </div>

                    <button type="submit" :disabled="form.processing || !cfgValid || !mapValid"
                            class="btn-shimmer w-full flex items-center justify-center gap-2 rounded-xl px-5 py-3 text-[13px] font-black text-white disabled:opacity-60 shadow-glow-sm transition-all hover:-translate-y-px"
                            style="background:linear-gradient(135deg,#1a237e,#3949ab)">
                        <span v-if="form.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                        <span v-else class="material-symbols-outlined text-[16px]">vpn_key</span>
                        {{ form.processing ? 'Registering…' : 'Register provider' }}
                        <span class="ml-1 text-[9px] font-black uppercase tracking-widest rounded bg-white/20 px-1.5 py-0.5">requires 2FA</span>
                    </button>
                </form>
            </SlidePanel>

    </div>
</template>
