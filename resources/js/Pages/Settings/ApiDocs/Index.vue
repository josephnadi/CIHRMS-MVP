<script setup>
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    activeModule:   String,
    version:        { type: String, default: 'v1' },
    baseUrl:        String,
    openapiYamlUrl: String,
    openapiJsonUrl: String,
    interactiveUrl: String,
    tokenCount:     { type: Number, default: 0 },
    webhookCount:   { type: Number, default: 0 },
});

// ── Endpoint catalogue ─────────────────────────────────────────────
// Curated reflection of the live `routes/api.php` v1 routes so the
// landing page reads like a real API reference, not just a link list.
const ENDPOINTS = [
    {
        group:  'Identity',
        icon:   'badge',
        accent: '#1a237e',
        scope:  'identity:read',
        routes: [
            { verb: 'GET', path: '/me', desc: 'Currently-authenticated employee profile' },
        ],
    },
    {
        group:  'Employees',
        icon:   'group',
        accent: '#3949ab',
        scope:  'employees:read',
        routes: [
            { verb: 'GET', path: '/employees',             desc: 'List employees, paginated, filterable by department' },
            { verb: 'GET', path: '/employees/{employee}',  desc: 'Single employee with full profile + contract details' },
        ],
    },
    {
        group:  'Payroll',
        icon:   'payments',
        accent: '#0e8a93',
        scope:  'payroll:read',
        routes: [
            { verb: 'GET', path: '/payroll/runs',                                  desc: 'Payroll run register' },
            { verb: 'GET', path: '/payroll/runs/{run}',                            desc: 'Run header + run-level totals' },
            { verb: 'GET', path: '/payroll/runs/{run}/returns',                    desc: 'Statutory return artefacts for a run' },
            { verb: 'GET', path: '/payroll/runs/{run}/returns/{return}/download',  desc: 'Signed statutory return download', scope: 'statutory:export' },
        ],
    },
    {
        group:  'Attendance',
        icon:   'badge',
        accent: '#12d9e3',
        scope:  'attendance:read',
        routes: [
            { verb: 'GET', path: '/attendance/summaries', desc: 'Per-employee monthly attendance digest' },
        ],
    },
    {
        group:  'Webhooks',
        icon:   'webhook',
        accent: '#d912e3',
        scope:  'webhooks:manage',
        routes: [
            { verb: 'GET',    path: '/webhook-subscriptions',                  desc: 'Active partner subscriptions' },
            { verb: 'POST',   path: '/webhook-subscriptions',                  desc: 'Register a new partner endpoint' },
            { verb: 'DELETE', path: '/webhook-subscriptions/{subscription}',   desc: 'Tear down a partner endpoint' },
        ],
    },
    {
        group:  'Analytics',
        icon:   'analytics',
        accent: '#b88a08',
        scope:  '—',
        routes: [
            { verb: 'POST', path: '/analytics-events',    desc: 'Stream a single front-end analytics event' },
            { verb: 'POST', path: '/ai/employee-summary', desc: 'Generate an AI-assisted profile narrative' },
        ],
    },
    {
        group:  'Health & spec',
        icon:   'monitor_heart',
        accent: '#16a34a',
        scope:  'public',
        routes: [
            { verb: 'GET', path: '/health',       desc: 'Liveness probe — public, throttled' },
            { verb: 'GET', path: '/openapi.yaml', desc: 'Machine-readable spec (YAML)'      },
            { verb: 'GET', path: '/openapi.json', desc: 'Machine-readable spec (JSON)'      },
        ],
    },
];

// ── Verb → tint ────────────────────────────────────────────────────
const verbMeta = (v) => ({
    GET:    { tone: '#0e8a93', bg: 'rgba(14,138,147,0.10)' },
    POST:   { tone: '#16a34a', bg: 'rgba(22,163,74,0.10)' },
    PATCH:  { tone: '#d97706', bg: 'rgba(217,119,6,0.10)' },
    PUT:    { tone: '#d97706', bg: 'rgba(217,119,6,0.10)' },
    DELETE: { tone: '#dc2626', bg: 'rgba(220,38,38,0.10)' },
}[v] ?? { tone: '#64748b', bg: 'rgba(100,116,139,0.10)' });

// ── Curl example ───────────────────────────────────────────────────
const curlExample = `curl ${props.baseUrl}/employees \\
  -H "Accept: application/json" \\
  -H "Authorization: Bearer YOUR_TOKEN"`;

