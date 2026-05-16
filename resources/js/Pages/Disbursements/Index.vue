<script setup>
import { reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Pagination from '@/Components/Pagination.vue';
import StatCard from '@/Components/StatCard.vue';
import EmptyState from '@/Components/EmptyState.vue';

const props = defineProps({
    disbursements: Object,
    stats:         Object,
    filters:       Object,
    activeModule:  String,
});

const localFilters = reactive({
    run_id:  props.filters?.run_id  ?? '',
    channel: props.filters?.channel ?? '',
    status:  props.filters?.status  ?? '',
});

const applyFilters = () => router.get(route('disbursements.index'), {
    run_id:  localFilters.run_id  || undefined,
    channel: localFilters.channel || undefined,
    status:  localFilters.status  || undefined,
}, { preserveState: true, replace: true });

const cedi = (v) => 'GHS ' + (Number(v) || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const channelClass = (c) => ({
    'mtn_momo':      'bg-amber-100 text-amber-800',
    'vodafone_cash': 'bg-rose-100 text-rose-800',
    'airtel_tigo':   'bg-sky-100 text-sky-800',
    'ghipss_ach':    'bg-emerald-100 text-emerald-800',
    'cash':          'bg-slate-100 text-slate-700',
    'cheque':        'bg-violet-100 text-violet-800',
}[c] ?? 'bg-slate-100 text-slate-700');
</script>

<template>
    <Head title="Disbursements" />

    <AuthenticatedLayout :active-module="activeModule">
        <template #header>
            <div>
                <p class="text-xs text-on-surface-variant/60">Phase 3 · Salary disbursement</p>
                <h1 class="text-2xl font-semibold tracking-tight">Disbursements</h1>
            </div>
        </template>

        <div class="py-6 space-y-6">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <StatCard label="Pending"        :value="stats.pending" />
                <StatCard label="Sent — awaiting settlement" :value="stats.sent" tone="warn" />
                <StatCard label="Settled"        :value="stats.settled" tone="success" />
                <StatCard label="Failed"         :value="stats.failed" tone="danger" />
                <StatCard label="MoMo settled (YTD)" :value="cedi(stats.momo_total)" class="md:col-span-2" />
                <StatCard label="E-Levy paid (YTD)"  :value="cedi(stats.e_levy_total)" class="md:col-span-2" />
            </div>

            <div class="bg-surface-container-lowest rounded-2xl shadow-sm border border-outline-variant/40">
                <div class="px-5 py-4 border-b border-outline-variant/40 flex flex-wrap gap-3 items-center">
                    <input v-model="localFilters.run_id" type="number" placeholder="Run ID"
                           @keyup.enter="applyFilters"
                           class="rounded-lg border-outline-variant text-sm w-24">
                    <select v-model="localFilters.channel" @change="applyFilters" class="rounded-lg border-outline-variant text-sm">
                        <option value="">All channels</option>
                        <option value="ghipss_ach">GhIPSS Bank</option>
                        <option value="mtn_momo">MTN MoMo</option>
                        <option value="vodafone_cash">Vodafone Cash</option>
                        <option value="airtel_tigo">AirtelTigo Money</option>
                        <option value="cash">Cash</option>
                        <option value="cheque">Cheque</option>
                    </select>
                    <select v-model="localFilters.status" @change="applyFilters" class="rounded-lg border-outline-variant text-sm">
                        <option value="">All statuses</option>
                        <option value="pending">Pending</option>
                        <option value="sent">Sent</option>
                        <option value="settled">Settled</option>
                        <option value="failed">Failed</option>
                        <option value="reversed">Reversed</option>
                    </select>
                </div>

                <div v-if="disbursements?.data?.length === 0">
                    <EmptyState title="No disbursements yet"
                                description="Approving a payroll run will materialise disbursement instructions here." />
                </div>

                <table v-else class="w-full text-sm">
                    <thead class="bg-surface-container-low text-on-surface-variant text-xs uppercase">
                        <tr>
                            <th class="px-5 py-3 text-left">Run</th>
                            <th class="px-5 py-3 text-left">Employee</th>
                            <th class="px-5 py-3 text-left">Channel</th>
                            <th class="px-5 py-3 text-right">Gross</th>
                            <th class="px-5 py-3 text-right">E-Levy</th>
                            <th class="px-5 py-3 text-right">Net</th>
                            <th class="px-5 py-3 text-left">Status</th>
                            <th class="px-5 py-3 text-left">Provider Ref</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/30">
                        <tr v-for="d in disbursements.data" :key="d.id"
                            :class="d.status === 'failed' ? 'bg-rose-50/40' : 'hover:bg-surface-container-low/60'">
                            <td class="px-5 py-3 font-mono text-xs">{{ d.run?.reference }}</td>
                            <td class="px-5 py-3">
                                <div class="font-medium">{{ d.employee?.name ?? '—' }}</div>
                                <div class="text-xs text-on-surface-variant/60 font-mono">{{ d.beneficiary_account }}</div>
                            </td>
                            <td class="px-5 py-3">
                                <span class="px-2 py-0.5 text-xs rounded-full font-semibold uppercase"
                                      :class="channelClass(d.channel)">{{ d.channel_label }}</span>
                            </td>
                            <td class="px-5 py-3 text-right">{{ cedi(d.gross_amount) }}</td>
                            <td class="px-5 py-3 text-right text-xs">
                                <span v-if="d.e_levy > 0">{{ cedi(d.e_levy) }}</span>
                                <span v-else class="text-on-surface-variant/40">—</span>
                            </td>
                            <td class="px-5 py-3 text-right font-semibold">{{ cedi(d.net_to_recipient) }}</td>
                            <td class="px-5 py-3">
                                <StatusBadge :status="d.status" :label="d.status_label" />
                                <p v-if="d.failure_reason" class="text-xs text-rose-700 mt-1">{{ d.failure_reason }}</p>
                            </td>
                            <td class="px-5 py-3 font-mono text-xs">{{ d.provider_reference ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>

                <div class="px-5 py-3 border-t border-outline-variant/40">
                    <Pagination :links="disbursements?.meta?.links ?? []" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
