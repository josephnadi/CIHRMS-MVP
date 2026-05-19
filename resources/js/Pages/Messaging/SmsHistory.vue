<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import EmptyState from '@/Components/EmptyState.vue';
import SlidePanel from '@/Components/SlidePanel.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    messages:     Object,
    inbound:      Array,
    stats:        Object,
    filters:      Object,
    activeModule: String,
});

// ── Filters ─────────────────────────────────────────────────────────
const localFilters = reactive({
    status:   props.filters?.status   ?? '',
    to_phone: props.filters?.to_phone ?? '',
});

const applyFilters = () => router.get(route('messaging.index'), {
    status:   localFilters.status   || undefined,
    to_phone: localFilters.to_phone || undefined,
}, { preserveState: true, replace: true });

const clearFilters = () => {
    localFilters.status = '';
    localFilters.to_phone = '';
    applyFilters();
};

// ── Send-SMS panel ──────────────────────────────────────────────────
const showSend = ref(false);
const sendForm = useForm({ to_phone: '', body: '' });
const submitSend = () => sendForm.post(route('messaging.send'), {
    preserveScroll: true,
    onSuccess: () => { showSend.value = false; sendForm.reset(); },
});

// ── PIN-issue panel ─────────────────────────────────────────────────
const showPin = ref(false);
const pinForm = useForm({ employee_id: '', phone: '', validity_days: 365 });
const submitPin = () => pinForm.post(route('messaging.pins.issue'), {
    preserveScroll: true,
    onSuccess: () => { showPin.value = false; pinForm.reset(); },
});

// ── Status meta ─────────────────────────────────────────────────────
const STATUS_META = {
    queued:    { cls: 'bg-amber-50 text-amber-700 border-amber-200',         dot: '#d97706', label: 'Queued'    },
    sent:      { cls: 'bg-cyan-50 text-cyan-700 border-cyan-200',            dot: '#12d9e3', label: 'Sent'      },
    delivered: { cls: 'bg-emerald-50 text-emerald-700 border-emerald-200',   dot: '#16a34a', label: 'Delivered' },
    failed:    { cls: 'bg-rose-50 text-rose-700 border-rose-200',            dot: '#dc2626', label: 'Failed'    },
    expired:   { cls: 'bg-slate-100 text-slate-600 border-slate-200',        dot: '#64748b', label: 'Expired'   },
};
const statusMeta = (s) => STATUS_META[s] ?? { cls: 'bg-slate-100 text-slate-600 border-slate-200', dot: '#64748b', label: s ?? '—' };

// ── Derived stats / KPIs ────────────────────────────────────────────
const totalDispatched = computed(() => {
    const s = props.stats ?? {};
    return (s.sent_today ?? 0) + (s.delivered_today ?? 0) + (s.failed_today ?? 0);
});

const deliveryRate = computed(() => {
    const s = props.stats ?? {};
    const completed = (s.delivered_today ?? 0) + (s.failed_today ?? 0);
    if (completed === 0) return null;
    return Math.round(((s.delivered_today ?? 0) / completed) * 100);
});

