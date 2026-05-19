<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    contract:     Object,
    activeModule: String,
});

const C = computed(() => props.contract.data ?? props.contract);

const send   = () => router.post(route('performance.contracts.send',   C.value.id), {}, { preserveScroll: true });
const sign   = () => router.post(route('performance.contracts.sign',   C.value.id), {}, { preserveScroll: true });
const revoke = () => {
    if (! window.confirm('Revoke this contract back to draft?\n\nThe employee will lose the pending-signature notification. You can amend KPIs and re-send afterwards.')) return;
    router.post(route('performance.contracts.revoke', C.value.id), {}, { preserveScroll: true });
};

const evalForm = useForm({ actuals: {}, end_year_note: '' });
const evaluate = () => evalForm.post(route('performance.contracts.evaluate', C.value.id), { preserveScroll: true });

const totalWeight = computed(() => (C.value.kpis ?? []).reduce((s, k) => s + Number(k.weight || 0), 0));
</script>

<template>
    <Head :title="`Contract — ${C.employee?.name}`" />
    <div data-page-root="true">
            <Teleport to="#page-header-mount" defer>
                <div class="flex items-center justify-between">
                    <div>
                        <Link :href="route('performance.contracts.index')" class="text-xs text-on-surface-variant/60 hover:underline">← All contracts</Link>
                        <h1 class="text-2xl font-semibold tracking-tight">{{ C.employee?.name }}</h1>
                        <p class="text-sm text-on-surface-variant/70">
                            Cycle: {{ C.cycle?.name }} · Supervisor: {{ C.supervisor?.name ?? '—' }}
                        </p>
                    </div>
                    <StatusBadge :status="C.status" :label="C.status_label" class="text-base" />
                </div>
            </Teleport>

            <div class="py-6 space-y-6">
                <!-- KPI table -->
                <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/40">
                    <div class="px-5 py-4 border-b border-outline-variant/40 flex justify-between items-center">
                        <h2 class="font-semibold">Key Performance Indicators</h2>
                        <span class="text-xs text-on-surface-variant/60">Weights sum to {{ totalWeight }}%</span>
                    </div>
                    <table class="w-full text-sm">
                        <thead class="bg-surface-container-low text-on-surface-variant text-xs uppercase">
                            <tr>
                                <th class="px-5 py-3 text-left">KPI</th>
                                <th class="px-5 py-3 text-left">Scorecard</th>
                                <th class="px-5 py-3 text-right">Weight</th>
                                <th class="px-5 py-3 text-right">Target</th>
                                <th class="px-5 py-3 text-right">Actual</th>
                                <th class="px-5 py-3 text-right">Score</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-outline-variant/30">
                            <tr v-for="(k, i) in (C.kpis ?? [])" :key="k.id ?? i">
                                <td class="px-5 py-3 font-medium">{{ k.name }}</td>
                                <td class="px-5 py-3 text-xs">
                                    <span v-if="k.scorecard" class="px-2 py-0.5 rounded-full bg-brand-navy/[0.06] text-brand-navy">
                                        {{ k.scorecard }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-right">{{ k.weight }}%</td>
                                <td class="px-5 py-3 text-right">{{ k.target }} {{ k.unit ?? '' }}</td>
                                <td class="px-5 py-3 text-right">{{ k.actual ?? '—' }}</td>
                                <td class="px-5 py-3 text-right font-semibold">
                                    <span v-if="k.score !== null && k.score !== undefined">{{ Number(k.score).toFixed(1) }}%</span>
                                    <span v-else class="text-on-surface-variant/40">—</span>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot v-if="C.weighted_achievement !== null" class="bg-surface-container-low/60 font-bold">
                            <tr>
                                <td colspan="5" class="px-5 py-3 text-right text-xs uppercase">Weighted achievement</td>
                                <td class="px-5 py-3 text-right text-base"
                                    :class="C.weighted_achievement >= 60 ? 'text-emerald-700' : 'text-rose-700'">
                                    {{ Number(C.weighted_achievement).toFixed(2) }}%
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Workflow -->
                <div class="rounded-2xl border border-outline-variant/40 p-5 bg-brand-navy/[0.03] space-y-3">
                    <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-brand-navy/70">Workflow</p>
                    <div class="flex flex-wrap gap-3">
                        <PrimaryButton v-if="C.status === 'draft'" @click="send">Send for signature</PrimaryButton>
                        <button v-if="C.status === 'pending_signature' && !C.employee_signed_at && !C.supervisor_signed_at"
                                type="button"
                                @click="revoke"
                                class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-2 text-[13px] font-bold text-amber-700 hover:bg-amber-100 transition-colors">
                            <span class="material-symbols-outlined text-[16px] align-middle mr-1">undo</span>
                            Revoke to draft
                        </button>
                        <PrimaryButton v-if="C.status === 'pending_signature' && C.can?.sign" @click="sign">Sign contract</PrimaryButton>
                    </div>

                    <div v-if="C.status === 'pending_signature'" class="text-xs text-on-surface-variant/60">
                        Signed by employee: <span :class="C.employee_signed_at ? 'text-emerald-700' : 'text-rose-700'">{{ C.employee_signed_at ? 'Yes' : 'Pending' }}</span>
                        · Signed by supervisor: <span :class="C.supervisor_signed_at ? 'text-emerald-700' : 'text-rose-700'">{{ C.supervisor_signed_at ? 'Yes' : 'Pending' }}</span>
                    </div>

                    <div v-if="C.status === 'active' && C.can?.evaluate" class="space-y-3 pt-2 border-t border-outline-variant/40">
                        <p class="text-sm font-semibold">End-of-cycle evaluation</p>
                        <div class="grid grid-cols-2 gap-3">
                            <div v-for="(k, i) in (C.kpis ?? [])" :key="`actual-${i}`" class="text-sm">
                                <label class="block text-xs text-on-surface-variant mb-1">{{ k.name }} — actual</label>
                                <input v-model.number="evalForm.actuals[k.id]" type="number" step="0.01"
                                       :placeholder="`target ${k.target} ${k.unit ?? ''}`"
                                       class="w-full rounded-lg border-outline-variant text-sm">
                            </div>
                        </div>
                        <textarea v-model="evalForm.end_year_note" rows="2"
                                  class="w-full rounded-lg border-outline-variant text-sm"
                                  placeholder="End-of-cycle note (optional)"></textarea>
                        <PrimaryButton @click="evaluate" :disabled="evalForm.processing">Evaluate (2FA)</PrimaryButton>
                    </div>
                </div>
            </div>
    </div>
</template>
