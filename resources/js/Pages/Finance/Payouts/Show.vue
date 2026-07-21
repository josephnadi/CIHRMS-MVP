<script setup>
import { computed, ref } from 'vue';
import { Head, Link, usePage, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    activeModule: { type: String, default: 'finance-payouts' },
    batch:        { type: Object, required: true },
    rows:         { type: Array,  default: () => [] },
});

const page = usePage();
const perms = computed(() => {
    const p = page.props?.auth?.permissions ?? [];
    return Array.isArray(p) ? p : (typeof p === 'function' ? p() : []);
});
const canRelease = computed(() => perms.value.includes('*') || perms.value.includes('payouts.release'));
const canReleaseThisBatch = computed(() => canRelease.value && props.batch.status === 'pending_release');

const money = (v, currency = props.batch.currency) =>
    `${currency} ` + (Number(v) || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const statusColor = (val) => ({
    draft:            'text-on-surface-variant bg-surface-container border-outline-variant',
    pending_release:  'text-amber-700 bg-amber-50 border-amber-100',
    released:         'text-blue-700 bg-blue-50 border-blue-100',
    completed:        'text-emerald-700 bg-emerald-50 border-emerald-100',
    settled:          'text-emerald-700 bg-emerald-50 border-emerald-100',
    sent:             'text-blue-700 bg-blue-50 border-blue-100',
    pending:          'text-amber-700 bg-amber-50 border-amber-100',
    failed:           'text-rose-700 bg-rose-50 border-rose-100',
    reversed:         'text-rose-900 bg-rose-100 border-rose-200',
    cancelled:        'text-rose-900 bg-rose-100 border-rose-200',
}[val] ?? 'text-on-surface-variant bg-surface-container border-outline-variant');

// ── Release (checker action) ────────────────────────────────────────────────
// A distinct confirmation step before dispatching real money: the dialog
// only closes on a successful release so the maker/checker sees any error
// (e.g. the segregation-of-duties 403) without losing their place.
const confirmOpen = ref(false);
const releaseForm = useForm({});

const openConfirm = () => { releaseForm.clearErrors(); confirmOpen.value = true; };
const doRelease = () => {
    releaseForm.post(route('finance.payouts.release', props.batch.id), {
        preserveScroll: true,
        onSuccess: () => { confirmOpen.value = false; },
    });
};
</script>

<template>
    <Head :title="`Payout batch ${batch.reference}`" />

    <div class="space-y-6 animate-reveal-up">
        <div>
            <Link :href="route('finance.payouts.index')" class="text-[11px] font-bold text-secondary hover:underline">← Back to payouts</Link>
            <div class="mt-2 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-[1.6rem] font-black tracking-tight text-primary">{{ batch.reference }}</h1>
                    <p class="text-[13px] text-on-surface-variant mt-0.5">{{ rows.length }} disbursement{{ rows.length === 1 ? '' : 's' }}</p>
                </div>
                <div class="flex items-center gap-3">
                    <button
                        v-if="canReleaseThisBatch"
                        type="button"
                        aria-label="Release payout batch"
                        @click="openConfirm"
                        class="rounded-xl border border-secondary/40 bg-secondary/5 px-3 py-2 text-[12px] font-bold text-secondary hover:bg-secondary/10"
                    >
                        <span class="material-symbols-outlined text-[14px] mr-1 align-text-bottom">send</span>Release
                    </button>
                    <span class="rounded-full px-3 py-1 text-[10px] font-black uppercase border" :class="statusColor(batch.status)">{{ batch.status_label }}</span>
                </div>
            </div>
            <p v-if="batch.requires_high_approval" class="mt-2 text-[11px] font-bold text-amber-700">
                This batch is above the high-value threshold and requires an elevated (payouts.release_high) approver.
            </p>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2 rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-x-auto">
                <table v-if="rows.length" class="w-full text-[12px]">
                    <thead class="border-b border-outline-variant/40">
                        <tr class="text-left">
                            <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Beneficiary</th>
                            <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Account</th>
                            <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Channel</th>
                            <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider text-right">Amount</th>
                            <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Status</th>
                            <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Failure reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id" class="border-t border-outline-variant/30">
                            <td class="px-4 py-2 text-on-surface">{{ row.beneficiary_name }}</td>
                            <td class="px-4 py-2 font-mono text-on-surface-variant">{{ row.beneficiary_account }}</td>
                            <td class="px-4 py-2 text-on-surface-variant">{{ row.channel }}</td>
                            <td class="px-4 py-2 text-right font-mono text-primary">{{ money(row.net_to_recipient) }}</td>
                            <td class="px-4 py-2">
                                <span class="rounded-full px-2 py-0.5 text-[9px] font-black uppercase border" :class="statusColor(row.status)">{{ row.status }}</span>
                            </td>
                            <td class="px-4 py-2 text-on-surface-variant">{{ row.failure_reason ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState v-else icon="receipt_long" title="No disbursements" description="This batch has no disbursement lines." />
            </div>

            <div class="space-y-4">
                <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5 text-[12px] space-y-2">
                    <div class="flex justify-between font-black text-primary text-[14px]"><span>Total</span><span class="font-mono">{{ money(batch.total_amount) }}</span></div>
                    <div class="flex justify-between"><span>Currency</span><span class="font-mono">{{ batch.currency }}</span></div>
                    <div class="flex justify-between"><span>Created by</span><span class="font-mono">#{{ batch.created_by }}</span></div>
                    <div v-if="batch.released_by" class="flex justify-between"><span>Released by</span><span class="font-mono">#{{ batch.released_by }}</span></div>
                    <div v-if="batch.released_at" class="flex justify-between"><span>Released at</span><span class="font-mono">{{ batch.released_at }}</span></div>
                    <div v-if="batch.created_at" class="flex justify-between"><span>Created at</span><span class="font-mono">{{ batch.created_at }}</span></div>
                </div>

                <p v-if="releaseForm.hasErrors" class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-[11px] font-bold text-rose-700">
                    {{ Object.values(releaseForm.errors)[0] }}
                </p>
            </div>
        </div>

        <ConfirmDialog
            :open="confirmOpen"
            title="Release this payout batch?"
            :message="`This dispatches ${money(batch.total_amount)} across ${rows.length} disbursement${rows.length === 1 ? '' : 's'} to the provider. This cannot be undone.`"
            confirm-text="Release"
            :loading="releaseForm.processing"
            @confirm="doRelease"
            @cancel="confirmOpen = false"
        />
    </div>
</template>
