<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    integrations:  Array,
    recentEvents:  Array,
    capabilityMap: Object,
    featureFlags:  Object,
    activeModule:  String,
});

const filter = ref('all');

const filtered = computed(() => {
    if (filter.value === 'all') return props.integrations;
    if (filter.value === 'connected') return props.integrations.filter(i => i.is_enabled);
    if (filter.value === 'available') return props.integrations.filter(i => !i.is_enabled);
    return props.integrations.filter(i => i.capability === filter.value);
});

const stats = computed(() => ({
    total:     props.integrations.length,
    connected: props.integrations.filter(i => i.is_enabled).length,
    pending:   props.integrations.filter(i => !i.driver_ready).length,
    failures:  props.recentEvents.filter(e => e.status === 'failed').length,
}));

const capabilityIcon = (capability) => ({
    crm:         'badge',
    files:       'cloud',
    spreadsheet: 'table_chart',
    messaging:   'forum',
    calendar:    'event',
    esign:       'draw',
    identity:    'verified_user',
}[capability] ?? 'extension');

// Capability color map — disciplined Sovereign Precision.
// crm/messaging/calendar/esign keep semantic colors; files = brand cyan
// (was generic cyan-blue #0891b2); identity = navy (was slate).
const capabilityColor = (capability) => ({
    crm:         '#205295',  // cobalt
    files:       '#12d9e3',  // brand cyan
    spreadsheet: '#059669',  // green
    messaging:   '#205295',  // cobalt
    calendar:    '#d97706',  // amber
    esign:       '#dc2626',  // red
    identity:    '#0a2647',  // navy (identity verification = institutional)
}[capability] ?? '#6b7280');

const connectForm = useForm({});
const connect = (provider) => {
    connectForm.post(route('admin.integrations.connect', provider), {
        preserveScroll: true,
    });
};

const disconnect = (provider) => {
    if (!confirm(`Disconnect ${provider}? Stored tokens will be revoked.`)) return;
    router.delete(route('admin.integrations.disconnect', provider), { preserveScroll: true });
};

const filters = [
    { key: 'all',         label: 'All' },
    { key: 'connected',   label: 'Connected' },
    { key: 'available',   label: 'Available' },
    { key: 'crm',         label: 'CRM' },
    { key: 'files',       label: 'Files' },
    { key: 'messaging',   label: 'Messaging' },
    { key: 'spreadsheet', label: 'Sheets' },
    { key: 'calendar',    label: 'Calendar' },
    { key: 'esign',       label: 'e-Sign' },
];

const fmtDate = (s) => s ? new Date(s).toLocaleString() : 'â€”';

const statusPill = (status) => {
    const map = {
        sent:     { bg: 'rgba(5,150,105,0.10)',  fg: '#059669', label: 'Sent' },
        received: { bg: 'rgba(32,82,149,0.10)',   fg: '#205295', label: 'Received' },
        queued:   { bg: 'rgba(217,119,6,0.10)',  fg: '#d97706', label: 'Queued' },
        failed:   { bg: 'rgba(220,38,38,0.10)',  fg: '#dc2626', label: 'Failed' },
    };
    return map[status] ?? { bg: 'rgba(107,114,128,0.10)', fg: '#6b7280', label: status };
};
</script>

