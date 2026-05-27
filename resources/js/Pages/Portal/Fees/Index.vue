<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';

defineOptions({ layout: PortalLayout });

const props = defineProps({
    invoices: { type: Object, required: true },
});

const payForm = useForm({});
function pay(invoiceId) {
    payForm.post(route('portal.fees.pay', invoiceId));
}

const isPayable = (status) => ['approved', 'partially_paid'].includes(status);
</script>

<template>
<Head title="My fees — CIHRM Portal" />
<div class="space-y-6">
    <header>
        <h1 class="text-2xl font-black text-primary">My fees</h1>
        <p class="text-sm text-on-surface-variant">All invoices on your account, latest first.</p>
    </header>

    <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-surface-container">
                <tr class="text-left text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">
                    <th class="px-4 py-3">Reference</th>
                    <th class="px-4 py-3">Date</th>
                    <th class="px-4 py-3">Total</th>
                    <th class="px-4 py-3">Paid</th>
                    <th class="px-4 py-3">Outstanding</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3 text-right">Action</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="inv in invoices.data" :key="inv.id" class="border-t border-outline-variant/40">
                    <td class="px-4 py-2 font-mono text-xs">{{ inv.reference }}</td>
                    <td class="px-4 py-2">{{ inv.invoice_date }}</td>
                    <td class="px-4 py-2 tabular-nums">{{ inv.currency }} {{ inv.total.toFixed(2) }}</td>
                    <td class="px-4 py-2 tabular-nums">{{ inv.currency }} {{ inv.amount_received.toFixed(2) }}</td>
                    <td class="px-4 py-2 tabular-nums font-semibold">{{ inv.currency }} {{ inv.outstanding.toFixed(2) }}</td>
                    <td class="px-4 py-2 capitalize text-xs">{{ inv.status.replace('_', ' ') }}</td>
                    <td class="px-4 py-2 text-right">
                        <button v-if="isPayable(inv.status)"
                                @click="pay(inv.id)" :disabled="payForm.processing"
                                class="rounded-xl bg-primary px-3 py-1.5 text-xs font-black text-white shadow-glow-sm disabled:opacity-50">
                            Pay now
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>
        <div v-if="!invoices.data || invoices.data.length === 0" class="px-6 py-12 text-center text-sm text-on-surface-variant">
            No invoices on file yet.
        </div>
    </div>
</div>
</template>
