<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    activeModule: { type: String, default: 'finance-payouts' },
    batches:      { type: Object, required: true },
});

const rows = computed(() => props.batches.data ?? props.batches ?? []);

const money = (v, currency = 'GHS') =>
    `${currency} ` + (Number(v) || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const statusColor = (val) => ({
    draft:            'text-on-surface-variant bg-surface-container border-outline-variant',
    pending_release:  'text-amber-700 bg-amber-50 border-amber-100',
    released:         'text-blue-700 bg-blue-50 border-blue-100',
    completed:        'text-emerald-700 bg-emerald-50 border-emerald-100',
    failed:           'text-rose-700 bg-rose-50 border-rose-100',
    cancelled:        'text-rose-900 bg-rose-100 border-rose-200',
}[val] ?? 'text-on-surface-variant bg-surface-container border-outline-variant');
</script>

<template>
    <Head title="Payouts" />

    <div class="space-y-6 animate-reveal-up">
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">FINANCE — PAYOUTS</p>
            <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Payout Batches</h1>
            <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                {{ rows.length }} batch{{ rows.length === 1 ? '' : 'es' }} · maker-checker release required before any provider is called.
            </p>
        </div>

        <div v-if="rows.length" class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-x-auto">
            <table class="w-full text-[12px]">
                <thead class="bg-surface-container border-b border-outline-variant/40">
                    <tr class="text-left">
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Reference</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider text-right">Total</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider text-right">Disbursements</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Status</th>
                        <th class="px-4 py-2.5 font-black text-on-surface-variant uppercase text-[10px] tracking-wider">Created</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="batch in rows" :key="batch.id" class="border-t border-outline-variant/30 hover:bg-surface-container/40">
                        <td class="px-4 py-2 font-mono font-bold text-primary">
                            <Link :href="route('finance.payouts.show', batch.id)" class="hover:underline">{{ batch.reference }}</Link>
                        </td>
                        <td class="px-4 py-2 text-right font-mono text-primary">{{ money(batch.total_amount, batch.currency) }}</td>
                        <td class="px-4 py-2 text-right font-mono text-on-surface-variant">{{ batch.disbursements_count ?? '—' }}</td>
                        <td class="px-4 py-2">
                            <span class="rounded-full px-2 py-0.5 text-[9px] font-black uppercase border" :class="statusColor(batch.status)">{{ batch.status_label }}</span>
                        </td>
                        <td class="px-4 py-2 text-on-surface-variant">{{ batch.created_at ?? '—' }}</td>
                        <td class="px-4 py-2 text-right">
                            <Link :href="route('finance.payouts.show', batch.id)" class="text-[11px] font-bold text-secondary hover:underline">View →</Link>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-else icon="send_money" title="No payout batches yet" description="Batches appear here once payroll or another source initiates a payout run." />
    </div>
</template>
