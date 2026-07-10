<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import StatCard from '@/Components/StatCard.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';


defineOptions({ layout: AuthenticatedLayout });
const props = defineProps({
    run:          Object,
    lines:        Object,
    returns:      Object,
    canRemit:     Boolean,
    activeModule: String,
});

const R = computed(() => props.run.data ?? props.run);
const cedi = (v) => 'GHS ' + (Number(v) || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const tab = ref('summary');

// Actions dispatched via router.post() directly (not backed by useForm) share
// this busy flag so their buttons can be disabled while in flight.
const busy = ref(false);
const busyPost = (url) => {
    busy.value = true;
    router.post(url, {}, { preserveScroll: true, onFinish: () => { busy.value = false; } });
};

const calculate = () => busyPost(route('payroll-runs.calculate', R.value.id));

const approveForm = useForm({ confirmation: 'approve' });
const approve = () => approveForm.post(route('payroll-runs.approve', R.value.id), { preserveScroll: true });

const reverseForm = useForm({ reason: '' });
const reverse = () => reverseForm.post(route('payroll-runs.reverse', R.value.id), { preserveScroll: true });

const markPaid = () => busyPost(route('payroll-runs.mark-paid', R.value.id));

// Disbursement: push the materialised payout instructions to their providers
// (MoMo/GhIPSS), then reconcile sent-but-unsettled ones.
const dispatchPayouts  = () => busyPost(route('disbursements.dispatch', R.value.id));
const reconcilePayouts = () => busyPost(route('disbursements.reconcile', R.value.id));
// GhIPSS has no status API — the operator confirms the bank settled the batch.
const confirmGhipss    = () => busyPost(route('disbursements.confirm-ghipss', R.value.id));

const lineRows = computed(() => props.lines?.data ?? props.lines ?? []);

const fileForm = useForm({ reference: '', submitted_at: '' });
const filingId = ref(null);
const openMarkFiled = (rt) => { filingId.value = rt.id; fileForm.reset(); fileForm.clearErrors(); };
const cancelFiled = () => { filingId.value = null; fileForm.reset(); fileForm.clearErrors(); };
const submitFiled = (rt) => fileForm.post(
    route('payroll-runs.return-mark-filed', { run: R.value.id, returnId: rt.id }),
    { preserveScroll: true, onSuccess: () => { filingId.value = null; } },
);
</script>

<template>
    <Head :title="`Payroll Run ${R.reference}`" />
    <div data-page-root="true">

            <Teleport to="#page-header-mount" defer>
                <div class="flex items-center justify-between">
                    <div>
                        <Link :href="route('payroll-runs.index')" class="text-xs text-on-surface-variant hover:underline">← All runs</Link>
                        <div class="flex items-center gap-2 mt-1 mb-1">
                            <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">receipt_long</span>
                            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">{{ R.period_label }} · {{ R.department?.name ?? 'Whole organization' }}</p>
                        </div>
                        <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight font-mono">{{ R.reference }}</h1>
                    </div>
                    <StatusBadge :status="R.status" :label="R.status_label" class="text-base" />
                </div>
            </Teleport>

            <div class="py-6 space-y-6">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <StatCard label="Lines processed" :value="R.lines_count" :sublabel="`${R.skipped_count} skipped`" />
                    <StatCard label="Gross" :value="cedi(R.totals.gross)" />
                    <StatCard label="Net pay" :value="cedi(R.totals.net)" />
                    <StatCard label="PAYE remitted" :value="cedi(R.totals.paye)" />
                    <StatCard label="SSNIT 5.5% (employee)" :value="cedi(R.totals.ssnit_employee)" />
                    <StatCard label="SSNIT 13% (employer)" :value="cedi(R.totals.ssnit_employer)" />
                    <StatCard label="NHIA (2.5% split)" :value="cedi(R.totals.nhia)" />
                    <StatCard label="Tier-2 (5% employer)" :value="cedi(R.totals.tier2)" />
                </div>

                <div class="flex flex-wrap gap-3">
                    <PrimaryButton v-if="['draft', 'calculated'].includes(R.status)"
                                   :disabled="busy" @click="calculate">Calculate / Recalculate</PrimaryButton>
                    <PrimaryButton v-if="R.status === 'calculated' && R.can?.approve"
                                   :disabled="approveForm.processing" @click="approve">Approve (2FA required)</PrimaryButton>
                    <PrimaryButton v-if="R.status === 'approved'" :disabled="busy" @click="markPaid">Mark as paid</PrimaryButton>
                    <SecondaryButton v-if="['approved','paid'].includes(R.status) && R.can?.disburse" :disabled="busy" @click="dispatchPayouts">Dispatch payouts</SecondaryButton>
                    <SecondaryButton v-if="['approved','paid'].includes(R.status) && R.can?.disburse" :disabled="busy" @click="reconcilePayouts">Reconcile payouts</SecondaryButton>
                    <SecondaryButton v-if="['approved','paid'].includes(R.status) && R.can?.disburse" :disabled="busy" @click="confirmGhipss">Confirm GhIPSS settlement</SecondaryButton>
                    <DangerButton  v-if="R.can?.reverse" :disabled="!reverseForm.reason || reverseForm.processing" @click="reverse">Reverse</DangerButton>
                </div>

                <div v-if="R.can?.reverse">
                    <label class="block text-xs font-medium text-on-surface-variant mb-1">Reversal reason (required if reversing)</label>
                    <textarea aria-label="Reversal reason (required if reversing)" v-model="reverseForm.reason" rows="2"
                              class="w-full rounded-lg border-outline-variant"></textarea>
                    <p v-if="reverseForm.errors.reason" class="mt-1 text-xs text-rose-600">{{ reverseForm.errors.reason }}</p>
                </div>

                <div class="bg-surface-container-lowest rounded-2xl shadow-sm border border-outline-variant/60">
                    <div class="px-5 py-3 border-b border-outline-variant/50 flex gap-6 text-sm">
                        <button @click="tab='lines'" :class="tab==='lines' ? 'text-blue-600 font-semibold' : 'text-on-surface-variant'">Lines ({{ lines?.meta?.total ?? lines?.data?.length ?? 0 }})</button>
                        <button @click="tab='returns'" :class="tab==='returns' ? 'text-blue-600 font-semibold' : 'text-on-surface-variant'">Statutory returns ({{ returns?.data?.length ?? returns?.length ?? 0 }})</button>
                    </div>

                    <div v-if="tab==='lines'" class="overflow-x-auto">
                        <table v-if="lineRows.length" class="w-full text-sm">
                            <thead class="bg-surface-container-low/20 text-on-surface-variant text-xs uppercase">
                                <tr>
                                    <th class="px-4 py-3 text-left">Employee</th>
                                    <th class="px-4 py-3 text-left">Grade/Step</th>
                                    <th class="px-4 py-3 text-right">Basic</th>
                                    <th class="px-4 py-3 text-right">Allowances</th>
                                    <th class="px-4 py-3 text-right">SSNIT 5.5%</th>
                                    <th class="px-4 py-3 text-right">PAYE</th>
                                    <th class="px-4 py-3 text-right">Net</th>
                                    <th class="px-4 py-3 text-left">Status</th>
                                    <th class="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-outline-variant/30">
                                <tr v-for="l in lineRows" :key="l.id"
                                    :class="l.status === 'skipped' ? 'bg-amber-50' : 'hover:bg-surface-container-low/20'">
                                    <td class="px-4 py-2">
                                        <div class="font-medium">{{ l.employee?.name ?? '—' }}</div>
                                        <div class="text-xs text-on-surface-variant">{{ l.employee?.employee_no }}</div>
                                    </td>
                                    <td class="px-4 py-2">{{ l.grade_code }} / {{ l.step }}</td>
                                    <td class="px-4 py-2 text-right">{{ cedi(l.basic) }}</td>
                                    <td class="px-4 py-2 text-right">{{ cedi(l.allowance_total) }}</td>
                                    <td class="px-4 py-2 text-right">{{ cedi(l.ssnit_employee) }}</td>
                                    <td class="px-4 py-2 text-right">{{ cedi(l.paye) }}</td>
                                    <td class="px-4 py-2 text-right font-semibold">{{ cedi(l.net) }}</td>
                                    <td class="px-4 py-2">
                                        <StatusBadge :status="l.status" :label="l.status" />
                                        <div v-if="l.skip_reason" class="text-xs text-amber-700">{{ l.skip_reason }}</div>
                                    </td>
                                    <td class="px-4 py-2 text-right">
                                        <a v-if="l.status !== 'skipped'" :href="route('payroll-runs.payslip', { run: R.id, line: l.id })"
                                           target="_blank" class="text-[12px] font-bold text-blue-600 hover:underline">Payslip</a>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-else class="p-8 text-center text-sm text-on-surface-variant">
                            No payroll lines yet — calculate the run to generate them.
                        </p>
                    </div>

                    <div v-if="tab==='returns'" class="divide-y divide-outline-variant/30">
                        <div v-for="rt in (returns?.data ?? returns ?? [])" :key="rt.id"
                             class="px-5 py-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="font-medium">{{ rt.kind_label }}</div>
                                    <div class="text-xs text-on-surface-variant">{{ rt.record_count }} records · {{ cedi(rt.total_amount) }}</div>
                                </div>
                                <div class="flex items-center gap-4">
                                    <span :class="{
                                            'text-amber-600': rt.status === 'pending',
                                            'text-rose-600': rt.status === 'overdue',
                                            'text-emerald-600': rt.status === 'submitted',
                                        }" class="text-[12px] font-bold capitalize">{{ rt.status }}</span>
                                    <span v-if="rt.due_date && rt.status !== 'submitted'" class="text-[11px] text-on-surface-variant">due {{ rt.due_date }}</span>
                                    <span v-if="rt.status === 'submitted'" class="text-[11px] text-on-surface-variant">filed {{ rt.submission_reference }}</span>
                                    <button v-if="canRemit && rt.status !== 'submitted' && filingId !== rt.id"
                                            @click="openMarkFiled(rt)"
                                            class="text-[12px] font-bold text-blue-600 hover:underline">Mark filed</button>
                                    <a :href="route('payroll-runs.return-download', { run: R.id, returnId: rt.id })"
                                       class="text-blue-600 hover:underline text-sm">Download</a>
                                </div>
                            </div>
                            <div v-if="filingId === rt.id" class="mt-3 flex items-center gap-2">
                                <input v-model="fileForm.reference" type="text" aria-label="Filing reference"
                                       placeholder="Filing reference (e.g. GRA-2026-06)"
                                       class="flex-1 rounded-lg border-outline-variant text-sm" />
                                <PrimaryButton @click="submitFiled(rt)" :disabled="fileForm.processing">Save</PrimaryButton>
                                <SecondaryButton @click="cancelFiled">Cancel</SecondaryButton>
                            </div>
                            <p v-if="filingId === rt.id && fileForm.errors.reference" class="mt-1 text-xs text-rose-600">{{ fileForm.errors.reference }}</p>
                        </div>
                        <div v-if="(returns?.data?.length ?? returns?.length ?? 0) === 0"
                             class="px-5 py-8 text-center text-on-surface-variant text-sm">
                            Approve the run to generate statutory return files.
                        </div>
                    </div>
                </div>
            </div>
    </div>
</template>