// ── Clipboard ──────────────────────────────────────────────────────
const copiedField = ref(null);
const copy = async (text, fieldId) => {
    try {
        await navigator.clipboard.writeText(text);
        copiedField.value = fieldId;
        setTimeout(() => { if (copiedField.value === fieldId) copiedField.value = null; }, 2200);
    } catch (e) { /* clipboard blocked */ }
};

// ── Stoplight embed ────────────────────────────────────────────────
const showEmbed = ref(false);
</script>

<template>
<Head title="API Reference" />
    <div data-page-root="true">
        <Teleport to="#page-header-mount" defer>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">menu_book</span>
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">INTEGRATIONS · DEVELOPER REFERENCE</p>
                    </div>
                    <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">CIHRMS API <span class="font-mono text-secondary/80">{{ version }}</span></h1>
                    <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                        REST-over-JSON · Sanctum bearer tokens · OpenAPI 3.1 spec · signed webhooks.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <a :href="openapiYamlUrl" target="_blank" rel="noopener"
                       class="inline-flex items-center gap-1.5 rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-bold text-on-surface-variant hover:bg-surface-container transition-colors">
                        <span class="material-symbols-outlined text-[15px]">description</span>
                        OpenAPI yaml
                    </a>
                    <a :href="openapiJsonUrl" target="_blank" rel="noopener"
                       class="inline-flex items-center gap-1.5 rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-bold text-on-surface-variant hover:bg-surface-container transition-colors">
                        <span class="material-symbols-outlined text-[15px]">code</span>
                        OpenAPI json
                    </a>
                    <button @click="showEmbed = !showEmbed" type="button"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px hover:shadow-glow active:scale-[0.97]"
                            style="background:linear-gradient(135deg,#1a237e,#3949ab)">
                        <span class="material-symbols-outlined text-[16px]" style="font-variation-settings:'FILL' 1">play_arrow</span>
                        {{ showEmbed ? 'Hide explorer' : 'Open explorer' }}
                    </button>
                </div>
            </div>
        </Teleport>

        <div class="space-y-8">

            <!-- ── Hero band ──────────────────────────────────────────── -->
            <div class="relative overflow-hidden rounded-3xl px-7 py-6 text-white animate-reveal-up"
                 style="background:linear-gradient(135deg,#1a237e 0%,#283593 55%,#3949ab 100%);border:1px solid rgba(255,255,255,0.07);">
                <div class="pointer-events-none absolute -right-12 -top-12 h-64 w-64 rounded-full blur-3xl" style="background:radial-gradient(circle,rgba(18,217,227,0.22),transparent 70%)"></div>
                <div class="pointer-events-none absolute -left-6 bottom-0 h-44 w-44 rounded-full blur-2xl" style="background:rgba(255,215,0,0.10)"></div>

                <div class="absolute inset-x-0 top-0 h-px overflow-hidden">
                    <div class="api-ribbon h-px w-1/3"></div>
                </div>

                <div class="relative flex flex-wrap items-center justify-between gap-8">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-400 api-live"></span>
                            <p class="text-[9px] font-black uppercase tracking-[0.25em]" style="color:rgba(18,217,227,0.85)">API v1 · live · stable</p>
                        </div>
                        <h2 class="text-3xl font-black leading-tight">Base URL</h2>
                        <div class="mt-2 flex items-stretch gap-2 max-w-2xl">
                            <code class="flex-1 font-mono text-[13px] rounded-lg px-3 py-2 break-all"
                                  style="background:rgba(0,0,0,0.30);border:1px solid rgba(255,255,255,0.15);color:#f5f5f5;">{{ baseUrl }}</code>
                            <button @click="copy(baseUrl, 'baseUrl')" type="button"
                                    class="inline-flex items-center gap-1.5 rounded-lg px-3.5 text-[11.5px] font-black uppercase tracking-wider transition-all hover:-translate-y-px"
                                    :style="copiedField === 'baseUrl' ? 'background:#16a34a;color:#fff;' : 'background:rgba(255,255,255,0.10);color:rgba(255,255,255,0.85);border:1px solid rgba(255,255,255,0.18);'">
                                <span class="material-symbols-outlined text-[14px]">{{ copiedField === 'baseUrl' ? 'check' : 'content_copy' }}</span>
                                {{ copiedField === 'baseUrl' ? 'Copied' : 'Copy' }}
                            </button>
                        </div>
                    </div>

                    <div class="flex items-center gap-7 flex-shrink-0">
                        <div v-for="(kpi, i) in [
                            { label: 'Resources',   val: ENDPOINTS.length,                    color: '#12d9e3' },
                            { label: 'Endpoints',   val: ENDPOINTS.reduce((s, g) => s + g.routes.length, 0), color: '#7986cb' },
                            { label: 'Tokens',      val: tokenCount,                          color: '#a7f3d0' },
                            { label: 'Webhooks',    val: webhookCount,                        color: '#ffd700' },
                        ]" :key="kpi.label" class="text-center"
                             :style="`animation:slideUpFade 0.45s ease both;animation-delay:${i*0.06}s`">
                            <p class="text-[28px] font-black leading-none tabular-nums" :style="`color:${kpi.color}`">{{ kpi.val }}</p>
                            <p class="mt-1 text-[9px] font-black uppercase tracking-[0.18em]" style="color:rgba(255,255,255,0.4)">{{ kpi.label }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Quick start ────────────────────────────────────────── -->
            <div class="grid grid-cols-1 lg:grid-cols-5 gap-4">
                <!-- Curl example (3 cols) -->
                <div class="lg:col-span-3 rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
                    <div class="flex items-center justify-between px-5 py-3 border-b border-outline-variant/50 bg-surface-container-low/30">
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-[16px] text-secondary">terminal</span>
                            <span class="text-[11px] font-black uppercase tracking-widest text-on-surface-variant">Quick start</span>
                        </div>
                        <button @click="copy(curlExample, 'curl')" type="button"
                                class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-[10.5px] font-bold text-on-surface-variant hover:bg-surface-container transition-colors">
                            <span class="material-symbols-outlined text-[12px]">{{ copiedField === 'curl' ? 'check' : 'content_copy' }}</span>
                            {{ copiedField === 'curl' ? 'Copied' : 'Copy' }}
                        </button>
                    </div>
                    <pre class="api-code overflow-x-auto px-5 py-4 text-[12.5px] font-mono leading-relaxed">{{ curlExample }}</pre>
                </div>

                <!-- Auth note (2 cols) -->
                <div class="lg:col-span-2 rounded-2xl border border-cyan-200/60 bg-cyan-50/30 dark:bg-cyan-900/10 dark:border-cyan-800/40 p-5">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="flex h-7 w-7 items-center justify-center rounded-lg" style="background:rgba(18,217,227,0.15)">
                            <span class="material-symbols-outlined text-[16px]" style="color:#0e8a93;font-variation-settings:'FILL' 1">key</span>
                        </span>
                        <h3 class="text-[14px] font-black text-primary">Authentication</h3>
                    </div>
                    <p class="text-[12px] text-on-surface leading-relaxed">
                        Every request needs a bearer token from <Link :href="route('api-tokens.index')" class="font-bold text-secondary underline decoration-secondary/40 underline-offset-2 hover:decoration-secondary">API Tokens</Link>.
                    </p>
                    <p class="mt-2 text-[11.5px] text-on-surface-variant leading-relaxed">
                        Tokens are scoped — request only the abilities your integration needs (e.g. <code class="font-mono bg-cyan-100/50 dark:bg-cyan-900/30 px-1 rounded">employees:read</code>). Rate limits per token are enforced at the gateway.
                    </p>
                    <Link :href="route('api-tokens.index')"
                          class="mt-3 inline-flex items-center gap-1 text-[12px] font-black text-secondary hover:underline">
                        Manage tokens
                        <span class="material-symbols-outlined text-[14px]">arrow_forward</span>
                    </Link>
                </div>
            </div>

            <!-- ── Endpoint catalogue ─────────────────────────────────── -->
            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-[16px] font-black text-primary">Endpoint catalogue</h3>
                        <p class="text-[12px] text-on-surface-variant">Resources exposed by API {{ version }}. Click any path to open the interactive explorer.</p>
                    </div>
                    <span class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/60 tabular-nums">
                        {{ ENDPOINTS.length }} groups · {{ ENDPOINTS.reduce((s, g) => s + g.routes.length, 0) }} endpoints
                    </span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                    <div v-for="(group, i) in ENDPOINTS" :key="group.group"
                         class="api-card rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden hover:shadow-md transition-shadow"
                         :style="`animation:slideUpFade 0.4s ease both;animation-delay:${i*0.05}s;border-left:3px solid ${group.accent};`">
                        <!-- Group header -->
                        <div class="flex items-center justify-between px-4 py-3 border-b border-outline-variant/40 bg-surface-container-low/20">
                            <div class="flex items-center gap-2 min-w-0">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg flex-shrink-0"
                                      :style="`background:${group.accent}15`">
                                    <span class="material-symbols-outlined text-[15px]" :style="`color:${group.accent};font-variation-settings:'FILL' 1`">{{ group.icon }}</span>
                                </span>
                                <h4 class="text-[14px] font-black text-primary truncate">{{ group.group }}</h4>
                            </div>
                            <code class="text-[10px] font-mono font-bold tabular-nums rounded px-1.5 py-0.5 flex-shrink-0"
                                  :style="`background:${group.accent}10;color:${group.accent};border:1px solid ${group.accent}33`"
                                  :title="`Scope: ${group.scope}`">
                                {{ group.scope }}
                            </code>
                        </div>

                        <!-- Routes -->
                        <ul class="divide-y divide-outline-variant/30">
                            <li v-for="r in group.routes" :key="r.path + r.verb"
                                class="api-route flex items-start gap-3 px-4 py-2.5 hover:bg-surface-container-low/30 transition-colors">
                                <span class="inline-flex items-center justify-center min-w-[44px] h-5 rounded-md text-[9.5px] font-black tabular-nums tracking-wider flex-shrink-0 mt-0.5"
                                      :style="`background:${verbMeta(r.verb).bg};color:${verbMeta(r.verb).tone}`">
                                    {{ r.verb }}
                                </span>
                                <div class="min-w-0 flex-1">
                                    <code class="block text-[12px] font-mono font-bold text-primary truncate" :title="r.path">{{ r.path }}</code>
                                    <p class="mt-0.5 text-[11px] text-on-surface-variant leading-snug">{{ r.desc }}</p>
                                </div>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- ── Interactive explorer (iframed Stoplight) ───────────── -->
            <div v-if="showEmbed" class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden animate-reveal-up">
                <div class="flex items-center justify-between px-5 py-3 border-b border-outline-variant/50 bg-surface-container-low/30">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-[16px] text-secondary">explore</span>
                        <span class="text-[11px] font-black uppercase tracking-widest text-on-surface-variant">Interactive explorer · Stoplight Elements</span>
                    </div>
                    <a :href="interactiveUrl" target="_blank" rel="noopener"
                       class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-[10.5px] font-bold text-on-surface-variant hover:bg-surface-container transition-colors">
                        <span class="material-symbols-outlined text-[12px]">open_in_new</span>
                        Open full-bleed
                    </a>
                </div>
                <iframe :src="interactiveUrl" class="w-full" style="height:calc(100vh - 320px);min-height:540px;border:0;" loading="lazy" title="API explorer"></iframe>
            </div>

        </div>

    </div>
</template>

<style scoped>
.api-live { animation: apiLive 1.6s ease-in-out infinite; }
@keyframes apiLive {
    0%, 100% { opacity: 1; transform: scale(1); box-shadow: 0 0 0 0 rgba(74, 222, 128, 0.7); }
    50%      { opacity: 0.4; transform: scale(0.7); box-shadow: 0 0 0 6px rgba(74, 222, 128, 0); }
}

.api-ribbon {
    background: linear-gradient(90deg, transparent, rgba(18,217,227,0.9), rgba(255,215,0,0.7), transparent);
    animation: apiRibbon 3.8s linear infinite;
}
@keyframes apiRibbon {
    0%   { transform: translateX(-100%); }
    100% { transform: translateX(400%); }
}

/* Code block — slight terminal flavour, monospace, dark surface */
.api-code {
    background: rgba(13, 20, 82, 0.04);
    color: rgb(var(--ct-on-surface));
}
.dark .api-code {
    background: rgba(255, 255, 255, 0.04);
}

.api-card:hover {
    transform: translateY(-1px);
    transition: transform 0.18s cubic-bezier(0.22, 1, 0.36, 1);
}

@media (prefers-reduced-motion: reduce) {
    .api-live, .api-ribbon, .api-card { animation: none !important; transition: none !important; }
}
</style>