// ── Helpers ─────────────────────────────────────────────────────────
const fmtDateTime = (d) => d ? new Date(d).toLocaleString('en-GH', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—';
</script>

<template>
<Head title="Messaging — SMS & USSD" />
    <div data-page-root="true">
        <Teleport to="#page-header-mount" defer>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Messaging</h1>
                    <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                        Outbound SMS · Inbound short-code · USSD 2FA PIN issuance
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <!-- Live ribbon — flagged shortcode -->
                    <div class="flex items-center gap-1.5 rounded-full bg-cyan-50 border border-cyan-200 px-3 py-1.5 dark:bg-cyan-900/20 dark:border-cyan-800/40">
                        <span class="h-1.5 w-1.5 rounded-full bg-cyan-500 live-dot"></span>
                        <span class="text-[10px] font-black uppercase tracking-widest text-cyan-700 dark:text-cyan-300 font-mono">*920*HR#</span>
                    </div>
                    <!-- PIN button — subtle gold (5% flagship action) -->
                    <button @click="showPin = true"
                            class="msg-pin-btn group flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-black transition-all hover:-translate-y-px active:scale-[0.97]">
                        <span class="material-symbols-outlined text-[18px]" style="font-variation-settings:'FILL' 1">pin</span>
                        Issue USSD PIN
                    </button>
                    <!-- Send — primary indigo action -->
                    <button @click="showSend = true"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px hover:shadow-glow active:scale-[0.97]"
                            style="background:linear-gradient(135deg,#1a237e,#3949ab)">
                        <span class="material-symbols-outlined text-[18px]" style="font-variation-settings:'FILL' 1">send</span>
                        Send SMS
                    </button>
                </div>
            </div>
        </Teleport>

        <div class="space-y-8">

            <!-- ── Hero band ───────────────────────────────────────── -->
            <div class="relative overflow-hidden rounded-3xl px-8 py-7 text-white animate-reveal-up"
                 style="background:linear-gradient(135deg,#1a237e 0%,#283593 55%,#3949ab 100%);border:1px solid rgba(255,255,255,0.07);">
                <div class="pointer-events-none absolute -right-16 -top-16 h-72 w-72 rounded-full blur-3xl" style="background:radial-gradient(circle,rgba(18,217,227,0.22),transparent 70%)"></div>
                <div class="pointer-events-none absolute -left-8 bottom-0 h-48 w-48 rounded-full blur-2xl" style="background:rgba(255,215,0,0.12)"></div>

                <!-- Live ribbon (animated streak across the top edge) -->
                <div class="absolute inset-x-0 top-0 h-px overflow-hidden">
                    <div class="msg-ribbon h-px w-1/3"></div>
                </div>

                <div class="relative flex flex-wrap items-center justify-between gap-8">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-400 live-dot"></span>
                            <p class="text-[9px] font-black uppercase tracking-[0.25em]" style="color:rgba(18,217,227,0.85)">Telecom gateway · live</p>
                        </div>
                        <h2 class="text-3xl font-black leading-tight">
                            <em class="not-italic" style="color:#12d9e3">{{ totalDispatched }}</em> message<span v-if="totalDispatched !== 1">s</span> dispatched today
                            <template v-if="deliveryRate !== null"> · <em class="not-italic" style="color:#a7f3d0">{{ deliveryRate }}%</em> delivered</template>
                        </h2>
                        <p class="mt-2 text-sm font-medium" style="color:rgba(255,255,255,0.55)">
                            <span style="color:#fda4af">{{ stats.failed_today }}</span> failure<span v-if="stats.failed_today !== 1">s</span> ·
                            <span style="color:#7986cb">{{ stats.inbound_today }}</span> inbound today ·
                            <span style="color:#ffd700">{{ stats.pin_enrolled }}</span> staff PIN-enrolled
                        </p>
                    </div>

                    <!-- Inline KPIs — gold reserved for the institutional health metric (PIN-enrolled) -->
                    <div class="flex items-center gap-7 flex-shrink-0">
                        <div v-for="(kpi, i) in [
                            { label: 'Sent',      val: stats.sent_today,      color: '#12d9e3' },
                            { label: 'Delivered', val: stats.delivered_today, color: '#a7f3d0' },
                            { label: 'Inbound',   val: stats.inbound_today,   color: '#7986cb' },
                            { label: 'Enrolled',  val: stats.pin_enrolled,    color: '#ffd700', flagship: true },
                        ]" :key="kpi.label" class="text-center"
                             :style="`animation:slideUpFade 0.45s ease both;animation-delay:${i*0.06}s`">
                            <p :class="['text-[32px] font-black leading-none tabular-nums', kpi.flagship ? 'msg-gold' : '']"
                               :style="`color:${kpi.color}`">{{ kpi.val }}</p>
                            <p class="mt-1 text-[9px] font-black uppercase tracking-[0.18em]" style="color:rgba(255,255,255,0.4)">{{ kpi.label }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── KPI tiles (palette personalities) ───────────────────── -->
            <div class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-5">
                <div v-for="(card, i) in [
                    { label: 'Sent today',     val: stats.sent_today,      sub: 'Queued + dispatched',  cls: 'icon-cyan',    icon: 'send' },
                    { label: 'Delivered',      val: stats.delivered_today, sub: 'Receipts in',           cls: 'icon-success', icon: 'mark_email_read' },
                    { label: 'Failed',         val: stats.failed_today,    sub: 'Carrier rejections',   cls: 'icon-danger',  icon: 'sms_failed' },
                    { label: 'Inbound',        val: stats.inbound_today,   sub: 'From shortcode',       cls: 'icon-magenta', icon: 'inbox' },
                    { label: 'PIN-enrolled',   val: stats.pin_enrolled,    sub: 'USSD-ready staff',     cls: 'icon-gold',    icon: 'pin', flagship: true },
                ]" :key="card.label"
                     class="group relative overflow-hidden rounded-2xl border p-5 transition-all hover:shadow-md hover:-translate-y-0.5"
                     :class="card.flagship
                        ? 'border-amber-200/60 bg-gradient-to-br from-amber-50/40 to-surface-container-lowest dark:from-amber-900/10'
                        : 'border-outline-variant/60 bg-surface-container-lowest'"
                     :style="`animation:slideUpFade 0.4s ease both;animation-delay:${i*0.05}s`">
                    <div class="icon-tile" :class="card.cls">
                        <span class="material-symbols-outlined">{{ card.icon }}</span>
                    </div>
                    <!-- 5% gold hairline reserved for the flagship card -->
                    <div v-if="card.flagship" class="absolute top-0 left-0 right-0 h-px"
                         style="background:linear-gradient(90deg,transparent,rgba(255,215,0,0.7),transparent)"></div>
                    <p class="mt-3 text-[10px] font-black uppercase tracking-[0.12em] text-on-surface-variant/70">{{ card.label }}</p>
                    <p class="mt-1 text-[26px] font-black tabular-nums text-primary leading-none">{{ card.val }}</p>
                    <p class="mt-1 text-[10px] font-semibold text-on-surface-variant">{{ card.sub }}</p>
                </div>
            </div>

            <!-- ── Outbound log ──────────────────────────────────────── -->
            <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">

                <!-- Filter row -->
                <div class="flex flex-wrap items-center gap-3 px-6 py-4 border-b border-outline-variant/50 bg-surface-container-low/30">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-[16px] text-secondary">filter_list</span>
                        <span class="text-[11px] font-black uppercase tracking-widest text-on-surface-variant">Outbound log</span>
                    </div>

                    <div class="relative flex-1 min-w-[200px] max-w-xs">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[16px] text-on-surface-variant/50">phone</span>
                        <input v-model="localFilters.to_phone" @keyup.enter="applyFilters"
                               placeholder="Filter by phone…"
                               class="w-full rounded-xl border-outline-variant pl-9 text-[12.5px] focus:border-secondary focus:ring-secondary/20"/>
                    </div>

                    <div class="flex flex-wrap items-center gap-1.5">
                        <button v-for="opt in [
                            { id: '',          label: 'All',       icon: 'list_alt' },
                            { id: 'queued',    label: 'Queued',    icon: 'pending' },
                            { id: 'sent',      label: 'Sent',      icon: 'send' },
                            { id: 'delivered', label: 'Delivered', icon: 'mark_email_read' },
                            { id: 'failed',    label: 'Failed',    icon: 'sms_failed' },
                        ]" :key="opt.id" @click="localFilters.status = opt.id; applyFilters()"
                                :class="['inline-flex items-center gap-1.5 rounded-xl border px-3 py-1.5 text-[11.5px] font-black uppercase tracking-wide transition-all',
                                          localFilters.status === opt.id
                                            ? 'border-secondary bg-secondary text-white shadow-glow-sm'
                                            : 'border-outline-variant text-on-surface-variant hover:border-secondary/40']">
                            <span class="material-symbols-outlined text-[13px]">{{ opt.icon }}</span>
                            {{ opt.label }}
                        </button>
                    </div>

                    <button v-if="localFilters.status || localFilters.to_phone" @click="clearFilters"
                            class="ml-auto inline-flex items-center gap-1 rounded-lg border border-outline-variant/60 px-2.5 py-1.5 text-[11px] font-bold text-on-surface-variant hover:bg-surface-container transition-colors">
                        <span class="material-symbols-outlined text-[14px]">close</span>
                        Clear
                    </button>
                </div>

                <!-- Empty state -->
                <div v-if="(messages?.data ?? []).length === 0" class="px-6 py-12">
                    <EmptyState
                        title="No SMS in this view"
                        description="Send a one-off message or wait for a trigger to fire."
                        icon="sms"
                    />
                </div>

                <!-- Table -->
                <table v-else class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[10px] font-black uppercase text-on-surface-variant tracking-widest bg-surface-container-low/20">
                            <th class="p-3 pl-6">When</th>
                            <th class="p-3">To</th>
                            <th class="p-3">Provider</th>
                            <th class="p-3">Body</th>
                            <th class="p-3 text-right">Seg</th>
                            <th class="p-3 text-right">Cost</th>
                            <th class="p-3 text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(m, i) in messages.data" :key="m.id"
                            class="border-t border-outline-variant/40 hover:bg-surface-container-low/20 transition-colors"
                            :style="`animation:slideUpFade 0.35s ease both;animation-delay:${Math.min(i, 12)*0.03}s`">
                            <td class="p-3 pl-6 text-[11.5px] font-mono text-on-surface-variant whitespace-nowrap">
                                {{ fmtDateTime(m.sent_at ?? m.created_at) }}
                            </td>
                            <td class="p-3 font-mono text-[12px] font-bold text-primary">{{ m.to_phone }}</td>
                            <td class="p-3 text-[11.5px] text-on-surface-variant capitalize">{{ m.provider }}</td>
                            <td class="p-3 text-[12px] max-w-md truncate">{{ m.body }}</td>
                            <td class="p-3 text-right text-[12px] font-mono tabular-nums">{{ m.segments }}</td>
                            <td class="p-3 text-right text-[11.5px] font-mono tabular-nums text-on-surface-variant">
                                {{ Number(m.cost) > 0 ? `GHS ${Number(m.cost).toFixed(4)}` : '—' }}
                            </td>
                            <td class="p-3 text-center">
                                <span class="inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[10px] font-black uppercase tracking-wider"
                                      :class="statusMeta(m.status).cls">
                                    <span class="h-1.5 w-1.5 rounded-full" :style="`background:${statusMeta(m.status).dot}`"></span>
                                    {{ statusMeta(m.status).label }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div v-if="messages?.meta?.links?.length > 3" class="px-6 py-3 border-t border-outline-variant/40">
                    <Pagination :links="messages.meta.links" />
                </div>
            </div>

            <!-- ── Inbound stream ──────────────────────────────────────── -->
            <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
                <div class="flex items-center justify-between px-6 py-4 border-b border-outline-variant/50 bg-surface-container-low/30">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-[16px] text-secondary">inbox</span>
                        <span class="text-[11px] font-black uppercase tracking-widest text-on-surface-variant">Inbound stream</span>
                        <span class="rounded-full border border-cyan-200 bg-cyan-50 px-2 py-0.5 text-[10px] font-black text-cyan-700">last 30</span>
                    </div>
                    <span class="rounded-full bg-cyan-50 border border-cyan-200 px-3 py-1 text-[10px] font-black uppercase tracking-wider text-cyan-700 font-mono">
                        *920*HR#
                    </span>
                </div>

                <div v-if="(inbound ?? []).length === 0" class="px-6 py-12">
                    <EmptyState
                        title="No inbound messages yet"
                        description="Replies to the shortcode and self-service queries will land here."
                        icon="inbox"
                    />
                </div>
                <div v-else class="divide-y divide-outline-variant/30">
                    <div v-for="(i, idx) in inbound" :key="i.id"
                         class="px-6 py-4 hover:bg-surface-container-low/20 transition-colors"
                         :style="`animation:slideUpFade 0.35s ease both;animation-delay:${Math.min(idx, 10)*0.04}s`">
                        <div class="flex flex-wrap items-center justify-between gap-2 text-[10.5px] mb-1.5">
                            <div class="flex items-center gap-2">
                                <span class="flex h-6 w-6 items-center justify-center rounded-lg" style="background:rgba(217,18,227,0.10)">
                                    <span class="material-symbols-outlined text-[13px]" style="color:#d912e3;font-variation-settings:'FILL' 1">call_received</span>
                                </span>
                                <span class="font-mono text-[12px] font-bold text-primary">{{ i.from_phone }}</span>
                                <span class="text-on-surface-variant/50">→</span>
                                <span class="font-mono text-[11.5px] text-on-surface-variant">{{ i.to_shortcode }}</span>
                            </div>
                            <span class="text-[10.5px] text-on-surface-variant/60 font-mono">{{ fmtDateTime(i.received_at) }}</span>
                        </div>
                        <p class="text-[13px] text-on-surface whitespace-pre-wrap leading-relaxed">{{ i.body }}</p>
                        <p v-if="i.parsed_intent" class="mt-2 inline-flex items-center gap-1 rounded-full bg-secondary/10 border border-secondary/20 px-2 py-0.5 text-[10px] font-black uppercase tracking-wider text-secondary">
                            <span class="material-symbols-outlined text-[11px]">flash_on</span>
                            Intent · {{ i.parsed_intent }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Send SMS slide panel ─────────────────────────────────── -->
        <SlidePanel :open="showSend" title="Send a one-off SMS" size="lg" @close="showSend = false">
            <form @submit.prevent="submitSend" class="space-y-5 p-6">
                <div class="rounded-xl bg-cyan-50/60 border border-cyan-200/60 dark:bg-cyan-900/15 dark:border-cyan-800/40 px-4 py-3 flex items-start gap-3">
                    <span class="material-symbols-outlined text-cyan-600 text-[20px] mt-0.5">info</span>
                    <p class="text-[12px] text-cyan-900 dark:text-cyan-200 leading-relaxed">
                        Single SMS segments hold 160 GSM characters. Longer text is split into segments and billed per segment by the carrier.
                    </p>
                </div>

                <div>
                    <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">
                        Recipient phone <span class="text-rose-500">*</span>
                    </label>
                    <input v-model="sendForm.to_phone" required placeholder="0200000099 or 233200000099"
                           class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] font-mono focus:border-secondary focus:ring-secondary/20"
                           :class="{ 'border-rose-400': sendForm.errors.to_phone }"/>
                    <p v-if="sendForm.errors.to_phone" class="mt-1 text-[11px] text-rose-500">{{ sendForm.errors.to_phone }}</p>
                </div>

                <div>
                    <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">
                        Message body <span class="text-rose-500">*</span>
                    </label>
                    <textarea v-model="sendForm.body" rows="5" required maxlength="1600"
                              class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20 resize-none"
                              :class="{ 'border-rose-400': sendForm.errors.body }"></textarea>
                    <p class="mt-1.5 flex items-center justify-between text-[11px]">
                        <span class="text-on-surface-variant/70">
                            <span class="font-bold tabular-nums" :class="sendForm.body.length > 160 ? 'text-amber-600' : 'text-primary'">{{ sendForm.body.length }}</span>
                            / 160 chars · {{ Math.max(1, Math.ceil(sendForm.body.length / 160)) }} segment<span v-if="Math.ceil(sendForm.body.length / 160) !== 1">s</span>
                        </span>
                        <span v-if="sendForm.body.length > 160" class="font-bold text-amber-600">Will split</span>
                    </p>
                </div>
            </form>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button type="button" @click="showSend = false"
                            class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors">
                        Cancel
                    </button>
                    <button @click="submitSend" :disabled="sendForm.processing"
                            class="btn-shimmer flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-black text-white disabled:opacity-60 shadow-glow-sm"
                            style="background:linear-gradient(135deg,#1a237e,#3949ab)">
                        <span v-if="sendForm.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                        <span v-else class="material-symbols-outlined text-[16px]">send</span>
                        Send SMS
                    </button>
                </div>
            </template>
        </SlidePanel>

        <!-- ── Issue PIN slide panel ─────────────────────────────────── -->
        <SlidePanel :open="showPin" title="Issue / rotate USSD PIN" size="lg" @close="showPin = false">
            <form @submit.prevent="submitPin" class="space-y-5 p-6">
                <!-- Gold-leaning info banner — this is the flagship action and the
                     only place gold appears outside the hero/KPI flagship. -->
                <div class="rounded-xl border border-amber-200/60 px-4 py-3 flex items-start gap-3"
                     style="background:linear-gradient(135deg,rgba(255,215,0,0.06),rgba(255,215,0,0.02));">
                    <span class="material-symbols-outlined text-[20px] mt-0.5" style="color:#b88a08;font-variation-settings:'FILL' 1">verified_user</span>
                    <p class="text-[12px] text-amber-900 dark:text-amber-200 leading-relaxed">
                        A 4-digit PIN is generated server-side and SMS'd to the employee's registered phone. The plaintext PIN is never stored — only the bcrypt hash. <span class="font-black">2FA required to issue.</span>
                    </p>
                </div>

                <div>
                    <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">
                        Employee ID <span class="text-rose-500">*</span>
                    </label>
                    <input v-model.number="pinForm.employee_id" type="number" required
                           class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] font-mono focus:border-secondary focus:ring-secondary/20"
                           :class="{ 'border-rose-400': pinForm.errors.employee_id }"/>
                </div>

                <div>
                    <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">
                        Phone <span class="ml-1 font-normal normal-case text-on-surface-variant/60">(E.164 or 0XXXXXXXXX)</span> <span class="text-rose-500">*</span>
                    </label>
                    <input v-model="pinForm.phone" required
                           class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] font-mono focus:border-secondary focus:ring-secondary/20"/>
                </div>

                <div>
                    <label class="block text-[11px] font-black uppercase tracking-wider text-on-surface-variant mb-1.5">
                        Validity <span class="ml-1 font-normal normal-case text-on-surface-variant/60">(days)</span>
                    </label>
                    <input v-model.number="pinForm.validity_days" type="number" min="1" max="730"
                           class="w-full rounded-xl border-outline-variant bg-surface-container-low text-[13px] focus:border-secondary focus:ring-secondary/20"/>
                </div>
            </form>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button type="button" @click="showPin = false"
                            class="rounded-xl border border-outline-variant px-4 py-2 text-[13px] font-semibold text-on-surface-variant hover:bg-surface-container transition-colors">
                        Cancel
                    </button>
                    <button @click="submitPin" :disabled="pinForm.processing"
                            class="msg-pin-btn flex items-center gap-2 rounded-xl px-5 py-2 text-[13px] font-black disabled:opacity-60">
                        <span v-if="pinForm.processing" class="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>
                        <span v-else class="material-symbols-outlined text-[16px]">pin</span>
                        Issue PIN (2FA)
                    </button>
                </div>
            </template>
        </SlidePanel>

    </div>
