<script setup>
import { Head } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';

defineOptions({ layout: PortalLayout });

defineProps({
    invoices: { type: Array, default: () => [] },
    receipts: { type: Array, default: () => [] },
});
</script>

<template>
<Head title="Statements — CIHRM Portal" />
<div class="space-y-6">
    <header>
        <h1 class="text-2xl font-black text-primary">Statements</h1>
        <p class="text-sm text-on-surface-variant">Your full history of invoices and payments.</p>
    </header>

    <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest">
        <header class="px-6 py-4 border-b border-outline-variant/60">
            <h2 class="text-sm font-black text-primary">Invoices</h2>
        </header>
        <table v-if="invoices.length" class="w-full text-sm">
            <thead class="text-left text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">
                <tr>
                    <th class="px-6 py-2">Reference</th>
                    <th class="px-6 py-2">Date</th>
                    <th class="px-6 py-2">Total</th>
                    <th class="px-6 py-2">Paid</th>
                    <th class="px-6 py-2">Status</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="i in invoices" :key="i.reference" class="border-t border-outline-variant/40">
                    <td class="px-6 py-2 font-mono text-xs">{{ i.reference }}</td>
                    <td class="px-6 py-2">{{ i.invoice_date }}</td>
                    <td class="px-6 py-2 tabular-nums">{{ i.currency }} {{ i.total.toFixed(2) }}</td>
                    <td class="px-6 py-2 tabular-nums">{{ i.currency }} {{ i.amount_received.toFixed(2) }}</td>
                    <td class="px-6 py-2 capitalize text-xs">{{ i.status.replace('_', ' ') }}</td>
                </tr>
            </tbody>
        </table>
        <div v-else class="px-6 py-8 text-center text-sm text-on-surface-variant">No invoices yet.</div>
    </section>

    <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest">
        <header class="px-6 py-4 border-b border-outline-variant/60">
            <h2 class="text-sm font-black text-primary">Receipts</h2>
        </header>
        <table v-if="receipts.length" class="w-full text-sm">
            <thead class="text-left text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">
                <tr>
                    <th class="px-6 py-2">Reference</th>
                    <th class="px-6 py-2">Date</th>
                    <th class="px-6 py-2">Amount</th>
                    <th class="px-6 py-2">External</th>
                    <th class="px-6 py-2">Status</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="r in receipts" :key="r.reference" class="border-t border-outline-variant/40">
                    <td class="px-6 py-2 font-mono text-xs">{{ r.reference }}</td>
                    <td class="px-6 py-2">{{ r.receipt_date }}</td>
                    <td class="px-6 py-2 tabular-nums">{{ r.currency }} {{ r.amount.toFixed(2) }}</td>
                    <td class="px-6 py-2 font-mono text-[10px]">{{ r.external_ref ?? '—' }}</td>
                    <td class="px-6 py-2 capitalize text-xs">{{ r.status }}</td>
                </tr>
            </tbody>
        </table>
        <div v-else class="px-6 py-8 text-center text-sm text-on-surface-variant">No payments recorded yet.</div>
    </section>
</div>
</template>
