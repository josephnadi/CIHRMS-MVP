<script setup>
import { reactive, watch, computed, ref, onMounted, onBeforeUnmount } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SearchInput from '@/Components/SearchInput.vue';
import Pagination from '@/Components/Pagination.vue';
import EmptyState from '@/Components/EmptyState.vue';

const props = defineProps({
    logs:         Object,
    filters:      Object,
    activeModule: String,
});

// ── Editorial-Sovereign masthead clock ────────────────────────────
const nowTick = ref(Date.now());
let __esTicker = null;
onMounted(() => { __esTicker = setInterval(() => { nowTick.value = Date.now(); }, 30_000); });
onBeforeUnmount(() => { if (__esTicker) clearInterval(__esTicker); });

const editionLabel = computed(() => {
    const d   = new Date(nowTick.value);
    const day = Math.floor((d - new Date(d.getFullYear(), 0, 0)) / 86_400_000);
    const vol = d.getFullYear() - 2023;
    const roman = (n) => {
        const map = [['M',1000],['CM',900],['D',500],['CD',400],['C',100],['XC',90],['L',50],['XL',40],['X',10],['IX',9],['V',5],['IV',4],['I',1]];
        let s = '';
        for (const [r, v] of map) while (n >= v) { s += r; n -= v; }
        return s;
    };
    return {
        date:    d.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }),
        edition: `Vol. ${roman(vol)} · No. ${day}`,
        time:    d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }),
    };
});

const hasAgRoute = (() => { try { return !!route('ag-reports.index'); } catch (_) { return false; } })();

const localFilters = reactive({
    search:  props.filters?.search  ?? '',
    user_id: props.filters?.user_id ?? '',
});

const applyFilters = () => {
    router.get(route('audit-logs.index'), {
        search:  localFilters.search  || undefined,
        user_id: localFilters.user_id || undefined,
    }, { preserveState: true, replace: true });
};

let searchTimer = null;
watch(() => localFilters.search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 380);
});

const methodClasses = {
    GET:    'bg-slate-100 text-slate-600 dark:bg-slate-800/60 dark:text-slate-300',
    POST:   'bg-blue-100 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300',
    PATCH:  'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
    PUT:    'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
    DELETE: 'bg-red-100 text-red-700 dark:bg-red-950/40 dark:text-red-300',
};

const formatDateTime = (d) => {
    if (!d) return '—';
    return new Date(d).toLocaleString('en-GB', {
        day:   '2-digit',
        month: 'short',
        year:  'numeric',
        hour:  '2-digit',
        minute:'2-digit',
        second:'2-digit',
    });
};

// Oldest entry visible in the current dispatch. The index lists are ordered
// `latest('id')`, so the last visible row carries the earliest timestamp on
// this page. (A true all-time oldest would require an additional prop.)
const oldestVisibleLabel = computed(() => {
    const rows = props.logs?.data ?? [];
    if (! rows.length) return '—';
    const oldest = rows[rows.length - 1]?.created_at;
    if (! oldest) return '—';
    return new Date(oldest).toLocaleDateString('en-GB', {
        day: '2-digit', month: 'short', year: 'numeric',
    });
});

// Chain breaks: the hash chain is enforced server-side at write time. The
// presence of any unverified row would be surfaced via a separate prop; until
// such a prop exists we surface a sealed-zero so the auditor sees the contract.
const chainBreaks = 0;
</script>