</template>

<style scoped>
/* ── 5% gold accent for the PIN button — the institutional executive
   action. Uses gold as a thin border/glow over the dark indigo base so
   it reads as premium without overwhelming the page. */
.msg-pin-btn {
    background: linear-gradient(135deg, #1a237e 0%, #283593 100%);
    color: #fff;
    border: 1px solid rgba(255, 215, 0, 0.45);
    box-shadow:
        0 0 0 1px rgba(255, 215, 0, 0.18),
        0 6px 18px -8px rgba(184, 138, 8, 0.55),
        inset 0 1px 0 rgba(255, 215, 0, 0.12);
    transition: transform .2s ease, box-shadow .25s ease, border-color .25s ease;
}
.msg-pin-btn:hover {
    border-color: rgba(255, 215, 0, 0.75);
    box-shadow:
        0 0 0 1px rgba(255, 215, 0, 0.32),
        0 10px 26px -8px rgba(184, 138, 8, 0.7),
        inset 0 1px 0 rgba(255, 215, 0, 0.22);
}

/* Gold flagship KPI in the hero — adds a subtle text-shadow glow so it
   feels like the headline number even at small size. */
.msg-gold {
    text-shadow: 0 0 18px rgba(255, 215, 0, 0.35);
    animation: msgGoldShimmer 4s ease-in-out infinite;
}
@keyframes msgGoldShimmer {
    0%, 100% { text-shadow: 0 0 14px rgba(255, 215, 0, 0.22); }
    50%      { text-shadow: 0 0 22px rgba(255, 215, 0, 0.55); }
}

/* Live ribbon — same idea as Service Desk: a thin streak across the top
   edge of the hero, signalling that data is flowing. */
.msg-ribbon {
    background: linear-gradient(90deg, transparent, rgba(18,217,227,0.9), rgba(255,215,0,0.7), transparent);
    animation: msgRibbon 4.2s linear infinite;
}
@keyframes msgRibbon {
    0%   { transform: translateX(-100%); }
    100% { transform: translateX(400%); }
}

/* Live-dot shared idiom */
.live-dot { animation: msgLiveDot 1.6s ease-in-out infinite; }
@keyframes msgLiveDot {
    0%, 100% { opacity: 1;   transform: scale(1);   box-shadow: 0 0 0 0 currentColor; }
    50%      { opacity: 0.4; transform: scale(0.7); box-shadow: 0 0 0 6px transparent; }
}

@media (prefers-reduced-motion: reduce) {
    .msg-pin-btn,
    .msg-gold,
    .msg-ribbon,
    .live-dot { animation: none !important; transition: none !important; }
}
</style>
