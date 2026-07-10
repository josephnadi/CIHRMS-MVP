<script setup>
import { computed, ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Pagination from '@/Components/Pagination.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    subscriptions:    Object,
    activeModule:     String,
    flash_secret:     String,
    available_events: Array,
});

// ── Normalised rows ────────────────────────────────────────────────
const subRows = computed(() => props.subscriptions?.data ?? []);

// ── Stats ──────────────────────────────────────────────────────────
const stats = computed(() => {
    const all     = subRows.value;
    const active  = all.filter(s => s.is_active);
    const failing = all.filter(s => Number(s.failure_count) > 0);
    const recent24h = all.filter(s => s.last_delivery_at && (Date.now() - new Date(s.last_delivery_at).getTime()) < 86_400_000);
    const totalFailures = all.reduce((sum, s) => sum + Number(s.failure_count ?? 0), 0);

    return {
        total:           props.subscriptions?.meta?.total ?? all.length,
        active:          active.length,
        paused:          all.length - active.length,
        failing:         failing.length,
        recent24h:       recent24h.length,
        totalFailures,
    };
});

// ── Event → palette tone ───────────────────────────────────────────
// payroll = brand blue (money flow), identity = cyan (verification),
// loan = magenta (HR-people), whistleblower/privacy = gold (5%),
// wildcard = red (dangerous).
const eventMeta = (e) => {
    if (e === '*')                  return { color: '#dc2626', icon: 'all_inclusive' };
    if (e.startsWith('payroll'))    return { color: '#1a237e', icon: 'payments' };
    if (e.startsWith('identity'))   return { color: '#0e8a93', icon: 'verified_user' };
    if (e.startsWith('loan'))       return { color: '#d912e3', icon: 'savings' };
    if (e.startsWith('offboarding')) return { color: '#7986cb', icon: 'logout' };
    if (e.startsWith('whistleblower') || e.startsWith('data_subject')) return { color: '#b88a08', icon: 'shield_lock' };
    return                                 { color: '#64748b', icon: 'circle' };
};

// ── Health score per subscription ──────────────────────────────────
// Synthesised from failure_count + last_delivery_at. Drives the per-row
// stripe at the leading edge of each row.
const healthFor = (s) => {
    if (!s.is_active) return { tone: '#64748b', label: 'paused' };
    const fails = Number(s.failure_count ?? 0);
    if (fails === 0)  return { tone: '#16a34a', label: 'healthy' };
    if (fails < 3)    return { tone: '#d97706', label: 'wobbling' };
    if (fails < 10)   return { tone: '#ea580c', label: 'degraded' };
    return                   { tone: '#dc2626', label: 'failing' };
};

// ── Form ───────────────────────────────────────────────────────────
const showPanel = ref(false);
const copied    = ref(false);

const form = useForm({
    partner_name:      '',
    callback_url:      '',
    subscribed_events: ['*'],
});

const submit = () => form.post(route('webhooks.store'), {
    preserveScroll: true,
    onSuccess: () => {
        showPanel.value = false;
        form.reset();
        form.subscribed_events = ['*'];
    },
});

const toggleActive = (s) => router.patch(route('webhooks.update', s.id), {
    is_active: !s.is_active,
    subscribed_events: s.subscribed_events,
}, { preserveScroll: true });

const remove = (id) => {
    if (! window.confirm('Delete this webhook subscription? The partner will stop receiving events immediately.')) return;
    router.delete(route('webhooks.destroy', id), { preserveScroll: true });
};

const copySecret = async () => {
    try {
        await navigator.clipboard.writeText(props.flash_secret);
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 2500);
    } catch (e) { /* clipboard blocked */ }
};

const toggleEvent = (e) => {
    const i = form.subscribed_events.indexOf(e);
    if (i === -1) form.subscribed_events.push(e);
    else          form.subscribed_events.splice(i, 1);
};