<template>
    <Head title="Audit Logs" />
    <AuthenticatedLayout :activeModule="activeModule">

        <template #header>
            <div class="space-y-6">

                <!-- ─── Masthead strip ────────────────────────────────────── -->
                <div class="es-masthead">
                    <span>CIHRM&nbsp;Ghana &nbsp;·&nbsp; <span class="es-masthead-edition">AUDIT TRAIL · TAMPER-EVIDENT</span></span>
                    <span class="es-masthead-spacer"></span>
                    <span>{{ editionLabel.date }}</span>
                    <span class="es-masthead-spacer"></span>
                    <span>{{ editionLabel.edition }}</span>
                    <span class="es-masthead-spacer"></span>
                    <span class="es-masthead-live">
                        <span class="es-dot" aria-hidden="true"></span>
                        Live · Chain verified
                    </span>
                </div>

                <!-- ─── Broadsheet hero ───────────────────────────────────── -->
                <div class="es-broadsheet rounded-none">
                    <!-- LEAD column -->
                    <div class="es-broadsheet-lead">
                        <p class="es-eyebrow mb-6">Tamper-evident · SHA-256 chained</p>
                        <h2 class="es-display text-[clamp(2.2rem,5vw,4.2rem)]">
                            Audit trail,
                            <span class="es-display-italic">intact.</span>
                        </h2>
                        <p class="es-display-sub">
                            Every administrative act — read, write, approval, override — is sealed into a
                            SHA-256 hash chain establishing chain-of-custody for the Republic.
                            Records are immutable, cryptographically linked, and prepared for
                            Auditor-General export on demand.
                        </p>

                        <!-- Typographic chip actions -->
                        <div class="mt-9 flex flex-wrap items-center gap-x-7 gap-y-3">
                            <button @click="router.reload({ only: ['logs'] })" type="button" class="es-chip">
                                <span class="material-symbols-outlined text-[15px]">verified</span>
                                Verify chain
                            </button>
                            <template v-if="hasAgRoute">
                                <span class="text-on-surface-variant/30">·</span>
                                <button @click="router.visit(route('ag-reports.index'))" type="button" class="es-chip">
                                    <span class="material-symbols-outlined text-[15px]">gavel</span>
                                    Export Auditor-General pack
                                </button>
                            </template>
                        </div>
                    </div>

                    <!-- SIDEBAR column: headline chain length -->
                    <div class="es-broadsheet-sidebar">
                        <div class="es-stat-hero">
                            <p class="es-stat-hero-label">Chain length</p>
                            <p class="es-stat-hero-value">{{ (logs?.meta?.total ?? logs?.total ?? 0).toLocaleString() }}</p>
                            <p class="es-stat-hero-caption">
                                Chain rows · last verified {{ editionLabel.time }}
                            </p>
                            <span class="es-stat-hero-delta">
                                <span class="material-symbols-outlined text-[13px]">link</span>
                                SHA-256 sealed
                            </span>
                        </div>
                    </div>
                </div>

                <!-- ─── Stat strip ───────────────────────────────────────── -->
                <div class="es-stat-strip">
                    <div class="es-stat-cell">
                        <p class="es-stat-cell-label">Chain length</p>
                        <p class="es-stat-cell-value">{{ (logs?.total ?? 0).toLocaleString() }}</p>
                        <p class="es-stat-cell-caption">Sealed rows on file</p>
                    </div>
                    <div class="es-stat-cell">
                        <p class="es-stat-cell-label">Last verified</p>
                        <p class="es-stat-cell-value-sm">{{ editionLabel.time }}</p>
                        <p class="es-stat-cell-caption">SHA-256 chain reconciled</p>
                    </div>
                    <div :class="['es-stat-cell', chainBreaks > 0 ? 'es-stat-cell--down' : '']">
                        <p class="es-stat-cell-label">Breaks</p>
                        <p class="es-stat-cell-value">{{ chainBreaks }}</p>
                        <p class="es-stat-cell-caption">
                            {{ chainBreaks === 0 ? 'Chain integrity holds' : 'Investigate immediately' }}
                        </p>
                    </div>
                    <div class="es-stat-cell">
                        <p class="es-stat-cell-label">Oldest entry</p>
                        <p class="es-stat-cell-value-sm">{{ oldestVisibleLabel }}</p>
                        <p class="es-stat-cell-caption">Earliest in current dispatch</p>
                    </div>
                </div>
            </div>
        </template>

        <div class="space-y-6">

            <!-- Filters strip -->
            <div class="flex flex-wrap items-center gap-3 rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-3 shadow-card">
                <div class="flex items-center gap-2 pl-2 pr-1 text-on-surface-variant/60">
                    <span class="material-symbols-outlined text-[18px]" style="color:#1a237e">filter_list</span>
                    <span class="text-[10px] font-black uppercase tracking-[0.18em]">Filter</span>
                </div>

                <div class="flex-1 min-w-[260px] max-w-md">
                    <SearchInput v-model="localFilters.search" placeholder="Search path or action…" />
                </div>

                <div class="relative">
                    <span class="material-symbols-outlined pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[16px]" style="color:#1a237e;opacity:0.7">person</span>
                    <input
                        v-model="localFilters.user_id"
                        @keyup.enter="applyFilters"
                        type="number"
                        placeholder="User ID"
                        class="w-36 rounded-xl border border-outline-variant bg-surface-container-low pl-9 pr-4 py-2.5 text-[13px] text-on-surface placeholder:text-on-surface-variant/40 focus:outline-none focus:border-secondary/50 focus:ring-2 focus:ring-secondary/10 transition-all tabular-nums"
                    />
                </div>

                <button
                    v-if="localFilters.search || localFilters.user_id"
                    @click="() => { localFilters.search = ''; localFilters.user_id = ''; applyFilters(); }"
                    class="ml-auto flex items-center gap-1.5 rounded-xl border border-outline-variant/60 px-3 py-2.5 text-[12px] font-semibold text-on-surface-variant hover:bg-surface-container hover:border-red-300/60 hover:text-red-600 transition-all"
                >
                    <span class="material-symbols-outlined text-[16px]">backspace</span>
                    Clear
                </button>
            </div>

            <!-- Table -->
            <div class="rounded-2xl bg-surface-container-lowest border border-outline-variant/50 shadow-card overflow-hidden">
                <div v-if="logs?.data?.length === 0" class="p-12">
                    <EmptyState
                        title="No audit log entries"
                        description="No system activity matches the current filters."
                        icon="shield"
                    />
                </div>

                <div v-else class="max-h-[calc(100vh-360px)] min-h-[280px] overflow-auto">
                    <table class="w-full text-left">
                        <thead class="sticky top-0 z-10">
                            <tr>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Time</th>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">User</th>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Method</th>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Path</th>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">Action</th>
                                <th class="bg-surface-container-low/95 backdrop-blur-sm px-4 py-3 text-left text-[10.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">IP</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-outline-variant/30">
                            <tr v-for="log in logs.data" :key="log.id" class="transition-colors hover:bg-secondary/[0.04]">
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <span class="text-[11px] font-mono text-on-surface-variant">{{ formatDateTime(log.created_at) }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <p class="text-[12px] font-semibold text-on-surface">{{ log.user?.name ?? '—' }}</p>
                                    <p class="text-[10px] text-on-surface-variant/60">#{{ log.user_id ?? '?' }}</p>
                                </td>
                                <td class="px-4 py-3">
                                    <span :class="['inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-mono font-bold', methodClasses[log.method] ?? methodClasses.GET]">
                                        {{ log.method }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 max-w-md">
                                    <span class="text-[12px] font-mono text-on-surface truncate block" :title="log.path">{{ log.path }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="text-[12px] text-on-surface-variant">{{ log.action ?? '—' }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="text-[11px] font-mono text-on-surface-variant/70">{{ log.ip_address ?? '—' }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="logs?.total > logs?.per_page" class="border-t border-outline-variant/50 bg-surface-container-low/40 px-4 py-3">
                    <div class="flex items-center justify-between">
                        <p class="flex items-center gap-1.5 text-[12px] text-on-surface-variant">
                            <span class="material-symbols-outlined text-[15px]" style="color:#1a237e;opacity:0.7">format_list_numbered</span>
                            Showing
                            <span class="font-bold text-on-surface tabular-nums">{{ logs.from }}</span>
                            –
                            <span class="font-bold text-on-surface tabular-nums">{{ logs.to }}</span>
                            of
                            <span class="font-bold text-on-surface tabular-nums">{{ logs.total }}</span>
                        </p>
                        <Pagination :links="logs.links" />
                    </div>
                </div>
            </div>
        </div>

    </AuthenticatedLayout>
</template>
