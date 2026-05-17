<script setup>
import { ref, reactive } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Pagination from '@/Components/Pagination.vue';
import StatCard from '@/Components/StatCard.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SlidePanel from '@/Components/SlidePanel.vue';

const props = defineProps({
    messages:     Object,
    inbound:      Array,
    stats:        Object,
    filters:      Object,
    activeModule: String,
});

const localFilters = reactive({
    status:   props.filters?.status   ?? '',
    to_phone: props.filters?.to_phone ?? '',
});

const applyFilters = () => router.get(route('messaging.index'), {
    status:   localFilters.status   || undefined,
    to_phone: localFilters.to_phone || undefined,
}, { preserveState: true, replace: true });

// ── Send-SMS panel ──────────────────────────────────────────────────────────
const showSend = ref(false);
const sendForm = useForm({ to_phone: '', body: '' });
const submitSend = () => sendForm.post(route('messaging.send'), {
    preserveScroll: true,
    onSuccess: () => { showSend.value = false; sendForm.reset(); },
});

// ── PIN-issue panel ─────────────────────────────────────────────────────────
const showPin = ref(false);
const pinForm = useForm({ employee_id: '', phone: '', validity_days: 365 });
const submitPin = () => pinForm.post(route('messaging.pins.issue'), {
    preserveScroll: true,
    onSuccess: () => { showPin.value = false; pinForm.reset(); },
});

// Status pill palette — borrows the same semantic colour set used elsewhere
// (green = delivered success, brand-cyan = sent in transit, rose = failed,
// amber = queued waiting, slate = expired).
const STATUS_META = {
    queued:    { cls: 'bg-amber-50 text-amber-700 border-amber-200',     dot: '#ffd700', label: 'Queued'    },
    sent:      { cls: 'bg-cyan-50 text-cyan-700 border-cyan-200',        dot: '#12d9e3', label: 'Sent'      },
    delivered: { cls: 'bg-emerald-50 text-emerald-700 border-emerald-200', dot: '#059669', label: 'Delivered' },
    failed:    { cls: 'bg-rose-50 text-rose-700 border-rose-200',        dot: '#dc2626', label: 'Failed'    },
    expired:   { cls: 'bg-slate-100 text-slate-600 border-slate-200',    dot: '#64748b', label: 'Expired'   },
};
const statusMeta = (s) => STATUS_META[s] ?? { cls: 'bg-slate-100 text-slate-600 border-slate-200', dot: '#64748b', label: s ?? '—' };
</script>

