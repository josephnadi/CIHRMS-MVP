<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import StatCard from '@/Components/StatCard.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';

const props = defineProps({
    loan:         Object,
    repayments:   Object,
    activeModule: String,
});

const L = computed(() => props.loan?.data ?? props.loan);
const repayments = computed(() => props.repayments?.data ?? props.repayments ?? []);
const cedi = (v) => 'GHS ' + (Number(v) || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const pct  = (v) => `${(Number(v) * 100).toFixed(1)}%`;

const decideForm = useForm({ decision: 'approve', reason: '' });
const decide = () => decideForm.post(route('loans.decide', L.value.id), { preserveScroll: true });

const disburseForm = useForm({ first_repayment_period: '' });
const disburse = () => disburseForm.post(route('loans.disburse', L.value.id), { preserveScroll: true });

const showReject = ref(false);
</script>

<template>
    <Head :title="`Loan ${L.reference}`" />
    <AuthenticatedLayout :active-module="activeModule">
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <Link :href="route('loans.index')" class="text-xs text-slate-500 hover:underline">← All loans</Link>
                    <h1 class="text-2xl font-semibold tracking-tight">{{ L.reference }}</h1>
                    <p class="text-sm text-slate-500">
                        {{ L.employee?.name }} · {{ L.product?.data?.name ?? L.product?.name ?? '—' }}
                    </p>
                </div>
                <StatusBadge :status="L.status" :label="L.status_label" class="text-base" />
            </div>
        </template>

        <div class="py-6 space-y-6">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <StatCard label="Principal" :value="cedi(L.principal)" />
                <StatCard label="Monthly installment" :value="cedi(L.monthly_installment)" />
                <StatCard label="Outstanding" :value="cedi(L.outstanding_balance)" tone="warn" />
                <StatCard label="Progress" :value="pct(L.progress)" sublabel="of total repayable" />
                <StatCard label="Term" :value="`${L.term_months} months`" />
                <StatCard label="Interest rate" :value="pct(L.booked_interest_rate)" sublabel="annual" />
                <StatCard label="Total interest" :value="cedi(L.total_interest)" />
                <StatCard label="Total repayable" :value="cedi(L.total_repayable)" />
            </div>

            <!-- Action toolbar -->
            <div class="flex flex-wrap gap-3">
                <template v-if="L.status === 'pending_approval' && L.can?.approve">
                    <PrimaryButton @click="decide" :disabled="decideForm.processing">Approve (2FA required)</PrimaryButton>
                    <DangerButton @click="showReject = true">Reject</DangerButton>
                </template>

                <PrimaryButton v-if="L.status === 'approved' && L.can?.disburse"
                               @click="disburse">Disburse (2FA required)</PrimaryButton>
            </div>

            <!-- Reject dialog (inline) -->
            <div v-if="showReject" class="bg-white rounded-2xl shadow-sm border border-rose-200 p-5 space-y-3">
                <h3 class="font-semibold text-rose-700">Reject loan application</h3>
                <textarea v-model="decideForm.reason" rows="3" placeholder="Reason (required)"
                          class="w-full rounded-lg border-slate-200"></textarea>
                <div class="flex gap-2">
                    <DangerButton @click="decideForm.decision='reject'; decide(); showReject=false"
                                  :disabled="!decideForm.reason || decideForm.processing">Confirm rejection</DangerButton>
                    <button class="text-slate-500" @click="showReject=false">Cancel</button>
                </div>
            </div>

            <!-- Repayment schedule -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100">
                <div class="px-5 py-4 border-b border-slate-100">
                    <h3 class="font-semibold">Amortization schedule</h3>
                    <p class="text-xs text-slate-500">{{ repayments.length }} installments · {{ L.installments_paid }} paid</p>
                </div>

                <div v-if="repayments.length === 0" class="px-5 py-12 text-center text-slate-500 text-sm">
                    Schedule generated at disbursement.
                </div>

                <table v-else class="w-full text-sm">
                    <thead class="bg-slate-50 text-slate-600 text-xs uppercase">
                        <tr>
                            <th class="px-4 py-3 text-left">#</th>
                            <th class="px-4 py-3 text-left">Period</th>
                            <th class="px-4 py-3 text-right">Installment</th>
                            <th class="px-4 py-3 text-right">Principal</th>
                            <th class="px-4 py-3 text-right">Interest</th>
                            <th class="px-4 py-3 text-right">Balance after</th>
                            <th class="px-4 py-3 text-left">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="r in repayments" :key="r.id"
                            :class="r.status === 'paid' ? 'bg-emerald-50/40' : 'hover:bg-slate-50'">
                            <td class="px-4 py-2 font-mono text-xs">{{ r.installment_no }}</td>
                            <td class="px-4 py-2">{{ r.due_period }}</td>
                            <td class="px-4 py-2 text-right">{{ cedi(r.scheduled_amount) }}</td>
                            <td class="px-4 py-2 text-right text-slate-600">{{ cedi(r.principal_portion) }}</td>
                            <td class="px-4 py-2 text-right text-slate-500">{{ cedi(r.interest_portion) }}</td>
                            <td class="px-4 py-2 text-right">{{ cedi(r.balance_after) }}</td>
                            <td class="px-4 py-2">
                                <StatusBadge :status="r.status" :label="r.status_label" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Application metadata -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 text-sm grid md:grid-cols-2 gap-4">
                <div>
                    <div class="text-xs text-slate-500">Applied by</div>
                    <div>{{ L.applicant?.name ?? '—' }} <span class="text-xs text-slate-400">· {{ L.applied_at ? new Date(L.applied_at).toLocaleString('en-GH') : '' }}</span></div>
                </div>
                <div>
                    <div class="text-xs text-slate-500">Approved by</div>
                    <div>{{ L.approver?.name ?? '—' }} <span class="text-xs text-slate-400">· {{ L.approved_at ? new Date(L.approved_at).toLocaleString('en-GH') : '' }}</span></div>
                </div>
                <div class="md:col-span-2">
                    <div class="text-xs text-slate-500">Purpose</div>
                    <div>{{ L.purpose || '—' }}</div>
                </div>
                <div v-if="L.rejection_reason" class="md:col-span-2">
                    <div class="text-xs text-rose-600">Rejection reason</div>
                    <div class="text-rose-700">{{ L.rejection_reason }}</div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