const closePanel = () => {
    showPanel.value = false;
    form.reset();
    form.subscribed_events = ['*'];
};

// ── Helpers ────────────────────────────────────────────────────────
const fmtDateTime = (d) => d ? new Date(d).toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';
const fmtRelative = (d) => {
    if (!d) return 'never';
    const diff = Math.floor((Date.now() - new Date(d).getTime()) / 60_000);
    if (diff < 1)    return 'just now';
    if (diff < 60)   return `${diff}m ago`;
    if (diff < 1440) return `${Math.floor(diff / 60)}h ago`;
    return `${Math.floor(diff / 1440)}d ago`;
};
const hostOf = (url) => {
    try { return new URL(url).host; } catch (e) { return url; }
};
</script>

<template>
<Head title="Webhook Subscriptions" />
    <div data-page-root="true">
        <Teleport to="#page-header-mount" defer>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">webhook</span>
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">INTEGRATIONS · EVENT BUS</p>
                    </div>
                    <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Webhook Subscriptions</h1>
                    <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                        HMAC-SHA256 signed event delivery to partner systems · GIFMIS, IPPD, Ghana Card.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <div class="flex items-center gap-1.5 rounded-full px-3 py-1.5"
                         :class="stats.failing > 0
                            ? 'bg-rose-50 border border-rose-200 dark:bg-rose-900/20 dark:border-rose-800/40'
                            : 'bg-emerald-50 border border-emerald-200 dark:bg-emerald-900/20 dark:border-emerald-800/40'">
                        <span class="h-1.5 w-1.5 rounded-full wh-pulse"
                              :style="`background:${stats.failing > 0 ? '#dc2626' : '#16a34a'}`"></span>
                        <span class="text-[10px] font-black uppercase tracking-widest tabular-nums"
                              :class="stats.failing > 0 ? 'text-rose-700 dark:text-rose-300' : 'text-emerald-700 dark:text-emerald-300'">
                            {{ stats.failing > 0 ? `${stats.failing} failing` : `${stats.active} healthy` }}
                        </span>
                    </div>
                    <button @click="showPanel = true" type="button"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px hover:shadow-glow active:scale-[0.97]"
                            style="background:linear-gradient(135deg,#1a237e,#3949ab)">
                        <span class="material-symbols-outlined text-[18px]" style="font-variation-settings:'FILL' 1">add_circle</span>
                        Register partner
                    </button>
                </div>
            </div>
        </Teleport>

        <div class="space-y-6">

            <!-- ── One-time signing-secret reveal ─────────────────────── -->
            <Transition
                enter-active-class="transition-all duration-300 ease-out"
                enter-from-class="opacity-0 -translate-y-2 scale-[0.98]"
                enter-to-class="opacity-100 translate-y-0 scale-100"
            >
                <div v-if="flash_secret" class="wh-secret relative overflow-hidden rounded-2xl px-6 py-5 text-white"
                     style="background:linear-gradient(135deg,#1a237e 0%,#283593 55%,#3949ab 100%);border:1px solid rgba(255,215,0,0.45);">
                    <div class="pointer-events-none absolute -right-12 -top-12 h-48 w-48 rounded-full blur-3xl" style="background:radial-gradient(circle,rgba(255,215,0,0.30),transparent 70%)"></div>
                    <div class="absolute inset-x-0 top-0 h-px overflow-hidden">
                        <div class="wh-ribbon h-px w-1/3"></div>
                    </div>

                    <div class="relative flex flex-wrap items-start gap-4">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl flex-shrink-0" style="background:rgba(255,215,0,0.18);border:1px solid rgba(255,215,0,0.42);">
                            <span class="material-symbols-outlined text-[20px]" style="color:#ffd700;font-variation-settings:'FILL' 1">key_vertical</span>
                        </span>
                        <div class="flex-1 min-w-0">
                            <p class="text-[9.5px] font-black uppercase tracking-[0.28em]" style="color:#ffd700">Signing secret · save now</p>
                            <h3 class="mt-0.5 text-[16px] font-black leading-tight">This secret cannot be retrieved again.</h3>
                            <p class="mt-1 text-[12px]" style="color:rgba(255,255,255,0.6)">
                                Partner verifies: <code class="bg-white/10 border border-white/15 rounded px-1.5 py-0.5 font-mono text-[11px]">sha256(timestamp + '.' + body)</code> = <code class="bg-white/10 border border-white/15 rounded px-1.5 py-0.5 font-mono text-[11px]">X-CIHRMS-Signature</code>
                            </p>

                            <div class="mt-3 flex items-stretch gap-2">
                                <code class="flex-1 font-mono text-[12.5px] rounded-lg px-3 py-2 break-all"
                                      style="background:rgba(0,0,0,0.35);border:1px solid rgba(255,215,0,0.32);color:#f5f5f5;">{{ flash_secret }}</code>
                                <button @click="copySecret" type="button"
                                        class="inline-flex items-center gap-1.5 rounded-lg px-3.5 text-[12px] font-black uppercase tracking-wider transition-all hover:-translate-y-px active:scale-[0.96]"
                                        :style="copied ? 'background:#16a34a;color:#fff;' : 'background:#ffd700;color:#0d1452;'">
                                    <span class="material-symbols-outlined text-[15px]">{{ copied ? 'check' : 'content_copy' }}</span>
                                    {{ copied ? 'Copied' : 'Copy' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </Transition>

            <!-- ── KPI tiles ──────────────────────────────────────────── -->
            <div class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-5">
                <div v-for="(card, i) in [
                    { label: 'Subscriptions', val: stats.total,         sub: 'Partner endpoints', cls: 'icon-brand',   icon: 'webhook' },
                    { label: 'Active',        val: stats.active,        sub: 'Receiving events',  cls: 'icon-success', icon: 'sync' },
                    { label: 'Paused',        val: stats.paused,        sub: 'Suspended',         cls: 'icon-magenta', icon: 'pause_circle' },
                    { label: 'Failing',       val: stats.failing,       sub: 'With error count',  cls: 'icon-danger',  icon: 'error', flagship: stats.failing > 0 },
                    { label: 'Hit 24h',       val: stats.recent24h,     sub: 'Last delivery',     cls: 'icon-cyan',    icon: 'bolt' },
                ]" :key="card.label"
                     class="group rounded-2xl border p-4 transition-all hover:shadow-md hover:-translate-y-0.5"
                     :class="card.flagship
                        ? 'border-rose-200/60 bg-rose-50/30 dark:bg-rose-900/10'
                        : 'border-outline-variant/60 bg-surface-container-lowest'"
                     :style="`animation:slideUpFade 0.35s ease both;animation-delay:${i*0.045}s`">
                    <div class="icon-tile" :class="card.cls">
                        <span class="material-symbols-outlined">{{ card.icon }}</span>
                    </div>
                    <p class="mt-3 text-[9.5px] font-black uppercase tracking-[0.14em] text-on-surface-variant/70">{{ card.label }}</p>
                    <p class="mt-1 text-[24px] font-black tabular-nums leading-none"
                       :class="card.flagship ? 'text-rose-600 wh-pulse-text' : 'text-primary'">{{ card.val }}</p>
                    <p class="mt-1 text-[10px] font-semibold text-on-surface-variant">{{ card.sub }}</p>
                </div>
            </div>

            <!-- ── Subscription register ──────────────────────────────── -->
            <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">

                <div class="flex items-center justify-between px-6 py-4 border-b border-outline-variant/50 bg-surface-container-low/30">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-[16px] text-secondary">cable</span>
                        <span class="text-[11px] font-black uppercase tracking-widest text-on-surface-variant">Partner endpoints</span>
                        <span class="rounded-full bg-secondary/10 border border-secondary/20 px-2 py-0.5 text-[10px] font-black text-secondary tabular-nums">{{ stats.total }}</span>
                    </div>
                    <span class="text-[10px] font-mono text-on-surface-variant/60">
                        Failures auto-pause delivery after 10 consecutive errors
                    </span>
                </div>

                <div v-if="subRows.length === 0" class="px-6 py-16">
                    <EmptyState
                        title="No webhook subscriptions"
                        description="Register a partner endpoint to start streaming events to GIFMIS, IPPD, or any external system."
                        icon="webhook"
                    >
                        <template #action>
                            <button @click="showPanel = true" type="button"
                                    class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2 text-[13px] font-black text-white shadow-glow-sm"
                                    style="background:linear-gradient(135deg,#1a237e,#3949ab)">
                                <span class="material-symbols-outlined text-[18px]">add</span>
                                Register first partner
                            </button>
                        </template>
                    </EmptyState>
                </div>

                <div v-else class="divide-y divide-outline-variant/40">
                    <div v-for="(s, i) in subRows" :key="s.id"
                         class="wh-row relative flex items-stretch gap-0 hover:bg-surface-container-low/30 transition-colors"
                         :style="`animation:slideUpFade 0.3s ease both;animation-delay:${Math.min(i, 12)*0.03}s`">
                        <!-- Leading health stripe — at-a-glance status per row -->
                        <span class="w-1 flex-shrink-0"
                              :class="s.is_active && Number(s.failure_count) === 0 ? 'wh-stripe-live' : ''"
                              :style="`background:${healthFor(s).tone}`"></span>

                        <div class="flex-1 grid grid-cols-12 gap-3 items-center px-5 py-4 min-w-0">
                            <!-- Partner + callback -->
                            <div class="col-span-4 min-w-0">
                                <p class="text-[13px] font-bold text-primary leading-tight truncate">{{ s.partner_name }}</p>
                                <p class="mt-0.5 text-[11px] font-mono text-on-surface-variant/70 truncate" :title="s.callback_url">
                                    <span class="material-symbols-outlined text-[11px] align-middle mr-0.5">link</span>
                                    {{ hostOf(s.callback_url) }}
                                </p>
                            </div>

                            <!-- Subscribed events -->
                            <div class="col-span-3 min-w-0">
                                <div class="flex flex-wrap gap-1 items-center">
                                    <code v-for="e in (s.subscribed_events ?? []).slice(0, 3)" :key="e"
                                          class="inline-flex items-center gap-0.5 text-[10px] font-mono font-bold rounded px-1.5 py-0.5"
                                          :style="`background:${eventMeta(e).color}15;color:${eventMeta(e).color};border:1px solid ${eventMeta(e).color}33`"
                                          :title="e">
                                        <span class="material-symbols-outlined text-[10px]">{{ eventMeta(e).icon }}</span>
                                        {{ e === '*' ? '*' : e.split('.')[0] }}
                                    </code>
                                    <span v-if="(s.subscribed_events ?? []).length > 3" class="text-[10px] font-bold text-on-surface-variant/60 tabular-nums">+{{ s.subscribed_events.length - 3 }}</span>
                                </div>
                            </div>

                            <!-- Last delivery -->
                            <div class="col-span-2 min-w-0">
                                <p class="text-[10px] font-black uppercase tracking-wider text-on-surface-variant/55">Last delivery</p>
                                <p class="mt-0.5 text-[12px] font-bold tabular-nums" :title="fmtDateTime(s.last_delivery_at)"
                                   :class="s.last_delivery_at ? 'text-primary' : 'text-on-surface-variant/50 italic'">
                                    {{ fmtRelative(s.last_delivery_at) }}
                                </p>
                            </div>

                            <!-- Failure counter -->
                            <div class="col-span-1 text-center">
                                <p class="text-[10px] font-black uppercase tracking-wider text-on-surface-variant/55">Fails</p>
                                <p class="mt-0.5 text-[14px] font-black tabular-nums"
                                   :style="`color:${Number(s.failure_count) > 0 ? '#dc2626' : '#94a3b8'}`">
                                    {{ s.failure_count ?? 0 }}
                                </p>
                            </div>

                            <!-- Status + actions -->
                            <div class="col-span-2 flex items-center justify-end gap-2 flex-wrap">
                                <span class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[10px] font-black uppercase tracking-wider"
                                      :style="`background:${healthFor(s).tone}10;color:${healthFor(s).tone};border-color:${healthFor(s).tone}40`">
                                    <span class="h-1.5 w-1.5 rounded-full" :style="`background:${healthFor(s).tone}`"></span>
                                    {{ healthFor(s).label }}
                                </span>
                            </div>
                        </div>

                        <!-- Trailing action column -->
                        <div class="flex items-center gap-1 px-3">
                            <button @click="toggleActive(s)" type="button"
                                    class="inline-flex items-center gap-1 rounded-md border border-outline-variant px-2 py-1 text-[10.5px] font-bold text-on-surface-variant hover:bg-surface-container transition-colors"
                                    :title="s.is_active ? 'Pause delivery' : 'Resume delivery'">
                                <span class="material-symbols-outlined text-[12px]">{{ s.is_active ? 'pause' : 'play_arrow' }}</span>
                                {{ s.is_active ? 'Pause' : 'Resume' }}
                            </button>
                            <button @click="remove(s.id)" type="button"
                                    class="inline-flex items-center gap-1 rounded-md border border-rose-200 px-2 py-1 text-[10.5px] font-bold text-rose-600 hover:bg-rose-50 transition-colors"
                                    title="Delete subscription">
                                <span class="material-symbols-outlined text-[12px]">delete</span>
                            </button>
                        </div>
                    </div>
                </div>

                <div v-if="subscriptions?.meta?.links?.length > 3" class="px-6 py-3 border-t border-outline-variant/40">
                    <Pagination :links="subscriptions.meta.links" />
                </div>
            </div>
        </div>

        <!-- ── Register partner panel ─────────────────────────────────── -->
        <SlidePanel :open="showPanel" title="Register webhook subscription" size="lg" @close="closePanel">
            <form @submit.prevent="submit" class="space-y-5 p-6">

                <div class="rounded-xl bg-cyan-50/60 border border-cyan-200/60 dark:bg-cyan-900/15 dark:border-cyan-800/40 px-4 py-3 flex items-start gap-3">
                    <span class="material-symbols-outlined text-cyan-600 text-[20px] mt-0.5">info</span>
                    <p class="text-[12px] text-cyan-900 dark:text-cyan-200 leading-relaxed">
                        A unique signing secret is generated and shown <span class="font-black">once</span>. The partner must sign every incoming verification with it. Endpoints failing 10× consecutively are auto-paused.
                    </p>
                </div>

                <div>
                    <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Partner name <span class="text-rose-500">*</span></label>
                    <input aria-label="Partner name" v-model="form.partner_name" required maxlength="120" placeholder="e.g. GIFMIS / IPPD / Ghana Card NIA"
                           class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20"
                           :class="{ 'border-rose-400': form.errors.partner_name }"/>
                    <p v-if="form.errors.partner_name" class="mt-1 text-[11px] text-rose-600">{{ form.errors.partner_name }}</p>
                </div>

                <div>
                    <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">Callback URL <span class="text-rose-500">*</span></label>
                    <input aria-label="Callback URL" v-model="form.callback_url" type="url" required placeholder="https://partner.gov.gh/webhooks/cihrms"
                           class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] font-mono focus:border-secondary focus:ring-secondary/20"
                           :class="{ 'border-rose-400': form.errors.callback_url }"/>
                    <p v-if="form.errors.callback_url" class="mt-1 text-[11px] text-rose-600">{{ form.errors.callback_url }}</p>
                    <p class="mt-1 text-[10.5px] text-on-surface-variant/60">HTTPS is required. The signing handshake is HMAC-SHA256.</p>
                </div>

                <div>
                    <label class="flex items-center justify-between mb-1.5">
                        <span class="text-[11px] font-black uppercase tracking-wider text-on-surface-variant">Subscribed events <span class="text-rose-500">*</span></span>
                        <span class="text-[10.5px] font-mono text-on-surface-variant/60">{{ form.subscribed_events.length }} selected</span>
                    </label>
                    <div class="max-h-56 overflow-y-auto rounded-xl border border-outline-variant bg-surface-container-low/40 p-2.5 space-y-1">
                        <button v-for="e in available_events" :key="e"
                                type="button"
                                @click="toggleEvent(e)"
                                :class="['w-full flex items-center gap-2 rounded-lg px-2.5 py-1.5 text-[11.5px] font-bold transition-all text-left',
                                          form.subscribed_events.includes(e)
                                            ? 'ring-2'
                                            : 'border border-outline-variant/40 text-on-surface-variant hover:border-secondary/40']"
                                :style="form.subscribed_events.includes(e)
                                    ? `background:${eventMeta(e).color}18;color:${eventMeta(e).color};box-shadow:0 0 0 2px ${eventMeta(e).color}33`
                                    : ''">
                            <span class="material-symbols-outlined text-[14px]">{{ form.subscribed_events.includes(e) ? 'check_circle' : eventMeta(e).icon }}</span>
                            <code class="font-mono flex-1">{{ e }}</code>
                        </button>
                    </div>
                </div>
            </form>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button type="button" @click="closePanel"
                            class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors">
                        Cancel
                    </button>
                    <button type="button" @click="submit"
                            :disabled="form.processing || !form.partner_name || !form.callback_url || form.subscribed_events.length === 0"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-black text-white disabled:opacity-50 shadow-glow-sm"
                            style="background:linear-gradient(135deg,#1a237e,#3949ab)">
                        <span v-if="form.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                        <span v-else class="material-symbols-outlined text-[16px]">webhook</span>
                        Register
                    </button>
                </div>
            </template>
        </SlidePanel>

    </div>