<template>
    <Head title="Integrations" />
    <AuthenticatedLayout :activeModule="activeModule">

        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2 text-[12px] font-semibold text-on-surface-variant/70 mb-1">
                        <span>Admin</span>
                        <span class="material-symbols-outlined text-[14px]">chevron_right</span>
                        <span>Integrations</span>
                    </div>
                    <h2 class="text-[1.6rem] font-black tracking-tight text-on-surface leading-tight">Integration Marketplace</h2>
                    <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                        Connect CIHRMS to your CRM, file storage, calendars and messaging platforms.
                    </p>
                </div>
            </div>
        </template>

        <div class="space-y-6">

            <!-- Stat strip -->
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-2xl border border-outline-variant/40 bg-surface-container-lowest p-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl" style="background:rgba(32,82,149,0.10)">
                            <span class="material-symbols-outlined text-[20px]" style="color:#205295">extension</span>
                        </div>
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-[0.12em] text-on-surface-variant/60">Total</p>
                            <p class="text-[22px] font-black text-on-surface leading-none">{{ stats.total }}</p>
                        </div>
                    </div>
                </div>
                <div class="rounded-2xl border border-outline-variant/40 bg-surface-container-lowest p-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl" style="background:rgba(5,150,105,0.10)">
                            <span class="material-symbols-outlined text-[20px]" style="color:#059669">link</span>
                        </div>
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-[0.12em] text-on-surface-variant/60">Connected</p>
                            <p class="text-[22px] font-black text-on-surface leading-none">{{ stats.connected }}</p>
                        </div>
                    </div>
                </div>
                <div class="rounded-2xl border border-outline-variant/40 bg-surface-container-lowest p-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl" style="background:rgba(217,119,6,0.10)">
                            <span class="material-symbols-outlined text-[20px]" style="color:#d97706">construction</span>
                        </div>
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-[0.12em] text-on-surface-variant/60">Awaiting Driver</p>
                            <p class="text-[22px] font-black text-on-surface leading-none">{{ stats.pending }}</p>
                        </div>
                    </div>
                </div>
                <div class="rounded-2xl border border-outline-variant/40 bg-surface-container-lowest p-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl" style="background:rgba(220,38,38,0.10)">
                            <span class="material-symbols-outlined text-[20px]" style="color:#dc2626">warning</span>
                        </div>
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-[0.12em] text-on-surface-variant/60">Recent Failures</p>
                            <p class="text-[22px] font-black text-on-surface leading-none">{{ stats.failures }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter chips -->
            <div class="flex flex-wrap items-center gap-2">
                <button
                    v-for="f in filters"
                    :key="f.key"
                    @click="filter = f.key"
                    class="rounded-full border px-3.5 py-1.5 text-[12px] font-bold transition-all"
                    :class="filter === f.key
                        ? 'border-secondary/30 bg-secondary/10 text-secondary'
                        : 'border-outline-variant/50 text-on-surface-variant hover:bg-surface-container-low'"
                >
                    {{ f.label }}
                </button>
            </div>

            <!-- Marketplace grid -->
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <article
                    v-for="i in filtered"
                    :key="i.provider"
                    class="group relative overflow-hidden rounded-2xl border border-outline-variant/40 bg-surface-container-lowest p-5 transition-all hover:-translate-y-0.5 hover:shadow-md"
                >
                    <!-- Capability ribbon -->
                    <div class="absolute top-0 right-0 flex items-center gap-1 rounded-bl-xl px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.14em] text-white"
                         :style="`background:${capabilityColor(i.capability)}`">
                        <span class="material-symbols-outlined text-[13px]">{{ capabilityIcon(i.capability) }}</span>
                        {{ i.capability }}
                    </div>

                    <div class="mb-4 flex items-start gap-3">
                        <div class="flex h-12 w-12 items-center justify-center rounded-xl border border-outline-variant/40 bg-white"
                             :style="`box-shadow: 0 0 0 1px ${capabilityColor(i.capability)}1a inset`">
                            <img v-if="i.logo" :src="i.logo" :alt="i.display_name" class="max-h-8 max-w-8 object-contain" />
                            <span v-else class="material-symbols-outlined text-[22px]" :style="`color:${capabilityColor(i.capability)}`">
                                {{ capabilityIcon(i.capability) }}
                            </span>
                        </div>
                        <div class="min-w-0 flex-1 pr-14">
                            <h3 class="truncate text-[14px] font-black tracking-tight text-on-surface">{{ i.display_name }}</h3>
                            <p class="mt-0.5 truncate text-[11px] font-medium text-on-surface-variant/70">{{ i.provider }}</p>
                        </div>
                    </div>

                    <!-- Status row -->
                    <div class="mb-4 grid grid-cols-2 gap-2 text-[11px] font-semibold">
                        <div class="rounded-lg bg-surface-container-low/60 px-2.5 py-2">
                            <p class="text-on-surface-variant/60 uppercase tracking-[0.10em] text-[9.5px]">Status</p>
                            <p class="mt-0.5 flex items-center gap-1" :class="i.is_enabled ? 'text-emerald-600' : 'text-on-surface-variant'">
                                <span class="h-1.5 w-1.5 rounded-full" :class="i.is_enabled ? 'bg-emerald-500' : 'bg-on-surface-variant/40'"></span>
                                {{ i.is_enabled ? 'Connected' : 'Not connected' }}
                            </p>
                        </div>
                        <div class="rounded-lg bg-surface-container-low/60 px-2.5 py-2">
                            <p class="text-on-surface-variant/60 uppercase tracking-[0.10em] text-[9.5px]">Driver</p>
                            <p class="mt-0.5" :class="i.driver_ready ? 'text-on-surface' : 'text-amber-600'">
                                {{ i.driver_ready ? 'Ready' : 'Pending build' }}
                            </p>
                        </div>
                    </div>

                    <p v-if="i.connected_at" class="mb-3 text-[11px] text-on-surface-variant/60">
                        Connected {{ fmtDate(i.connected_at) }}
                    </p>

                    <!-- Actions -->
                    <div class="flex items-center gap-2">
                        <button
                            v-if="!i.is_enabled"
                            @click="connect(i.provider)"
                            :disabled="!i.configured && !i.driver_ready"
                            class="flex-1 rounded-xl px-3 py-2 text-[12px] font-bold text-white transition-all disabled:cursor-not-allowed disabled:opacity-50"
                            :style="`background:linear-gradient(135deg, ${capabilityColor(i.capability)}, ${capabilityColor(i.capability)}dd)`"
                        >
                            <span class="material-symbols-outlined text-[14px] mr-1 align-middle">link</span>
                            Connect
                        </button>
                        <button
                            v-else
                            @click="disconnect(i.provider)"
                            class="flex-1 rounded-xl border border-outline-variant/60 px-3 py-2 text-[12px] font-bold text-on-surface-variant transition-all hover:border-red-500/40 hover:bg-red-500/5 hover:text-red-600"
                        >
                            <span class="material-symbols-outlined text-[14px] mr-1 align-middle">link_off</span>
                            Disconnect
                        </button>
                    </div>

                    <p v-if="!i.configured && !i.is_enabled" class="mt-3 text-[10.5px] text-amber-600/90 leading-snug">
                        Add this provider's credentials to <code class="font-mono text-[10px] bg-amber-500/10 px-1 rounded">.env</code> before connecting.
                    </p>
                </article>
            </div>

            <!-- Capability routing summary -->
            <section class="rounded-2xl border border-outline-variant/40 bg-surface-container-lowest p-5">
                <h3 class="text-[14px] font-black tracking-tight text-on-surface">Active Capability Routing</h3>
                <p class="mt-1 text-[12px] text-on-surface-variant">
                    Each capability in the app dispatches to the configured provider. Swap via env vars (<code class="font-mono text-[11px] bg-surface-container-low/60 px-1 rounded">INT_CRM_DRIVER</code>, etc.).
                </p>
                <div class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="(provider, capability) in capabilityMap" :key="capability"
                         class="flex items-center justify-between rounded-xl border border-outline-variant/40 bg-surface-container-low/40 px-3 py-2.5">
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-[16px]" :style="`color:${capabilityColor(capability)}`">
                                {{ capabilityIcon(capability) }}
                            </span>
                            <span class="text-[12px] font-bold uppercase tracking-[0.10em] text-on-surface-variant/70">{{ capability }}</span>
                        </div>
                        <span class="text-[12px] font-mono text-on-surface">{{ provider ?? 'â€”' }}</span>
                    </div>
                </div>
            </section>

            <!-- Feature flags -->
            <section class="rounded-2xl border border-outline-variant/40 bg-surface-container-lowest p-5">
                <h3 class="text-[14px] font-black tracking-tight text-on-surface">Feature Flags</h3>
                <p class="mt-1 text-[12px] text-on-surface-variant">
                    Gate new integration-driven flows safely from <code class="font-mono text-[11px] bg-surface-container-low/60 px-1 rounded">.env</code>.
                </p>
                <div class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="(enabled, name) in featureFlags" :key="name"
                         class="flex items-center justify-between rounded-xl border border-outline-variant/40 bg-surface-container-low/40 px-3 py-2.5">
                        <span class="text-[12px] font-mono text-on-surface">{{ name }}</span>
                        <span class="rounded-full px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.14em]"
                              :class="enabled ? 'bg-emerald-500/15 text-emerald-600' : 'bg-on-surface-variant/10 text-on-surface-variant/70'">
                            {{ enabled ? 'On' : 'Off' }}
                        </span>
                    </div>
                </div>
            </section>

            <!-- Recent events -->
            <section class="rounded-2xl border border-outline-variant/40 bg-surface-container-lowest overflow-hidden">
                <div class="flex items-center justify-between border-b border-outline-variant/40 px-5 py-4">
                    <div>
                        <h3 class="text-[14px] font-black tracking-tight text-on-surface">Recent Integration Events</h3>
                        <p class="text-[12px] text-on-surface-variant">Last 20 outbound + inbound calls.</p>
                    </div>
                    <span class="rounded-full bg-surface-container-low/60 px-2.5 py-1 text-[11px] font-bold text-on-surface-variant">{{ recentEvents.length }} events</span>
                </div>
                <div v-if="recentEvents.length" class="divide-y divide-outline-variant/30">
                    <div v-for="e in recentEvents" :key="e.id"
                         class="flex items-start gap-3 px-5 py-3 hover:bg-surface-container-low/40 transition-colors">
                        <span class="material-symbols-outlined text-[18px] mt-0.5"
                              :style="`color:${e.direction === 'outbound' ? '#205295' : '#205295'}`">
                            {{ e.direction === 'outbound' ? 'arrow_outward' : 'arrow_downward' }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="font-mono text-[12px] font-bold text-on-surface">{{ e.event_type }}</span>
                                <span class="text-[11px] text-on-surface-variant/70">Â·</span>
                                <span class="text-[11px] text-on-surface-variant">{{ e.integration?.display_name ?? 'â€”' }}</span>
                            </div>
                            <p v-if="e.error" class="mt-0.5 text-[11px] text-red-600 line-clamp-1">{{ e.error }}</p>
                        </div>
                        <div class="flex flex-col items-end gap-1">
                            <span class="rounded-full px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.10em]"
                                  :style="`background:${statusPill(e.status).bg};color:${statusPill(e.status).fg}`">
                                {{ statusPill(e.status).label }}
                            </span>
                            <span class="text-[10.5px] text-on-surface-variant/60">{{ fmtDate(e.created_at) }}</span>
                        </div>
                    </div>
                </div>
                <div v-else class="px-5 py-12 text-center">
                    <span class="material-symbols-outlined text-[40px] text-on-surface-variant/30">inbox</span>
                    <p class="mt-2 text-[13px] font-semibold text-on-surface-variant">No integration events yet.</p>
                    <p class="text-[12px] text-on-surface-variant/70">Connect a provider above to see traffic appear here.</p>
                </div>
            </section>

        </div>
    </AuthenticatedLayout>
</template>
