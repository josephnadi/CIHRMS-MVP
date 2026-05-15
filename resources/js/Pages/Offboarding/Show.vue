<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import StatCard from '@/Components/StatCard.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';

const props = defineProps({
    case:         Object,
    clearance:    Object,
    settlement:   Object,
    activeModule: String,
});

const C = computed(() => props.case.data ?? props.case);
const S = computed(() => props.settlement?.data ?? props.settlement ?? null);
const clearanceGroups = computed(() => props.clearance ?? {});

const cedi = (v) => 'GHS ' + (Number(v) || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const pct  = (v) => Math.round((Number(v) || 0) * 100) + '%';

const tab = ref('clearance');

// ── Clearance actions ────────────────────────────────────────────────────────
const clearItem = (item) => {
    router.post(route('offboarding.clearance.update', { case: C.value.id, item: item.id }),
        { action: 'clear', notes: '' },
        { preserveScroll: true });
};
const waiveForm = useForm({ action: 'waive', notes: '' });
const showWaive = ref(null);
const submitWaive = () => waiveForm.post(
    route('offboarding.clearance.update', { case: C.value.id, item: showWaive.value }),
    { preserveScroll: true, onSuccess: () => { showWaive.value = null; waiveForm.reset('notes'); } },
);

// ── Settlement actions ───────────────────────────────────────────────────────
const settleForm = useForm({
    gratuity_months_per_year:  1.0,
    severance_months_per_year: 1.5,
    working_days_per_month:    22,
    ex_gratia:                 0,
    prorated_13th_month:       0,
    other_deductions:          0,
    pay_paye:                  true,
});
const calculate = () => settleForm.post(route('offboarding.settlement.calculate', C.value.id), { preserveScroll: true });
const approve   = () => router.post(route('offboarding.settlement.approve', C.value.id), {}, { preserveScroll: true });
const complete  = () => router.post(route('offboarding.complete', C.value.id), {}, { preserveScroll: true });
</script>

<template>
    <Head :title="`Case ${C.reference}`" />

    <AuthenticatedLayout :active-module="activeModule">
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <Link :href="route('offboarding.index')" class="text-xs text-on-surface-variant/60 hover:underline">← All cases</Link>
                    <h1 class="text-2xl font-semibold tracking-tight">{{ C.reference }}</h1>
                    <p class="text-sm text-on-surface-variant/70">
                        {{ C.employee?.name }} · {{ C.employee?.department }} · {{ C.exit_type_label }}
                    </p>
                </div>
                <StatusBadge :status="C.status" :label="C.status_label" class="text-base" />
            </div>
        </template>

        <div class="py-6 space-y-6">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <StatCard label="Notice received"     :value="C.notice_received_on" />
                <StatCard label="Last working day"    :value="C.last_working_day" />
                <StatCard label="Clearance progress"  :value="pct(C.clearance_progress)" />
                <StatCard label="Net settlement"      :value="S ? cedi(S.net_payable) : '—'" />
            </div>

            <div class="bg-surface-container-lowest rounded-2xl shadow-sm border border-outline-variant/40">
                <div class="px-5 py-3 border-b border-outline-variant/40 flex gap-6 text-sm">
                    <button @click="tab='clearance'"
                            :class="tab==='clearance' ? 'text-secondary font-semibold' : 'text-on-surface-variant/70'">
                        Clearance
                    </button>
                    <button @click="tab='settlement'"
                            :class="tab==='settlement' ? 'text-secondary font-semibold' : 'text-on-surface-variant/70'">
                        Settlement
                    </button>
                </div>

                <!-- CLEARANCE TAB -->
                <div v-if="tab==='clearance'" class="p-5 space-y-6">
                    <div v-for="(items, area) in clearanceGroups" :key="area"
                         class="rounded-xl border border-outline-variant/40 overflow-hidden">
                        <div class="px-4 py-2 bg-surface-container-low text-xs font-bold uppercase tracking-[0.18em]">
                            {{ items.data?.[0]?.area_label ?? area }}
                        </div>
                        <div class="divide-y divide-outline-variant/30">
                            <div v-for="i in (items.data ?? items)" :key="i.id"
                                 class="px-4 py-3 flex items-center gap-3">
                                <span class="material-symbols-outlined text-[20px]"
                                      :class="i.status === 'cleared' ? 'text-emerald-600' :
                                              i.status === 'waived' ? 'text-amber-500' : 'text-on-surface-variant/40'">
                                    {{ i.status === 'cleared' ? 'check_circle' :
                                       i.status === 'waived' ? 'remove_circle' : 'radio_button_unchecked' }}
                                </span>
                                <div class="flex-1">
                                    <div class="font-medium text-sm">
                                        {{ i.label }}
                                        <span v-if="!i.is_required" class="text-xs text-on-surface-variant/50 ml-1">(optional)</span>
                                    </div>
                                    <div v-if="i.notes" class="text-xs text-on-surface-variant/60 mt-1">{{ i.notes }}</div>
                                    <div v-if="i.cleared_at" class="text-xs text-on-surface-variant/50 mt-1">
                                        {{ i.status === 'cleared' ? 'Cleared' : 'Waived' }} by {{ i.cleared_by?.name ?? '—' }}
                                        on {{ new Date(i.cleared_at).toLocaleDateString('en-GH') }}
                                    </div>
                                </div>
                                <div v-if="i.status === 'pending' && C.can?.clear" class="flex gap-2">
                                    <button @click="clearItem(i)"
                                            class="text-xs px-2 py-1 rounded-md bg-emerald-50 text-emerald-700 hover:bg-emerald-100">
                                        Clear
                                    </button>
                                    <button @click="showWaive = i.id"
                                            class="text-xs px-2 py-1 rounded-md bg-amber-50 text-amber-700 hover:bg-amber-100">
                                        Waive
                                    </button>
                                </div>
                            </div>

                            <!-- Inline waive form -->
                            <div v-for="i in (items.data ?? items).filter(x => showWaive === x.id)" :key="`waive-${i.id}`"
                                 class="px-4 py-3 bg-amber-50/30">
                                <form @submit.prevent="submitWaive" class="flex gap-3 items-end">
                                    <div class="flex-1">
                                        <label class="block text-xs font-medium text-on-surface-variant mb-1">Reason for waiving (required)</label>
                                        <input v-model="waiveForm.notes" class="w-full rounded-lg border-outline-variant text-sm" required>
                                    </div>
                                    <PrimaryButton type="submit" :disabled="waiveForm.processing">Confirm waive</PrimaryButton>
                                    <button type="button" @click="showWaive = null" class="text-xs text-on-surface-variant/60 hover:underline">Cancel</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SETTLEMENT TAB -->
                <div v-if="tab==='settlement'" class="p-5 space-y-5">
                    <!-- Calculation knobs (visible if no approved settlement yet and user can settle) -->
                    <div v-if="(!S || S.status === 'calculated') && C.can?.settle"
                         class="rounded-xl border border-outline-variant/40 p-4 space-y-3 bg-brand-navy/[0.03]">
                        <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-brand-navy/70">Calculation overrides</p>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                            <div>
                                <label class="block text-xs text-on-surface-variant mb-1">Gratuity (months/yr)</label>
                                <input v-model.number="settleForm.gratuity_months_per_year" type="number" step="0.1" class="w-full rounded-lg border-outline-variant">
                            </div>
                            <div>
                                <label class="block text-xs text-on-surface-variant mb-1">Severance (months/yr)</label>
                                <input v-model.number="settleForm.severance_months_per_year" type="number" step="0.1" class="w-full rounded-lg border-outline-variant">
                            </div>
                            <div>
                                <label class="block text-xs text-on-surface-variant mb-1">Working days / month</label>
                                <input v-model.number="settleForm.working_days_per_month" type="number" class="w-full rounded-lg border-outline-variant">
                            </div>
                            <div>
                                <label class="block text-xs text-on-surface-variant mb-1">Ex-gratia (GHS)</label>
                                <input v-model.number="settleForm.ex_gratia" type="number" step="0.01" class="w-full rounded-lg border-outline-variant">
                            </div>
                            <div>
                                <label class="block text-xs text-on-surface-variant mb-1">Prorated 13th-month</label>
                                <input v-model.number="settleForm.prorated_13th_month" type="number" step="0.01" class="w-full rounded-lg border-outline-variant">
                            </div>
                            <div>
                                <label class="block text-xs text-on-surface-variant mb-1">Other deductions</label>
                                <input v-model.number="settleForm.other_deductions" type="number" step="0.01" class="w-full rounded-lg border-outline-variant">
                            </div>
                            <div class="flex items-end">
                                <label class="inline-flex items-center gap-2 text-sm">
                                    <input v-model="settleForm.pay_paye" type="checkbox" class="rounded">
                                    Apply PAYE
                                </label>
                            </div>
                        </div>
                        <PrimaryButton @click="calculate" :disabled="settleForm.processing">
                            {{ S ? 'Recalculate' : 'Calculate settlement' }}
                        </PrimaryButton>
                    </div>

                    <!-- Settlement snapshot -->
                    <div v-if="S" class="grid md:grid-cols-2 gap-5">
                        <div class="rounded-xl border border-outline-variant/40 p-5 space-y-2">
                            <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-emerald-700">Earnings</p>
                            <div class="flex justify-between text-sm"><span>Gratuity</span><span>{{ cedi(S.earnings.gratuity) }}</span></div>
                            <div class="flex justify-between text-sm"><span>Severance (Act 651)</span><span>{{ cedi(S.earnings.severance) }}</span></div>
                            <div class="flex justify-between text-sm"><span>Leave encashment ({{ S.accrued_leave_days }} days)</span><span>{{ cedi(S.earnings.leave_encashment) }}</span></div>
                            <div class="flex justify-between text-sm"><span>Pro-rated 13th month</span><span>{{ cedi(S.earnings.prorated_13th_month) }}</span></div>
                            <div class="flex justify-between text-sm"><span>Ex-gratia</span><span>{{ cedi(S.earnings.ex_gratia) }}</span></div>
                            <div class="flex justify-between text-sm font-bold border-t border-outline-variant/40 pt-2 mt-2">
                                <span>Gross settlement</span><span>{{ cedi(S.earnings.gross_settlement) }}</span>
                            </div>
                        </div>
                        <div class="rounded-xl border border-outline-variant/40 p-5 space-y-2">
                            <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-rose-700">Deductions</p>
                            <div class="flex justify-between text-sm"><span>Outstanding loans (netted)</span><span>{{ cedi(S.deductions.outstanding_loans) }}</span></div>
                            <div class="flex justify-between text-sm"><span>Garnishments</span><span>{{ cedi(S.deductions.garnishments) }}</span></div>
                            <div class="flex justify-between text-sm"><span>Other deductions</span><span>{{ cedi(S.deductions.other_deductions) }}</span></div>
                            <div class="flex justify-between text-sm"><span>PAYE on settlement</span><span>{{ cedi(S.deductions.paye_on_settlement) }}</span></div>
                            <div class="flex justify-between text-sm font-bold border-t border-outline-variant/40 pt-2 mt-2">
                                <span>Total deductions</span><span>{{ cedi(S.deductions.total_deductions) }}</span>
                            </div>
                            <div class="flex justify-between text-base font-bold pt-2 mt-2 border-t-2 border-brand-navy/40">
                                <span>Net payable</span><span class="text-brand-navy">{{ cedi(S.net_payable) }}</span>
                            </div>
                            <p class="text-xs text-on-surface-variant/60 pt-2">
                                Years of service: {{ S.years_of_service }} · Basic snapshot: {{ cedi(S.basic_salary) }}
                            </p>
                        </div>
                    </div>

                    <!-- Workflow buttons -->
                    <div v-if="S" class="flex flex-wrap gap-3 pt-2">
                        <PrimaryButton v-if="S.status === 'calculated' && C.can?.approve_settle"
                                       @click="approve">Approve settlement (2FA)</PrimaryButton>
                        <PrimaryButton v-if="S.status === 'approved' && C.can?.complete"
                                       @click="complete">Complete case &amp; terminate employee (2FA)</PrimaryButton>
                        <p v-if="S.status === 'approved' && !C.can?.complete" class="text-sm text-on-surface-variant/70">
                            Settlement approved. Case awaits HR completion.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