</template>

<style scoped>
.wh-pulse { animation: whPulse 1.6s ease-in-out infinite; }
@keyframes whPulse {
    0%, 100% { opacity: 1; transform: scale(1); box-shadow: 0 0 0 0 currentColor; }
    50%      { opacity: 0.4; transform: scale(0.7); box-shadow: 0 0 0 6px transparent; }
}

.wh-pulse-text { animation: whPulseText 2s ease-in-out infinite; }
@keyframes whPulseText {
    0%, 100% { text-shadow: 0 0 0 rgba(220,38,38,0); }
    50%      { text-shadow: 0 0 16px rgba(220,38,38,0.55); }
}

.wh-stripe-live { animation: whStripeLive 2s ease-in-out infinite; }
@keyframes whStripeLive {
    0%, 100% { opacity: 1; }
    50%      { opacity: 0.55; }
}

.wh-ribbon {
    background: linear-gradient(90deg, transparent, rgba(255,215,0,0.85), rgba(18,217,227,0.65), transparent);
    animation: whRibbon 3.6s linear infinite;
}
@keyframes whRibbon {
    0%   { transform: translateX(-100%); }
    100% { transform: translateX(400%); }
}

.wh-secret { animation: whGlow 2.4s ease-in-out infinite; }
@keyframes whGlow {
    0%, 100% { box-shadow: 0 0 0 0 rgba(255,215,0,0.15), 0 8px 22px -8px rgba(13,20,82,0.45); }
    50%      { box-shadow: 0 0 0 4px rgba(255,215,0,0.10), 0 14px 32px -10px rgba(13,20,82,0.6); }
}

@media (prefers-reduced-motion: reduce) {
    .wh-pulse, .wh-pulse-text, .wh-stripe-live, .wh-ribbon, .wh-secret { animation: none !important; }
}
</style>