<template>
    <Head title="Messaging — SMS & USSD" />
    <AuthenticatedLayout :active-module="activeModule">
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">forum</span>
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">Phase 3 · Low-bandwidth reach (SMS + USSD)</p>
                    </div>
                    <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Messaging</h1>
                    <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                        Outbound SMS log, inbound short-code reads, and USSD 2FA PIN issuance.
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="showPin = true"
                            class="flex items-center gap-2 rounded-xl border border-outline-variant/80 px-4 py-2 text-[13px] font-bold text-on-surface-variant hover:bg-secondary/10 hover:text-secondary hover:border-secondary/30 transition-all">
                        <span class="material-symbols-outlined text-[17px]">pin</span>
                        Issue USSD PIN
                    </button>
                    <button @click="showSend = true"
                            class="flex items-center gap-2 rounded-xl bg-secondary px-4 py-2 text-[13px] font-bold text-white hover:bg-secondary-container transition-all shadow-glow-sm">
                        <span class="material-symbols-outlined text-[17px]">send</span>
                        Send SMS
                    </button>
                </div>
            </div>
        </template>

        <div class="py-6 space-y-6">
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                <StatCard label="Sent today"      :value="stats.sent_today" />
                <StatCard label="Delivered today" :value="stats.delivered_today" tone="success" />
                <StatCard label="Failed today"    :value="stats.failed_today"    tone="danger" />
                <StatCard label="Inbound today"   :value="stats.inbound_today" />
                <StatCard label="PIN-enrolled"    :value="stats.pin_enrolled" />
            </div>

            <!-- Outbound log -->
            <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/40">
                <div class="px-5 py-4 border-b border-outline-variant/40 flex flex-wrap gap-3 items-center">
                    <input v-model="localFilters.to_phone" @keyup.enter="applyFilters"
                           placeholder="Filter by phone…"
                           class="rounded-lg border-outline-variant text-sm flex-1 min-w-[200px]">
                    <select v-model="localFilters.status" @change="applyFilters"
                            class="rounded-lg border-outline-variant text-sm">
                        <option value="">All statuses</option>
                        <option value="queued">Queued</option>
                        <option value="sent">Sent</option>
                        <option value="delivered">Delivered</option>
                        <option value="failed">Failed</option>
                    </select>
                </div>

                <table class="w-full text-sm">
                    <thead class="bg-surface-container-low text-on-surface-variant text-xs uppercase">
                        <tr>
                            <th class="px-5 py-3 text-left">When</th>
                            <th class="px-5 py-3 text-left">To</th>
                            <th class="px-5 py-3 text-left">Provider</th>
                            <th class="px-5 py-3 text-left">Body</th>
                            <th class="px-5 py-3 text-right">Seg</th>
                            <th class="px-5 py-3 text-right">Cost</th>
                            <th class="px-5 py-3 text-left">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/30">
                        <tr v-for="m in messages?.data ?? []" :key="m.id" class="hover:bg-surface-container-low/60">
                            <td class="px-5 py-3 text-xs whitespace-nowrap">
                                {{ m.sent_at ? new Date(m.sent_at).toLocaleString('en-GH') : new Date(m.created_at).toLocaleString('en-GH') }}
                            </td>
                            <td class="px-5 py-3 font-mono text-xs">{{ m.to_phone }}</td>
                            <td class="px-5 py-3 text-xs">{{ m.provider }}</td>
                            <td class="px-5 py-3 text-xs max-w-md truncate">{{ m.body }}</td>
                            <td class="px-5 py-3 text-right text-xs">{{ m.segments }}</td>
                            <td class="px-5 py-3 text-right text-xs">{{ m.cost > 0 ? `GHS ${Number(m.cost).toFixed(4)}` : '—' }}</td>
                            <td class="px-5 py-3">
                                <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-[10px] font-black uppercase tracking-wider"
                                      :class="statusMeta(m.status).cls">
                                    <span class="h-1.5 w-1.5 rounded-full" :style="`background:${statusMeta(m.status).dot}`"></span>
                                    {{ statusMeta(m.status).label }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="px-5 py-3 border-t border-outline-variant/40">
                    <Pagination :links="messages?.meta?.links ?? []" />
                </div>
            </div>

            <!-- Inbound (smaller, secondary) -->
            <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/50 shadow-card overflow-hidden">
                <div class="px-5 py-4 border-b border-outline-variant/40 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-[18px] text-brand-cyan">inbox</span>
                        <h2 class="font-bold text-[14px] text-on-surface">Inbound SMS <span class="text-on-surface-variant/60 font-medium">(last 30)</span></h2>
                    </div>
                    <span class="rounded-full bg-secondary/10 border border-secondary/20 px-3 py-1 text-[10px] font-bold uppercase tracking-wider text-secondary">
                        Shortcode *920*HR#
                    </span>
                </div>
                <div v-if="(inbound ?? []).length === 0" class="px-5 py-12 text-center text-sm text-on-surface-variant/60">
                    No inbound messages yet.
                </div>
                <div v-else class="divide-y divide-outline-variant/30">
                    <div v-for="i in inbound" :key="i.id" class="px-5 py-3 text-sm hover:bg-surface-container-low/60 transition-colors">
                        <div class="flex justify-between text-xs text-on-surface-variant/70 mb-1">
                            <span><span class="font-mono text-on-surface">{{ i.from_phone }}</span> → <span class="font-mono text-on-surface">{{ i.to_shortcode }}</span></span>
                            <span>{{ new Date(i.received_at).toLocaleString('en-GH') }}</span>
                        </div>
                        <p class="whitespace-pre-wrap">{{ i.body }}</p>
                        <p v-if="i.parsed_intent" class="mt-1 inline-flex items-center gap-1 rounded-full bg-secondary/10 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-secondary">
                            <span class="material-symbols-outlined text-[11px]">flash_on</span>
                            Intent: {{ i.parsed_intent }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Send-SMS slide panel -->
        <SlidePanel v-model="showSend" title="Send a one-off SMS">
            <form @submit.prevent="submitSend" class="space-y-4">
                <div>
                    <label class="block text-xs font-medium text-on-surface-variant mb-1">To (phone)</label>
                    <input v-model="sendForm.to_phone" required placeholder="0200000099 or 233200000099"
                           class="w-full rounded-lg border-outline-variant text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-on-surface-variant mb-1">Body</label>
                    <textarea v-model="sendForm.body" rows="4" required maxlength="1600"
                              class="w-full rounded-lg border-outline-variant text-sm"></textarea>
                    <p class="text-xs text-on-surface-variant/60 mt-1">
                        {{ sendForm.body.length }} / 160 (1 segment) — longer messages are split into 160-char chunks.
                    </p>
                </div>
                <PrimaryButton type="submit" :disabled="sendForm.processing">Send</PrimaryButton>
            </form>
        </SlidePanel>

        <!-- Issue PIN slide panel -->
        <SlidePanel v-model="showPin" title="Issue / rotate USSD PIN">
            <form @submit.prevent="submitPin" class="space-y-4">
                <p class="text-xs text-on-surface-variant/60">
                    A 4-digit PIN is generated and SMS'd to the employee's registered phone.
                    The plaintext PIN is never stored. Employees use it to authenticate the *920*HR# self-service path.
                </p>
                <div>
                    <label class="block text-xs font-medium text-on-surface-variant mb-1">Employee ID</label>
                    <input v-model.number="pinForm.employee_id" type="number" required
                           class="w-full rounded-lg border-outline-variant text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-on-surface-variant mb-1">Phone (E.164 or Ghanaian 0XXXX)</label>
                    <input v-model="pinForm.phone" aria-label="Phone number (E.164 or Ghanaian)" required class="w-full rounded-lg border-outline-variant text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-on-surface-variant mb-1">Validity (days)</label>
                    <input v-model.number="pinForm.validity_days" type="number" min="1" max="730"
                           class="w-full rounded-lg border-outline-variant text-sm">
                </div>
                <PrimaryButton type="submit" :disabled="pinForm.processing">Issue PIN (2FA required)</PrimaryButton>
            </form>
        </SlidePanel>
    </AuthenticatedLayout>
</template>
